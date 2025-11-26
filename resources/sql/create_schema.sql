-- MySQL Schema for Rental Calendar
-- Converted from PostgreSQL DDL

CREATE TABLE IF NOT EXISTS users (
    user_id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    emailaddress           VARCHAR(255) UNIQUE NOT NULL,
    display_name           VARCHAR(120),
    is_admin               BOOLEAN NOT NULL DEFAULT FALSE,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS properties (
    property_id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_name          VARCHAR(200) NOT NULL,
    calendar_export_guid   CHAR(36) NOT NULL DEFAULT (UUID()),
    timezone               VARCHAR(64) NOT NULL DEFAULT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_partners (
    sync_partner_id        SMALLINT AUTO_INCREMENT PRIMARY KEY,
    sync_partner_name      VARCHAR(150) NOT NULL UNIQUE,
    partner_color          CHAR(7) NOT NULL COMMENT '#RRGGBB',
    partner_logo           TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    user_id                BIGINT,
    source                 VARCHAR(32) NOT NULL CHECK (source IN ('internal', 'sync_partner')),
    sync_partner_id        SMALLINT,
    sync_partner_last_checked DATETIME,
    reservation_guid       TEXT NOT NULL COMMENT 'For internal reservations: application generates UUID. For sync_partner: use partner unique reservation ID.',
    reservation_status     VARCHAR(32) NOT NULL CHECK (reservation_status IN ('pending','confirmed','cancelled')),
    reservation_name       VARCHAR(200) NOT NULL,
    reservation_description TEXT,
    reservation_start_date DATE NOT NULL,
    reservation_start_time VARCHAR(16) NOT NULL CHECK (reservation_start_time IN ('early','standard')),
    reservation_end_date   DATE NOT NULL,
    reservation_end_time   VARCHAR(16) NOT NULL CHECK (reservation_end_time IN ('standard','late')),
    notes                  TEXT,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (reservation_guid(255)),
    INDEX idx_reservations_property_dates (property_id, reservation_start_date, reservation_end_date),
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (sync_partner_id) REFERENCES sync_partners(sync_partner_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_calendar_import_links (
    property_calendar_import_link_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    sync_partner_id        SMALLINT NOT NULL,
    import_link_url        TEXT NOT NULL,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    last_fetch_status      VARCHAR(50),
    last_fetch_at          DATETIME,
    UNIQUE KEY (property_id, sync_partner_id),
    UNIQUE KEY (import_link_url(255)),
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (sync_partner_id) REFERENCES sync_partners(sync_partner_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleaners (
    cleaner_id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    cleaner_name           VARCHAR(150) NOT NULL,
    cleaner_initials       VARCHAR(5) NOT NULL UNIQUE,
    phone                  VARCHAR(40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_cleaning (
    property_cleaning_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    cleaning_date          DATE NOT NULL,
    cleaning_window        VARCHAR(32) CHECK (cleaning_window IN ('am','pm','full_day')),
    cleaner_id             BIGINT,
    notes                  TEXT,
    created_by             BIGINT,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (property_id, cleaning_date, cleaner_id),
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (cleaner_id) REFERENCES cleaners(cleaner_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_maintenance (
    property_maintenance_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    maintenance_start_date DATE NOT NULL,
    maintenance_end_date   DATE NOT NULL CHECK (maintenance_end_date >= maintenance_start_date),
    maintenance_description TEXT NOT NULL,
    maintenance_type       VARCHAR(80),
    created_by             BIGINT,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_property_permissions (
    user_id                BIGINT NOT NULL,
    property_id            BIGINT NOT NULL,
    can_view_calendar      BOOLEAN NOT NULL DEFAULT FALSE,
    can_create_reservation BOOLEAN NOT NULL DEFAULT FALSE,
    can_add_cleaning       BOOLEAN NOT NULL DEFAULT FALSE,
    can_add_maintenance    BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (user_id, property_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

