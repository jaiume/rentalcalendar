-- Remove password_hash column from users table
-- This system uses email-based login codes and links only

ALTER TABLE users DROP COLUMN password_hash;

