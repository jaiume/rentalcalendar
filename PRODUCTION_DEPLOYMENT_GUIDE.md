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

