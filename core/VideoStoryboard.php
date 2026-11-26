<?php
/**
 * VideoStoryboard — the deterministic half of the infographic video pipeline.
 *
 *   • Dataset: parse CSV / JSON / "Label: value" text into
 *       { values: {key: {label, value, unit}}, lists: {key: {label, values[], unit}}, notes[] }
 *     Every number that ends up on screen comes from here — never from the model.
 *   • Form: validate the generation form (per-field errors + warnings).
 *   • Prompt: the static system prompt (cached), the JSON schema, and the
 *     compact per-job user message.
 *   • Resolve: check the model's storyboard against the dataset and limits,
 *     fill {{key}} placeholders, compute ratios/differences, and attach a
 *     render-ready copy (`_r`) to every scene. Errors block rendering.
 *
 * Units: 'seconds' | 'number' | 'percent'.
 */

class VideoStoryboard
{
    public const LANGUAGES = [
        'sr-Latn' => 'Serbian, Latin script',
        'sr-Cyrl' => 'Serbian, Cyrillic script',
        'en'      => 'English',
    ];
    public const SCENE_TYPES = ['title', 'area_compare', 'big_numbers', 'spotlight', 'bar_ranking', 'quote', 'outro'];
    public const MAX_FOOTAGE = 8;
    public const MAX_ROWS    = 5000;

    /** Character limits per text field (renderer shrinks text, these keep it legible). */
    private const LIMITS = [
        'headline_line' => 24, 'headline' => 42, 'headline_accent' => 24, 'subtitle' => 80,
        'label' => 30, 'legend' => 50, 'value_label' => 22, 'template' => 40,
        'quote' => 170, 'attribution' => 50, 'cta' => 40, 'ticker' => 160,
    ];
    private const DEFAULT_DURATION = [
        'title' => 3, 'area_compare' => 6, 'big_numbers' => 5, 'spotlight' => 6,
        'bar_ranking' => 6, 'quote' => 5, 'outro' => 3,
    ];

    // ─────────────────────────────────────────────────────────── dataset ──

    public static function emptyDataset(string $source = ''): array
    {
        return ['source' => $source, 'values' => [], 'lists' => [], 'notes' => []];
    }

