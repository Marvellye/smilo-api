<?php

declare(strict_types=1);

namespace Smilo\Helper;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth
{
    private static string $secret = '';
    private static int $expiry = 86400;

    public static function init(): void
    {
        self::$secret = getenv('JWT_SECRET') ?: 'change-me';
        self::$expiry = (int) (getenv('JWT_EXPIRY') ?: 86400);
    }

    public static function generateToken(array $payload): string
    {
        self::init();
        $now = time();
        $claims = array_merge([
            'iat' => $now,
            'exp' => $now + self::$expiry,
        ], $payload);
        return JWT::encode($claims, self::$secret, 'HS256');
    }

    public static function decode(string $token): ?array
    {
        self::init();
        try {
            $decoded = JWT::decode($token, new Key(self::$secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function getUserFromRequest(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = substr($header, 7);
        return self::decode($token);
    }
}
