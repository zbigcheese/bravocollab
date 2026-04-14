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

        $user = Auth::login($email, $password);
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

    public function me(): void
    {
        $this->requireAuth();

        $user = Auth::currentUser();
        $this->json(['user' => $user]);
    }
}
