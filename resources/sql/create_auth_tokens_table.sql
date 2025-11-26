-- Create auth_tokens table for session management
-- This table stores authentication tokens for logged-in users
-- Expiry is calculated from last_touched + auth_token_expiry config value
-- last_touched is updated each time the token is successfully verified

CREATE TABLE IF NOT EXISTS auth_tokens (
    auth_token_id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT NOT NULL,
    token                  VARCHAR(255) NOT NULL COMMENT 'Hashed session token',
    last_touched           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_last_touched (last_touched),
    INDEX idx_token (token(64)),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

