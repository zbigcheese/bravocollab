<?php
/**
 * One-shot check that the Anthropic key in config/config.php works for the
 * infographic video feature. Runs ONE storyboard call (a few cents) on a
 * small built-in speeches CSV and prints the resolved scenes + any issues.
 * No database needed.
 *
 *   php tools/test_video_ai.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../core/Anthropic.php';
require __DIR__ . '/../core/VideoStoryboard.php';

if (!Anthropic::isConfigured()) {
    fwrite(STDERR, "No key: set 'anthropic_api_key' in config/config.php (or the ANTHROPIC_API_KEY env var).\n");
    exit(1);
}

$csv = "speaker,club,start,end\n"
     . "Aleksandar Vučić,SNS,10:00:00,10:20:00\n"
     . "Ana Brnabić,SNS,10:20:00,10:45:30\n"
     . "Jovana Jovanović,Studentska lista,10:45:30,10:52:10\n"
     . "Aleksandar Vučić,SNS,11:00:00,11:30:03\n"
     . "Marko Marković,Studentska lista,11:30:03,11:47:12\n"
     . "Aleksandar Vučić,SNS,12:00:00,12:50:00\n";
$ds = VideoStoryboard::parseCsv($csv, 'speeches.csv');
$form = [
    'topic' => 'Ko je koliko govorio u Skupštini?', 'brief' => 'Drugi dan konstitutivne sednice. Vučić je šef kluba SNS.',
    'language' => 'sr-Latn', 'target_seconds' => 30, 'date_chip' => '18. novembar 2026.', 'context_chip' => 'Konstitutivna sednica',
    'section_tag' => 'SKUPŠTINA', 'ticker' => '', 'domain' => 'bravo.org.rs', 'footage_ids' => [],
];

$cfg = Anthropic::config();
echo "Model {$cfg['model']}, effort {$cfg['effort']} — calling the API…\n";
$t = microtime(true);
try {
    $res = Anthropic::json(
        [['type' => 'text', 'text' => VideoStoryboard::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']]],
        [['type' => 'text', 'text' => 'FOOTAGE: none — use "none" for every background.'], ['type' => 'text', 'text' => VideoStoryboard::userText($form, $ds)]],
        VideoStoryboard::schema()
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("OK in %.1f s (served by %s). Usage: %s\n\n", microtime(true) - $t, $res['model'], json_encode($res['usage']));

[$sb, $issues] = VideoStoryboard::resolve($res['data'], $ds, $form, []);
echo "Ticker: {$sb['meta']['ticker']}\nTotal: {$sb['meta']['duration_s']} s\n\n";
foreach ($sb['scenes'] as $i => $s) {
    echo ($i + 1) . ". {$s['type']} ({$s['duration_s']} s): " . json_encode($s['_r'], JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\nIssues: " . ($issues ? '' : "none\n");
foreach ($issues as $is) echo "  [{$is['level']}] scene " . ($is['scene'] === null ? '-' : $is['scene'] + 1) . " {$is['field']}: {$is['message']}\n";
