<?php
/**
 * VideoController — AI infographic videos, per card. Gated by the
 * per-user feature flag Features::INFOGRAPHIC_VIDEO (Admin → Users).
 *
 * Flow:  prefill → save_draft (validate form + build dataset, no AI)
 *        → generate (one Claude call, runs after the response is flushed;
 *          the client polls `get`) → update_storyboard (edits, no AI)
 *        → approve → browser renders the MP4 → save_render (card attachment).
 */

require_once __DIR__ . '/../core/Features.php';
require_once __DIR__ . '/../core/Anthropic.php';
require_once __DIR__ . '/../core/VideoStoryboard.php';
require_once __DIR__ . '/../core/FileUpload.php';

class VideoController extends Controller
{
    private const DATA_MIMES = ['text/csv', 'text/plain', 'application/json'];
    private const STALE_MINUTES = 5;

    // ───────────────────────────────────────────────────────── endpoints ──

    /** GET card_id → suggested form (from the card), selectable sources, existing jobs. */
    public function prefill(): void
    {
        $this->gate();
        $this->requireGet();
        $cardId = (int) ($_GET['card_id'] ?? 0);
        [$card, $boardId] = $this->loadCard($cardId);

        $db = Database::get();
        $labels = $db->prepare('SELECT l.name FROM card_labels cl JOIN labels l ON l.id = cl.label_id WHERE cl.card_id = :id ORDER BY l.id');
        $labels->execute(['id' => $cardId]);
        $labelNames = array_values(array_filter($labels->fetchAll(PDO::FETCH_COLUMN)));

        $cm = $db->prepare('SELECT c.body, u.display_name FROM comments c JOIN users u ON u.id = c.user_id
                            WHERE c.card_id = :id ORDER BY c.created_at DESC LIMIT 8');
        $cm->execute(['id' => $cardId]);
        $comments = array_reverse($cm->fetchAll());

        $brief = trim((string) $card['description']);
        if ($comments) {
            $brief .= ($brief !== '' ? "\n\n" : '') . "Comments:\n" . implode("\n", array_map(
                fn($c) => '- ' . $c['display_name'] . ': ' . mb_substr(preg_replace('/\s+/u', ' ', (string) $c['body']), 0, 300),
                $comments
            ));
        }
        $brief = mb_substr($brief, 0, 6000);

        [$images, $dataFiles] = $this->cardAttachments($cardId);
        $config = require __DIR__ . '/../config/config.php';

        $sample = $card['title'] . ' ' . $card['description'];
        $cyr = preg_match_all('/\p{Cyrillic}/u', $sample);
        $lat = preg_match_all('/[a-zA-ZšđčćžŠĐČĆŽ]/u', $sample);

        $dateSrc = $card['due_date'] ?: $card['start_date'];
        $form = [
            'topic'          => mb_substr((string) $card['title'], 0, 120),
            'brief'          => $brief,
            'language'       => $cyr > $lat ? 'sr-Cyrl' : 'sr-Latn',
            'target_seconds' => 30,
            'date_chip'      => $dateSrc ? self::serbianDate($dateSrc, $cyr > $lat) : '',
            'context_chip'   => mb_substr($labelNames[0] ?? '', 0, 40),
            'section_tag'    => mb_substr(mb_strtoupper((string) $card['board_title']), 0, 20),
            'ticker'         => '',
            'domain'         => (string) ($config['video_brand_domain'] ?? 'bravo.org.rs'),
            'footage_ids'    => array_slice(array_map(fn($a) => (int) $a['id'], $images), 0, VideoStoryboard::MAX_FOOTAGE),
            'data_kind'      => '',
            'data_attachment_id' => 0,
            'data_text'      => '',
        ];
        if ($dataFiles) {
            $form['data_kind'] = 'attachment';
            $form['data_attachment_id'] = (int) $dataFiles[0]['id'];
        } else {
            // "Label: value" lines in the description are a ready-made dataset.
            $ds = VideoStoryboard::parseText((string) $card['description']);
            if (count($ds['values']) >= 2) {
                $form['data_kind'] = 'text';
                $form['data_text'] = (string) $card['description'];
            }
        }

        $this->json([
            'form'        => $form,
            'images'      => $images,
            'data_files'  => $dataFiles,
            'languages'   => VideoStoryboard::LANGUAGES,
            'jobs'        => $this->jobsForCard($cardId),
            'ai'          => $this->aiStatus(),
        ]);
    }

    /** POST {card_id, job_id?, form} → validate + build the dataset; creates/updates a draft. No AI. */
    public function saveDraft(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $in = $this->getJSON();
        $jobId = (int) ($in['job_id'] ?? 0);

        if ($jobId) {
            $job = $this->loadJob($jobId);
            $cardId = (int) $job['card_id'];
            if ($job['status'] === 'storyboarding') {
                $this->json(['error' => 'A storyboard is being generated for this video right now.'], 409);
                return;
            }
        } else {
            $cardId = (int) ($in['card_id'] ?? 0);
            $this->loadCard($cardId);
        }

        [$images, $dataFiles] = $this->cardAttachments($cardId);
        [$form, $errors, $warnings] = VideoStoryboard::validateForm(
            (array) ($in['form'] ?? []),
            array_map(fn($a) => (int) $a['id'], $images),
            array_map(fn($a) => (int) $a['id'], $dataFiles)
        );

        $dataset = null;
        if (!$errors) {
            $dataset = $this->buildDataset($form, $dataFiles);
            if (!$dataset['values']) {
                $field = $form['data_kind'] === 'text' ? 'data_text' : 'data_attachment_id';
                $errors[$field] = 'No numbers could be read from this data. ' . implode(' ', $dataset['notes']);
            } elseif (count($dataset['values']) < 2) {
                $warnings['data_kind'] = 'Only one value found — comparison scenes need at least two.';
            }
        }
        if ($errors) {
            $this->json(['error' => 'Please fix the highlighted fields.', 'errors' => $errors, 'warnings' => $warnings, 'dataset' => $dataset], 422);
            return;
        }

        $db = Database::get();
        $params = [
            'topic'   => mb_substr($form['topic'], 0, 255),
            'form'    => json_encode($form, JSON_UNESCAPED_UNICODE),
            'dataset' => json_encode($dataset, JSON_UNESCAPED_UNICODE),
        ];
        if ($jobId) {
            // Keep an existing storyboard consistent with the new data/form.
            $sbJson = $job['storyboard_json'];
            $old = json_decode((string) $sbJson, true);
            if ($old && !empty($old['scenes'])) {
                [$sb, $issues] = VideoStoryboard::resolve(['scenes' => $old['scenes'], 'ticker' => $form['ticker'] ?: ($old['meta']['ticker'] ?? '')],
                    $dataset, $form, $form['footage_ids']);
                $sb['issues'] = $issues;
                $sbJson = json_encode($sb, JSON_UNESCAPED_UNICODE);
            }
            $db->prepare('UPDATE video_jobs SET topic = :topic, form_json = :form, dataset_json = :dataset, storyboard_json = :sb, error = NULL WHERE id = :id')
               ->execute($params + ['id' => $jobId, 'sb' => $sbJson]);
        } else {
            $db->prepare("INSERT INTO video_jobs (card_id, user_id, status, topic, form_json, dataset_json)
                          VALUES (:card, :user, 'draft', :topic, :form, :dataset)")
               ->execute($params + ['card' => $cardId, 'user' => Auth::userId()]);
            $jobId = (int) $db->lastInsertId();
        }

        $this->json(['job' => $this->jobPayload($this->loadJob($jobId)), 'warnings' => $warnings]);
    }

    /** POST {id} → one Claude call (storyboard + footage tags). Responds at once; poll `get`. */
    public function generate(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $job = $this->loadJob((int) ($this->getJSON()['id'] ?? 0));

        if ($job['status'] === 'storyboarding' && !$this->isStale($job)) {
            $this->json(['error' => 'Already generating.'], 409);
            return;
        }
        if (!Anthropic::isConfigured()) {
            $this->json(['error' => 'The Anthropic API key is not configured on the server (config/config.php → anthropic_api_key).'], 503);
            return;
        }
        $ai = $this->aiStatus();
        if ($ai['remaining_today'] <= 0) {
            $this->json(['error' => "Daily AI limit reached ({$ai['daily_limit']} calls). Edits and rendering still work."], 429);
            return;
        }

        $db = Database::get();
        $db->prepare("UPDATE video_jobs SET status = 'storyboarding', error = NULL WHERE id = :id")->execute(['id' => $job['id']]);
        $job['status'] = 'storyboarding';

        // The Claude call can outlast shared-hosting request limits: answer
        // now, release the session lock (so polling isn't blocked), finish
        // the work in this same process.
        $this->json(['job' => $this->jobPayload($job)]);
        session_write_close();
        self::finishResponse();
        ignore_user_abort(true);
        @set_time_limit(240);

        $this->runStoryboard($job);
    }

    /** GET id → full job (form, dataset, storyboard, issues, usage). */
    public function get(): void
    {
        $this->gate();
        $this->requireGet();
        $job = $this->loadJob((int) ($_GET['id'] ?? 0));
        if ($job['status'] === 'storyboarding' && $this->isStale($job)) {
            Database::get()->prepare("UPDATE video_jobs SET status = 'failed', error = 'Generation timed out. Try again.' WHERE id = :id")
                ->execute(['id' => $job['id']]);
            $job = $this->loadJob((int) $job['id']);
        }
        $this->json(['job' => $this->jobPayload($job, true)]);
    }

    /** POST {id, scenes, meta?} → re-validate + re-resolve the edited storyboard. No AI. */
    public function updateStoryboard(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $in = $this->getJSON();
        $job = $this->loadJob((int) ($in['id'] ?? 0));
        if ($job['status'] === 'storyboarding') {
            $this->json(['error' => 'Wait for the storyboard to finish generating.'], 409);
            return;
        }
        $form = json_decode((string) $job['form_json'], true) ?: [];
        $dataset = json_decode((string) $job['dataset_json'], true) ?: VideoStoryboard::emptyDataset();

        // Chip/ticker overrides from the review screen update the form too.
        $meta = (array) ($in['meta'] ?? []);
        $limits = ['date_chip' => 30, 'context_chip' => 40, 'section_tag' => 20, 'domain' => 40, 'ticker' => 160];
        foreach ($limits as $k => $max) {
            if (array_key_exists($k, $meta)) $form[$k] = mb_substr(trim((string) $meta[$k]), 0, $max);
        }
        $scenes = array_map(function ($s) { unset($s['_r']); return $s; }, array_values((array) ($in['scenes'] ?? [])));

        [$sb, $issues] = VideoStoryboard::resolve(
            ['scenes' => $scenes, 'ticker' => $form['ticker'] ?? ''], $dataset, $form, $form['footage_ids'] ?? []
        );
        $sb['issues'] = $issues;
        $status = $job['status'] === 'draft' || $job['status'] === 'failed' ? 'review' : $job['status'];
        if ($status === 'rendering') $status = 'review';
        Database::get()->prepare('UPDATE video_jobs SET storyboard_json = :sb, form_json = :form, status = :st WHERE id = :id')
            ->execute(['sb' => json_encode($sb, JSON_UNESCAPED_UNICODE), 'form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'st' => $status, 'id' => $job['id']]);

        $this->json(['job' => $this->jobPayload($this->loadJob((int) $job['id']), true)]);
    }

    /** POST {id} → lock in the storyboard and hand the renderer what it needs. */
    public function approve(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $job = $this->loadJob((int) ($this->getJSON()['id'] ?? 0));
        $sb = json_decode((string) $job['storyboard_json'], true);
        if (!$sb || empty($sb['scenes'])) {
            $this->json(['error' => 'There is no storyboard to render yet.'], 400);
            return;
        }
        if (VideoStoryboard::hasErrors($sb['issues'] ?? [])) {
            $this->json(['error' => 'Fix the errors flagged in the storyboard before rendering.'], 422);
            return;
        }
        Database::get()->prepare("UPDATE video_jobs SET status = 'rendering' WHERE id = :id")->execute(['id' => $job['id']]);

        $backgrounds = [];
        foreach ($sb['scenes'] as $s) {
            if (preg_match('/^att_(\d+)$/', $s['background'] ?? '', $m)) {
                $backgrounds[$s['background']] = 'api.php?action=attachments.download&id=' . (int) $m[1];
            }
        }
        $this->json([
            'job'        => $this->jobPayload($this->loadJob((int) $job['id']), true),
            'assets'     => ['backgrounds' => $backgrounds, 'logo' => 'public/img/logo_white.png'],
        ]);
    }

    /** POST multipart {id, file} → store the rendered MP4 as a card attachment. */
    public function saveRender(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $job = $this->loadJob((int) ($_POST['id'] ?? 0));
        if (empty($_FILES['file'])) {
            $this->json(['error' => 'No video uploaded'], 400);
            return;
        }
        $config = require __DIR__ . '/../config/config.php';
        try {
            $file = FileUpload::handle($_FILES['file'], (int) ($config['video_max_upload_size'] ?? 60 * 1024 * 1024));
        } catch (RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 400);
            return;
        }
        if ($file['mime_type'] !== 'video/mp4') {
            FileUpload::deleteFile($file['stored_name']);
            $this->json(['error' => 'The upload is not an MP4 video.'], 400);
            return;
        }

        $cardId = (int) $job['card_id'];
        $boardId = (int) $this->getBoardIdForCard($cardId);
        $name = 'infographic-' . VideoStoryboard::slug($job['topic']) . '-' . date('Ymd-His') . '.mp4';
        $db = Database::get();
        $db->prepare(
            'INSERT INTO attachments (card_id, comment_id, user_id, original_name, stored_name, file_size, mime_type, is_image, thumbnail_path)
             VALUES (:card, NULL, :user, :name, :stored, :size, :mime, 0, NULL)'
        )->execute([
            'card' => $cardId, 'user' => Auth::userId(), 'name' => $name, 'stored' => $file['stored_name'],
            'size' => $file['file_size'], 'mime' => 'video/mp4',
        ]);
        $attId = (int) $db->lastInsertId();
        $db->prepare("UPDATE video_jobs SET status = 'done', output_attachment_id = :att, error = NULL WHERE id = :id")
           ->execute(['att' => $attId, 'id' => $job['id']]);

        $this->publishSSE($boardId, SSE_ATTACHMENT_ADDED, ['card_id' => $cardId, 'comment_id' => null, 'attachment_id' => $attId]);
        $this->publishSSE($boardId, SSE_VIDEO_JOB_CHANGED, ['card_id' => $cardId, 'job_id' => (int) $job['id'], 'status' => 'done']);
        $this->logActivity($boardId, $cardId, 'attachment_added', ['filename' => $name]);
        $this->notifyCardEvent($cardId, $boardId, NOTIF_VIDEO_READY, ['attachment_id' => $attId, 'video_title' => $job['topic']]);

        $this->json(['job' => $this->jobPayload($this->loadJob((int) $job['id'])), 'attachment_id' => $attId]);
    }

    /** POST {id} → delete a job (the rendered attachment, if any, stays on the card). */
    public function delete(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $job = $this->loadJob((int) ($this->getJSON()['id'] ?? 0));
        if ((int) $job['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'Only the creator can delete this video job.'], 403);
            return;
        }
        Database::get()->prepare('DELETE FROM video_jobs WHERE id = :id')->execute(['id' => $job['id']]);
        $this->json(['success' => true]);
    }

    /** POST {card_id, text} → AI turns free text into "Label: value" lines for review. */
    public function extractData(): void
    {
        $this->gate();
        $this->requirePost();
        $this->validateCSRF();
        $in = $this->getJSON();
        $this->loadCard((int) ($in['card_id'] ?? 0));
        $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 20000));
        if (mb_strlen($text) < 10) {
            $this->json(['error' => 'Paste some text with numbers first.'], 400);
            return;
        }
        if (!Anthropic::isConfigured()) {
            $this->json(['error' => 'The Anthropic API key is not configured on the server.'], 503);
            return;
        }
        if ($this->aiStatus()['remaining_today'] <= 0) {
            $this->json(['error' => 'Daily AI limit reached.'], 429);
            return;
        }
        session_write_close();
        $system = [['type' => 'text', 'text' =>
            "Extract the numeric facts from the user's text as lines \"Label: value\". "
            . "Copy every number exactly as written; never calculate, round, sum or convert. "
            . "Keep durations as written (e.g. \"1 h 40 min 3 s\" or \"1:40:03\") and percentages with %. "
            . "Use short labels in the text's own language that say what is counted and for whom. "
            . "A series of values for one label goes on one line, comma-separated. Skip anything without a number."]];
        try {
            $res = Anthropic::json($system, [['type' => 'text', 'text' => $text]], [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['lines'],
                'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ], 4000, 90);
        } catch (RuntimeException $e) {
            $this->logUsage(null, 'extract_failed', Anthropic::config()['model'], []);
            $this->json(['error' => $e->getMessage()], 502);
            return;
        }
        $this->logUsage(null, 'extract', $res['model'], $res['usage']);
        $lines = implode("\n", array_map('strval', (array) ($res['data']['lines'] ?? [])));
        $this->json(['text' => $lines, 'dataset' => VideoStoryboard::parseText($lines), 'usage' => $res['usage']]);
    }

    // ───────────────────────────────────────────────────────── the AI step ──

    private function runStoryboard(array $job): void
    {
        $db = Database::get();
        $form = json_decode((string) $job['form_json'], true) ?: [];
        $dataset = json_decode((string) $job['dataset_json'], true) ?: VideoStoryboard::emptyDataset();
        $tags = json_decode((string) $job['footage_tags_json'], true) ?: [];
        $footageIds = array_map('intval', $form['footage_ids'] ?? []);
        $model = Anthropic::config()['model'];

        try {
            $content = [];
            // Re-use earlier footage descriptions instead of re-sending images.
            $known = array_filter($footageIds, fn($id) => isset($tags['att_' . $id]));
            if ($footageIds && count($known) === count($footageIds)) {
                $lines = array_map(fn($id) => "att_{$id}: " . $tags['att_' . $id], $footageIds);
                $content[] = ['type' => 'text', 'text' => "FOOTAGE (already described, return footage as []):\n" . implode("\n", $lines)];
            } else {
                foreach ($this->footageThumbs($footageIds) as $id => $b64) {
                    $content[] = ['type' => 'text', 'text' => "id: att_{$id}"];
                    $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]];
                }
                if (!$content) $content[] = ['type' => 'text', 'text' => 'FOOTAGE: none — use "none" for every background.'];
            }
            $content[] = ['type' => 'text', 'text' => VideoStoryboard::userText($form, $dataset)];

            $res = Anthropic::json(
                [['type' => 'text', 'text' => VideoStoryboard::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']]],
                $content,
                VideoStoryboard::schema()
            );
            $this->logUsage((int) $job['id'], 'storyboard', $res['model'], $res['usage'], (int) $job['user_id']);

            foreach ((array) ($res['data']['footage'] ?? []) as $f) {
                $fid = (string) ($f['id'] ?? '');
                if (preg_match('/^att_\d+$/', $fid) && in_array((int) substr($fid, 4), $footageIds, true)) {
                    $tags[$fid] = mb_substr((string) ($f['description'] ?? ''), 0, 120);
                }
            }
            if (trim((string) ($form['ticker'] ?? '')) !== '') $res['data']['ticker'] = $form['ticker'];
            [$sb, $issues] = VideoStoryboard::resolve($res['data'], $dataset, $form, $footageIds);
            $sb['issues'] = $issues;

            $db->prepare("UPDATE video_jobs SET status = 'review', storyboard_json = :sb, footage_tags_json = :tags, error = NULL WHERE id = :id")
               ->execute(['sb' => json_encode($sb, JSON_UNESCAPED_UNICODE), 'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE), 'id' => $job['id']]);
        } catch (Throwable $e) {
            error_log('Video storyboard failed (job ' . $job['id'] . '): ' . $e->getMessage());
            $this->logUsage((int) $job['id'], 'storyboard_failed', $model, [], (int) $job['user_id']);
            $db->prepare("UPDATE video_jobs SET status = 'failed', error = :err WHERE id = :id")
               ->execute(['err' => mb_substr($e->getMessage(), 0, 1000), 'id' => $job['id']]);
        }
    }

    /** @return array<int,string> attachmentId => base64 JPEG thumbnail (≤300px, ~100 tokens each). */
    private function footageThumbs(array $ids): array
    {
        if (!$ids) return [];
        $config = require __DIR__ . '/../config/config.php';
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::get()->query("SELECT id, thumbnail_path FROM attachments WHERE id IN ({$in}) AND is_image = 1")->fetchAll();
        $out = [];
        foreach ($ids as $id) {
            foreach ($rows as $r) {
                if ((int) $r['id'] !== (int) $id || !$r['thumbnail_path']) continue;
                $path = $config['upload_dir'] . '/thumbnails/' . basename($r['thumbnail_path']);
                if (is_file($path) && filesize($path) < 400000) $out[(int) $id] = base64_encode((string) file_get_contents($path));
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────── helpers ──

    private function gate(): void
    {
        $this->requireAuth();
        if (!Features::has((int) Auth::userId(), Features::INFOGRAPHIC_VIDEO)) {
            $this->json(['error' => 'Infographic video is not enabled for your account. Ask an admin.'], 403);
            exit;
        }
    }

    /** @return array{0: array, 1: int} [card row + board_title, boardId] */
    private function loadCard(int $cardId): array
    {
        $boardId = $cardId ? $this->getBoardIdForCard($cardId) : null;
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            exit;
        }
        $this->requireBoardAccess($boardId);
        $stmt = Database::get()->prepare(
            'SELECT c.*, b.title AS board_title FROM cards c JOIN lists l ON l.id = c.list_id JOIN boards b ON b.id = l.board_id WHERE c.id = :id'
        );
        $stmt->execute(['id' => $cardId]);
        return [$stmt->fetch(), $boardId];
    }

    private function loadJob(int $id): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM video_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $job = $stmt->fetch();
        if (!$job) {
            $this->json(['error' => 'Video job not found'], 404);
            exit;
        }
        $this->loadCard((int) $job['card_id']);
        return $job;
    }

    /** @return array{0: array, 1: array} [image attachments, data attachments] of the card. */
    private function cardAttachments(int $cardId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT id, original_name, mime_type, is_image, file_size, created_at FROM attachments WHERE card_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute(['id' => $cardId]);
        $images = $data = [];
        foreach ($stmt->fetchAll() as $a) {
            $a['id'] = (int) $a['id'];
            if ((int) $a['is_image'] === 1) $images[] = $a;
            elseif (in_array($a['mime_type'], self::DATA_MIMES, true)) $data[] = $a;
        }
        return [$images, $data];
    }

    private function buildDataset(array $form, array $dataFiles): array
    {
        if ($form['data_kind'] === 'text') {
            return VideoStoryboard::parseText($form['data_text'], 'Pasted text');
        }
        foreach ($dataFiles as $a) {
            if ($a['id'] !== $form['data_attachment_id']) continue;
            $stmt = Database::get()->prepare('SELECT stored_name FROM attachments WHERE id = :id');
            $stmt->execute(['id' => $a['id']]);
            $config = require __DIR__ . '/../config/config.php';
            $path = $config['upload_dir'] . '/attachments/' . basename((string) $stmt->fetchColumn());
            return VideoStoryboard::parseFile($path, $a['mime_type'], $a['original_name']);
        }
        return VideoStoryboard::emptyDataset();
    }

    private function jobsForCard(int $cardId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT j.id, j.status, j.topic, j.output_attachment_id, j.updated_at, u.display_name AS user_name
             FROM video_jobs j JOIN users u ON u.id = j.user_id WHERE j.card_id = :id ORDER BY j.updated_at DESC LIMIT 20'
        );
        $stmt->execute(['id' => $cardId]);
        return $stmt->fetchAll();
    }

    private function jobPayload(array $job, bool $full = false): array
    {
        $out = [
            'id' => (int) $job['id'], 'card_id' => (int) $job['card_id'], 'status' => $job['status'],
            'topic' => $job['topic'], 'error' => $job['error'], 'updated_at' => $job['updated_at'],
            'output_attachment_id' => $job['output_attachment_id'] ? (int) $job['output_attachment_id'] : null,
            'form' => json_decode((string) $job['form_json'], true),
            'dataset' => json_decode((string) $job['dataset_json'], true),
        ];
        if ($full) {
            $out['storyboard'] = json_decode((string) $job['storyboard_json'], true);
            $out['footage_tags'] = json_decode((string) $job['footage_tags_json'], true) ?: (object) [];
            $u = Database::get()->prepare(
                'SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens,
                        COALESCE(SUM(cache_read_tokens),0) AS cache_read_tokens FROM ai_usage_log WHERE video_job_id = :id'
            );
            $u->execute(['id' => $job['id']]);
            $out['usage'] = array_map('intval', $u->fetch());
            $out['ai'] = $this->aiStatus();
        }
        return $out;
    }

    private function aiStatus(): array
    {
        $config = require __DIR__ . '/../config/config.php';
        $limit = (int) ($config['video_daily_ai_limit'] ?? 25);
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM ai_usage_log WHERE user_id = :u AND created_at >= CURDATE()');
        $stmt->execute(['u' => Auth::userId()]);
        $used = (int) $stmt->fetchColumn();
        return [
            'configured' => Anthropic::isConfigured(), 'model' => Anthropic::config()['model'],
            'daily_limit' => $limit, 'remaining_today' => max(0, $limit - $used),
        ];
    }

    private function logUsage(?int $jobId, string $kind, string $model, array $usage, ?int $userId = null): void
    {
        try {
            Database::get()->prepare(
                'INSERT INTO ai_usage_log (user_id, video_job_id, kind, model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens)
                 VALUES (:u, :j, :k, :m, :i, :o, :cr, :cw)'
            )->execute([
                'u' => $userId ?? Auth::userId(), 'j' => $jobId, 'k' => $kind, 'm' => mb_substr($model, 0, 64),
                'i' => $usage['input_tokens'] ?? 0, 'o' => $usage['output_tokens'] ?? 0,
                'cr' => $usage['cache_read_tokens'] ?? 0, 'cw' => $usage['cache_write_tokens'] ?? 0,
            ]);
        } catch (Throwable $e) {
            error_log('ai_usage_log insert failed: ' . $e->getMessage());
        }
    }

    private function isStale(array $job): bool
    {
        return strtotime((string) $job['updated_at']) < time() - self::STALE_MINUTES * 60;
    }

    private static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); return; }
        if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); return; }
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    private static function serbianDate(string $date, bool $cyrillic): string
    {
        $ts = strtotime($date);
        if (!$ts) return '';
        $lat = ['januar', 'februar', 'mart', 'april', 'maj', 'jun', 'jul', 'avgust', 'septembar', 'oktobar', 'novembar', 'decembar'];
        $cyr = ['јануар', 'фебруар', 'март', 'април', 'мај', 'јун', 'јул', 'август', 'септембар', 'октобар', 'новембар', 'децембар'];
        $m = (int) date('n', $ts) - 1;
        return date('j', $ts) . '. ' . ($cyrillic ? $cyr[$m] : $lat[$m]) . ' ' . date('Y', $ts) . '.';
    }
}
