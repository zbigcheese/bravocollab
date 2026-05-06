<?php
/**
 * Minimal Web Push implementation — VAPID-only, no encrypted payload.
 *
 * Sends an empty push body (TTL header only) signed with VAPID. The service
 * worker on the receiving end fetches the actual notification details from
 * our API. This sidesteps the RFC 8291 ECDH+AES128GCM payload encryption
 * dance, which is non-trivial in pure PHP without Composer dependencies.
 *
 * VAPID keys must be generated once via tools/generate_vapid.php and stored
 * in config.php (vapid_public_key / vapid_private_key, both raw base64url).
 */

require_once __DIR__ . '/../config/database.php';

class WebPush
{
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        Database::get()->exec(
            "CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`      INT UNSIGNED NOT NULL,
                `endpoint`     VARCHAR(500) NOT NULL,
                `p256dh`       VARCHAR(255) NOT NULL,
                `auth`         VARCHAR(255) NOT NULL,
                `user_agent`   VARCHAR(500) DEFAULT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` DATETIME DEFAULT NULL,
                UNIQUE KEY `uk_user_endpoint` (`user_id`, `endpoint`(190)),
                INDEX `idx_user` (`user_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $checked = true;
    }

    public static function isConfigured(): bool
    {
        $config = self::config();
        return !empty($config['vapid_public_key']) && !empty($config['vapid_private_key']);
    }

    public static function publicKey(): string
    {
        $config = self::config();
        return $config['vapid_public_key'] ?? '';
    }

    /**
     * Send a push notification trigger to every subscription belonging to a
     * user. Failed subscriptions (HTTP 404 or 410) are deleted automatically.
     * Returns ['sent' => int, 'failed' => int, 'pruned' => int].
     */
    public static function sendToUser(int $userId): array
    {
        if (!self::isConfigured()) return ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        self::ensureSchema();

        $stmt = Database::get()->prepare(
            'SELECT * FROM push_subscriptions WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $subs = $stmt->fetchAll();

        $sent = 0; $failed = 0; $pruned = 0;
        foreach ($subs as $sub) {
            try {
                $code = self::sendOne($sub['endpoint']);
                if ($code >= 200 && $code < 300) {
                    $sent++;
                    Database::get()->prepare(
                        'UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = :id'
                    )->execute(['id' => $sub['id']]);
                } elseif ($code === 404 || $code === 410) {
                    Database::get()->prepare(
                        'DELETE FROM push_subscriptions WHERE id = :id'
                    )->execute(['id' => $sub['id']]);
                    $pruned++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                error_log('WebPush sendOne failed: ' . $e->getMessage());
                $failed++;
            }
        }
        return compact('sent', 'failed', 'pruned');
    }

    /**
     * Send one VAPID-signed push to the given endpoint. Returns the HTTP
     * status code from the push service.
     */
    private static function sendOne(string $endpoint): int
    {
        $config  = self::config();
        $privB64 = $config['vapid_private_key'];
        $pubB64  = $config['vapid_public_key'];
        $sub     = $config['vapid_subject'] ?? ('mailto:' . ($config['mail_from'] ?? 'admin@localhost'));

        $audience = self::endpointAudience($endpoint);
        $jwt = self::buildVapidJwt($privB64, $audience, $sub);

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $pubB64,
            'TTL: 86400',
            'Content-Length: 0',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HEADER         => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /** Push services want aud = scheme://host (no path). */
    private static function endpointAudience(string $endpoint): string
    {
        $u = parse_url($endpoint);
        return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
    }

    /**
     * Build a VAPID JWT (ES256) signed with the given private key.
     * Private key is raw base64url-encoded 32-byte EC P-256 scalar (the d
     * value), as produced by tools/generate_vapid.php.
     */
    private static function buildVapidJwt(string $privKeyB64, string $audience, string $subject): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $body   = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600,    // 12h validity (max 24h per spec)
            'sub' => $subject,
        ];
        $enc = self::b64urlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
             . '.' . self::b64urlEncode(json_encode($body, JSON_UNESCAPED_SLASHES));

        // Reconstruct the EC PEM key from the raw 32-byte scalar so openssl
        // can sign with it.
        $pem = self::pemFromRawPrivateKey(self::b64urlDecode($privKeyB64));
        $pkey = openssl_pkey_get_private($pem);
        if (!$pkey) throw new RuntimeException('Invalid VAPID private key (openssl_pkey_get_private failed)');

        $sigDer = '';
        if (!openssl_sign($enc, $sigDer, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl_sign failed for VAPID JWT');
        }
        // openssl_sign returns ASN.1 DER for EC; Web Push wants raw r||s
        // (IEEE P-1363, 64 bytes). Convert.
        $sigRaw = self::derToRawSignature($sigDer);

        return $enc . '.' . self::b64urlEncode($sigRaw);
    }

    /**
     * Build a PEM-encoded EC P-256 private key from the raw 32-byte scalar.
     * Uses the standard ASN.1 EC PRIVATE KEY structure (RFC 5915).
     */
    private static function pemFromRawPrivateKey(string $raw): string
    {
        if (strlen($raw) !== 32) {
            throw new RuntimeException('VAPID private key must be 32 raw bytes; got ' . strlen($raw));
        }
        // ASN.1 wrapper for ECPrivateKey ::= SEQUENCE { version, privateKey, parameters [0], publicKey [1] }
        // Without publicKey block, just version + privateKey + parameters.
        // EC OID: 1.2.840.10045.3.1.7 (P-256, prime256v1) — DER bytes:
        // 06 08 2A 86 48 CE 3D 03 01 07
        $oid = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $version = "\x02\x01\x01";                                // INTEGER 1
        $privOctet = "\x04\x20" . $raw;                           // OCTET STRING 32 bytes
        $params = "\xa0\x0a" . $oid;                              // [0] EXPLICIT params
        $body = $version . $privOctet . $params;
        $seq = "\x30" . self::derLen(strlen($body)) . $body;

        $b64 = base64_encode($seq);
        $b64 = chunk_split($b64, 64, "\n");
        return "-----BEGIN EC PRIVATE KEY-----\n" . $b64 . "-----END EC PRIVATE KEY-----\n";
    }

    /** Convert openssl ECDSA DER signature to raw 64-byte r||s for JOSE/JWS. */
    private static function derToRawSignature(string $der): string
    {
        // SEQUENCE
        if (ord($der[0]) !== 0x30) throw new RuntimeException('Bad DER signature');
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $lenBytes = ord($der[1]) & 0x7f;
            $offset = 2 + $lenBytes;
        }
        // INTEGER r
        if (ord($der[$offset]) !== 0x02) throw new RuntimeException('Bad DER signature (r)');
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        // INTEGER s
        if (ord($der[$offset]) !== 0x02) throw new RuntimeException('Bad DER signature (s)');
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        // Strip leading 0x00 if present (added when high bit is set), then
        // left-pad to 32 bytes.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) return chr($n);
        $bytes = '';
        while ($n > 0) { $bytes = chr($n & 0xff) . $bytes; $n >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $b64): string
    {
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($b64, '-_', '+/'));
    }

    private static function config(): array
    {
        static $config;
        if ($config === null) $config = require __DIR__ . '/../config/config.php';
        return $config;
    }
}
