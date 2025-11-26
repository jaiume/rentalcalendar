<?php

namespace App\DAO;

use PDO;
use PDOException;

class AuthTokenDAO
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a new auth token
     */
    public function create(int $userId, string $hashedToken): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO auth_tokens (user_id, token)
                 VALUES (:user_id, :token)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'token' => $hashedToken,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create auth token: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find all non-expired auth tokens with user details
     */
    public function findAllNonExpiredWithUserDetails(int $expirySeconds): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT t.auth_token_id, t.token, t.user_id, t.last_touched,
                        u.emailaddress as email, 
                        u.display_name as name, 
                        u.is_admin
                 FROM auth_tokens t
                 JOIN users u ON t.user_id = u.user_id
                 WHERE DATE_ADD(t.last_touched, INTERVAL :expiry_seconds SECOND) > NOW()
                 ORDER BY t.last_touched DESC'
            );
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch auth tokens: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find all non-expired auth tokens (for token verification)
     */
    public function findAllNonExpired(int $expirySeconds): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT auth_token_id, token FROM auth_tokens 
                 WHERE DATE_ADD(last_touched, INTERVAL :expiry_seconds SECOND) > NOW()'
            );
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch auth tokens: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update last touched time for a token
     */
    public function updateLastTouched(int $authTokenId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE auth_tokens SET last_touched = NOW() WHERE auth_token_id = :auth_token_id'
            );
            $stmt->execute(['auth_token_id' => $authTokenId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update token: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete an auth token by ID
     */
    public function deleteById(int $authTokenId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM auth_tokens WHERE auth_token_id = :auth_token_id'
            );
            $stmt->execute(['auth_token_id' => $authTokenId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to delete auth token: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean up expired tokens
     */
    public function cleanupExpired(int $expirySeconds): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM auth_tokens WHERE DATE_ADD(last_touched, INTERVAL :expiry_seconds SECOND) <= NOW()'
            );
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            return true;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to cleanup expired tokens: " . $e->getMessage(), 0, $e);
        }
    }
}
