<?php

namespace App\DAO;

use PDO;
use PDOException;

class CleaningDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'property_cleaning';
    }

    protected function getPrimaryKey(): string
    {
        return 'property_cleaning_id';
    }

    /**
     * Get cleaning records within date range
     */
    public function findByDateRange(string $startDate, string $endDate, ?int $propertyId = null): array
    {
        try {
            $query = 'SELECT 
                pc.property_cleaning_id,
                pc.property_id,
                pc.cleaning_date,
                pc.cleaning_window,
                pc.cleaner_id,
                pc.notes,
                c.cleaner_initials
                FROM property_cleaning pc
                LEFT JOIN cleaners c ON pc.cleaner_id = c.cleaner_id
                WHERE pc.cleaning_date >= :start_date AND pc.cleaning_date <= :end_date';
            
            $params = [':start_date' => $startDate, ':end_date' => $endDate];
            
            if ($propertyId) {
                $query .= ' AND pc.property_id = :property_id';
                $params[':property_id'] = $propertyId;
            }
            
            $query .= ' ORDER BY pc.cleaning_date';
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch cleaning records: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new cleaning record
     */
    public function create(
        int $propertyId,
        string $cleaningDate,
        ?int $cleanerId = null,
        ?string $cleaningWindow = null,
        ?string $notes = null,
        ?int $createdBy = null
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO property_cleaning 
                (property_id, cleaning_date, cleaner_id, cleaning_window, notes, created_by)
                VALUES (:property_id, :cleaning_date, :cleaner_id, :cleaning_window, :notes, :created_by)'
            );
            
            $stmt->execute([
                'property_id' => $propertyId,
                'cleaning_date' => $cleaningDate,
                'cleaner_id' => $cleanerId,
                'cleaning_window' => $cleaningWindow,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create cleaning record: " . $e->getMessage(), 0, $e);
        }
    }
}
