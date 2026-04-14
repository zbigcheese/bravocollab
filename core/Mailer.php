<?php

class Mailer
{
    public static function sendInvitation(string $toEmail, string $token, string $inviterName): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        $registerUrl = $config['base_url'] . '/index.php?page=register&token=' . urlencode($token);

        $subject = "You've been invited to " . $config['app_name'];

        $body = self::buildHtml(
            $config['app_name'],
            "You've been invited!",
            "<p><strong>{$inviterName}</strong> has invited you to join <strong>{$config['app_name']}</strong>, "
            . "a task coordination tool for the Bravo! movement.</p>"
            . "<p>Click the button below to create your account:</p>"
            . "<p style=\"text-align:center;margin:30px 0;\">"
            . "<a href=\"{$registerUrl}\" style=\"background-color:#0079BF;color:#fff;padding:12px 32px;text-decoration:none;border-radius:4px;font-weight:bold;display:inline-block;\">Accept Invitation</a>"
            . "</p>"
            . "<p style=\"color:#666;font-size:13px;\">This invitation expires in {$config['invitation_expiry_days']} days.</p>"
            . "<p style=\"color:#999;font-size:12px;\">If the button doesn't work, copy this link:<br>{$registerUrl}</p>"
        );

        return self::send($toEmail, $subject, $body);
    }

    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $config = require __DIR__ . '/../config/config.php';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $config['mail_from_name'] . ' <' . $config['mail_from'] . '>',
            'Reply-To: ' . $config['mail_from'],
            'X-Mailer: BravoCollab',
        ];

        return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }

    private static function buildHtml(string $appName, string $title, string $content): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,sans-serif;">
<div style="max-width:560px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <div style="background:#0079BF;padding:24px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">{$appName}</h1>
  </div>
  <div style="padding:32px;">
    <h2 style="margin-top:0;color:#172b4d;">{$title}</h2>
    {$content}
  </div>
  <div style="padding:16px 32px;background:#f4f5f7;text-align:center;color:#999;font-size:12px;">
    &copy; {$appName} — Bravo! Movement
  </div>
</div>
</body>
</html>
HTML;
    }
}
