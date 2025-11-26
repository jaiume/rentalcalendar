<?php

namespace App\DAO;

use PDO;
use PDOException;

abstract class BaseDAO
{
    public function __construct(
        protected readonly PDO $db
    ) {
    }

    /**
     * Get the table name for this DAO
     */
    abstract protected function getTableName(): string;

    /**
     * Get the primary key column name for this DAO
     */
    abstract protected function getPrimaryKey(): string;

    /**
     * Find a record by ID
     */
    public function findById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->getTableName()} WHERE {$this->getPrimaryKey()} = :id"
            );
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to find record by ID: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a record by ID
     */
    public function deleteById(int $id): bool
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM {$this->getTableName()} WHERE {$this->getPrimaryKey()} = :id"
            );
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to delete record: " . $e->getMessage(), 0, $e);
        }
    }
}
