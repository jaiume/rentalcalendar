-- Rename created_at to last_touched in auth_tokens table
-- This represents when the token was last successfully verified/used

ALTER TABLE auth_tokens 
CHANGE COLUMN created_at last_touched DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;







