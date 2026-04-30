# Production Deployment Guide - Maintenance Event Enhancement

**Date:** 2025-12-20  
**Issue Resolved:** Column not found error + AirBNB ignoring maintenance events

---

## Overview

This deployment fixes two issues:
1. **Missing database column** causing production errors
2. **Enhanced iCalendar output** to help AirBNB recognize maintenance blocks

---

## Files to Deploy

### 1. Database Migration
**File:** `resources/sql/add_maintenance_type.sql`
```sql
ALTER TABLE property_maintenance 
ADD COLUMN maintenance_type VARCHAR(100) DEFAULT NULL 
COMMENT 'Type or category of maintenance'
AFTER maintenance_description;
```

### 2. Code Changes
**File:** `src/Controllers/ICalExportController.php`
- Lines 116-144 enhanced with blocking properties

---

## Deployment Steps

### Step 1: Backup Production Database
```bash
# On production server
mysqldump -u [user] -p [database] > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Apply Database Migration
```bash
# Option A: Via command line
mysql -u [prod_user] -p [prod_database] < resources/sql/add_maintenance_type.sql

# Option B: Via phpMyAdmin
# Copy and paste the ALTER TABLE statement
```

### Step 3: Verify Database Change
```sql
DESCRIBE property_maintenance;
```

**Expected output should include:**
```
maintenance_type    varchar(100)    YES         NULL
```

### Step 4: Deploy Code Changes
```bash
# Copy the updated file to production
scp src/Controllers/ICalExportController.php [production_server]:[path]/src/Controllers/
```

### Step 5: Clear Cache (if applicable)
```bash
# If you have opcode caching enabled
# Restart PHP-FPM or clear cache
sudo systemctl restart php-fpm
```

### Step 6: Test iCal Feed
```bash
# Replace with your actual export GUID
curl https://rentalcalendar.newburyhill.com/calendar/export/[GUID].ics
```

**Expected output for maintenance events:**
```
BEGIN:VEVENT
UID:maintenance-8
SUMMARY:Pool Upgrade
TRANSP:OPAQUE
CLASS:PUBLIC
X-MICROSOFT-CDO-BUSYSTATUS:OOF
DTSTART;VALUE=DATE:20260501
DTEND;VALUE=DATE:20260531
STATUS:CONFIRMED
END:VEVENT
```

---

## Verification Checklist

- [ ] Database backup completed
- [ ] Migration applied successfully
- [ ] `maintenance_type` column exists
- [ ] Code deployed to production
- [ ] PHP cache cleared (if applicable)
- [ ] iCal feed accessible
- [ ] New properties appear in maintenance events (TRANSP, CLASS, X-MICROSOFT)
- [ ] No errors in production logs
- [ ] AirBNB calendar re-synced
- [ ] Test booking attempt during maintenance period

---

## Testing the Fix

### 1. Verify Database Column
```bash
mysql -u [user] -p [database] -e "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'property_maintenance' AND COLUMN_NAME = 'maintenance_type';"
```

### 2. Test iCal Export
Visit your calendar export URL and verify:
- No "Column not found" errors
- Maintenance events include new properties
- Format is valid iCalendar

### 3. Validate iCalendar Format
Use online validator: https://icalendar.org/validator.html

### 4. Test with AirBNB
1. Go to AirBNB calendar settings
2. Re-import or refresh the iCal feed
3. Check if maintenance dates are blocked
4. Try to create a test booking during maintenance period

---

## Rollback Plan

If issues occur, rollback using these steps:

### Rollback Code
```bash
# Restore previous version
git checkout HEAD~1 src/Controllers/ICalExportController.php
```

### Rollback Database
```bash
# Remove the column (only if necessary)
mysql -u [user] -p [database] -e "ALTER TABLE property_maintenance DROP COLUMN maintenance_type;"
```

**Note:** Don't rollback the database unless the column causes issues, as the code expects it.

---

## Expected Behavior After Deployment

### ✅ Fixed Issues
- No more "Column not found: maintenance_type" errors
- iCalendar feed exports successfully
- Maintenance events include blocking properties

### ⚠️ Important Notes

**AirBNB Limitation:**
Even with these enhancements, AirBNB may still not recognize maintenance events because their iCal integration is designed primarily for **reservation synchronization**, not general calendar blocking.

**If AirBNB Still Ignores Maintenance:**
1. Use AirBNB's native calendar blocking feature
2. Manually block dates for maintenance periods
3. Consider AirBNB API integration for automated blocking

**Other Platforms:**
The enhanced iCalendar format will benefit other platforms:
- VRBO/HomeAway (better blocking support)
- Booking.com
- Google Calendar, Outlook, Apple Calendar
- Other vacation rental management systems

---

## Monitoring

After deployment, monitor for:

### Application Logs
```bash
tail -f /home/Newburyhill/web/rentalcalendar.newburyhill.com/public_html/logs/*.log
```

### Look for:
- ✅ Successful iCal exports
- ❌ Any new database errors
- ❌ PHP warnings or notices

### Success Metrics
- iCal feed loads without errors
- Maintenance events appear in feed
- New properties present in output
- No 500 errors in access logs

---

## Support Information

### Development Testing
- ✅ Development database migrated
- ✅ Code changes applied
- ✅ No linting errors
- ✅ Test output verified

### Related Documentation
- `SCHEMA_VERIFICATION_REPORT.md` - Database schema verification
- `ICAL_MAINTENANCE_ENHANCEMENT.md` - Enhancement details
- `resources/sql/add_maintenance_type.sql` - Migration file

### Contact
If issues arise during deployment, refer to:
- Error logs: `/logs/` directory
- Database backups: Created in Step 1
- This guide for rollback procedures

---

## Post-Deployment

1. **Monitor for 24 hours** - Check logs and error reports
2. **Test bookings** - Ensure normal operations continue
3. **Verify AirBNB sync** - Check if maintenance blocks work
4. **Document results** - Note whether AirBNB respects the blocks
5. **Plan next steps** - If AirBNB still ignores, implement manual blocking

---

**Deployment prepared by:** AI Assistant  
**Date:** 2025-12-20  
**Status:** Ready for production deployment

---

# Guest Services Portal — Deployment Addendum

**Date:** 2026-04-29
**Scope:** Adds the hostname-routed guest portal (PayPal-paid laundry combo + supply requests) for a configurable subset of properties. The matching staff/cleaner portal is a separate, future session and is NOT part of this deployment.

## Critical pre-flight: lock down `config/`

Live verification on the dev host showed `https://dev…/config/config.ini` was returning HTTP 200 with the full file (DB password, SMTP password). **This must be fixed before deploying anything else** — adding `config/portals/<slug>.php` (with the laundry padlock combination) and `[paypal] secret` to `config/config.ini` would expose those secrets too.

The fix is included in this changeset:

- `[.htaccess](.htaccess)` now contains a `RewriteRule ^config(/|$) - [F,L]` that 403s any `/config/...` URL via Apache.
- `[config/.htaccess](config/.htaccess)` adds `Require all denied` (Apache 2.4+, with 2.2 fallback).
- Per-portal config is stored as PHP files (`config/portals/<slug>.php`) rather than JSON because the production nginx (HestiaCP-managed `*.json` static handler) bypasses Apache for `.json` requests. PHP files are forwarded to PHP-FPM, where `.htaccess` blocks them.

Verify after deploy:

```bash
curl -i https://<host>/config/config.ini                       # expect 403
curl -i https://<host>/config/portals/maravalroad.php          # expect 403
curl -i https://<host>/config/portals/maravalroad.example.php  # expect 403
```

## DNS / vhost / TLS

The guest portal lives on a separate hostname (one per portal group, e.g. `maravalroad.com`). Both faces (admin/calendar + guest portal) share the same Slim app and document root (`public_html/`).

1. **DNS**: point each guest hostname (e.g. `maravalroad.com`) at the same server IP as the existing admin hostname.
2. **Vhost**: add the guest hostname to the existing vhost's `server_name` list (HestiaCP: "Web Domain → Aliases"), or create a parallel vhost that proxies to the same Slim app. Either way the request must reach the same `index.php`.
3. **TLS**: PayPal Smart Buttons require HTTPS in production. Issue a certificate covering the guest hostname (Let's Encrypt is fine; HestiaCP handles this automatically when a domain is added).
4. **Hostname routing**: in `config/config.ini` set `[portal] admin_url` to the canonical URL of the admin face (e.g. `https://rentalcalendar.newburyhill.com`). The hostname portion of that URL is the allow-list for the admin face — any hostname not matching it AND not matching an active `portal_groups.guest_hostname` row will return 404 (intentional — the admin app is no longer exposed on unknown hostnames). The full URL is also reused as the link prefix in admin notification emails.

## Database migrations

Apply these in order against the production DB (manual; this app has no migration runner):

```bash
mysql -u [user] -p [database] < resources/sql/create_portal_groups_table.sql
mysql -u [user] -p [database] < resources/sql/create_portal_group_properties_table.sql
mysql -u [user] -p [database] < resources/sql/create_supply_requests_table.sql
mysql -u [user] -p [database] < resources/sql/create_payments_table.sql
```

`resources/sql/create_schema.sql` is also updated for fresh installs.

## Composer

```bash
composer install --no-dev --optimize-autoloader
```

This adds `guzzlehttp/guzzle` (used for the PayPal REST client). No other runtime dependencies were added.

## Configuration

### `config/config.ini`

Add (or fill in real values for) the new sections:

```ini
[paypal]
env = "live"             ; or "sandbox" while testing
client_id = "<from PayPal developer dashboard>"
secret    = "<from PayPal developer dashboard>"

[portal]
; Single canonical URL for this environment's admin face. Hostname
; portion drives the admin allow-list; full URL is reused as the
; link prefix in admin notification emails.
admin_url = "https://rentalcalendar.newburyhill.com"

; Recipient for guest-portal admin notification emails (laundry
; payments, supply requests). Leave blank to disable admin emails
; entirely (e.g. on staging).
admin_email = "ops@example.com"
```

`secret` is server-side only; it is never exposed to the browser. `client_id` is exposed via `GET /api/paypal/config` so the PayPal SDK can be loaded.

**Migrating from a previous install:** the previous `[portal] admin_hostnames` (comma-separated list) has been replaced by the single `admin_url` above. Set `admin_url` to whichever hostname you want the admin face served on; any other hostnames that previously appeared in `admin_hostnames` will now return 404 unless they are also seeded as guest-portal hostnames.

### Per-portal config — `config/portals/<slug>.php`

For each portal group you want to launch, copy `config/portals/maravalroad.example.php` to `config/portals/<slug>.php` and edit:

- `laundry.price_cents` and `laundry.currency` (e.g. `500`, `"USD"` for $5.00)
- `laundry.padlock_combination` and `laundry.padlock_instructions_html` (the latter is rendered as HTML to the guest after a successful capture)
- `supplies.item_suggestions` (string array of checkboxes shown on the supplies form)

Each section has an `enabled` flag — set it to `false` to hide that section from the guest UI without removing the data.

The real config files are gitignored; only `*.example.php` siblings are committed. Rotating the padlock combination is a single-file edit in this PHP file (no DB change needed). Combos are NEVER stored in the `payments` table.

### Seed `portal_groups`

```sql
INSERT INTO portal_groups (slug, name, guest_hostname, is_active)
VALUES ('maravalroad', 'Maraval Road', 'maravalroad.com', 1);
```

Then assign properties to the group via `/admin/portal-groups/{id}/edit`, or directly:

```sql
INSERT INTO portal_group_properties (portal_group_id, property_id) VALUES (?, ?);
```

The same property may belong to multiple portal groups — there is no unique constraint on `property_id`.

## Templates

Each portal owns its full template set under `templates/portals/<slug>/`. The first portal ships with `templates/portals/maravalroad/` containing `guest_layout.twig`, `home.twig`, `laundry.twig`, `supplies.twig`, `supplies_thanks.twig`. To add a second portal, copy that directory to a new slug, tweak as desired, and seed a matching `portal_groups` row + JSON config file.

## Verification after deploy

1. `https://<admin-host>/login` — admin face still works, returns 200.
2. `https://<admin-host>/admin/portal-groups` — new admin page lists the seeded group.
3. `https://<admin-host>/admin/payments` — empty audit list renders without errors.
4. `https://<admin-host>/admin/supply-requests` — empty audit list renders without errors.
5. `https://<guest-host>/` — landing page renders with the configured services.
6. `https://<guest-host>/laundry` — PayPal Smart Buttons render (sandbox payment flow can be tested end-to-end with a PayPal sandbox buyer account).
7. `https://<guest-host>/supplies` — form renders with the property dropdown populated and item suggestions checkboxed; submitting redirects to `/supplies/thanks` and writes a row to `supply_requests`.
8. `https://<admin-host>/admin` on the guest hostname returns 404 (intentional — admin face never exposed on guest hostname).
9. `https://<guest-host>/login` returns 404 (admin auth never exposed on guest hostname).

## Local development

There is no DNS for the guest hostname locally. Add the configured hostname to `/etc/hosts`:

```
127.0.0.1 maravalroad.local
```

Seed `portal_groups.guest_hostname = 'maravalroad.local'` and set `[portal] admin_url = "https://dev.<your-admin-host>"` in `config.ini`. The dev nginx vhost only serves the admin hostname's `server_name` by default — for full local guest-face testing you'll either need to add the guest hostname to the vhost's `server_name`, or temporarily point an existing dev hostname at the guest portal by editing `portal_groups.guest_hostname`.

## Out of scope (future staff/cleaner portal session)

- `cleaners` table → `staff` rename with `staff_type` discriminator
- Universal `staff.<domain>` portal (separate hostname, configured in `config.ini`)
- Magic-link authentication and device-token persistence for staff
- Web-push notifications (VAPID, service worker, `staff_push_subscriptions` table)
- Adding `property_cleaning_id` to `supply_requests` (the schema is forward-compatible: existing rows will simply have `NULL` and remain triagable from `/admin/supply-requests`)

## Rollback

The new tables and columns are additive; rolling back is "remove the new routes/files and don't reference the new tables". Specifically:

1. `git revert` (or revert the deploy) to remove `index.php`, `config/routes.php`, and `config/container.php` changes plus the new `src/`/`templates/` files.
2. Revert `[.htaccess](.htaccess)` and `[config/.htaccess](config/.htaccess)` only if the security lockdown is causing legitimate access problems (it should not — only `/config/...` URLs are blocked).
3. The new tables can be left in place harmlessly; if you want to clean them up:

```sql
DROP TABLE payments;
DROP TABLE supply_requests;
DROP TABLE portal_group_properties;
DROP TABLE portal_groups;
```

(Drop in this order to respect foreign keys.)




