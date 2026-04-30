-- Guest-submitted supply requests (towels, sheets, toilet paper, etc.)
-- request_text is built server-side from the chosen suggestion checkboxes
-- plus any free text the guest typed. The cleaning-schedule tie-in
-- (property_cleaning_id, etc.) is intentionally deferred to the future
-- staff portal session, which can ALTER this table without disrupting
-- v1 rows.
CREATE TABLE IF NOT EXISTS supply_requests (
    supply_request_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
    portal_group_id        BIGINT NOT NULL,
    property_id            BIGINT NOT NULL,
    request_text           TEXT NOT NULL,
    status                 VARCHAR(16) NOT NULL DEFAULT 'pending'
                           CHECK (status IN ('pending','acknowledged','fulfilled','cancelled')),
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supply_requests_group_status (portal_group_id, status, created_at),
    INDEX idx_supply_requests_property (property_id, created_at),
    FOREIGN KEY (portal_group_id) REFERENCES portal_groups(portal_group_id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
