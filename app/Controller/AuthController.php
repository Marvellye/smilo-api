<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;

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
        if ($shopName === '' || $location === '') {
            \Flight::json(['error' => 'Shop name and location are required'], 422);
            return;
        }

        $stmt = $this->db->prepare('SELECT phone FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $userPhone = $stmt->fetchColumn();

        $stmt = $this->db->prepare('INSERT INTO sellers (name, location, phone, user_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$shopName, $location, $data['phone'] ?? ($userPhone ?: null), $userId]);
        $sellerId = (int) $this->db->lastInsertId();

        $sp = $this->db->prepare('INSERT INTO seller_profiles (user_id, shop_name, location, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), location = VALUES(location), phone = VALUES(phone)');
        try {
            $sp->execute([$userId, $shopName, $location, $data['phone'] ?? ($userPhone ?: null)]);
        } catch (\PDOException $e) {
            // SQLite has no ON DUPLICATE KEY — upsert manually
            $chk = $this->db->prepare('SELECT id FROM seller_profiles WHERE user_id = ?');
            $chk->execute([$userId]);
            if ($chk->fetch()) {
                $upd = $this->db->prepare('UPDATE seller_profiles SET shop_name = ?, location = ?, phone = ? WHERE user_id = ?');
                $upd->execute([$shopName, $location, $data['phone'] ?? ($userPhone ?: null), $userId]);
            } else {
                $ins = $this->db->prepare('INSERT INTO seller_profiles (user_id, shop_name, location, phone) VALUES (?, ?, ?, ?)');
                $ins->execute([$userId, $shopName, $location, $data['phone'] ?? ($userPhone ?: null)]);
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
}
