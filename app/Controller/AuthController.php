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
        \Flight::json(['data' => $user]);
    }
}
