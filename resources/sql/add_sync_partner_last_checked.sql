-- Add sync_partner_last_checked column to reservations table
-- This column tracks when sync partner reservations were last checked during sync

ALTER TABLE reservations 
ADD COLUMN sync_partner_last_checked DATETIME NULL AFTER sync_partner_id;




