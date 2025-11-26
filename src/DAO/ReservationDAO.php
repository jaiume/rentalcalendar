<?php

namespace App\DAO;

use PDO;
use PDOException;

class ReservationDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'reservations';
    }

    protected function getPrimaryKey(): string
    {
        return 'reservation_id';
    }

    /**
     * Find reservation by GUID
     */
    public function findByGuid(string $guid): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT reservation_id, reservation_status, reservation_start_date, reservation_end_date 
                 FROM reservations 
                 WHERE reservation_guid = :uid'
            );
            $stmt->execute(['uid' => $guid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to find reservation by GUID: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get reservations within date range
     */
    public function findByDateRange(string $startDate, string $endDate, ?int $propertyId = null): array
    {
        try {
            $query = 'SELECT 
                reservation_id,
                property_id,
                reservation_name,
                reservation_description,
                reservation_start_date,
                reservation_start_time,
                reservation_end_date,
                reservation_end_time,
                reservation_status,
                source,
                sync_partner_name,
                NULL as partner_color
                FROM reservations
                WHERE reservation_start_date <= :end_date 
                AND reservation_end_date >= :start_date
                AND reservation_status != "cancelled"';
            
            $params = [':start_date' => $startDate, ':end_date' => $endDate];
            
            if ($propertyId) {
                $query .= ' AND property_id = :property_id';
                $params[':property_id'] = $propertyId;
            }
            
            $query .= ' ORDER BY reservation_start_date, reservation_start_time';
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch reservations: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new reservation
     */
    public function create(
        int $propertyId,
        ?int $userId,
        string $source,
        string $guid,
        string $status,
        string $name,
        ?string $description,
        string $startDate,
        string $startTime,
        string $endDate,
        string $endTime,
        ?string $syncPartnerName = null
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO reservations 
                (property_id, user_id, source, reservation_guid, reservation_status, reservation_name, 
                 reservation_description, reservation_start_date, reservation_start_time, 
                 reservation_end_date, reservation_end_time, sync_partner_name)
                VALUES (:property_id, :user_id, :source, :guid, :status, :name, 
                        :description, :start_date, :start_time, :end_date, :end_time, :sync_partner_name)'
            );
            
            $stmt->execute([
                'property_id' => $propertyId,
                'user_id' => $userId,
                'source' => $source,
                'guid' => $guid,
                'status' => $status,
                'name' => $name,
                'description' => $description,
                'start_date' => $startDate,
                'start_time' => $startTime,
                'end_date' => $endDate,
                'end_time' => $endTime,
                'sync_partner_name' => $syncPartnerName,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create reservation: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update reservation dates and details
     */
    public function updateDatesAndDetails(
        int $reservationId,
        string $startDate,
        string $endDate,
        string $summary,
        ?string $description = null
    ): bool {
        try {
            $stmt = $this->db->prepare(
                'UPDATE reservations 
                 SET reservation_start_date = :start,
                     reservation_end_date = :end,
                     reservation_name = :summary,
                     reservation_description = :description,
                     sync_partner_last_checked = NOW(),
                     updated_at = NOW()
                 WHERE reservation_id = :id'
            );
            $stmt->execute([
                'start' => $startDate,
                'end' => $endDate,
                'summary' => $summary,
                'description' => $description,
                'id' => $reservationId
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update reservation: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update last checked time for sync partner reservations
     */
    public function updateLastChecked(int $reservationId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE reservations SET sync_partner_last_checked = NOW() WHERE reservation_id = :id'
            );
            $stmt->execute(['id' => $reservationId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update reservation last checked: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get internal reservations for export (only internal, not cancelled)
     */
    public function findInternalForExport(int $propertyId): array
    {
        try {
            $query = 'SELECT 
                reservation_id,
                reservation_name,
                reservation_description,
                reservation_start_date,
                reservation_start_time,
                reservation_end_date,
                reservation_end_time,
                reservation_guid
                FROM reservations
                WHERE property_id = :property_id
                AND source = "internal"
                AND reservation_status != "cancelled"
                ORDER BY reservation_start_date, reservation_start_time';
            
            $stmt = $this->db->prepare($query);
            $stmt->execute(['property_id' => $propertyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch internal reservations for export: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete reservations for a property and sync partner that are not in the provided list of GUIDs
     */
    public function deleteNotInGuidList(int $propertyId, string $syncPartnerName, array $guids): int
    {
        if (empty($guids)) {
            // If no GUIDs provided, delete all for this property and partner
            try {
                $stmt = $this->db->prepare(
                    'DELETE FROM reservations 
                     WHERE property_id = :property_id 
                     AND source = "sync_partner" 
                     AND sync_partner_name = :sync_partner_name'
                );
                $stmt->execute([
                    'property_id' => $propertyId,
                    'sync_partner_name' => $syncPartnerName
                ]);
                return $stmt->rowCount();
            } catch (PDOException $e) {
                throw new \RuntimeException("Failed to delete old reservations: " . $e->getMessage(), 0, $e);
            }
        }

        try {
            // Create placeholders for IN clause
            $placeholders = [];
            $params = [
                'property_id' => $propertyId,
                'sync_partner_name' => $syncPartnerName
            ];
            
            foreach ($guids as $index => $guid) {
                $key = ':guid' . $index;
                $placeholders[] = $key;
                $params[$key] = $guid;
            }
            
            $placeholdersStr = implode(',', $placeholders);
            
            $stmt = $this->db->prepare(
                "DELETE FROM reservations 
                 WHERE property_id = :property_id 
                 AND source = 'sync_partner' 
                 AND sync_partner_name = :sync_partner_name
                 AND reservation_guid NOT IN ($placeholdersStr)"
            );
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to delete old reservations: " . $e->getMessage(), 0, $e);
        }
    }
}
