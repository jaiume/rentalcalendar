# Rental Calendar Schema

## 1. Requirements Coverage (`requirements-pass`)

| Domain area | Required fields | Notes |
|-------------|-----------------|-------|
| Properties | `property_id`, `property_name`, `calendar_export_guid` | Added `timezone` for consistent calendar math and automatic UUID for exports. |
| Reservations | `reservation_id`, `property_id`, `source`, `sync_partner`, `sync_partner_last_checked`, `user_id`, `reservation_guid`, `reservation_status`, `reservation_name`, `reservation_start_date`, `reservation_start_time`, `reservation_end_date`, `reservation_end_time` | Adds optional `reservation_description`, `notes`, `sync_partner_id` FK, and timestamps. `reservation_guid` is internally generated UUID for internal reservations, or the sync partner's unique ID for synced reservations. Time-of-day preferences modeled via constrained TEXT. |
| Sync Partner | `sync_partner_name`, `partner_color`, `partner_logo` | Stores brand color/asset. |
| Calendar Import Links | `property_id`, `sync_partner`, `import_link_url` | Bridge table ties a property to a partner feed with enable flag and last status. |
| Cleaning | `property_id`, `cleaning_date`, `cleaner_id` | Adds optional `cleaning_window` and `notes`, plus timestamps. |
| Cleaners | `cleaner_id`, `cleaner_name`, `cleaner_initials` | Stores unique initials for calendar badges. |
| Maintenance | `property_id`, `maintenance_start_date`, `maintenance_end_date`, `maintenance_description` | Adds `maintenance_type`, `created_by`, and timestamps. |
| Users | `emailaddress`, `isadmin` | Adds `user_id` surrogate key, password hash (or SSO subject), display name, and status. |
| User Permissions | `user_id`, `property_id`, `can_view_calendar`, `can_create_reservation`, `can_add_cleaning`, `can_add_maintenance` | Composite PK. |
| Config | `maintenance_color`, `cleaning_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end` | Stored in `config.ini` file, not in database. |

## 2. Entity Relationships (`relationships-model`)

- `properties.property_id` ←→ `reservations.property_id`, `property_cleaning.property_id`, `property_maintenance.property_id`, `property_calendar_import_links.property_id`, and `user_property_permissions.property_id`.
- `users.user_id` ←→ `reservations.user_id` (creator), `property_maintenance.created_by`, `user_property_permissions.user_id`.
- `sync_partners.sync_partner_id` ←→ `reservations.sync_partner_id` and `property_calendar_import_links.sync_partner_id`; nullable to allow purely internal bookings.
- `cleaners.cleaner_id` ←→ `property_cleaning.cleaner_id`.
- Composite uniqueness:
  - `reservations` enforce unique `reservation_guid` and prevent overlapping date ranges per property through an exclusion constraint.
  - `property_calendar_import_links` unique on `(property_id, sync_partner_id)`.
  - `user_property_permissions` unique on `(user_id, property_id)`.
- Cascading rules:
  - Deleting a property cascades to dependent rows (cleaning, maintenance, reservations, import links, permissions).
  - Deleting a user sets nullable ownership fields to `NULL` except permissions, which cascade delete.
  - Deleting a sync partner sets `reservations.sync_partner_id` to `NULL` but prevents deletion when import links reference it (enforced via `ON DELETE RESTRICT`).

## 3. SQL DDL (`ddl-draft`)

