<?php

namespace App\DAO;

use PDO;
use PDOException;

class UserDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'users';
    }

    protected function getPrimaryKey(): string
    {
        return 'user_id';
    }

    /**
     * Find user by email address
     */
    public function findByEmail(string $email): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT user_id FROM users WHERE emailaddress = :email LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to find user by email: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get user ID by email
     */
    public function getUserIdByEmail(string $email): ?int
    {
        $user = $this->findByEmail($email);
        return $user ? (int) $user['user_id'] : null;
    }

    /**
     * Check if user exists by email
     */
    public function userExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * Get all users ordered by email
     */
    public function findAll(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT user_id, emailaddress, display_name, is_admin, is_active 
                 FROM users 
                 ORDER BY emailaddress'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch users: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new user
     */
    public function create(string $email, ?string $displayName = null, bool $isAdmin = false, bool $isActive = true): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (emailaddress, display_name, is_admin, is_active)
                 VALUES (:email, :display_name, :is_admin, :is_active)'
            );
            $stmt->execute([
                'email' => $email,
                'display_name' => $displayName,
                'is_admin' => $isAdmin ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create user: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a user
     */
    public function update(int $userId, ?string $displayName = null, ?bool $isAdmin = null): bool
    {
        try {
            $updates = [];
            $params = ['id' => $userId];

            if ($displayName !== null) {
                $updates[] = 'display_name = :display_name';
                $params['display_name'] = $displayName;
            }

            if ($isAdmin !== null) {
                $updates[] = 'is_admin = :is_admin';
                $params['is_admin'] = $isAdmin ? 1 : 0;
            }

            if (empty($updates)) {
                return false;
            }

            $stmt = $this->db->prepare(
                'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = :id'
            );
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update user: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Toggle user active status
     */
    public function toggleActive(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE users SET is_active = NOT is_active WHERE user_id = :id'
            );
            $stmt->execute(['id' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to toggle user status: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get user with all details for authentication
     */
    public function findByIdWithDetails(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT user_id, emailaddress, display_name, is_admin, is_active 
                 FROM users 
                 WHERE user_id = :id'
            );
            $stmt->execute(['id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to find user: " . $e->getMessage(), 0, $e);
        }
    }
}
