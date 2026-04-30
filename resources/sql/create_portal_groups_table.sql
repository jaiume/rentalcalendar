-- Guest portal groups (one per public-facing portal hostname/brand).
-- The slug drives the JSON config filename and the template directory,
-- so it must be path-safe; application code enforces ^[a-z0-9_-]+$.
CREATE TABLE IF NOT EXISTS portal_groups (
    portal_group_id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    slug                   VARCHAR(64) NOT NULL UNIQUE COMMENT 'Path-safe identifier; matches config/portals/<slug>.json and templates/portals/<slug>/',
    name                   VARCHAR(200) NOT NULL,
    guest_hostname         VARCHAR(255) NULL UNIQUE COMMENT 'Public hostname for the guest portal, e.g. maravalroad.com',
    is_active              TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
