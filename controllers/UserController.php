<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../core/Mailer.php';

class UserController extends Controller
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function list(): void
    {
        $this->requireAuth();

        // Non-admins can list users for member pickers, but with limited fields
        $db = Database::get();

        if (Auth::isAdmin()) {
            $stmt = $db->query(
                'SELECT id, email, display_name, role, is_active, last_login_at, created_at FROM users ORDER BY display_name ASC'
            );
        } else {
            $stmt = $db->query(
                'SELECT id, display_name, email FROM users WHERE is_active = 1 ORDER BY display_name ASC'
            );
        }

        $this->json(['users' => $stmt->fetchAll()]);
    }

    public function invite(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $email = trim($data['email'] ?? '');
        $role = $data['role'] ?? ROLE_MEMBER;

        $v = new Validator();
        $v->required($email, 'email')->email($email);
        if ($v->fails()) {
            $this->json(['error' => $v->firstError()], 400);
            return;
        }

        // Validate role
        if (!in_array($role, [ROLE_ADMIN, ROLE_MEMBER], true)) {
            $role = ROLE_MEMBER;
        }

        $db = Database::get();

        // Check if user already exists
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $this->json(['error' => 'A user with this email already exists'], 400);
            return;
        }

        // Check for existing pending invitation
        $stmt = $db->prepare(
            'SELECT id FROM invitations WHERE email = :email AND accepted_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $this->json(['error' => 'An active invitation already exists for this email'], 400);
            return;
        }

        // Rate limiting: max 20 invitations per hour per admin
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM invitations WHERE invited_by = :uid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $stmt->execute(['uid' => Auth::userId()]);
        if ((int) $stmt->fetchColumn() >= 20) {
            $this->json(['error' => 'Invitation rate limit reached. Try again later.'], 429);
            return;
        }

        // Create invitation
        $config = require __DIR__ . '/../config/config.php';
        $token = bin2hex(random_bytes(32));
        $expiryDays = $config['invitation_expiry_days'];

        $db->prepare(
            'INSERT INTO invitations (email, token, invited_by, role, expires_at)
             VALUES (:email, :token, :invited_by, :role, DATE_ADD(NOW(), INTERVAL :days DAY))'
        )->execute([
            'email'      => $email,
            'token'      => $token,
            'invited_by' => Auth::userId(),
            'role'       => $role,
            'days'       => $expiryDays,
        ]);

        // Send email
        $sent = Mailer::sendInvitation($email, $token, Auth::userName());

        $this->json([
            'success'    => true,
            'email_sent' => $sent,
            'message'    => $sent
                ? 'Invitation sent to ' . $email
                : 'Invitation created but email failed to send. Share this link manually.',
            'invite_url' => !$sent ? ($config['base_url'] . '/index.php?page=register&token=' . $token) : null,
        ]);
    }

    public function toggleActive(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $userId = (int) ($data['user_id'] ?? 0);

        if ($userId === Auth::userId()) {
            $this->json(['error' => 'You cannot deactivate yourself'], 400);
            return;
        }

        $user = $this->userModel->find($userId);
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }

        $newStatus = $user['is_active'] ? 0 : 1;
        $this->userModel->update($userId, ['is_active' => $newStatus]);

        $this->json(['success' => true, 'is_active' => $newStatus]);
    }

    public function updateRole(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $userId = (int) ($data['user_id'] ?? 0);
        $role = $data['role'] ?? '';

        if ($userId === Auth::userId()) {
            $this->json(['error' => 'You cannot change your own role'], 400);
            return;
        }

        if (!in_array($role, [ROLE_ADMIN, ROLE_MEMBER], true)) {
            $this->json(['error' => 'Invalid role'], 400);
            return;
        }

        $this->userModel->update($userId, ['role' => $role]);

        $this->json(['success' => true]);
    }
}
