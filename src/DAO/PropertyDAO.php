<?php

namespace App\DAO;

use PDO;
use PDOException;

class PropertyDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'properties';
    }

    protected function getPrimaryKey(): string
    {
        return 'property_id';
    }

    /**
     * Get all properties ordered by name
     */
    public function findAll(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT property_id, property_name, timezone, cleaner_tails FROM properties ORDER BY property_name'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch properties: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all properties with all columns
     */
    public function findAllWithAllColumns(): array
    {
        try {
            $stmt = $this->db->query('SELECT * FROM properties ORDER BY property_name');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch properties: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new property
     */
    public function create(string $propertyName, string $timezone = 'UTC', bool $cleanerTails = false): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO properties (property_name, timezone, cleaner_tails) VALUES (:name, :timezone, :cleaner_tails)'
            );
            $stmt->execute([
                'name' => $propertyName,
                'timezone' => $timezone,
                'cleaner_tails' => $cleanerTails ? 1 : 0,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create property: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update a property
     */
    public function update(int $propertyId, string $propertyName, string $timezone = 'UTC', bool $cleanerTails = false): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE properties 
                 SET property_name = :name, timezone = :timezone, cleaner_tails = :cleaner_tails
                 WHERE property_id = :id'
            );
            $stmt->execute([
                'id' => $propertyId,
                'name' => $propertyName,
                'timezone' => $timezone,
                'cleaner_tails' => $cleanerTails ? 1 : 0,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update property: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find property by calendar export GUID
     */
    public function findByExportGuid(string $guid): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM properties WHERE calendar_export_guid = :guid'
            );
            $stmt->execute(['guid' => $guid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to find property by export GUID: " . $e->getMessage(), 0, $e);
        }
    }
}
