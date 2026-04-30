<?php

namespace App\DAO;

use PDO;
use PDOException;

class SupplyRequestDAO extends BaseDAO
{
    public const VALID_STATUSES = ['pending', 'acknowledged', 'fulfilled', 'cancelled'];

    protected function getTableName(): string
    {
        return 'supply_requests';
    }

    protected function getPrimaryKey(): string
    {
        return 'supply_request_id';
    }

    public function create(int $portalGroupId, int $propertyId, string $requestText): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO supply_requests (portal_group_id, property_id, request_text, status)
                 VALUES (:portal_group_id, :property_id, :request_text, :status)'
            );
            $stmt->execute([
                'portal_group_id' => $portalGroupId,
                'property_id' => $propertyId,
                'request_text' => $requestText,
                'status' => 'pending',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to create supply request: ' . $e->getMessage(), 0, $e);
        }
    }

    public function updateStatus(int $supplyRequestId, string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid supply request status: ' . $status);
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE supply_requests SET status = :status WHERE supply_request_id = :id'
            );
            $stmt->execute(['status' => $status, 'id' => $supplyRequestId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to update supply request status: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{portal_group_id?: int, status?: string, property_id?: int, from?: string, to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForAudit(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['portal_group_id'])) {
            $where[] = 'sr.portal_group_id = :portal_group_id';
            $params['portal_group_id'] = (int) $filters['portal_group_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'sr.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['property_id'])) {
            $where[] = 'sr.property_id = :property_id';
            $params['property_id'] = (int) $filters['property_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'sr.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'sr.created_at <= :to';
            $params['to'] = $filters['to'];
        }

        $sql = 'SELECT sr.*, p.property_name, pg.name AS portal_group_name, pg.slug AS portal_group_slug
                  FROM supply_requests sr
                  JOIN properties p ON p.property_id = sr.property_id
                  JOIN portal_groups pg ON pg.portal_group_id = sr.portal_group_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sr.created_at DESC LIMIT 500';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch supply requests: ' . $e->getMessage(), 0, $e);
        }
    }
}
