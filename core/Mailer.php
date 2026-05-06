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

        // Cards: assignment OR personal-board OR coordinator-with-opt-in.
        // The coordinator branch is gated on user_preferences.notify_coordinator_cards,
        // so coordinators who haven't opted in are excluded from the recap.
        $cardsStmt = $db->prepare(
            'SELECT DISTINCT c.id, c.title, c.due_date, l.board_id, b.title AS board_title
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             LEFT JOIN card_assignments ca ON ca.card_id = c.id AND ca.user_id = :uid_a
             LEFT JOIN user_preferences up ON up.user_id = :uid_pref
             WHERE (ca.user_id = :uid_a2
                    OR (b.is_personal = 1 AND b.created_by = :uid_p)
                    OR (c.coordinator_id = :uid_co
                        AND COALESCE(up.notify_coordinator_cards, 0) = 1))
               AND c.is_archived = 0
               AND c.due_complete = 0
               AND b.is_archived = 0
               AND DATE(c.due_date) BETWEEN :start AND :end
             ORDER BY c.due_date ASC'
        );
        $cardsStmt->execute([
            'uid_a' => $userId, 'uid_a2' => $userId, 'uid_p' => $userId,
            'uid_co' => $userId, 'uid_pref' => $userId,
            'start' => $window[0], 'end' => $window[7],
        ]);
        $cards = $cardsStmt->fetchAll();

        // Same shape for checklist items: assigned-to-me OR sitting on a
        // card in my personal board.
        $itemsStmt = $db->prepare(
            'SELECT DISTINCT ci.id, ci.content, ci.due_date, ch.card_id,
                    c.title AS card_title, l.board_id, b.title AS board_title
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE (ci.assigned_to = :uid_a
                    OR (b.is_personal = 1 AND b.created_by = :uid_p))
               AND ci.is_checked = 0
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND ci.due_date BETWEEN :start AND :end
             ORDER BY ci.due_date ASC'
        );
        $itemsStmt->execute([
            'uid_a' => $userId, 'uid_p' => $userId,
            'start' => $window[0], 'end' => $window[7],
        ]);
        $items = $itemsStmt->fetchAll();

        // Overdue: cards / items whose due_date is strictly before today
        // (CET) and that aren't completed or archived. Same user-relationship
        // filters as the in-window queries: assignee, personal board, or
        // opted-in coordinator. Surfaced as a single section at the top of
        // the email so they don't get lost beneath the upcoming days.
        $today = $cetNow->format('Y-m-d');

        $overdueCardsStmt = $db->prepare(
            'SELECT DISTINCT c.id, c.title, c.due_date, l.board_id, b.title AS board_title
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             LEFT JOIN card_assignments ca ON ca.card_id = c.id AND ca.user_id = :uid_a
             LEFT JOIN user_preferences up ON up.user_id = :uid_pref
             WHERE (ca.user_id = :uid_a2
                    OR (b.is_personal = 1 AND b.created_by = :uid_p)
                    OR (c.coordinator_id = :uid_co
                        AND COALESCE(up.notify_coordinator_cards, 0) = 1))
               AND c.is_archived = 0
               AND c.due_complete = 0
               AND b.is_archived = 0
               AND DATE(c.due_date) < :today
             ORDER BY c.due_date ASC'
        );
        $overdueCardsStmt->execute([
            'uid_a' => $userId, 'uid_a2' => $userId, 'uid_p' => $userId,
            'uid_co' => $userId, 'uid_pref' => $userId,
            'today' => $today,
        ]);
        $overdueCards = $overdueCardsStmt->fetchAll();

        $overdueItemsStmt = $db->prepare(
            'SELECT DISTINCT ci.id, ci.content, ci.due_date, ch.card_id,
                    c.title AS card_title, l.board_id, b.title AS board_title
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE (ci.assigned_to = :uid_a
                    OR (b.is_personal = 1 AND b.created_by = :uid_p))
               AND ci.is_checked = 0
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND ci.due_date < :today
             ORDER BY ci.due_date ASC'
        );
        $overdueItemsStmt->execute([
            'uid_a' => $userId, 'uid_p' => $userId, 'today' => $today,
        ]);
        $overdueItems = $overdueItemsStmt->fetchAll();

        $sections = [];
        if (!empty($overdueCards) || !empty($overdueItems)) {
            $sections[] = [
                'label'      => 'Overdue',
                'cards'      => $overdueCards,
                'items'      => $overdueItems,
                'is_overdue' => true,
            ];
        }
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
            // Overdue section gets per-item dates inline (since the section
            // header isn't a single date) and a red headline color so it
            // visually announces "fix these first."
            $isOverdue = !empty($sec['is_overdue']);

            foreach ($sec['cards'] as $c) {
                $url = $baseUrl . '/index.php?page=board&id=' . (int) $c['board_id']
                     . '&card=' . (int) $c['id'];
                $title      = htmlspecialchars($c['title'], ENT_QUOTES);
                $boardTitle = htmlspecialchars($c['board_title'], ENT_QUOTES);
                $dateSuffix = '';
                if ($isOverdue) {
                    try {
                        $d = new DateTime($c['due_date']);
                        $dateSuffix = ' &middot; was due ' . htmlspecialchars($d->format('M j'), ENT_QUOTES);
                    } catch (Throwable $e) { /* skip */ }
                }
                $rows .= '<li style="margin:8px 0;line-height:1.45;">'
                       . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                       .   'style="color:#026AA7;text-decoration:none;font-weight:600;">'
                       .   $title
                       . '</a>'
                       . ' <span style="color:#5e6c84;font-size:12px;">— ' . $boardTitle . $dateSuffix . '</span>'
                       . '</li>';
            }

            foreach ($sec['items'] as $it) {
                $url = $baseUrl . '/index.php?page=board&id=' . (int) $it['board_id']
                     . '&card=' . (int) $it['card_id'];
                $content    = htmlspecialchars($it['content'], ENT_QUOTES);
                $cardTitle  = htmlspecialchars($it['card_title'], ENT_QUOTES);
                $boardTitle = htmlspecialchars($it['board_title'], ENT_QUOTES);
                $dateSuffix = '';
                if ($isOverdue) {
                    try {
                        $d = new DateTime($it['due_date']);
                        $dateSuffix = ' &middot; was due ' . htmlspecialchars($d->format('M j'), ENT_QUOTES);
                    } catch (Throwable $e) { /* skip */ }
                }
                // List-style:none + inline checkbox glyph distinguishes tasks from cards.
                $rows .= '<li style="margin:8px 0;line-height:1.45;list-style:none;">'
                       . '<span style="color:#5e6c84;margin-right:6px;font-size:14px;" aria-hidden="true">&#9745;</span>'
                       . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                       .   'style="color:#026AA7;text-decoration:none;">'
                       .   $content
                       . '</a>'
                       . ' <span style="color:#5e6c84;font-size:12px;">— ' . $cardTitle
                       .   ' &middot; ' . $boardTitle . $dateSuffix . '</span>'
                       . '</li>';
            }

            $label = htmlspecialchars($sec['label'], ENT_QUOTES);
            $headingColor = $isOverdue ? '#BF2600' : '#172b4d';
            $sectionsHtml .=
                  '<h3 style="margin:22px 0 6px;color:' . $headingColor . ';font-size:15px;'
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
            case 'comment_reply':   $line = "<strong>{$actor}</strong> replied to your comment on <strong>{$card}</strong>"; break;
            case 'due_soon':        $line = "<strong>{$card}</strong> is due soon"; break;
            case 'due_overdue':     $line = "<strong>{$card}</strong> is overdue"; break;
            case 'board_invited':   $line = "<strong>{$actor}</strong> added you to <strong>{$board}</strong>"; break;
            default:                $line = "You have a new notification"; break;
        }

        // For comment-triggered notifications, quote the comment body so the
        // recipient can see what was actually said without opening the card.
        if (($type === 'comment_added' || $type === 'comment_mention' || $type === 'comment_reply')
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
            case 'comment_reply':   return "{$actor} replied to your comment on {$card} — {$appName}";
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
     * Send mail with deliverability-friendly defaults:
     *   - multipart/alternative (plaintext + HTML) — HTML-only is a spam signal
     *   - explicit Date and Message-ID headers — many MTAs add them but not all
     *   - RFC 2047 subject encoding when subject contains non-ASCII bytes
     *   - envelope sender pinned to mail_from via sendmail's -f flag, so SPF
     *     for the From-domain is actually consulted (default envelope is
     *     username@server-host, which is what kills most cPanel deliverability)
     *
     * Returns rich diagnostics for the admin test endpoint.
     */
    public static function sendWithDiagnostics(string $to, string $subject, string $htmlBody): array
    {
        $config = require __DIR__ . '/../config/config.php';

        $fromAddr = $config['mail_from'];
        $fromName = $config['mail_from_name'];
        $from     = $fromName . ' <' . $fromAddr . '>';
        $fromHost = (substr(strrchr($fromAddr, '@') ?: '', 1)) ?: 'localhost';

        $boundary = 'bc-' . bin2hex(random_bytes(8));
        $plainBody = self::htmlToText($htmlBody);

        // Quoted-printable encoding both parts. The HTML in particular has
        // long inline-styled lines that would otherwise blow past exim's
        // 2048-byte SMTP line-length limit and get rejected at handoff.
        // QP wraps lines at 76 chars with soft `=\r\n` breaks that the
        // receiving client unfolds, so the rendered output is unaffected.
        $plainEncoded = quoted_printable_encode(wordwrap($plainBody, 75, "\n", false));
        $htmlEncoded  = quoted_printable_encode($htmlBody);

        $body =
            "This is a multi-part message in MIME format.\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $plainEncoded . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $htmlEncoded . "\r\n\r\n"
            . "--{$boundary}--\r\n";

        $messageId = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $fromHost . '>';
        $encodedSubject = self::encodeSubject($subject);

        $headers = [
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: ' . $messageId,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . $from,
            'Reply-To: ' . $fromAddr,
            'X-Mailer: BravoCollab',
        ];

        // Pin the SMTP envelope sender (Mail FROM) to the From-domain mailbox
        // so receiving servers check SPF against bravo.org.rs (where the SPF
        // record exists) rather than the cPanel server's hostname.
        $envelopeFrom = filter_var($fromAddr, FILTER_VALIDATE_EMAIL) ?: '';
        $additionalParams = $envelopeFrom !== '' ? '-f ' . $envelopeFrom : '';

        // Clear any prior error so we know whatever we read after is from this call.
        error_clear_last();
        $result = mail($to, $encodedSubject, $body, implode("\r\n", $headers), $additionalParams);
        $lastError = error_get_last();

        return [
            'ok'                 => (bool) $result,
            'to'                 => $to,
            'subject'            => $subject,
            'subject_encoded'    => $encodedSubject,
            'from'               => $from,
            'envelope_from'      => $envelopeFrom,
            'additional_params'  => $additionalParams,
            'message_id'         => $messageId,
            'headers'            => $headers,
            'body_length'        => strlen($body),
            'html_length'        => strlen($htmlBody),
            'plain_length'       => strlen($plainBody),
            'body_preview'       => mb_substr($plainBody, 0, 600),
            'mail_return'        => (bool) $result,
            'last_error'         => $lastError,
        ];
    }

    /**
     * Subject lines containing non-ASCII bytes need RFC 2047 encoding,
     * otherwise mail() passes the raw bytes and many MTAs / clients render
     * them as `?` or ditch the message entirely. ASCII subjects are returned
     * unchanged so we don't add overhead for the common case.
     */
    private static function encodeSubject(string $subject): string
    {
        if (preg_match('/[\x80-\xff]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }

    /**
     * Best-effort HTML → plain text for the multipart/alternative fallback.
     * Doesn't need to be perfect — just readable enough that mail clients
     * with HTML disabled, plus spam scanners that score on text content,
     * see something coherent.
     */
    private static function htmlToText(string $html): string
    {
        // Drop non-content sections entirely so their markup doesn't leak.
        $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
        // Replace block-level tags with newlines and list items with bullets.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|ul|ol|table)>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse internal whitespace and runs of blank lines.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n[ \t]+/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
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
