<?php

namespace App\Services;

use DateTime;
use PDO;

class AuthenticationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly ConfigService $config
    ) {
    }

    private function getUserByEmail(string $email): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT user_id FROM users WHERE email = :email'
            );
            $stmt->execute(['email' => $email]);

            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return (int) $user['user_id'];
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function generateToken(string $email): ?string
    {
        try {
            $userId = $this->getUserByEmail($email);
            if (!$userId) {
                throw new \RuntimeException('User not found');
            }

            $tokenLength = (int) $this->config::get('auth.token_length', 32);
            $token = bin2hex(random_bytes(max(16, $tokenLength / 2)));

            $expirySeconds = (int) $this->config::get('auth.token_expiry', 604800);
            $expiry = (new DateTime())->modify("+{$expirySeconds} seconds");

            $stmt = $this->db->prepare(
                'INSERT INTO auth_tokens (user_id, token, expiry)
                 VALUES (:user_id, :token, :expiry)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'token' => password_hash($token, PASSWORD_DEFAULT),
                'expiry' => $expiry->format('Y-m-d H:i:s'),
            ]);

            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function verifyToken(string $token): ?array
    {
        try {
            $this->cleanupExpiredTokens();

            $stmt = $this->db->prepare(
                'SELECT t.token, t.expiry, t.user_id, u.email, u.name, u.is_admin, u.unifi_site_name
                 FROM auth_tokens t
                 JOIN users u ON t.user_id = u.user_id
                 WHERE t.expiry > NOW()
                 ORDER BY t.created_at DESC'
            );
            $stmt->execute();

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token'])) {
                    return [
                        'user_id' => $row['user_id'],
                        'email' => $row['email'],
                        'name' => $row['name'],
                        'is_admin' => $row['is_admin'],
                        'unifi_site_name' => $row['unifi_site_name'],
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function extendTokenExpiry(string $token): bool
    {
        try {
            $expirySeconds = (int) $this->config::get('auth.token_expiry', 604800);
            $newExpiry = (new DateTime())->modify("+{$expirySeconds} seconds");

            $stmt = $this->db->prepare(
                'UPDATE auth_tokens SET expiry = :expiry WHERE token = :token'
            );

            return $stmt->execute([
                'expiry' => $newExpiry->format('Y-m-d H:i:s'),
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function cleanupExpiredTokens(): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM auth_tokens WHERE expiry <= NOW()'
        );
        $stmt->execute();
    }

    public function deleteToken(string $token): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM auth_tokens WHERE token = :token'
        );
        $stmt->execute(['token' => $token]);
    }
}

