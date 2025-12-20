# iCalendar Maintenance Event Enhancement

**Date:** 2025-12-20  
**Issue:** AirBNB ignoring maintenance events in iCalendar feed

## Changes Made

Enhanced maintenance events in the iCalendar export to include additional properties that signal the dates should be blocked/unavailable.

### Modified File
- `src/Controllers/ICalExportController.php` (lines 116-144)

### Properties Added

1. **`TRANSP:OPAQUE`** 
   - RFC 5545 standard property
   - Indicates the event blocks time (makes it "busy")
   - Opposite of `TRANSPARENT` which wouldn't block time

2. **`CLASS:PUBLIC`**
   - Standard iCalendar property
   - Marks the event as public
   - Some platforms check this for event visibility

3. **`X-MICROSOFT-CDO-BUSYSTATUS:OOF`**
   - Microsoft CDO extension (widely supported)
   - "Out of Facility" status
   - Commonly recognized by calendar systems as unavailability

## Before (Old Format)

```
BEGIN:VEVENT
UID:maintenance-8
SUMMARY:Pool Upgrade
DTSTART;VALUE=DATE:20260501
DTEND;VALUE=DATE:20260531
STATUS:CONFIRMED
END:VEVENT
```

## After (New Format)

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

## Expected Behavior

These properties signal to calendar systems that:
- The time period is busy/unavailable (OPAQUE)
- The event is public (PUBLIC)
- The facility is out of service (OOF)

This should increase the likelihood that platforms like AirBNB will recognize and respect these maintenance blocks.

## Testing Procedure

1. ✅ **Development:** Changes applied and linting passed
2. ⏳ **Production:** Deploy changes to production server
3. ⏳ **Verify:** Check iCal feed includes new properties
4. ⏳ **Test:** Re-sync calendar with AirBNB
5. ⏳ **Monitor:** Confirm AirBNB blocks dates during maintenance periods

## Important Notes

### Realistic Expectations
- AirBNB's iCal integration is primarily designed for **reservation synchronization**
- They may still ignore maintenance events regardless of formatting
- Most vacation rental platforms prefer their native blocking features

### Fallback Plan
If AirBNB continues to ignore maintenance events:
1. Use AirBNB's native calendar blocking feature manually
2. Keep the enhanced iCal feed (benefits other platforms that do respect it)
3. Consider AirBNB API integration for automated blocking (if available)

### Other Platforms
These enhancements may benefit synchronization with other platforms:
- VRBO/HomeAway
- Booking.com
- Generic calendar applications (Google Calendar, Outlook, etc.)

## Related Files

- Migration: `resources/sql/add_maintenance_type.sql` (adds missing column)
- Schema: `resources/sql/create_schema.sql` (updated with maintenance_type)
- DAO: `src/DAO/MaintenanceDAO.php` (queries maintenance_type)
- Report: `SCHEMA_VERIFICATION_REPORT.md` (database verification)

## Deployment Checklist

- [x] Development database updated with maintenance_type column
- [x] Code changes applied to ICalExportController.php
- [x] No linting errors
- [ ] Deploy to production server
- [ ] Run `add_maintenance_type.sql` on production database
- [ ] Test iCal feed output
- [ ] Re-sync with AirBNB
- [ ] Verify blocking behavior

