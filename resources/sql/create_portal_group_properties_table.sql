-- M2M membership table: which properties belong to which guest portal group.
-- Same property may appear in multiple portal groups (intentional, no extra
-- unique constraint on property_id).
CREATE TABLE IF NOT EXISTS portal_group_properties (
    portal_group_id        BIGINT NOT NULL,
    property_id            BIGINT NOT NULL,
    PRIMARY KEY (portal_group_id, property_id),
    FOREIGN KEY (portal_group_id) REFERENCES portal_groups(portal_group_id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
