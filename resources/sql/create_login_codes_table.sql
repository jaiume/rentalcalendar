-- Create login_codes table for email-based authentication
-- This table stores 6-digit codes and login tokens for passwordless authentication

CREATE TABLE IF NOT EXISTS login_codes (
    login_code_id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id              BIGINT NOT NULL,
    code                 VARCHAR(255) NOT NULL COMMENT 'Hashed 6-digit code',
    token                VARCHAR(255) NOT NULL COMMENT 'Hashed token for direct link login',
    code_expiry          DATETIME NOT NULL COMMENT 'Code expires in 15 minutes',
    token_expiry         DATETIME NOT NULL COMMENT 'Token expires in 1 week',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (user_id),
    INDEX idx_code_expiry (code_expiry),
    INDEX idx_token_expiry (token_expiry),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



