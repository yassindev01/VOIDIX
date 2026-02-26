<?php
// src/Auth.php

namespace App;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Exception;

class Auth
{
    private $db;
    private $jwtSecret;

    public function __construct(Database $db)
    {
        $this->db = $db->getConnection();
        $config = Config::getInstance();
        $this->jwtSecret = $config->get('JWT_SECRET');

        if (empty($this->jwtSecret)) {
            throw new Exception('JWT_SECRET not configured in .env');
        }
    }

    public function generateJwtToken(int $userId, string $telegramSessionFile): string
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + (60 * 60 * 24 * 7);

        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $expirationTime,
            'uid'  => $userId,
            'session' => basename($telegramSessionFile)
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    public function validateJwtToken(string $jwt): ?object
    {
        try {
            $decoded = JWT::decode($jwt, new Key($this->jwtSecret, 'HS256'));
            return $decoded;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Finds or creates a user, now including api_id and api_hash.
     */
    public function findOrCreateUser(array $telegramUser, int $apiId = null, string $apiHash = null): int
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegramUser['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $stmt = $this->db->prepare(
                "UPDATE users SET api_id = ?, api_hash = ?, first_name = ?, last_name = ?, username = ?, phone_number = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([
                $apiId,
                $apiHash,
                $telegramUser['first_name'] ?? null,
                $telegramUser['last_name'] ?? null,
                $telegramUser['username'] ?? null,
                $telegramUser['phone'] ?? null,
                $user['id']
            ]);
            return $user['id'];
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO users (telegram_id, api_id, api_hash, first_name, last_name, username, phone_number) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $telegramUser['id'],
                $apiId,
                $apiHash,
                $telegramUser['first_name'] ?? null,
                $telegramUser['last_name'] ?? null,
                $telegramUser['username'] ?? null,
                $telegramUser['phone'] ?? null
            ]);
            return $this->db->lastInsertId();
        }
    }

    public function createSession(int $userId, string $telegramSessionFile, string $jwtToken, int $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO sessions (user_id, session_hash, jwt_refresh_token, telegram_session_file, expires_at) VALUES (?, ?, ?, ?, ?)"
        );
        return $stmt->execute([
            $userId,
            $jwtToken,
            $jwtToken,
            $telegramSessionFile,
            date('Y-m-d H:i:s', $expiresAt)
        ]);
    }

    public function invalidateSession(string $sessionHash): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE session_hash = ?");
        return $stmt->execute([$sessionHash]);
    }

    public function getSessionByHash(string $sessionHash): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sessions WHERE session_hash = ?");
        $stmt->execute([$sessionHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
