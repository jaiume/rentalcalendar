<?php

namespace App\DAO;

use PDO;
use PDOException;

class MaintenanceDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'property_maintenance';
    }

    protected function getPrimaryKey(): string
    {
        return 'property_maintenance_id';
    }

    /**
     * Get maintenance records within date range
     */
    public function findByDateRange(string $startDate, string $endDate, ?int $propertyId = null): array
    {
        try {
            $query = 'SELECT 
                property_maintenance_id,
                property_id,
                maintenance_start_date,
                maintenance_end_date,
                maintenance_description,
                maintenance_type
                FROM property_maintenance
                WHERE maintenance_start_date <= :end_date 
                AND maintenance_end_date >= :start_date';
            
            $params = [':start_date' => $startDate, ':end_date' => $endDate];
            
            if ($propertyId) {
                $query .= ' AND property_id = :property_id';
                $params[':property_id'] = $propertyId;
            }
            
            $query .= ' ORDER BY maintenance_start_date';
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch maintenance records: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get maintenance records for export
     */
    public function findForExport(int $propertyId): array
    {
        try {
            $query = 'SELECT 
                property_maintenance_id,
                maintenance_start_date,
                maintenance_end_date,
                maintenance_description,
                maintenance_type
                FROM property_maintenance
                WHERE property_id = :property_id
                ORDER BY maintenance_start_date';
            
            $stmt = $this->db->prepare($query);
            $stmt->execute(['property_id' => $propertyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch maintenance for export: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new maintenance record
     */
    public function create(
        int $propertyId,
        string $startDate,
        string $endDate,
        string $description,
        ?string $maintenanceType = null,
        ?int $createdBy = null
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO property_maintenance 
                (property_id, maintenance_start_date, maintenance_end_date, 
                 maintenance_description, maintenance_type, created_by)
                VALUES (:property_id, :start_date, :end_date, :description, :type, :created_by)'
            );
            
            $stmt->execute([
                'property_id' => $propertyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'description' => $description,
                'type' => $maintenanceType,
                'created_by' => $createdBy,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create maintenance record: " . $e->getMessage(), 0, $e);
        }
    }
}