```sql
-- Enable uuid generation (Postgres); adjust for MySQL if needed.
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE users (
    user_id                BIGSERIAL PRIMARY KEY,
    emailaddress           CITEXT UNIQUE NOT NULL,
    password_hash          TEXT NOT NULL,
    display_name           VARCHAR(120),
    is_admin               BOOLEAN NOT NULL DEFAULT FALSE,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE properties (
    property_id            BIGSERIAL PRIMARY KEY,
    property_name          VARCHAR(200) NOT NULL,
    calendar_export_guid   UUID NOT NULL DEFAULT gen_random_uuid(),
    timezone               VARCHAR(64) NOT NULL DEFAULT 'UTC'
);

CREATE TABLE sync_partners (
    sync_partner_id        SMALLSERIAL PRIMARY KEY,
    sync_partner_name      VARCHAR(150) NOT NULL UNIQUE,
    partner_color          CHAR(7) NOT NULL, -- #RRGGBB
    partner_logo           TEXT
);

CREATE TABLE reservations (
    reservation_id         BIGSERIAL PRIMARY KEY,
    property_id            BIGINT NOT NULL REFERENCES properties(property_id) ON DELETE CASCADE,
    user_id                BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    source                 VARCHAR(32) NOT NULL CHECK (source IN ('internal', 'sync_partner')),
    sync_partner_id        SMALLINT REFERENCES sync_partners(sync_partner_id) ON DELETE SET NULL,
    sync_partner_last_checked TIMESTAMPTZ,
    reservation_guid       TEXT NOT NULL, -- For internal reservations: application generates UUID. For sync_partner: use partner's unique reservation ID.
    reservation_status     VARCHAR(32) NOT NULL CHECK (reservation_status IN ('pending','confirmed','cancelled')),
    reservation_name       VARCHAR(200) NOT NULL,
    reservation_description TEXT,
    reservation_start_date DATE NOT NULL,
    reservation_start_time VARCHAR(16) NOT NULL CHECK (reservation_start_time IN ('early','standard')),
    reservation_end_date   DATE NOT NULL,
    reservation_end_time   VARCHAR(16) NOT NULL CHECK (reservation_end_time IN ('standard','late')),
    notes                  TEXT,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (reservation_guid)
);

CREATE INDEX idx_reservations_property_dates
    ON reservations (property_id, reservation_start_date, reservation_end_date);

-- Prevent overlapping reservations per property (Postgres syntax).
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER TABLE reservations
    ADD CONSTRAINT reservations_no_overlap
    EXCLUDE USING gist (
        property_id WITH =,
        daterange(reservation_start_date, reservation_end_date, '[]') WITH &&
    )
    WHERE (reservation_status IN ('pending','confirmed'));

CREATE TABLE property_calendar_import_links (
    property_calendar_import_link_id BIGSERIAL PRIMARY KEY,
    property_id            BIGINT NOT NULL REFERENCES properties(property_id) ON DELETE CASCADE,
    sync_partner_id        SMALLINT NOT NULL REFERENCES sync_partners(sync_partner_id) ON DELETE RESTRICT,
    import_link_url        TEXT NOT NULL,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    last_fetch_status      VARCHAR(50),
    last_fetch_at          TIMESTAMPTZ,
    UNIQUE (property_id, sync_partner_id),
    UNIQUE (import_link_url)
);

CREATE TABLE cleaners (
    cleaner_id             BIGSERIAL PRIMARY KEY,
    cleaner_name           VARCHAR(150) NOT NULL,
    cleaner_initials       VARCHAR(5) NOT NULL UNIQUE,
    phone                  VARCHAR(40)
);

CREATE TABLE property_cleaning (
    property_cleaning_id   BIGSERIAL PRIMARY KEY,
    property_id            BIGINT NOT NULL REFERENCES properties(property_id) ON DELETE CASCADE,
    cleaning_date          DATE NOT NULL,
    cleaning_window        VARCHAR(32) CHECK (cleaning_window IN ('am','pm','full_day')),
    cleaner_id             BIGINT REFERENCES cleaners(cleaner_id) ON DELETE SET NULL,
    notes                  TEXT,
    created_by             BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (property_id, cleaning_date, COALESCE(cleaner_id, 0))
);

CREATE TABLE property_maintenance (
    property_maintenance_id BIGSERIAL PRIMARY KEY,
    property_id            BIGINT NOT NULL REFERENCES properties(property_id) ON DELETE CASCADE,
    maintenance_start_date DATE NOT NULL,
    maintenance_end_date   DATE NOT NULL CHECK (maintenance_end_date >= maintenance_start_date),
    maintenance_description TEXT NOT NULL,
    maintenance_type       VARCHAR(80),
    created_by             BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE user_property_permissions (
    user_id                BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    property_id            BIGINT NOT NULL REFERENCES properties(property_id) ON DELETE CASCADE,
    can_view_calendar      BOOLEAN NOT NULL DEFAULT FALSE,
    can_create_reservation BOOLEAN NOT NULL DEFAULT FALSE,
    can_add_cleaning       BOOLEAN NOT NULL DEFAULT FALSE,
    can_add_maintenance    BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (user_id, property_id)
);
```

Note: Configuration values (`maintenance_color`, `cleaning_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end`) are stored in `config.ini` file, not in the database.

## 4. Consistency & Business Rules (`consistency-rules`)

1. **Reservation overlap guard** – The GiST exclusion constraint blocks conflicting pending/confirmed bookings per property. Cancellations remain in the table for audit but no longer block.
2. **Source of truth** – `source='sync_partner'` rows must carry `sync_partner_id`. The `reservation_guid` for sync partner reservations must be the partner's unique reservation ID (not an internally generated UUID). For internal reservations, the application should generate a UUID for `reservation_guid`. When an import runs, update `sync_partner_last_checked` regardless of detected changes.
3. **Export payloads** – Use `calendar_export_guid` to mint per-property iCal feeds. Filtering by `reservation_status='confirmed'` avoids publishing tentative internal holds.
4. **Cleaning windows vs reservations** – Before inserting into `property_cleaning`, ensure the cleaning date falls inside (or immediately after) a reservation. This can be enforced in application logic or via a deferred trigger.
5. **Maintenance vs reservations** – Maintenance ranges should block new reservations. Either extend the exclusion constraint or run a trigger to reject any reservation intersecting maintenance for the same property.
6. **Permission enforcement** – UI/API endpoints should gate creation routes based on `user_property_permissions`. Admins can bypass by checking `users.is_admin`.
7. **Config values** – Configuration values (`maintenance_color`, `cleaning_color`, `early_start_time`, `late_end_time`, `standard_start`, `standard_end`) are stored in `config.ini` file and read at application startup.
8. **Audit fields** – Application layer must keep `updated_at` fresh (e.g., via triggers) on `reservations`, `property_cleaning`, and `property_maintenance` tables to support sync partner diffing and cache invalidation.

This document can serve as the basis for migrations in Laravel (Eloquent), Sequelize, Prisma, or any other ORM driving the React-based portal backend.

