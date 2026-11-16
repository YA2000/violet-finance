# Numbering

## Transaction numbers — `T2600001`

`T` + two-digit year + five digits (more digits after 99,999: `T26100000`). Reserved from **Transaction numbers**
before the form is filled, or given when a form is saved without one.

- `Reservation::takeNumber($year)` locks the year row of `txn_counters` (`SELECT … FOR UPDATE`), takes `next_number`,
  skips any number that already exists, and stores the next one. Two users at the same second get different numbers.
- A deleted or cancelled number is **never** given again: the counter never goes down.
- The first number of a year starts after the highest number already used that year (import of old numbers).
- The year is the year of the transaction date, not of today.

## Received payments — `RP-26-0001`

`Support\Numbering::next('RP', Receipt::class, $year)`: the numeric maximum of the year + 1.

## Projects — `298-2026-084-ALRAHMA`

`code` = the next integer (`Project::nextCode()`, given by the system, never typed);
`name` = `year-NNN-PARTNER` where `year` comes from the start date, `NNN` = `Project::nextYearNo($year)` (3 digits) and
PARTNER = `Project::partnerTag()` (`partners.short`, else the name, letters and digits only). `full_code` = `code-name`.
An extension keeps the same project and adds a `project_amendments` row. Projects created before this scheme keep
their old code (`year_no` empty); renumbering them is deferred.
