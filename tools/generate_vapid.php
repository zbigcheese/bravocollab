<?php
/**
 * Generate a VAPID key pair for Web Push.
 *
 * Run from CLI: php tools/generate_vapid.php
 * Copy the printed values into config/config.php (vapid_public_key,
 * vapid_private_key). Run this ONCE — the same keys are used for the
 * lifetime of the app. Rotating them invalidates every existing
 * push subscription.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$res = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
if (!$res) { fwrite(STDERR, "openssl_pkey_new failed\n"); exit(1); }

$details = openssl_pkey_get_details($res);
if (empty($details['ec']['d']) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    fwrite(STDERR, "Could not extract EC key components\n");
    exit(1);
}

// Private key: raw 32-byte d scalar, base64url.
$priv = $details['ec']['d'];
$priv = str_pad($priv, 32, "\x00", STR_PAD_LEFT);

// Public key: uncompressed point format 0x04 || X || Y, total 65 bytes,
// base64url. This is what the Web Push spec calls the application server key.
$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$pub = "\x04" . $x . $y;

$b64url = static fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

echo "VAPID key pair generated. Add these two lines to config/config.php:\n\n";
echo "    'vapid_public_key'  => '" . $b64url($pub)  . "',\n";
echo "    'vapid_private_key' => '" . $b64url($priv) . "',\n\n";
echo "And optionally:\n";
echo "    'vapid_subject'     => 'mailto:admin@bravo.org.rs',\n\n";
echo "Done.\n";
