# Rental Calendar Schema

## 1. Requirements Coverage (`requirements-pass`)

| Domain area | Required fields | Notes |
|-------------|-----------------|-------|
| Properties | `property_id`, `property_name`, `calendar_export_guid`, `timezone`, `cleaner_tails` | Automatic UUID for exports. `cleaner_tails` controls whether cleaner tail indicators show on calendar. |
| Reservations | `reservation_id`, `property_id`, `source`, `sync_partner_name`, `is_orphaned`, `user_id`, `reservation_guid`, `reservation_status`, `reservation_name`, `reservation_start_date`, `reservation_start_time`, `reservation_end_date`, `reservation_end_time` | Adds optional `reservation_description`, `notes`, and timestamps. `reservation_guid` is internally generated UUID for internal reservations, or the sync partner's unique ID for synced reservations. `is_orphaned` flag indicates reservation is no longer in sync feed but kept for retention period. |
| Sync Partner | `sync_partner_name`, `bar_color` | Sync partner configuration is stored in `config.ini` sections (e.g., `[AirBNB]`). |
| Calendar Import Links | `property_id`, `sync_partner_name`, `import_link_url` | Bridge table ties a property to a partner feed with enable flag and last status. |
| Cleaning | `property_id`, `cleaning_date`, `cleaner_id` | Adds optional `cleaning_window` and `notes`, plus timestamps. |
| Cleaners | `cleaner_id`, `cleaner_name`, `cleaner_initials` | Stores unique initials for calendar badges. |
| Maintenance | `property_id`, `maintenance_start_date`, `maintenance_end_date`, `maintenance_description` | Adds `created_by` and timestamps. |
| Users | `emailaddress`, `is_admin` | Adds `user_id` surrogate key, display name, and status. Uses passwordless email login. |
| User Permissions | `user_id`, `property_id`, `can_view_calendar`, `can_create_reservation`, `can_add_cleaning`, `can_add_maintenance` | Composite PK. |
| Auth Tokens | `auth_token_id`, `user_id`, `token`, `last_touched` | Stores hashed session tokens for authenticated users. |
| Login Codes | `login_code_id`, `user_id`, `code`, `token`, `code_expiry`, `token_expiry` | Stores passwordless login codes sent via email. |
| Config | `maintenance_color`, `cleaning_color`, `reservation_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end` | Stored in `config.ini` file, not in database. Sync partner bar colors also in config. |

## 2. Entity Relationships (`relationships-model`)

- `properties.property_id` ←→ `reservations.property_id`, `property_cleaning.property_id`, `property_maintenance.property_id`, `property_calendar_import_links.property_id`, and `user_property_permissions.property_id`.
- `users.user_id` ←→ `reservations.user_id` (creator), `property_maintenance.created_by`, `user_property_permissions.user_id`.
- `reservations.sync_partner_name` and `property_calendar_import_links.sync_partner_name` store the sync partner name directly (e.g., 'AirBNB') rather than using a foreign key. Sync partner configuration (bar colors, etc.) is stored in `config.ini`.
- `cleaners.cleaner_id` ←→ `property_cleaning.cleaner_id`.
- Composite uniqueness:
  - `reservations` enforce unique `reservation_guid`.
  - `property_calendar_import_links` unique on `import_link_url`.
  - `user_property_permissions` unique on `(user_id, property_id)`.
- Cascading rules:
  - Deleting a property cascades to dependent rows (cleaning, maintenance, reservations, import links, permissions).
  - Deleting a user sets nullable ownership fields to `NULL` except permissions, which cascade delete.

## 3. SQL DDL (`ddl-draft`)

```sql
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
    cleaner_tails          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether to show cleaner tails on calendar'
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
```

Note: Configuration values (`maintenance_color`, `cleaning_color`, `reservation_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end`, and sync partner `bar_color`, `recheck_interval`, `keep_deleted_reservations_for`) are stored in `config.ini` file, not in the database.

## 4. Consistency & Business Rules (`consistency-rules`)

1. **Reservation uniqueness** – The `reservation_guid` column enforces unique reservations. For sync partner reservations, this is the partner's unique reservation ID. For internal reservations, the application generates a UUID.
2. **Source of truth** – `source='sync_partner'` rows carry `sync_partner_name` (e.g., 'AirBNB'). The `reservation_guid` for sync partner reservations must be the partner's unique reservation ID (not an internally generated UUID).
3. **Orphaned reservations** – When a sync partner reservation is no longer in the feed:
   - Future reservations (end_date >= today): Deleted immediately (cancellation)
   - Past reservations (end_date < today): Marked as `is_orphaned=1` and kept for the configured retention period (`keep_deleted_reservations_for` in config.ini), then deleted. Orphaned reservations display with a lighter color on the calendar.
4. **Export payloads** – Use `calendar_export_guid` to mint per-property iCal feeds. Filtering by `reservation_status='confirmed'` avoids publishing tentative internal holds.
5. **Cleaning windows vs reservations** – Before inserting into `property_cleaning`, ensure the cleaning date falls inside (or immediately after) a reservation. This can be enforced in application logic.
6. **Maintenance vs reservations** – Maintenance ranges should block new reservations. This is enforced in application logic.
7. **Permission enforcement** – UI/API endpoints should gate creation routes based on `user_property_permissions`. Admins can bypass by checking `users.is_admin`.
8. **Config values** – Configuration values (`maintenance_color`, `cleaning_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end`, sync partner `bar_color`, `recheck_interval`, `keep_deleted_reservations_for`) are stored in `config.ini` file and read at application startup.
9. **Audit fields** – MySQL automatically updates `updated_at` via `ON UPDATE CURRENT_TIMESTAMP` on `reservations`, `property_cleaning`, and `property_maintenance` tables.

This document serves as the reference for the MySQL schema used by the PHP/Slim backend.

