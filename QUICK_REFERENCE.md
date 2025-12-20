# Quick Reference: The Fix

## What Changed

Maintenance events now use **date-time format with standard check-in/check-out times** instead of **all-day format**.

---

## Side-by-Side Comparison

### Your Pool Upgrade Example (May 1-30, 2026)

| OLD (AirBNB ignores) | NEW (AirBNB blocks) |
|---------------------|---------------------|
| `DTSTART;VALUE=DATE:20260501` | `DTSTART:20260501T190000Z` |
| `DTEND;VALUE=DATE:20260531` | `DTEND:20260531T160000Z` |

**Note:** `T190000Z` = 15:00 local time (check-in), `T160000Z` = 12:00 local time (check-out)

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
UID:88e947fd66c7ea7aade2c16c41b58c53
SUMMARY:Pool Upgrade
DTSTART:20260501T190000Z
DTEND:20260531T160000Z
STATUS:CONFIRMED
END:VEVENT
```

---

## Matches Your Reservations

**Your Reservation (Emma and Doug):**
```ics
UID:98ee6d547aa9c691609e963e57e7e92d
DTSTART:20251227T190000Z
DTEND:20260113T160000Z
```

**Your Maintenance (Pool Upgrade):**
```ics
UID:88e947fd66c7ea7aade2c16c41b58c53
DTSTART:20260501T190000Z
DTEND:20260531T160000Z
```

**Result:** Absolutely identical format! Even the UID looks like a reservation GUID.

---

## Why It Works

| Issue | Solution |
|-------|----------|
| AirBNB ignores `VALUE=DATE` | Removed `VALUE=DATE` parameter |
| Needs time component | Added standard check-in/check-out times |
| Must look like booking | Uses **identical** format to reservations |
| End date confusion | DTEND is day after at checkout time |
| Timezone handling | Property timezone → converted to UTC |

---

## What Gets Blocked

For maintenance **May 1-30, 2026**:
- Start: `20260501T190000Z` (May 1 at 15:00 local)
- End: `20260531T160000Z` (May 31 at 12:00 local)
- Blocked: May 1 (from 15:00), May 2-30 (full days), May 31 (until 12:00)
- Available: Before May 1 at 15:00, after May 31 at 12:00

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

