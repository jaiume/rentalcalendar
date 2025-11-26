<?php

namespace App\DAO;

use PDO;
use PDOException;

class UserPropertyPermissionDAO extends BaseDAO
{
    protected function getTableName(): string
    {
        return 'user_property_permissions';
    }

    protected function getPrimaryKey(): string
    {
        return 'user_id'; // Composite key, but we'll handle it specially
    }

    /**
     * Get all permissions for a specific user
     */
    public function getPermissionsForUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT property_id, can_view_calendar, can_create_reservation, 
                        can_add_cleaning, can_add_maintenance
                 FROM user_property_permissions 
                 WHERE user_id = :user_id
                 ORDER BY property_id'
            );
            $stmt->execute(['user_id' => $userId]);
            $permissions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $permissions[$row['property_id']] = $row;
            }
            return $permissions;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch user permissions: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Set permission for a user and property
     */
    public function setPermission(
        int $userId,
        int $propertyId,
        bool $canViewCalendar,
        bool $canCreateReservation,
        bool $canAddCleaning,
        bool $canAddMaintenance
    ): bool {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO user_property_permissions 
                 (user_id, property_id, can_view_calendar, can_create_reservation, 
                  can_add_cleaning, can_add_maintenance)
                 VALUES (:user_id, :property_id, :can_view_calendar, :can_create_reservation,
                         :can_add_cleaning, :can_add_maintenance)
                 ON DUPLICATE KEY UPDATE
                    can_view_calendar = VALUES(can_view_calendar),
                    can_create_reservation = VALUES(can_create_reservation),
                    can_add_cleaning = VALUES(can_add_cleaning),
                    can_add_maintenance = VALUES(can_add_maintenance)'
            );
            
            $stmt->execute([
                'user_id' => $userId,
                'property_id' => $propertyId,
                'can_view_calendar' => $canViewCalendar ? 1 : 0,
                'can_create_reservation' => $canCreateReservation ? 1 : 0,
                'can_add_cleaning' => $canAddCleaning ? 1 : 0,
                'can_add_maintenance' => $canAddMaintenance ? 1 : 0,
            ]);
            
            return true;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to set permission: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Remove all permissions for a user and property
     */
    public function removePermission(int $userId, int $propertyId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM user_property_permissions 
                 WHERE user_id = :user_id AND property_id = :property_id'
            );
            $stmt->execute([
                'user_id' => $userId,
                'property_id' => $propertyId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to remove permission: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if user has specific permission for a property
     */
    public function hasPermission(int $userId, int $propertyId, string $permissionType): bool
    {
        $validPermissions = [
            'can_view_calendar',
            'can_create_reservation',
            'can_add_cleaning',
            'can_add_maintenance'
        ];

        if (!in_array($permissionType, $validPermissions)) {
            throw new \InvalidArgumentException("Invalid permission type: {$permissionType}");
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT {$permissionType} FROM user_property_permissions 
                 WHERE user_id = :user_id AND property_id = :property_id"
            );
            $stmt->execute([
                'user_id' => $userId,
                'property_id' => $propertyId,
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result[$permissionType] == 1;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to check permission: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all properties a user has any permission for
     */
    public function getPropertiesForUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT p.property_id, p.property_name, p.timezone
                 FROM properties p
                 INNER JOIN user_property_permissions upp ON p.property_id = upp.property_id
                 WHERE upp.user_id = :user_id
                 ORDER BY p.property_name'
            );
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to fetch user properties: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update all permissions for a user at once
     * This is useful for the permissions form where we update all properties at once
     */
    public function updateAllPermissionsForUser(int $userId, array $permissions): bool
    {
        try {
            $this->db->beginTransaction();

            // First, delete all existing permissions for this user
            $stmt = $this->db->prepare('DELETE FROM user_property_permissions WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);

            // Then insert the new permissions
            $insertStmt = $this->db->prepare(
                'INSERT INTO user_property_permissions 
                 (user_id, property_id, can_view_calendar, can_create_reservation, 
                  can_add_cleaning, can_add_maintenance)
                 VALUES (:user_id, :property_id, :can_view_calendar, :can_create_reservation,
                         :can_add_cleaning, :can_add_maintenance)'
            );

            foreach ($permissions as $propertyId => $perms) {
                // Only insert if at least one permission is granted
                if (!empty($perms['can_view_calendar']) || 
                    !empty($perms['can_create_reservation']) || 
                    !empty($perms['can_add_cleaning']) || 
                    !empty($perms['can_add_maintenance'])) {
                    
                    $insertStmt->execute([
                        'user_id' => $userId,
                        'property_id' => $propertyId,
                        'can_view_calendar' => !empty($perms['can_view_calendar']) ? 1 : 0,
                        'can_create_reservation' => !empty($perms['can_create_reservation']) ? 1 : 0,
                        'can_add_cleaning' => !empty($perms['can_add_cleaning']) ? 1 : 0,
                        'can_add_maintenance' => !empty($perms['can_add_maintenance']) ? 1 : 0,
                    ]);
                }
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new \RuntimeException("Failed to update user permissions: " . $e->getMessage(), 0, $e);
        }
    }
}

