<?php

namespace App\DAO;

use PDO;
use PDOException;

class PortalGroupDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'portal_groups';
    }

    protected function getPrimaryKey(): string
    {
        return 'portal_group_id';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        try {
            $stmt = $this->db->query('SELECT * FROM portal_groups ORDER BY name');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch portal groups: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(): array
    {
        try {
            $stmt = $this->db->query('SELECT * FROM portal_groups WHERE is_active = 1 ORDER BY name');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch active portal groups: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findBySlug(string $slug): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM portal_groups WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to find portal group by slug: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findByGuestHostname(string $hostname): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM portal_groups WHERE guest_hostname = :host AND is_active = 1'
            );
            $stmt->execute(['host' => $hostname]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to find portal group by hostname: ' . $e->getMessage(), 0, $e);
        }
    }

    public function create(string $slug, string $name, ?string $guestHostname, bool $isActive): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO portal_groups (slug, name, guest_hostname, is_active)
                 VALUES (:slug, :name, :guest_hostname, :is_active)'
            );
            $stmt->execute([
                'slug' => $slug,
                'name' => $name,
                'guest_hostname' => $guestHostname,
                'is_active' => $isActive ? 1 : 0,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to create portal group: ' . $e->getMessage(), 0, $e);
        }
    }

    public function update(int $id, string $slug, string $name, ?string $guestHostname, bool $isActive): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE portal_groups
                    SET slug = :slug,
                        name = :name,
                        guest_hostname = :guest_hostname,
                        is_active = :is_active
                  WHERE portal_group_id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'guest_hostname' => $guestHostname,
                'is_active' => $isActive ? 1 : 0,
            ]);
            return $stmt->rowCount() >= 0;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to update portal group: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findProperties(int $portalGroupId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT p.*
                   FROM properties p
                   JOIN portal_group_properties pgp ON pgp.property_id = p.property_id
                  WHERE pgp.portal_group_id = :id
                  ORDER BY p.property_name'
            );
            $stmt->execute(['id' => $portalGroupId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch portal-group properties: ' . $e->getMessage(), 0, $e);
        }
    }

    public function assignProperty(int $portalGroupId, int $propertyId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO portal_group_properties (portal_group_id, property_id)
                 VALUES (:portal_group_id, :property_id)'
            );
            $stmt->execute([
                'portal_group_id' => $portalGroupId,
                'property_id' => $propertyId,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to assign property to portal group: ' . $e->getMessage(), 0, $e);
        }
    }

    public function unassignProperty(int $portalGroupId, int $propertyId): void
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM portal_group_properties
                  WHERE portal_group_id = :portal_group_id AND property_id = :property_id'
            );
            $stmt->execute([
                'portal_group_id' => $portalGroupId,
                'property_id' => $propertyId,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to unassign property from portal group: ' . $e->getMessage(), 0, $e);
        }
    }

    public function propertyBelongsToGroup(int $propertyId, int $portalGroupId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM portal_group_properties
                  WHERE portal_group_id = :portal_group_id AND property_id = :property_id'
            );
            $stmt->execute([
                'portal_group_id' => $portalGroupId,
                'property_id' => $propertyId,
            ]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to check property/portal-group membership: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Replace the property assignments for a portal group in one transaction.
     *
     * @param int[] $propertyIds
     */
    public function setProperties(int $portalGroupId, array $propertyIds): void
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare(
                'DELETE FROM portal_group_properties WHERE portal_group_id = :id'
            );
            $del->execute(['id' => $portalGroupId]);

            if (!empty($propertyIds)) {
                $ins = $this->db->prepare(
                    'INSERT IGNORE INTO portal_group_properties (portal_group_id, property_id)
                     VALUES (:portal_group_id, :property_id)'
                );
                foreach ($propertyIds as $propertyId) {
                    $ins->execute([
                        'portal_group_id' => $portalGroupId,
                        'property_id' => (int) $propertyId,
                    ]);
                }
            }

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Failed to set portal-group properties: ' . $e->getMessage(), 0, $e);
        }
    }
}
