<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;
use Smilo\Helper\Mailer;

class AuthController
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /** POST /api/auth/register */
    public function register(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            \Flight::json(['error' => 'Name, email, and password are required'], 422);
            return;
        }

        // Check duplicate email
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            \Flight::json(['error' => 'Email already registered'], 409);
            return;
        }

        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        $role = in_array($data['role'] ?? '', ['buyer', 'seller']) ? $data['role'] : 'buyer';

        $stmt = $this->db->prepare('INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['name'],
            $data['email'],
            $passwordHash,
            $data['phone'] ?? null,
            $role,
        ]);
        $userId = (int) $this->db->lastInsertId();

        // If seller, create seller profile
        if ($role === 'seller' && !empty($data['shop_name'])) {
            $sp = $this->db->prepare('INSERT INTO seller_profiles (user_id, shop_name, location, phone) VALUES (?, ?, ?, ?)');
            $sp->execute([$userId, $data['shop_name'], $data['location'] ?? 'Nigeria', $data['phone'] ?? null]);
        }

        $token = Auth::generateToken([
            'sub'  => $userId,
            'email'=> $data['email'],
            'role' => $role,
        ]);

        \Flight::json([
            'token' => $token,
            'user'  => [
                'id'    => $userId,
                'name'  => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role'  => $role,
            ],
        ]);
    }

    /** POST /api/auth/login */
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['email']) || empty($data['password'])) {
            \Flight::json(['error' => 'Email and password are required'], 422);
            return;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            \Flight::json(['error' => 'Invalid email or password'], 401);
            return;
        }

        $token = Auth::generateToken([
            'sub'  => (int) $user['id'],
            'email'=> $user['email'],
            'role' => $user['role'],
        ]);

        \Flight::json([
            'token' => $token,
            'user'  => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'] ?? null,
                'role'  => $user['role'],
            ],
        ]);
    }

    /** GET /api/auth/me — returns current user from token */
    public function me(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $stmt = $this->db->prepare('SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            \Flight::json(['error' => 'User not found'], 404);
            return;
        }

        $user['id'] = (int) $user['id'];
        $user['seller'] = $this->sellerForUser((int) $user['id']);
        \Flight::json(['data' => $user]);
    }

    /** PUT /api/auth/profile — update own name/phone */
    public function updateProfile(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $fields = [];
        $params = [];

        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $fields[] = 'name = ?';
            $params[] = trim((string) $data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $fields[] = 'phone = ?';
            $params[] = $data['phone'] ?: null;
        }
        if (!$fields) {
            \Flight::json(['error' => 'Nothing to update'], 422);
            return;
        }

        $params[] = $payload['sub'];
        $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        $stmt = $this->db->prepare('SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        $user['id'] = (int) $user['id'];
        $user['seller'] = $this->sellerForUser((int) $user['id']);
        \Flight::json(['data' => $user]);
    }

    /** POST /api/auth/become-seller — create shop for current user */
    public function becomeSeller(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }
        $userId = (int) $payload['sub'];

        // Already a seller?
        $existing = $this->sellerForUser($userId);
        if ($existing) {
            \Flight::json(['data' => $existing]);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $shopName = trim((string) ($data['shop_name'] ?? ''));
        $location = trim((string) ($data['location'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($shopName === '' || $location === '') {
            \Flight::json(['error' => 'Shop name and location are required'], 422);
            return;
        }
        // Phone is mandatory — buyers reach sellers via call/WhatsApp
        if ($phone === '') {
            $stmt = $this->db->prepare('SELECT phone FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $phone = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($phone === '') {
                \Flight::json(['error' => 'Phone number is required so buyers can reach you'], 422);
                return;
            }
        }

        $stmt = $this->db->prepare('INSERT INTO sellers (name, location, phone, user_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$shopName, $location, $phone, $userId]);
        $sellerId = (int) $this->db->lastInsertId();

        $sp = $this->db->prepare('INSERT INTO seller_profiles (user_id, shop_name, location, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), location = VALUES(location), phone = VALUES(phone)');
        try {
            $sp->execute([$userId, $shopName, $location, $phone]);
        } catch (\PDOException $e) {
            // SQLite has no ON DUPLICATE KEY — upsert manually
            $chk = $this->db->prepare('SELECT id FROM seller_profiles WHERE user_id = ?');
            $chk->execute([$userId]);
            if ($chk->fetch()) {
                $upd = $this->db->prepare('UPDATE seller_profiles SET shop_name = ?, location = ?, phone = ? WHERE user_id = ?');
                $upd->execute([$shopName, $location, $phone, $userId]);
            } else {
                $ins = $this->db->prepare('INSERT INTO seller_profiles (user_id, shop_name, location, phone) VALUES (?, ?, ?, ?)');
                $ins->execute([$userId, $shopName, $location, $phone]);
            }
        }

        $this->db->prepare("UPDATE users SET role = 'seller' WHERE id = ?")->execute([$userId]);

        \Flight::json(['data' => $this->sellerForUser($userId)], 201);
    }

    /** @return array<string,mixed>|null */
    private function sellerForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, location, verified, phone FROM sellers WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $seller = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$seller) return null;
        $seller['id'] = (int) $seller['id'];
        $seller['verified'] = (bool) $seller['verified'];
        return $seller;
    }

    /**
     * POST /api/auth/forgot-password {email}
     *
     * Always answers with the same generic message so the endpoint cannot be
     * used to discover which emails have accounts.
     */
    public function forgotPassword(): void
    {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));

        $response = ['message' => 'If that email is registered, a reset link is on its way.'];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \Flight::json($response);
            return;
        }

        $stmt = $this->db->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            \Flight::json($response);
            return;
        }

        // Only one live token per account
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int) $user['id']]);

        // Store only the hash — a leaked DB row cannot be turned into a reset link
        $token   = bin2hex(random_bytes(32));
        $ttl     = (int) (getenv('RESET_TOKEN_TTL') ?: 3600);
        $expires = date('Y-m-d H:i:s', time() + $ttl);

        $this->db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([(int) $user['id'], hash('sha256', $token), $expires]);

        $siteUrl = rtrim((string) (getenv('SITE_URL') ?: 'http://localhost:5173'), '/');
        $link    = $siteUrl . '/reset-password?token=' . $token;
        $minutes = (int) ceil($ttl / 60);
        $name    = htmlspecialchars((string) ($user['name'] ?: 'there'), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#1e293b">
          <h2 style="margin:0 0 4px;color:#1d4ed8">Reset your Smilo password</h2>
          <p style="color:#64748b;margin:0 0 20px">Hi {$name}, we received a request to reset your password.</p>
          <p style="margin:0 0 24px">
            <a href="{$link}"
               style="display:inline-block;background:#f97316;color:#fff;text-decoration:none;font-weight:700;padding:13px 24px;border-radius:8px">
              Choose a new password
            </a>
          </p>
          <p style="font-size:13px;color:#64748b;margin:0 0 8px">This link expires in {$minutes} minute(s) and can only be used once.</p>
          <p style="font-size:13px;color:#64748b;margin:0 0 20px">If you didn't request this, you can safely ignore this email — your password stays unchanged.</p>
          <p style="font-size:12px;color:#94a3b8;word-break:break-all;margin:0">Button not working? Paste this into your browser:<br>{$link}</p>
        </div>
        HTML;

        Mailer::send((string) $user['email'], 'Reset your Smilo password', $html);

        // With no SMTP configured there is no inbox to check locally, so surface
        // the link in the response — strictly when debug is on.
        $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if ($debug && !Mailer::isConfigured()) {
            $response['debug_reset_link'] = $link;
            $response['debug_note'] = 'SMTP is not configured — the email was written to storage/logs/mail.log instead.';
        }

        \Flight::json($response);
    }

    /** POST /api/auth/reset-password {token, password} */
    public function resetPassword(): void
    {
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($token === '') {
            \Flight::json(['error' => 'Reset token is required'], 422);
            return;
        }
        if (strlen($password) < 6) {
            \Flight::json(['error' => 'Password must be at least 6 characters'], 422);
            return;
        }

        $stmt = $this->db->prepare('SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            \Flight::json(['error' => 'This reset link is invalid or has already been used.'], 400);
            return;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->db->prepare('DELETE FROM password_resets WHERE id = ?')->execute([(int) $row['id']]);
            \Flight::json(['error' => 'This reset link has expired. Please request a new one.'], 400);
            return;
        }

        $this->db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['user_id']]);

        // Single use — drop every token for this account
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int) $row['user_id']]);

        \Flight::json(['message' => 'Password updated. You can now sign in.']);
    }
}
