-- Remove expiry column from auth_tokens table
-- Expiry is now calculated from created_at + auth_token_expiry config value

ALTER TABLE auth_tokens DROP COLUMN expiry;