    /** Parse a data file by MIME/extension. */
    public static function parseFile(string $path, string $mime, string $name): array
    {
        $raw = (string) @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        $raw = self::toUtf8($raw);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === 'application/json' || $ext === 'json') {
            return self::parseJson($raw, $name);
        }
        if ($mime === 'text/csv' || $ext === 'csv' || $ext === 'tsv') {
            return self::parseCsv($raw, $name);
        }
        // Plain text: CSV if it looks tabular, else "Label: value" lines.
        $first = strtok($raw, "\n") ?: '';
        if (substr_count($first, ',') >= 2 || substr_count($first, ';') >= 2 || substr_count($first, "\t") >= 1) {
            $ds = self::parseCsv($raw, $name);
            if ($ds['values']) return $ds;
        }
        return self::parseText($raw, $name);
    }

    public static function parseCsv(string $raw, string $source = 'CSV'): array
    {
        $ds = self::emptyDataset($source);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $firstLine = strtok($raw, "\n") ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = null;
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null] || count(array_filter($r, fn($c) => trim((string) $c) !== '')) === 0) continue;
            if ($header === null) { $header = array_map(fn($h) => trim((string) $h), $r); continue; }
            $row = [];
            foreach ($header as $i => $h) $row[$h !== '' ? $h : "col{$i}"] = trim((string) ($r[$i] ?? ''));
            $rows[] = $row;
            if (count($rows) >= self::MAX_ROWS) { $ds['notes'][] = 'Only the first ' . self::MAX_ROWS . ' rows were read.'; break; }
        }
        fclose($fh);
        if (!$header || !$rows) {
            $ds['notes'][] = 'No data rows found (the first row must be a header).';
            return $ds;
        }
        return self::fromRows($rows, $source, $ds);
    }

    /**
     * Rows → dataset. Detects measures (numbers, percentages, durations,
     * start/end time pairs) and categories, then aggregates every measure per
     * category value. Keys look like "club.sns.seconds.sum".
     */
    public static function fromRows(array $rows, string $source, ?array $ds = null): array
    {
        $ds = $ds ?? self::emptyDataset($source);
        $cols = array_keys($rows[0]);
        $kind = [];   // col => number|percent|duration|clock|category|empty
        foreach ($cols as $c) {
            $vals = array_values(array_filter(array_column($rows, $c), fn($v) => $v !== '' && $v !== null));
            if (!$vals) { $kind[$c] = 'empty'; continue; }
            $sample = array_slice($vals, 0, 200);
            $isClock = count(array_filter($sample, fn($v) => self::parseClock((string) $v) !== null)) === count($sample);
            $isPct   = count(array_filter($sample, fn($v) => preg_match('/%\s*$/', (string) $v) && self::parseNumber((string) $v) !== null)) === count($sample);
            $isNum   = count(array_filter($sample, fn($v) => self::parseNumber((string) $v) !== null)) === count($sample);
            if ($isClock) {
                $kind[$c] = preg_match('/^(start|end|begin|stop|from|to|od|do|po[cč]etak|kraj|po[cč]elo|zavr[sš]eno|vreme_od|vreme_do)/iu', $c)
                    ? 'clock' : 'duration';
            } elseif ($isPct) {
                $kind[$c] = 'percent';
            } elseif ($isNum) {
                $kind[$c] = 'number';
            } else {
                $kind[$c] = 'category';
            }
        }

        // Measures: col => [label, unit, extractor]
        $measures = [];
        $startCol = $endCol = null;
        foreach ($cols as $c) {
            if ($kind[$c] !== 'clock') continue;
            if (preg_match('/^(start|begin|from|od|po[cč])/iu', $c)) $startCol = $startCol ?? $c;
            else $endCol = $endCol ?? $c;
        }
        if ($startCol && $endCol) {
            $measures['seconds'] = ['label' => "duration ({$startCol}→{$endCol})", 'unit' => 'seconds',
                'get' => function ($row) use ($startCol, $endCol) {
                    $a = self::parseClock($row[$startCol] ?? ''); $b = self::parseClock($row[$endCol] ?? '');
                    if ($a === null || $b === null) return null;
                    $d = $b - $a;
                    return $d < 0 ? $d + 86400 : $d;
                }];
            $ds['notes'][] = "Durations computed as {$endCol} − {$startCol}.";
        }
        foreach ($cols as $c) {
            $slug = self::slug($c);
            if ($kind[$c] === 'duration') {
                $measures[$slug] = ['label' => $c, 'unit' => 'seconds', 'get' => fn($row) => self::parseClock($row[$c] ?? '')];
            } elseif ($kind[$c] === 'number' || $kind[$c] === 'percent') {
                $unit = $kind[$c] === 'percent' ? 'percent'
                    : (preg_match('/(sec|sek|second|trajanj|duration)/iu', $c) ? 'seconds' : 'number');
                $measures[$slug] = ['label' => $c, 'unit' => $unit, 'get' => fn($row) => self::parseNumber($row[$c] ?? '')];
            }
        }
        if (!$measures) {
            $ds['notes'][] = 'No numeric, percentage, duration or start/end columns found.';
        }

        $rowCount = count($rows);
        self::addValue($ds, 'total.rows', 'Total rows', $rowCount, 'number');
        foreach ($measures as $m => $def) {
            $col = array_map($def['get'], $rows);
            $nums = array_values(array_filter($col, fn($v) => $v !== null));
            if (!$nums) continue;
            if ($def['unit'] !== 'percent') {
                self::addValue($ds, "total.{$m}.sum", "{$def['label']} — total", array_sum($nums), $def['unit']);
            }
            if ($rowCount > 1) {
                self::addValue($ds, "total.{$m}.avg", "{$def['label']} — average per row", array_sum($nums) / count($nums), $def['unit']);
            }
            if ($rowCount <= 40) {
                self::addList($ds, "total.{$m}", "{$def['label']} — every row in order", $nums, $def['unit']);
            }
        }

        // Group by each category column (skip free-text columns with too many distinct values).
        foreach ($cols as $c) {
            if ($kind[$c] !== 'category') continue;
            $groups = [];
            foreach ($rows as $i => $row) {
                $g = trim((string) ($row[$c] ?? ''));
                if ($g === '') continue;
                $groups[$g][] = $i;
            }
            if (count($groups) > 40 || count($groups) < 1) continue;
            $cSlug = self::slug($c);
            $used = [];
            foreach ($groups as $g => $idx) {
                $gSlug = self::slug($g);
                $base = $gSlug; $n = 2;
                while (isset($used[$gSlug])) $gSlug = $base . '_' . $n++;
                $used[$gSlug] = true;
                $prefix = "{$cSlug}.{$gSlug}";
                if ($rowCount > count($groups)) {
                    self::addValue($ds, "{$prefix}.count", "{$c} = {$g} — number of rows", count($idx), 'number');
                }
                foreach ($measures as $m => $def) {
                    $vals = [];
                    foreach ($idx as $i) { $v = ($def['get'])($rows[$i]); if ($v !== null) $vals[] = $v; }
                    if (!$vals) continue;
                    if ($def['unit'] === 'percent') {
                        if (count($vals) === 1) self::addValue($ds, "{$prefix}.{$m}", "{$c} = {$g} — {$def['label']}", $vals[0], 'percent');
                        continue;
                    }
                    $sum = array_sum($vals);
                    self::addValue($ds, "{$prefix}.{$m}.sum", "{$c} = {$g} — {$def['label']} (total)", $sum, $def['unit']);
                    $total = $ds['values']["total.{$m}.sum"]['value'] ?? 0;
                    if ($total > 0 && count($groups) > 1) {
                        self::addValue($ds, "{$prefix}.{$m}.share", "{$c} = {$g} — share of all {$def['label']}", $sum / $total * 100, 'percent');
                    }
                    if (count($vals) > 1 && count($vals) <= 60) {
                        self::addList($ds, "{$prefix}.{$m}", "{$c} = {$g} — {$def['label']} per row", $vals, $def['unit']);
                    }
                }
            }
        }
        return $ds;
    }

    public static function parseJson(string $raw, string $source = 'JSON'): array
    {
        $ds = self::emptyDataset($source);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $ds['notes'][] = 'The JSON file could not be parsed.';
            return $ds;
        }
        // Already in dataset shape.
        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $k => $v) {
                if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) {
                    self::addValue($ds, self::slugKey((string) $k), (string) ($v['label'] ?? $k), (float) $v['value'],
                        self::normUnit($v['unit'] ?? 'number'));
                } elseif (is_numeric($v)) {
                    self::addValue($ds, self::slugKey((string) $k), (string) $k, (float) $v, 'number');
                }
            }
            foreach (($data['lists'] ?? []) as $k => $l) {
                $vals = is_array($l) ? ($l['values'] ?? $l) : [];
                $vals = array_values(array_filter($vals, 'is_numeric'));
                if ($vals) self::addList($ds, self::slugKey((string) $k), (string) ($l['label'] ?? $k), array_map('floatval', $vals), self::normUnit($l['unit'] ?? 'number'));
            }
            return $ds;
        }
        // Array of row objects → same pipeline as CSV.
        if (self::isList($data) && $data && is_array($data[0])) {
            $rows = array_map(fn($r) => array_map(fn($v) => is_scalar($v) ? (string) $v : '', (array) $r), array_slice($data, 0, self::MAX_ROWS));
            return self::fromRows($rows, $source, $ds);
        }
        // Nested object → flatten numeric leaves.
        $walk = function ($node, string $path) use (&$walk, &$ds) {
            if (is_array($node) && self::isList($node) && $node && count(array_filter($node, 'is_numeric')) === count($node)) {
                self::addList($ds, self::slugKey($path), $path, array_map('floatval', $node), self::guessUnit($path));
                return;
            }
            if (is_array($node)) {
                foreach ($node as $k => $v) $walk($v, $path === '' ? (string) $k : "{$path}.{$k}");
                return;
            }
            if (is_numeric($node)) {
                self::addValue($ds, self::slugKey($path), $path, (float) $node, self::guessUnit($path));
            } elseif (is_string($node) && ($s = self::parseClock($node)) !== null) {
                self::addValue($ds, self::slugKey($path), $path, $s, 'seconds');
            }
        };
        $walk($data, '');
        return $ds;
    }

    /**
     * "Label: value" lines (also "Label = value", "Label<TAB>value"). A value
     * with several numbers ("12, 7, 3") becomes a list. Unparsed lines are
     * counted in notes so the UI can offer the AI extraction instead.
     */
    public static function parseText(string $raw, string $source = 'Pasted text'): array
    {
        $ds = self::emptyDataset($source);
        $skipped = 0;
        foreach (preg_split('/\R/u', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^(.{1,80}?)\s*(?::|=|\t|—| - )\s*(.+)$/u', $line, $m)) { $skipped++; continue; }
            $label = trim($m[1]);
            $valueText = trim($m[2]);
            $key = 'text.' . self::slug($label);
            if (($s = self::parseDuration($valueText)) !== null) {
                self::addValue($ds, $key, $label, $s, 'seconds');
            } elseif (preg_match('/^-?[\d.,\s]+%$/u', $valueText) && ($n = self::parseNumber($valueText)) !== null) {
                self::addValue($ds, $key, $label, $n, 'percent');
            } elseif (($n = self::parseNumber($valueText)) !== null) {
                self::addValue($ds, $key, $label, $n, self::guessUnit($label));
            } elseif (preg_match_all('/-?\d+(?:[.,]\d+)?/', $valueText, $nm) && count($nm[0]) > 1) {
                self::addList($ds, $key, $label, array_map(fn($x) => (float) str_replace(',', '.', $x), $nm[0]), self::guessUnit($label));
            } else {
                $skipped++;
            }
        }
        if ($skipped) $ds['notes'][] = "{$skipped} line(s) had no recognisable \"Label: value\" number.";
        return $ds;
    }

    private static function addValue(array &$ds, string $key, string $label, $value, string $unit): void
    {
        if (!is_finite((float) $value)) return;
        $base = $key; $n = 2;
        while (isset($ds['values'][$key])) $key = $base . '_' . $n++;
        $ds['values'][$key] = ['label' => mb_substr($label, 0, 120), 'value' => round((float) $value, 4), 'unit' => $unit];
    }

    private static function addList(array &$ds, string $key, string $label, array $values, string $unit): void
    {
        $ds['lists'][$key] = ['label' => mb_substr($label, 0, 120), 'values' => array_map(fn($v) => round((float) $v, 4), array_values($values)), 'unit' => $unit];
    }

    // ───────────────────────────────────────────────────────── parsing helpers ──

    /** "1:40:03", "40:03", "2026-11-18 10:15:00", "10:15" → seconds (clock or duration). */
    public static function parseClock(string $v): ?float
    {
        $v = trim($v);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?/', $v, $m)) {
            $ts = strtotime("{$m[1]} 00:00:00 UTC");
            return $ts === false ? null : (float) ($ts + $m[2] * 3600 + $m[3] * 60 + ($m[4] ?? 0));
        }
        if (preg_match('/^(\d{1,3}):(\d{2})(?::(\d{2}))?(?:[.,]\d+)?$/', $v, $m)) {
            return isset($m[3]) && $m[3] !== ''
                ? (float) ($m[1] * 3600 + $m[2] * 60 + $m[3])
                : (float) ($m[1] * 60 + $m[2]);   // mm:ss
        }
        return null;
    }

    /** Clock formats plus "1 h 40 min 3 s" / "23 min" / "45 s". */
    public static function parseDuration(string $v): ?float
    {
        $v = trim($v);
        if (preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', $v)) return self::parseClock($v);
        if (preg_match('/^(?:(\d+)\s*(?:h|č|ч|sat[ia]?|hours?)\s*)?(?:(\d+)\s*(?:min|мин|minuta?|minutes?)\s*)?(?:(\d+)\s*(?:s|с|sek|sec|sekundi?|seconds?))?$/iu', $v, $m)
            && ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '') !== '') {
            return (float) ((int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0));
        }
        return null;
    }

    /** "1.234,5" / "1,234.5" / "12,5" / "12.5" / "45%" → float. */
    public static function parseNumber(string $v): ?float
    {
        $v = trim(str_replace(["\u{00A0}", ' ', '%'], '', $v));
        if ($v === '' || !preg_match('/^[-+]?[\d.,]+$/', $v) || !preg_match('/\d/', $v)) return null;
        $lastDot = strrpos($v, '.'); $lastComma = strrpos($v, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $dec = $lastDot > $lastComma ? '.' : ',';
            $thou = $dec === '.' ? ',' : '.';
            $v = str_replace([$thou, $dec], ['', '.'], $v);
        } elseif ($lastComma !== false) {
            $v = preg_match('/^[-+]?\d{1,3}(,\d{3})+$/', $v) ? str_replace(',', '', $v) : str_replace(',', '.', $v);
        } elseif ($lastDot !== false && substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    private static function guessUnit(string $label): string
    {
        if (preg_match('/(sec|sek|second|trajanj|duration|vreme govora)/iu', $label)) return 'seconds';
        if (preg_match('/(percent|procen|udeo|share|%)/iu', $label)) return 'percent';
        return 'number';
    }

    private static function normUnit($u): string
    {
        $u = strtolower((string) $u);
        if (in_array($u, ['s', 'sec', 'secs', 'second', 'seconds', 'duration'], true)) return 'seconds';
        if (in_array($u, ['%', 'pct', 'percent', 'percentage'], true)) return 'percent';
        return 'number';
    }

    public static function slug(string $s): string
    {
        static $map = [
            'š' => 's', 'đ' => 'dj', 'č' => 'c', 'ć' => 'c', 'ž' => 'z', 'Š' => 's', 'Đ' => 'dj', 'Č' => 'c', 'Ć' => 'c', 'Ž' => 'z',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'dj', 'е' => 'e', 'ж' => 'z', 'з' => 'z', 'и' => 'i',
            'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'ћ' => 'c', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'dz', 'ш' => 's',
        ];
        $s = strtr(mb_strtolower(trim($s)), $map);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        return $s === '' ? 'x' : substr($s, 0, 40);
    }

    private static function slugKey(string $path): string
    {
        return implode('.', array_map([self::class, 'slug'], explode('.', $path)));
    }

    private static function toUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
        $conv = @mb_convert_encoding($s, 'UTF-8', 'Windows-1250');
        return $conv !== false ? $conv : $s;
    }

    // ─────────────────────────────────────────────────────────── formatting ──

    /** Format a value for the screen. Must stay in sync with VideoRenderer.formatValue(). */
    public static function format(float $value, string $unit, string $lang): string
    {
        $cyr = $lang === 'sr-Cyrl';
        if ($unit === 'seconds') {
            $t = (int) round($value);
            $h = intdiv($t, 3600); $m = intdiv($t % 3600, 60); $s = $t % 60;
            $parts = [];
            if ($h) $parts[] = $h . ($cyr ? ' ч' : ' h');
            if ($m) $parts[] = $m . ($cyr ? ' мин' : ' min');
            if ($s || !$parts) $parts[] = $s . ($cyr ? ' с' : ' s');
            return implode(' ', $parts);
        }
        if ($unit === 'percent') {
            return self::formatNumber($value, $lang, abs($value - round($value)) < 0.05 ? 0 : 1) . '%';
        }
        return self::formatNumber($value, $lang);
    }

    public static function formatNumber(float $value, string $lang, ?int $decimals = null): string
    {
        $en = $lang === 'en';
        if ($decimals === null) {
            $decimals = abs($value - round($value)) < 0.005 ? 0 : (abs($value) >= 10 ? 1 : 2);
        }
        $out = number_format($value, $decimals, $en ? '.' : ',', $en ? ',' : '.');
        if ($decimals > 0) $out = rtrim(rtrim($out, '0'), $en ? '.' : ',');
        return $out;
    }

    // ───────────────────────────────────────────────────────────────── form ──

    /**
     * Validate the generation form. Returns [clean form, errors{field:msg}, warnings{field:msg}].
     * $imageIds / $dataIds: attachment ids that really belong to the card.
     */
    public static function validateForm(array $f, array $imageIds, array $dataIds): array
    {
        $errors = []; $warnings = [];
        $str = fn($k, $max) => mb_substr(trim((string) ($f[$k] ?? '')), 0, $max + 1);
        $clean = [
            'topic'          => $str('topic', 120),
            'brief'          => $str('brief', 6000),
            'language'       => (string) ($f['language'] ?? 'sr-Latn'),
            'target_seconds' => (int) ($f['target_seconds'] ?? 30),
            'date_chip'      => $str('date_chip', 30),
            'context_chip'   => $str('context_chip', 40),
            'section_tag'    => $str('section_tag', 20),
            'ticker'         => $str('ticker', self::LIMITS['ticker']),
            'domain'         => $str('domain', 40),
            'footage_ids'    => array_values(array_unique(array_map('intval', (array) ($f['footage_ids'] ?? [])))),
            'data_kind'      => in_array($f['data_kind'] ?? '', ['attachment', 'text'], true) ? $f['data_kind'] : '',
            'data_attachment_id' => (int) ($f['data_attachment_id'] ?? 0),
            'data_text'      => mb_substr((string) ($f['data_text'] ?? ''), 0, 20000),
        ];

        $len = fn($k) => mb_strlen($clean[$k]);
        if ($len('topic') < 3) $errors['topic'] = 'A topic of at least 3 characters is required.';
        elseif ($len('topic') > 120) $errors['topic'] = 'Keep the topic under 120 characters.';
        if ($len('brief') > 6000) $errors['brief'] = 'Notes are limited to 6000 characters (keeps the AI call cheap).';
        if (!isset(self::LANGUAGES[$clean['language']])) $errors['language'] = 'Pick a language.';
        if ($clean['target_seconds'] < 15 || $clean['target_seconds'] > 60) $errors['target_seconds'] = 'Length must be 15–60 seconds.';
        foreach (['date_chip' => 30, 'context_chip' => 40, 'section_tag' => 20, 'domain' => 40] as $k => $max) {
            if ($len($k) > $max) $errors[$k] = "Max {$max} characters.";
        }
        if ($len('ticker') > self::LIMITS['ticker']) $errors['ticker'] = 'Max ' . self::LIMITS['ticker'] . ' characters.';

        $bad = array_diff($clean['footage_ids'], $imageIds);
        if ($bad) $errors['footage_ids'] = 'Some selected images are not image attachments of this card.';
        elseif (count($clean['footage_ids']) > self::MAX_FOOTAGE) $errors['footage_ids'] = 'Select at most ' . self::MAX_FOOTAGE . ' images.';
        elseif (!$clean['footage_ids']) $warnings['footage_ids'] = 'No footage selected — scenes will use the plain brand background.';

        if ($clean['data_kind'] === '') {
            $errors['data_kind'] = 'Data is required: pick a data attachment (CSV/JSON/TXT) or paste the numbers.';
        } elseif ($clean['data_kind'] === 'attachment' && !in_array($clean['data_attachment_id'], $dataIds, true)) {
            $errors['data_attachment_id'] = 'Pick a CSV, JSON or TXT attachment of this card.';
        } elseif ($clean['data_kind'] === 'text' && trim($clean['data_text']) === '') {
            $errors['data_text'] = 'Paste the numbers, one "Label: value" per line.';
        }
        if ($len('date_chip') === 0) $warnings['date_chip'] = 'No date chip — most infographics show the date of the data.';
        return [$clean, $errors, $warnings];
    }

    // ─────────────────────────────────────────────────────────────── prompt ──

    /** Static system prompt — identical for every job so it caches across regenerations. */
    public static function systemPrompt(): string
    {
        return <<<'TXT'
You are the storyboard writer for BRAVO (bravo.org.rs), a civic movement in Serbia. You plan short vertical (9:16) animated infographic videos for Instagram Reels / TikTok. Code renders the video from your storyboard and inserts every number, so you choose scenes, write short copy and point at data keys.

FACTUAL RULES (political content, strictly enforced by code):
- Never type a figure that comes from the data. Reference it: value_ref / a_ref / b_ref / segments_ref take a dataset key, and inside any text you may write {{key}} to insert a formatted value (e.g. "{{speaker.aleksandar_vucic.count}} javljanja").
- Only use keys that appear in the DATASET or LISTS sections, exactly as written.
- Do not invent facts, quotes, causes or motives that are not in the topic, notes or dataset. Neutral, precise wording; the numbers make the point.
- Quotes (quote scene) only if the notes contain that exact quote, with its speaker.

SCENE LIBRARY (pick from these only). Every scene object carries every field; fill only the fields its type uses and leave the rest empty ("" / [] / callout kind "none" with empty refs and template).
- title: opening card. headline = 1-3 short lines separated by "
" (<=24 chars each), headline_accent = final line shown in the accent colour (<=24 chars), subtitle <=80 chars. ~3 s.
- area_compare: 2-3 circles whose AREA is proportional to the value. headline, subtitle, items: label (<=30 chars), value_ref, accent (true for the one to highlight). All items must share a unit. legend explains the encoding, e.g. "površina kruga = vreme govora". ~6 s.
- big_numbers: 1-3 rows in items (label + value_ref shown as a big counting number), optional headline, optional callout. callout.kind: "ratio" (a/b, template must contain {ratio}, e.g. "SNS: {ratio} puta duže"), "difference" (a-b, template contains {diff}), or "none". ~5 s.
- spotlight: one entity. headline (name), subtitle (role/context, may use {{key}}), value_label (e.g. "UKUPNO"), value_ref, segments_ref = a LISTS key to draw a segmented bar (each block = one item, width proportional), or "" for none. ~6 s.
- bar_ranking: headline, subtitle, 2-6 items as horizontal bars, same unit. ~6 s.
- quote: quote + attribution. ~5 s.
- outro: closing card, headline + cta (e.g. "Prati BRAVO"). ~3 s.

STRUCTURE: start with title, end with outro, 4-8 scenes in total, one idea per scene, build toward the most striking comparison. duration_s values must add up to the target length (±10%).

STYLE: news-infographic tone, short punchy lines, condensed bold type, white on darkened photo, BRAVO orange accent. Write ALL copy in the requested language and script (sr-Latn: Serbian Latin; sr-Cyrl: Serbian Cyrillic; en: English).

FOOTAGE: images may follow, each introduced by its id. Describe each in footage[] (<=12 words, what is visible). Set each scene's background to the id that fits it best, vary them across scenes, or "none".

TICKER: if the form's ticker is empty, write one line (<=140 chars) for the scrolling news ticker; otherwise return it unchanged.
TXT;
    }

    /**
     * JSON schema for structured outputs. One flat scene object with every
     * field required — a union of per-type scene objects compiles to a grammar
     * the API rejects as too large. Unused fields come back empty; resolve()
     * keeps only the fields each scene type uses. No min/max keywords either
     * (unsupported): limits are enforced in resolve().
     */
    public static function schema(): array
    {
        $s = ['type' => 'string'];
        $obj = fn(array $props) => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
        $scene = $obj([
            'type'            => ['type' => 'string', 'enum' => self::SCENE_TYPES],
            'duration_s'      => ['type' => 'number'],
            'background'      => $s,
            'headline'        => $s,
            'headline_accent' => $s,
            'subtitle'        => $s,
            'items'           => ['type' => 'array', 'items' => $obj(['label' => $s, 'value_ref' => $s, 'accent' => ['type' => 'boolean']])],
            'legend'          => $s,
            'callout'         => $obj(['kind' => ['type' => 'string', 'enum' => ['ratio', 'difference', 'none']], 'a_ref' => $s, 'b_ref' => $s, 'template' => $s]),
            'value_label'     => $s,
            'value_ref'       => $s,
            'segments_ref'    => $s,
            'quote'           => $s,
            'attribution'     => $s,
            'cta'             => $s,
        ]);
        return $obj([
            'ticker'  => $s,
            'footage' => ['type' => 'array', 'items' => $obj(['id' => $s, 'description' => $s])],
            'scenes'  => ['type' => 'array', 'items' => $scene],
        ]);
    }

    /** Flat model output → internal scene shape (title lines array, big_numbers rows). */
    private static function unflatten(array $sc): array
    {
        $type = $sc['type'] ?? '';
        if ($type === 'title' && is_string($sc['headline'] ?? null)) {
            $sc['headline'] = preg_split('/\R|\s\|\s/u', $sc['headline']);
        }
        if ($type === 'big_numbers' && !isset($sc['rows']) && isset($sc['items'])) {
            $sc['rows'] = $sc['items'];
        }
        return $sc;
    }

    /**
     * Compact per-job prompt text. The dataset goes in as one line per key
     * (no raw rows) so even big CSVs stay a few hundred tokens.
     */
    public static function userText(array $form, array $ds): string
    {
        $lang = $form['language'];
        $lines = [
            'TOPIC: ' . $form['topic'],
            'LANGUAGE: ' . $lang . ' (' . self::LANGUAGES[$lang] . ')',
            'TARGET LENGTH: ' . $form['target_seconds'] . ' s',
            'ON-SCREEN CHIPS (fixed, do not repeat them in scenes): date="' . $form['date_chip'] . '" context="' . $form['context_chip'] . '" section="' . $form['section_tag'] . '"',
            'FORM TICKER: "' . $form['ticker'] . '"',
            '',
            'NOTES:',
            $form['brief'] !== '' ? $form['brief'] : '(none)',
            '',
            'DATASET (key | meaning | value as it will appear on screen):',
        ];
        $i = 0;
        foreach ($ds['values'] as $k => $v) {
            if (++$i > 150) { $lines[] = '… (' . (count($ds['values']) - 150) . ' more keys omitted)'; break; }
            $lines[] = "{$k} | {$v['label']} | " . self::format($v['value'], $v['unit'], $lang);
        }
        if ($ds['lists']) {
            $lines[] = '';
            $lines[] = 'LISTS (for segments_ref):';
            foreach (array_slice($ds['lists'], 0, 40, true) as $k => $l) {
                $lines[] = "{$k} | {$l['label']} | " . count($l['values']) . ' items';
            }
        }
        return implode("\n", $lines);
    }

    // ────────────────────────────────────────────────────────────── resolve ──

    /**
     * Normalise + validate a storyboard against the dataset and attach a
     * render-ready `_r` to every scene. Returns [storyboard, issues[]].
     * issue = {scene: int|null, field: string, level: 'error'|'warning', message}
     */
    public static function resolve(array $sb, array $ds, array $form, array $footageIds): array
    {
        $lang = $form['language'] ?? 'sr-Latn';
        $issues = [];
        $add = function (?int $i, string $field, string $level, string $msg) use (&$issues) {
            $issues[] = ['scene' => $i, 'field' => $field, 'level' => $level, 'message' => $msg];
        };
        $bgOk = array_merge(['none'], array_map(fn($id) => 'att_' . $id, $footageIds));

        $ticker = trim((string) ($sb['ticker'] ?? ''));
        if ($ticker === '') $ticker = $form['ticker'] ?? '';
        if (mb_strlen($ticker) > self::LIMITS['ticker']) $add(null, 'ticker', 'warning', 'Ticker is long; it will scroll for a while.');

        $val = function (?int $i, string $field, string $ref) use ($ds, $lang, $add) {
            $ref = trim($ref);
            if ($ref === '') { $add($i, $field, 'error', 'Pick a data value.'); return null; }
            if (!isset($ds['values'][$ref])) { $add($i, $field, 'error', "Unknown data key \"{$ref}\"."); return null; }
            $v = $ds['values'][$ref];
            return ['value' => $v['value'], 'unit' => $v['unit'], 'display' => self::format($v['value'], $v['unit'], $lang)];
        };
        $text = function (?int $i, string $field, $str, string $limitKey) use ($ds, $lang, $add) {
            $str = trim((string) $str);
            $out = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', function ($m) use ($ds, $lang, $i, $field, $add) {
                if (!isset($ds['values'][$m[1]])) { $add($i, $field, 'error', "Unknown data key \"{$m[1]}\" in text."); return $m[0]; }
                $v = $ds['values'][$m[1]];
                return self::format($v['value'], $v['unit'], $lang);
            }, $str);
            $typed = preg_replace('/\{\{[^}]*\}\}/', '', $str);
            if (preg_match('/\d/', $typed)) {
                $add($i, $field, 'warning', 'Contains a typed number — check it, or replace it with a {{data key}}.');
            }
            $max = self::LIMITS[$limitKey];
            if (mb_strlen($out) > $max) $add($i, $field, mb_strlen($out) > $max * 1.6 ? 'error' : 'warning', "Too long (" . mb_strlen($out) . "/{$max} chars).");
            return $out;
        };

        $scenes = [];
        $total = 0.0;
        foreach (array_values((array) ($sb['scenes'] ?? [])) as $i => $sc) {
            $sc = self::unflatten((array) $sc);
            $type = $sc['type'] ?? '';
            if (!in_array($type, self::SCENE_TYPES, true)) { $add($i, 'type', 'error', "Unknown scene type \"{$type}\"."); continue; }
            $dur = (float) ($sc['duration_s'] ?? 0);
            if ($dur <= 0) $dur = self::DEFAULT_DURATION[$type];
            $dur = max(2.0, min(12.0, round($dur * 2) / 2));
            $total += $dur;
            $bg = (string) ($sc['background'] ?? 'none');
            if (!in_array($bg, $bgOk, true)) {
                $add($i, 'background', 'warning', 'Background image not available; using the brand background.');
                $bg = 'none';
            }
            $n = ['type' => $type, 'duration_s' => $dur, 'background' => $bg];
            $r = [];

            switch ($type) {
                case 'title':
                    $n['headline'] = array_values(array_filter(array_map('strval', (array) ($sc['headline'] ?? [])), fn($l) => trim($l) !== ''));
                    $n['headline'] = array_slice($n['headline'], 0, 3);
                    $n['headline_accent'] = (string) ($sc['headline_accent'] ?? '');
                    $n['subtitle'] = (string) ($sc['subtitle'] ?? '');
                    if (!$n['headline'] && trim($n['headline_accent']) === '') $add($i, 'headline', 'error', 'The title needs a headline.');
                    $r['headline'] = array_map(fn($l) => $text($i, 'headline', $l, 'headline_line'), $n['headline']);
                    $r['headline_accent'] = $text($i, 'headline_accent', $n['headline_accent'], 'headline_accent');
                    $r['subtitle'] = $text($i, 'subtitle', $n['subtitle'], 'subtitle');
                    break;

                case 'area_compare':
                case 'bar_ranking':
                case 'big_numbers':
                    $listKey = $type === 'big_numbers' ? 'rows' : 'items';
                    [$min, $max] = $type === 'area_compare' ? [2, 3] : ($type === 'bar_ranking' ? [2, 6] : [1, 3]);
                    $n['headline'] = (string) ($sc['headline'] ?? '');
                    $r['headline'] = $text($i, 'headline', $n['headline'], 'headline');
                    if ($type !== 'big_numbers') {
                        $n['subtitle'] = (string) ($sc['subtitle'] ?? '');
                        $r['subtitle'] = $text($i, 'subtitle', $n['subtitle'], 'subtitle');
                    }
                    $n[$listKey] = [];
                    $r[$listKey] = [];
                    $units = [];
                    foreach (array_values((array) ($sc[$listKey] ?? [])) as $j => $it) {
                        $it = ['label' => (string) ($it['label'] ?? ''), 'value_ref' => (string) ($it['value_ref'] ?? ''), 'accent' => !empty($it['accent'])];
                        $n[$listKey][] = $it;
                        $res = $val($i, "{$listKey}.{$j}.value_ref", $it['value_ref']);
                        if ($res) $units[$res['unit']] = true;
                        if ($res && $type === 'area_compare' && $res['value'] <= 0) $add($i, "{$listKey}.{$j}.value_ref", 'error', 'Circle areas need a value above zero.');
                        $r[$listKey][] = ['label' => $text($i, "{$listKey}.{$j}.label", $it['label'], 'label'), 'accent' => $it['accent']] + ($res ?? ['value' => 0, 'unit' => 'number', 'display' => '?']);
                    }
                    $cnt = count($n[$listKey]);
                    if ($cnt < $min || $cnt > $max) $add($i, $listKey, 'error', "Needs {$min}–{$max} entries (has {$cnt}).");
                    if ($type !== 'big_numbers' && count($units) > 1) $add($i, $listKey, 'error', 'All compared values must have the same unit.');
                    if ($type === 'bar_ranking') usort($r['items'], fn($a, $b) => $b['value'] <=> $a['value']);
                    if ($type === 'area_compare') {
                        $n['legend'] = (string) ($sc['legend'] ?? '');
                        $r['legend'] = $text($i, 'legend', $n['legend'], 'legend');
                    }
                    if ($type === 'big_numbers') {
                        $c = (array) ($sc['callout'] ?? []);
                        $c = ['kind' => in_array($c['kind'] ?? '', ['ratio', 'difference'], true) ? $c['kind'] : 'none',
                              'a_ref' => (string) ($c['a_ref'] ?? ''), 'b_ref' => (string) ($c['b_ref'] ?? ''), 'template' => (string) ($c['template'] ?? '')];
                        $n['callout'] = $c;
                        $r['callout'] = null;
                        if ($c['kind'] !== 'none') {
                            $a = $val($i, 'callout.a_ref', $c['a_ref']);
                            $b = $val($i, 'callout.b_ref', $c['b_ref']);
                            $ph = $c['kind'] === 'ratio' ? '{ratio}' : '{diff}';
                            if (!str_contains($c['template'], $ph)) $add($i, 'callout.template', 'error', "The callout text must contain {$ph}.");
                            if ($a && $b) {
                                if ($a['unit'] !== $b['unit']) $add($i, 'callout', 'error', 'Callout compares values with different units.');
                                if ($c['kind'] === 'ratio' && $b['value'] == 0) {
                                    $add($i, 'callout.b_ref', 'error', 'Cannot divide by zero.');
                                } else {
                                    $fill = $c['kind'] === 'ratio'
                                        ? self::formatNumber($a['value'] / $b['value'], $lang, 1)
                                        : self::format(abs($a['value'] - $b['value']), $a['unit'], $lang);
                                    $tpl = $text($i, 'callout.template', $c['template'], 'template');
                                    $r['callout'] = ['text' => str_replace($ph, $fill, $tpl), 'kind' => $c['kind']];
                                }
                            }
                        }
                    }
                    break;

                case 'spotlight':
                    foreach (['headline' => 'headline', 'subtitle' => 'subtitle', 'value_label' => 'value_label'] as $k => $lim) {
                        $n[$k] = (string) ($sc[$k] ?? '');
                        $r[$k] = $text($i, $k, $n[$k], $lim);
                    }
                    $n['value_ref'] = (string) ($sc['value_ref'] ?? '');
                    $n['segments_ref'] = trim((string) ($sc['segments_ref'] ?? ''));
                    $r['value'] = $val($i, 'value_ref', $n['value_ref']);
                    $r['segments'] = null;
                    if ($n['segments_ref'] !== '') {
                        $l = $ds['lists'][$n['segments_ref']] ?? null;
                        if (!$l) $add($i, 'segments_ref', 'error', "Unknown list \"{$n['segments_ref']}\".");
                        elseif (count($l['values']) > 80) $add($i, 'segments_ref', 'error', 'Too many segments to draw (max 80).');
                        else $r['segments'] = $l['values'];
                    }
                    break;

                case 'quote':
                    $n['quote'] = (string) ($sc['quote'] ?? '');
                    $n['attribution'] = (string) ($sc['attribution'] ?? '');
                    if (trim($n['quote']) === '') $add($i, 'quote', 'error', 'The quote is empty.');
                    elseif (($form['brief'] ?? '') !== '' && !str_contains(mb_strtolower($form['brief']), mb_strtolower(mb_substr(trim($n['quote'], " \"„“”'"), 0, 30)))) {
                        $add($i, 'quote', 'warning', 'This quote was not found word-for-word in the notes — verify it.');
                    }
                    $r['quote'] = $text($i, 'quote', $n['quote'], 'quote');
                    $r['attribution'] = $text($i, 'attribution', $n['attribution'], 'attribution');
                    break;

                case 'outro':
                    $n['headline'] = (string) ($sc['headline'] ?? '');
                    $n['cta'] = (string) ($sc['cta'] ?? '');
                    $r['headline'] = $text($i, 'headline', $n['headline'], 'headline');
                    $r['cta'] = $text($i, 'cta', $n['cta'], 'cta');
                    break;
            }
            $n['_r'] = $r;
            $scenes[] = $n;
        }

        $count = count($scenes);
        if ($count < 2) $add(null, 'scenes', 'error', 'The storyboard needs at least 2 scenes.');
        if ($count > 10) $add(null, 'scenes', 'error', 'At most 10 scenes.');
        $target = (int) ($form['target_seconds'] ?? 30);
        if ($count && abs($total - $target) > $target * 0.35) {
            $add(null, 'scenes', 'warning', "Total length is {$total} s (target {$target} s).");
        }

        $out = [
            'meta' => [
                'title' => $form['topic'] ?? '', 'date_chip' => $form['date_chip'] ?? '', 'context_chip' => $form['context_chip'] ?? '',
                'section_tag' => mb_strtoupper($form['section_tag'] ?? ''), 'ticker' => $ticker, 'domain' => $form['domain'] ?? '',
                'language' => $lang, 'fps' => 30, 'width' => 1080, 'height' => 1920, 'duration_s' => $total,
            ],
            'scenes' => $scenes,
        ];
        return [$out, $issues];
    }

    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $i) if ($i['level'] === 'error') return true;
        return false;
    }
}
