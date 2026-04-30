-- Generic payments audit table. Designed so future paid services slot in
-- without schema churn (item_type / item_reference / description).
-- Laundry access is the first item_type; future examples might be
-- 'late_checkout', 'parking', 'extra_cleaning', etc.
-- Sensitive data (e.g. the laundry padlock combination) is NEVER stored
-- here; it lives in the per-portal JSON config.
CREATE TABLE IF NOT EXISTS payments (
    payment_id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    portal_group_id        BIGINT NOT NULL,
    item_type              VARCHAR(64) NOT NULL COMMENT 'Machine code identifying what was paid for, e.g. laundry_access',
    item_reference         VARCHAR(128) NULL COMMENT 'Optional domain reference, e.g. property_id for laundry',
    description            VARCHAR(255) NULL COMMENT 'Human-readable label captured at payment time',
    paypal_order_id        VARCHAR(64) NULL UNIQUE COMMENT 'NULL allowed for future non-PayPal providers',
    provider               VARCHAR(32) NOT NULL DEFAULT 'paypal',
    amount_cents           INT NOT NULL,
    currency               CHAR(3) NOT NULL,
    status                 VARCHAR(16) NOT NULL
                           CHECK (status IN ('created','completed','failed','refunded')),
    payer_email            VARCHAR(255) NULL,
    ip_address             VARCHAR(45) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at           DATETIME NULL,
    INDEX idx_payments_group_item_status (portal_group_id, item_type, status),
    INDEX idx_payments_created (created_at),
    FOREIGN KEY (portal_group_id) REFERENCES portal_groups(portal_group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
