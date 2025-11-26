<?php

namespace App\DAO;

use PDO;
use PDOException;

class PropertyCalendarImportLinkDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'property_calendar_import_links';
    }

    protected function getPrimaryKey(): string
    {
        return 'property_calendar_import_link_id';
    }

    /**
     * Get all active import links
     */
    public function findAllActive(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT property_id, sync_partner_name, import_link_url, last_fetch_at
                 FROM property_calendar_import_links 
                 WHERE is_active = 1'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch import links: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all import links with property names
     */
    public function findAllWithPropertyNames(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT pcl.*, p.property_name
                 FROM property_calendar_import_links pcl
                 JOIN properties p ON pcl.property_id = p.property_id
                 ORDER BY p.property_name, pcl.sync_partner_name'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch import links: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new import link
     */
    public function create(
        int $propertyId,
        string $syncPartnerName,
        string $importLinkUrl,
        bool $isActive = true
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO property_calendar_import_links (property_id, sync_partner_name, import_link_url, is_active)
                 VALUES (:property_id, :sync_partner_name, :url, :is_active)'
            );
            $stmt->execute([
                'property_id' => $propertyId,
                'sync_partner_name' => $syncPartnerName,
                'url' => $importLinkUrl,
                'is_active' => $isActive ? 1 : 0,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create import link: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update link status and last fetch time
     */
    public function updateLinkStatus(int $propertyId, string $url, string $status): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE property_calendar_import_links 
                 SET last_fetch_status = :status, 
                     last_fetch_at = NOW() 
                 WHERE property_id = :property_id AND import_link_url = :url'
            );
            $stmt->execute([
                'status' => substr($status, 0, 50),
                'property_id' => $propertyId,
                'url' => $url
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to update link status: " . $e->getMessage(), 0, $e);
        }
    }
}
