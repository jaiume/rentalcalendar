<?php

namespace App\DAO;

use DateTime;
use PDO;
use PDOException;

class LoginCodeDAO
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Delete existing login codes for a user
     */
    public function deleteByUserId(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM login_codes WHERE user_id = :user_id'
            );
            $stmt->execute(['user_id' => $userId]);
            return true;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to delete login codes: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new login code
     */
    public function create(
        int $userId,
        string $hashedCode,
        string $hashedToken,
        DateTime $codeExpiry,
        DateTime $tokenExpiry
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO login_codes (user_id, code, token, code_expiry, token_expiry, created_at)
                 VALUES (:user_id, :code, :token, :code_expiry, :token_expiry, NOW())'
            );
            $stmt->execute([
                'user_id' => $userId,
                'code' => $hashedCode,
                'token' => $hashedToken,
                'code_expiry' => $codeExpiry->format('Y-m-d H:i:s'),
                'token_expiry' => $tokenExpiry->format('Y-m-d H:i:s'),
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create login code: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find all non-expired login codes
     */
    public function findAllNonExpired(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT lc.login_code_id, lc.code, lc.token, lc.user_id, lc.code_expiry, lc.token_expiry
                 FROM login_codes lc
                 WHERE lc.code_expiry > NOW() OR lc.token_expiry > NOW()
                 ORDER BY lc.created_at DESC'
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch login codes: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find non-expired login codes for code verification
     */
    public function findNonExpiredCodes(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT lc.login_code_id, lc.code, lc.token, lc.user_id, lc.code_expiry
                 FROM login_codes lc
                 WHERE lc.code_expiry > NOW()
                 ORDER BY lc.created_at DESC'
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch login codes: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find non-expired login codes for token verification
     */
    public function findNonExpiredTokens(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT lc.login_code_id, lc.token, lc.user_id, lc.token_expiry
                 FROM login_codes lc
                 WHERE lc.token_expiry > NOW()
                 ORDER BY lc.created_at DESC'
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch login tokens: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a login code by ID
     */
    public function deleteById(int $loginCodeId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM login_codes WHERE login_code_id = :login_code_id'
            );
            $stmt->execute(['login_code_id' => $loginCodeId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to delete login code: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean up expired codes and tokens
     */
    public function cleanupExpired(): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM login_codes WHERE code_expiry <= NOW() AND token_expiry <= NOW()'
            );
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to cleanup expired codes: " . $e->getMessage(), 0, $e);
        }
    }
}
