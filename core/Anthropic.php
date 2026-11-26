<?php
/**
 * Anthropic — minimal Messages API client over curl (zero-build: no Composer,
 * so no SDK). Server-side only; the key never reaches the browser.
 *
 * Config (config/config.php): anthropic_api_key, anthropic_model, anthropic_effort.
 * The key can also come from the ANTHROPIC_API_KEY environment variable.
 */

class Anthropic
{
    private const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2026-10-21';

    /** Models that accept the server-side refusal fallback (`fallbacks: "default"`). */
    private const FALLBACK_MODELS = [
        'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-fable-5-2',
    ];

    /** Set after the API rejects `fallbacks`, so the retry (and later calls) skip it. */
    private static bool $disableFallbacks = false;

    public static function config(): array
    {
        $config = require __DIR__ . '/../config/config.php';
        $key = trim((string) ($config['anthropic_api_key'] ?? ''));
        if ($key === '') $key = trim((string) getenv('ANTHROPIC_API_KEY'));
        return [
            'key'    => $key,
            'model'  => (string) ($config['anthropic_model'] ?? 'claude-opus-5-5') ?: 'claude-opus-5-5',
            'effort' => in_array($config['anthropic_effort'] ?? '', ['low', 'medium', 'high'], true)
                ? $config['anthropic_effort'] : 'low',
        ];
    }

    public static function isConfigured(): bool
    {
        return self::config()['key'] !== '';
    }

    /**
     * Ask for one JSON object matching $schema (structured outputs, so the
     * response is guaranteed to parse). Returns ['data' => array, 'usage' => array, 'model' => string].
     *
     * @param array $system  text blocks; the static ones should come first (cached prefix)
     * @param array $content user-turn content blocks (text / image)
     * @throws RuntimeException on transport, API, refusal or truncation errors
     */
    public static function json(array $system, array $content, array $schema, int $maxTokens = 12000, int $timeout = 150): array
    {
        $cfg = self::config();
        if ($cfg['key'] === '') {
            throw new RuntimeException('Anthropic API key is not configured (config/config.php → anthropic_api_key).');
        }

        $body = [
            'model'         => $cfg['model'],
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'messages'      => [['role' => 'user', 'content' => $content]],
            'output_config' => [
                'effort' => $cfg['effort'],
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['key'],
            'anthropic-version: ' . self::API_VERSION,
        ];
        if (!self::$disableFallbacks && in_array($cfg['model'], self::FALLBACK_MODELS, true)) {
            // On a safety-classifier decline the API re-runs the request on a
            // fallback model inside the same call instead of just stopping.
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $raw = false;
        $status = 0;
        // One retry for rate limits / overload / 5xx — the only retryable classes.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw !== false && !in_array($status, [429, 500, 502, 503, 529], true)) break;
            if ($attempt === 0) sleep(3);
        }
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach the Anthropic API: ' . $curlErr);
        }
        $res = json_decode($raw, true);
        if (!is_array($res)) {
            throw new RuntimeException("Anthropic API returned an unreadable response (HTTP {$status}).");
        }
        if ($status === 400 && isset($body['fallbacks']) && stripos((string) ($res['error']['message'] ?? ''), 'fallback') !== false) {
            // The optional refusal fallback was rejected (e.g. not available on
            // this account) — retry once without it rather than failing the job.
            error_log('Anthropic: retrying without fallbacks: ' . ($res['error']['message'] ?? ''));
            self::$disableFallbacks = true;
            return self::json($system, $content, $schema, $maxTokens, $timeout);
        }
        if ($status >= 400 || ($res['type'] ?? '') === 'error') {
            $msg = $res['error']['message'] ?? 'unknown error';
            throw new RuntimeException("Anthropic API error (HTTP {$status}): {$msg}");
        }

        $stop = $res['stop_reason'] ?? '';
        if ($stop === 'refusal') {
            throw new RuntimeException('The model declined this request. Try rephrasing the brief.');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('The response was cut off (max_tokens). Try a shorter brief or fewer scenes.');
        }

        $text = '';
        foreach ($res['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('The model returned no usable JSON.');
        }

        $u = $res['usage'] ?? [];
        return [
            'data'  => $data,
            'model' => (string) ($res['model'] ?? $cfg['model']),
            'usage' => [
                'input_tokens'       => (int) ($u['input_tokens'] ?? 0),
                'output_tokens'      => (int) ($u['output_tokens'] ?? 0),
                'cache_read_tokens'  => (int) ($u['cache_read_input_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($u['cache_creation_input_tokens'] ?? 0),
            ],
        ];
    }
}
