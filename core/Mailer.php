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

    public static function sendPasswordReset(string $toEmail, string $token): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        $resetUrl = $config['base_url'] . '/index.php?page=reset_password&token=' . urlencode($token);

        $subject = "Reset your password — " . $config['app_name'];

        $body = self::buildHtml(
            $config['app_name'],
            "Password Reset",
            "<p>We received a request to reset your password. Click the button below to choose a new one:</p>"
            . "<p style=\"text-align:center;margin:30px 0;\">"
            . "<a href=\"{$resetUrl}\" style=\"background-color:#0079BF;color:#fff;padding:12px 32px;text-decoration:none;border-radius:4px;font-weight:bold;display:inline-block;\">Reset Password</a>"
            . "</p>"
            . "<p style=\"color:#666;font-size:13px;\">This link expires in 1 hour. If you didn't request this, you can safely ignore this email.</p>"
            . "<p style=\"color:#999;font-size:12px;\">If the button doesn't work, copy this link:<br>{$resetUrl}</p>"
        );

        return self::send($toEmail, $subject, $body);
    }

    /**
     * Send a digest of unread notifications. Subject reflects count:
     *  - 1 notification → specific, derived from the notification content
     *  - N notifications → "You have X new notifications in BravoCollab"
     */
    public static function sendNotificationDigest(string $toEmail, string $displayName, array $notifications): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        $count  = count($notifications);
        if ($count === 0) return false;

        $subject = ($count === 1)
            ? self::notificationSubject($notifications[0], $config['app_name'])
            : "You have {$count} new notifications in " . $config['app_name'];

        $items = '';
        foreach ($notifications as $n) {
            $data = is_string($n['data']) ? json_decode($n['data'], true) : ($n['data'] ?? []);
            $data = is_array($data) ? $data : [];
            $text = self::notificationText($n['type'], $data);
            $url  = self::notificationUrl($config['base_url'], $data);
            $items .= '<li style="margin-bottom:14px;line-height:1.5;">'
                   .   '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                   .     'style="display:block;color:#026AA7;text-decoration:none;">' . $text . '</a>'
                   . '</li>';
        }

        $greeting = 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES) . ',';
        $intro = ($count === 1)
            ? "<p>{$greeting}</p><p>You have a new notification waiting in " . $config['app_name'] . ":</p>"
            : "<p>{$greeting}</p><p>You have <strong>{$count} unread notifications</strong> waiting in " . $config['app_name'] . ":</p>";

        $body = self::buildHtml(
            $config['app_name'],
            'New notifications',
            $intro
            . '<ul style="padding-left:20px;margin:16px 0;">' . $items . '</ul>'
            . '<p style="color:#666;font-size:13px;margin-top:24px;">Click any item above to open it. If you\'re signed out, you\'ll be taken there after signing in.</p>'
        );

        return self::send($toEmail, $subject, $body);
    }

    /**
     * Build the "what's next" sections for $userId across the 8-day window
     * starting from $cetNow's date in CET. Returns a list of:
     *   ['label' => 'Today, Apr 26', 'cards' => [...], 'items' => [...]]
     * with empty days omitted. Used by both cron.php (8am CET digest) and
     * the admin "test: dailyemail" endpoint.
     */
    public static function buildWhatsNextSectionsForUser(PDO $db, int $userId, DateTime $cetNow): array
    {
        $cetTz = $cetNow->getTimezone();

        $window = [];
        for ($i = 0; $i < 8; $i++) {
            $d = (clone $cetNow)->setTime(0, 0, 0)->modify("+{$i} day");
            $window[] = $d->format('Y-m-d');
        }

        $cardsStmt = $db->prepare(
            'SELECT c.id, c.title, c.due_date, l.board_id, b.title AS board_title
             FROM cards c
             JOIN card_assignments ca ON ca.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE ca.user_id = :uid
               AND c.is_archived = 0
               AND c.due_complete = 0
               AND b.is_archived = 0
               AND DATE(c.due_date) BETWEEN :start AND :end
             ORDER BY c.due_date ASC'
        );
        $cardsStmt->execute(['uid' => $userId, 'start' => $window[0], 'end' => $window[7]]);
        $cards = $cardsStmt->fetchAll();

        $itemsStmt = $db->prepare(
            'SELECT ci.id, ci.content, ci.due_date, ch.card_id,
                    c.title AS card_title, l.board_id, b.title AS board_title
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE ci.assigned_to = :uid
               AND ci.is_checked = 0
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND ci.due_date BETWEEN :start AND :end
             ORDER BY ci.due_date ASC'
        );
        $itemsStmt->execute(['uid' => $userId, 'start' => $window[0], 'end' => $window[7]]);
        $items = $itemsStmt->fetchAll();

        $sections = [];
        for ($i = 0; $i < 8; $i++) {
            $dateStr  = $window[$i];
            $dayCards = array_values(array_filter(
                $cards, fn($c) => substr($c['due_date'], 0, 10) === $dateStr
            ));
            $dayItems = array_values(array_filter(
                $items, fn($it) => $it['due_date'] === $dateStr
            ));
            if (empty($dayCards) && empty($dayItems)) continue;

            $d = new DateTime($dateStr, $cetTz);
            $formatted = $d->format('M j');
            if     ($i === 0) $label = "Today, {$formatted}";
            elseif ($i === 1) $label = "Tomorrow, {$formatted}";
            else              $label = $formatted;

            $sections[] = [
                'label' => $label,
                'cards' => $dayCards,
                'items' => $dayItems,
            ];
        }
        return $sections;
    }

    /**
     * Daily "what's next" overview. $sections is a list of:
     *   ['label' => 'Today, Apr 26', 'cards' => [...], 'items' => [...]]
     * Each card row carries id, title, due_date, board_id, board_title.
     * Each item row carries id, content, due_date, card_id, card_title,
     * board_id, board_title.
     */
    public static function sendWhatsNext(string $toEmail, string $displayName, array $sections): bool
    {
        $built = self::buildWhatsNext($displayName, $sections);
        if ($built === null) return false;
        return self::send($toEmail, $built['subject'], $built['body']);
    }

    /**
     * Build (but don't send) the "what's next" email. Returns
     * ['subject' => ..., 'body' => ...] or null if there's nothing to send.
     * Exposed so the admin test endpoint can preview the rendered email and
     * collect diagnostics from the underlying mail() call.
     */
    public static function buildWhatsNext(string $displayName, array $sections): ?array
    {
        $config  = require __DIR__ . '/../config/config.php';
        $appName = $config['app_name'];
        $baseUrl = rtrim($config['base_url'], '/');

        if (empty($sections)) return null;

        // Date in CET so the subject lines up with the email body's "Today" anchor.
        // Including the date makes each day's subject unique, which prevents
        // Gmail from threading consecutive days' digests into one collapsed row.
        $cetDate = (new DateTime('now', new DateTimeZone('Europe/Belgrade')))->format('M j');
        $subject = "What's next — {$cetDate} — {$appName}";

        $sectionsHtml = '';
        foreach ($sections as $sec) {
            $rows = '';

            foreach ($sec['cards'] as $c) {
                $url = $baseUrl . '/index.php?page=board&id=' . (int) $c['board_id']
                     . '&card=' . (int) $c['id'];
                $title      = htmlspecialchars($c['title'], ENT_QUOTES);
                $boardTitle = htmlspecialchars($c['board_title'], ENT_QUOTES);
                $rows .= '<li style="margin:8px 0;line-height:1.45;">'
                       . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                       .   'style="color:#026AA7;text-decoration:none;font-weight:600;">'
                       .   $title
                       . '</a>'
                       . ' <span style="color:#5e6c84;font-size:12px;">— ' . $boardTitle . '</span>'
                       . '</li>';
            }

            foreach ($sec['items'] as $it) {
                $url = $baseUrl . '/index.php?page=board&id=' . (int) $it['board_id']
                     . '&card=' . (int) $it['card_id'];
                $content    = htmlspecialchars($it['content'], ENT_QUOTES);
                $cardTitle  = htmlspecialchars($it['card_title'], ENT_QUOTES);
                $boardTitle = htmlspecialchars($it['board_title'], ENT_QUOTES);
                // List-style:none + inline checkbox glyph distinguishes tasks from cards.
                $rows .= '<li style="margin:8px 0;line-height:1.45;list-style:none;">'
                       . '<span style="color:#5e6c84;margin-right:6px;font-size:14px;" aria-hidden="true">&#9745;</span>'
                       . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                       .   'style="color:#026AA7;text-decoration:none;">'
                       .   $content
                       . '</a>'
                       . ' <span style="color:#5e6c84;font-size:12px;">— ' . $cardTitle
                       .   ' &middot; ' . $boardTitle . '</span>'
                       . '</li>';
            }

            $label = htmlspecialchars($sec['label'], ENT_QUOTES);
            $sectionsHtml .=
                  '<h3 style="margin:22px 0 6px;color:#172b4d;font-size:15px;'
                .          'border-bottom:1px solid #dfe1e6;padding-bottom:4px;">'
                . $label
                . '</h3>'
                . '<ul style="padding-left:22px;margin:0;">' . $rows . '</ul>';
        }

        $greeting = 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES) . ',';
        $body = self::buildHtml(
            $appName,
            "What's next",
              "<p>{$greeting}</p>"
            . "<p>Here\u{2019}s what you have coming up:</p>"
            . $sectionsHtml
            . '<p style="color:#666;font-size:13px;margin-top:24px;">'
            .   'Click any item to open it in ' . $appName . '.'
            . '</p>'
        );

        return ['subject' => $subject, 'body' => $body];
    }

    private static function notificationText(string $type, array $data): string
    {
        $actor = htmlspecialchars($data['actor_name'] ?? 'Someone', ENT_QUOTES);
        $card  = htmlspecialchars($data['card_title'] ?? 'a card', ENT_QUOTES);
        $board = htmlspecialchars($data['board_title'] ?? 'a board', ENT_QUOTES);

        $line = '';
        switch ($type) {
            case 'card_assigned':   $line = "<strong>{$actor}</strong> assigned you to <strong>{$card}</strong>"; break;
            case 'card_unassigned': $line = "<strong>{$actor}</strong> removed you from <strong>{$card}</strong>"; break;
            case 'comment_added':   $line = "<strong>{$actor}</strong> commented on <strong>{$card}</strong>"; break;
            case 'comment_mention': $line = "<strong>{$actor}</strong> mentioned you in <strong>{$card}</strong>"; break;
            case 'due_soon':        $line = "<strong>{$card}</strong> is due soon"; break;
            case 'due_overdue':     $line = "<strong>{$card}</strong> is overdue"; break;
            case 'board_invited':   $line = "<strong>{$actor}</strong> added you to <strong>{$board}</strong>"; break;
            default:                $line = "You have a new notification"; break;
        }

        // For comment-triggered notifications, quote the comment body so the
        // recipient can see what was actually said without opening the card.
        if (($type === 'comment_added' || $type === 'comment_mention')
            && !empty($data['body'])
        ) {
            $body     = (string) $data['body'];
            $bodyHtml = nl2br(htmlspecialchars($body, ENT_QUOTES));
            $line .= '<div style="margin-top:6px;padding:8px 12px;'
                  . 'border-left:3px solid #5bc0de;background:#f4f5f7;'
                  . 'color:#444;font-size:13px;border-radius:0 4px 4px 0;'
                  . 'white-space:normal;">' . $bodyHtml . '</div>';
        }

        return $line;
    }

    private static function notificationSubject(array $n, string $appName): string
    {
        $data = is_string($n['data']) ? json_decode($n['data'], true) : ($n['data'] ?? []);
        $data = is_array($data) ? $data : [];
        $actor = $data['actor_name'] ?? 'Someone';
        $card  = $data['card_title'] ?? 'a card';
        $board = $data['board_title'] ?? 'a board';
        switch ($n['type']) {
            case 'card_assigned':   return "{$actor} assigned you to {$card} — {$appName}";
            case 'card_unassigned': return "{$actor} removed you from {$card} — {$appName}";
            case 'comment_added':   return "{$actor} commented on {$card} — {$appName}";
            case 'comment_mention': return "{$actor} mentioned you in {$card} — {$appName}";
            case 'due_soon':        return "{$card} is due soon — {$appName}";
            case 'due_overdue':     return "{$card} is overdue — {$appName}";
            case 'board_invited':   return "You were added to {$board} — {$appName}";
            default:                return "New notification — {$appName}";
        }
    }

    private static function notificationUrl(string $baseUrl, array $data): string
    {
        $boardId = (int) ($data['board_id'] ?? 0);
        $cardId  = (int) ($data['card_id'] ?? 0);
        $url = rtrim($baseUrl, '/') . '/index.php?page=board&id=' . $boardId;
        if ($cardId) $url .= '&card=' . $cardId;
        return $url;
    }

    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        return self::sendWithDiagnostics($to, $subject, $htmlBody)['ok'];
    }

    /**
     * Same as send() but returns detailed info about what was attempted and
     * whatever PHP/the MTA reported. Used by the admin "test: dailyemail"
     * endpoint to surface why a "sent successfully" message can still result
     * in no email arriving.
     *
     * Returns: ['ok' => bool, 'to' => ..., 'subject' => ..., 'from' => ...,
     *           'headers' => [...], 'body_length' => int, 'body_preview' => str,
     *           'mail_return' => bool, 'last_error' => array|null]
     */
    public static function sendWithDiagnostics(string $to, string $subject, string $htmlBody): array
    {
        $config = require __DIR__ . '/../config/config.php';

        $from = $config['mail_from_name'] . ' <' . $config['mail_from'] . '>';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from,
            'Reply-To: ' . $config['mail_from'],
            'X-Mailer: BravoCollab',
        ];

        // Clear any prior error so we know whatever we read after is from this call.
        error_clear_last();
        $result = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        $lastError = error_get_last();

        return [
            'ok'           => (bool) $result,
            'to'           => $to,
            'subject'      => $subject,
            'from'         => $from,
            'headers'      => $headers,
            'body_length'  => strlen($htmlBody),
            'body_preview' => mb_substr(strip_tags($htmlBody), 0, 600),
            'mail_return'  => (bool) $result,
            'last_error'   => $lastError,
        ];
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
