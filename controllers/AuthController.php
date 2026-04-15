<?php

class AuthController extends Controller
{
    public function login(): void
    {
        $this->requirePost();

        $data = $this->getJSON();
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            $this->json(['error' => 'Email and password are required'], 400);
            return;
        }

        // Rate limiting: check login attempts
        $db = Database::get();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM `login_attempts` WHERE `ip_address` = :ip AND `attempted_at` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );
        $stmt->execute(['ip' => $ip]);
        if ((int) $stmt->fetchColumn() >= 5) {
            $this->json(['error' => 'Too many login attempts. Please wait a minute.'], 429);
            return;
        }

        // Record attempt
        $db->prepare('INSERT INTO `login_attempts` (`ip_address`, `email`) VALUES (:ip, :email)')
           ->execute(['ip' => $ip, 'email' => $email]);

        $remember = !empty($data['remember']);
        $user = Auth::login($email, $password, $remember);
        if (!$user) {
            $this->json(['error' => 'Invalid email or password'], 401);
            return;
        }

        $this->json([
            'success'  => true,
            'user'     => [
                'id'           => $user['id'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
                'role'         => $user['role'],
            ],
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function logout(): void
    {
        Auth::logout();
        $this->json(['success' => true]);
    }

    public function register(): void
    {
        $this->requirePost();

        $data = $this->getJSON();
        $token = trim($data['token'] ?? '');
        $displayName = trim($data['display_name'] ?? '');
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        // Validate invitation token
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM `invitations` WHERE `token` = :token AND `accepted_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            $this->json(['error' => 'Invalid or expired invitation'], 400);
            return;
        }

        // Check if email already registered
        $stmt = $db->prepare('SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1');
        $stmt->execute(['email' => $invitation['email']]);
        if ($stmt->fetch()) {
            $this->json(['error' => 'An account with this email already exists'], 400);
            return;
        }

        // Validate input
        $v = new Validator();
        $v->required($displayName, 'display_name')
          ->maxLength($displayName, 100, 'display_name')
          ->password($password)
          ->match($password, $passwordConfirm, 'password_confirm');

        if ($v->fails()) {
            $this->json(['error' => $v->firstError(), 'errors' => $v->errors()], 400);
            return;
        }

        // Create user
        $userId = (int) $db->prepare(
            'INSERT INTO `users` (`email`, `password_hash`, `display_name`, `role`) VALUES (:email, :hash, :name, :role)'
        )->execute([
            'email' => $invitation['email'],
            'hash'  => Auth::hashPassword($password),
            'name'  => $displayName,
            'role'  => $invitation['role'],
        ]);
        $userId = (int) $db->lastInsertId();

        // Mark invitation as accepted
        $db->prepare('UPDATE `invitations` SET `accepted_at` = NOW() WHERE `id` = :id')
           ->execute(['id' => $invitation['id']]);

        // Auto-add user to pre-assigned boards
        $boardStmt = $db->prepare('SELECT board_id FROM invitation_boards WHERE invitation_id = :iid');
        $boardStmt->execute(['iid' => $invitation['id']]);
        $addMember = $db->prepare(
            'INSERT IGNORE INTO board_members (board_id, user_id, role) VALUES (:bid, :uid, :role)'
        );
        foreach ($boardStmt->fetchAll() as $row) {
            $addMember->execute(['bid' => $row['board_id'], 'uid' => $userId, 'role' => BOARD_ROLE_MEMBER]);
        }

        // Auto-login
        Auth::login($invitation['email'], $password);

        $this->json([
            'success' => true,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function forgotPassword(): void
    {
        $this->requirePost();

        $data = $this->getJSON();
        $email = trim($data['email'] ?? '');

        if (empty($email)) {
            $this->json(['error' => 'Email is required'], 400);
            return;
        }

        $db = Database::get();

        // Rate limit: max 3 reset requests per email per hour
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM `password_resets` WHERE `email` = :email AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $stmt->execute(['email' => $email]);
        if ((int) $stmt->fetchColumn() >= 3) {
            // Don't reveal that we're rate limiting — just say success
            $this->json(['success' => true, 'message' => 'If an account with that email exists, a reset link has been sent.']);
            return;
        }

        // Check if user exists (but don't reveal if they don't)
        $stmt = $db->prepare('SELECT `id` FROM `users` WHERE `email` = :email AND `is_active` = 1 LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalidate any existing tokens
            $db->prepare('UPDATE `password_resets` SET `used_at` = NOW() WHERE `email` = :email AND `used_at` IS NULL')
               ->execute(['email' => $email]);

            // Create new token
            $token = bin2hex(random_bytes(32));
            $db->prepare(
                'INSERT INTO `password_resets` (`email`, `token`, `expires_at`) VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute(['email' => $email, 'token' => $token]);

            require_once __DIR__ . '/../core/Mailer.php';
            Mailer::sendPasswordReset($email, $token);
        }

        // Always return success to prevent email enumeration
        $this->json(['success' => true, 'message' => 'If an account with that email exists, a reset link has been sent.']);
    }

    public function resetPassword(): void
    {
        $this->requirePost();

        $data = $this->getJSON();
        $token = trim($data['token'] ?? '');
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        if (empty($token)) {
            $this->json(['error' => 'Invalid reset link'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM `password_resets` WHERE `token` = :token AND `used_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $this->json(['error' => 'This reset link is invalid or has expired. Please request a new one.'], 400);
            return;
        }

        $v = new Validator();
        $v->password($password)->match($password, $passwordConfirm, 'password_confirm');
        if ($v->fails()) {
            $this->json(['error' => $v->firstError()], 400);
            return;
        }

        // Update password
        $db->prepare('UPDATE `users` SET `password_hash` = :hash WHERE `email` = :email')
           ->execute(['hash' => Auth::hashPassword($password), 'email' => $reset['email']]);

        // Mark token as used
        $db->prepare('UPDATE `password_resets` SET `used_at` = NOW() WHERE `id` = :id')
           ->execute(['id' => $reset['id']]);

        $this->json(['success' => true, 'message' => 'Password has been reset. You can now sign in.']);
    }

    public function me(): void
    {
        $this->requireAuth();

        $user = Auth::currentUser();
        $this->json(['user' => $user]);
    }
}
