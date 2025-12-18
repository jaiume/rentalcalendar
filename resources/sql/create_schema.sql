-- MySQL Schema for Rental Calendar

CREATE TABLE IF NOT EXISTS users (
    user_id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    emailaddress           VARCHAR(255) UNIQUE NOT NULL,
    display_name           VARCHAR(120),
    is_admin               TINYINT(1) NOT NULL DEFAULT 0,
    is_active              TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS properties (
    property_id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_name          VARCHAR(200) NOT NULL,
    calendar_export_guid   CHAR(36) NOT NULL DEFAULT (UUID()),
    timezone               VARCHAR(64) NOT NULL DEFAULT 'UTC',
    cleaner_tails          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether to show cleaner tails on calendar for this property'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    user_id                BIGINT,
    source                 VARCHAR(32) NOT NULL CHECK (source IN ('internal', 'sync_partner')),
    sync_partner_name      VARCHAR(150) COMMENT 'Name of sync partner (e.g., AirBNB) for sync_partner reservations',
    is_orphaned            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set to 1 when reservation is no longer in sync feed but kept for retention period',
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
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_calendar_import_links (
    property_calendar_import_link_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    property_id            BIGINT NOT NULL,
    sync_partner_name      VARCHAR(150) NOT NULL DEFAULT 'AirBNB' COMMENT 'Name of sync partner (e.g., AirBNB)',
    import_link_url        TEXT NOT NULL,
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    last_fetch_status      VARCHAR(50),
    last_fetch_at          DATETIME,
    UNIQUE KEY (import_link_url(255)),
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE
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
    created_by             BIGINT,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_property_permissions (
    user_id                BIGINT NOT NULL,
    property_id            BIGINT NOT NULL,
    can_view_calendar      TINYINT(1) NOT NULL DEFAULT 0,
    can_create_reservation TINYINT(1) NOT NULL DEFAULT 0,
    can_add_cleaning       TINYINT(1) NOT NULL DEFAULT 0,
    can_add_maintenance    TINYINT(1) NOT NULL DEFAULT 0,
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

CREATE TABLE IF NOT EXISTS login_codes (
    login_code_id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT NOT NULL UNIQUE,
    code                   VARCHAR(255) NOT NULL COMMENT 'Hashed login code sent via email',
    token                  VARCHAR(255) NOT NULL COMMENT 'Token returned after successful code verification',
    code_expiry            DATETIME NOT NULL,
    token_expiry           DATETIME NOT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code_expiry (code_expiry),
    INDEX idx_token_expiry (token_expiry),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

