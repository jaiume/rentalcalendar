-- Add maintenance_type column to property_maintenance table
-- This column stores the type/category of maintenance (e.g., "Scheduled Maintenance", "Emergency Repair", etc.)

ALTER TABLE property_maintenance 
ADD COLUMN maintenance_type VARCHAR(100) DEFAULT NULL 
COMMENT 'Type or category of maintenance'
AFTER maintenance_description;

