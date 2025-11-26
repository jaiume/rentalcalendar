<?php

namespace App\DAO;

use PDO;
use PDOException;

class CleanerDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'cleaners';
    }

    protected function getPrimaryKey(): string
    {
        return 'cleaner_id';
    }

    /**
     * Get all cleaners ordered by name
     */
    public function findAll(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT cleaner_id, cleaner_name, cleaner_initials, phone FROM cleaners ORDER BY cleaner_name'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch cleaners: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all cleaners with all columns
     */
    public function findAllWithAllColumns(): array
    {
        try {
            $stmt = $this->db->query('SELECT * FROM cleaners ORDER BY cleaner_name');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch cleaners: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new cleaner
     */
    public function create(string $cleanerName, string $cleanerInitials, ?string $phone = null): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO cleaners (cleaner_name, cleaner_initials, phone)
                 VALUES (:name, :initials, :phone)'
            );
            $stmt->execute([
                'name' => $cleanerName,
                'initials' => $cleanerInitials,
                'phone' => $phone,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create cleaner: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a cleaner
     */
    public function update(int $cleanerId, string $cleanerName, string $cleanerInitials, ?string $phone = null): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE cleaners 
                 SET cleaner_name = :name, cleaner_initials = :initials, phone = :phone
                 WHERE cleaner_id = :id'
            );
            $stmt->execute([
                'id' => $cleanerId,
                'name' => $cleanerName,
                'initials' => $cleanerInitials,
                'phone' => $phone,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update cleaner: " . $e->getMessage(), 0, $e);
        }
    }
}
