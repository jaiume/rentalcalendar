# Quick Reference: The Fix

## What Changed

Maintenance events now use **date-time format** instead of **all-day format**.

---

## Side-by-Side Comparison

### Your Pool Upgrade Example (May 1-30, 2026)

| OLD (AirBNB ignores) | NEW (AirBNB blocks) |
|---------------------|---------------------|
| `DTSTART;VALUE=DATE:20260501` | `DTSTART:20260501T000000Z` |
| `DTEND;VALUE=DATE:20260531` | `DTEND:20260601T000000Z` |

---

## Full Event Comparison

### ❌ OLD FORMAT
```ics
BEGIN:VEVENT
UID:maintenance-8
SUMMARY:Pool Upgrade
DTSTART;VALUE=DATE:20260501
DTEND;VALUE=DATE:20260531
STATUS:CONFIRMED
END:VEVENT
```

### ✅ NEW FORMAT
```ics
BEGIN:VEVENT
UID:maintenance-8
SUMMARY:Pool Upgrade
DTSTART:20260501T000000Z
DTEND:20260601T000000Z
STATUS:CONFIRMED
END:VEVENT
```

---

## Why It Works

| Issue | Solution |
|-------|----------|
| AirBNB ignores `VALUE=DATE` | Removed `VALUE=DATE` parameter |
| Needs time component | Added `T000000Z` (midnight UTC) |
| Must look like booking | Uses same format as reservations |
| End date confusion | DTEND is next day (exclusive) |

---

## What Gets Blocked

For maintenance **May 1-30, 2026**:
- Start: `20260501T000000Z` (May 1 at midnight)
- End: `20260601T000000Z` (June 1 at midnight)
- Blocked days: May 1, 2, 3, ..., 29, 30 (30 days total)
- Available: May 31 onward

---

## Deploy Checklist

- [ ] Run database migration (if not done)
- [ ] Upload ICalExportController.php
- [ ] Verify iCal feed output
- [ ] Re-sync AirBNB calendar
- [ ] Test: Try to book during maintenance
- [ ] Confirm: Dates are blocked

---

**Bottom Line:** Changed from all-day to date-time format. AirBNB will now block.

