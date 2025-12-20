-- Add is_orphaned column to reservations table
-- This column tracks when sync partner reservations are no longer in the sync feed
-- but are kept for the configured retention period (keep_deleted_reservations_for)
-- Orphaned reservations display with a lighter color on the calendar

ALTER TABLE reservations 
ADD COLUMN is_orphaned TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Set to 1 when reservation is no longer in sync feed but kept for retention period'
AFTER sync_partner_name;



