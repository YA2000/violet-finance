# Violet Finance — technical documentation

Written so that a developer who has never seen the project can run it, change it and deploy it safely.
Read in this order:

1. [architecture.md](architecture.md) — the big picture: folders, request flow, the main services.
2. [data-model.md](data-model.md) — every table and what owns it.
3. [forms-engine.md](forms-engine.md) — how PTR/JAR/... are defined in JSON and calculated (the heart of the system).
4. [money-and-rates.md](money-and-rates.md) — currencies, the two ways of typing a rate, US-dollar values, budget figures.
5. [numbering.md](numbering.md) — transaction numbers (T2600001) and project codes.
6. [upgrades.md](upgrades.md) — how a database is brought to the current version (migrations + the V3…V9 steps).
7. [security.md](security.md) — permissions, two-factor sign-in, the audit trail.
8. [deployment.md](deployment.md) — server, the `violet` command, backups, rollback, what to do on an error 500.
9. [testing.md](testing.md) — running the tests, writing new ones, CI on GitHub.
10. [removed-modules.md](removed-modules.md) — what was deleted (advances module, payroll, shared costs...) and what was kept.

Arabic summary for the finance team: [دليل-عربي.md](دليل-عربي.md).

## Ten facts to know before touching anything

1. **Forms are data, not code.** PTR, JAR, Advance... live in `form_types.schema` (JSON) and are edited in the Form builder.
   A change in a form = a change in the database, saved with a copy in `form_versions`.
2. **Upgrades are code that edits forms.** When a form must change for everyone, a class `app/Support/V<n>.php` patches
   the schema once (guarded by a `upgrade_v<n>_done` setting). Never edit an old V class: add a new one.
3. **Rates are typed the market way** (1 EUR = 1.16 USD, 1 USD = 122 SYP) and stored the QuickBooks way (US dollars for 1 unit).
   `currencies.in_usd` decides which. See money-and-rates.md.
4. **A saved form is final and locked** (`state = done`). Changing it needs `documents.manage` (unlock) or the treasury
   (value to pay). Both are written to the audit trail.
5. **Numbers are never reused.** `txn_counters` only goes up.
6. **Budget figures read `spend_lines`**, a table written every time a form is saved / corrected / cancelled.
   If it ever looks wrong: `php artisan violet:expenses-rebuild`.
7. **Old tables of removed modules are still in the database** on purpose (no data loss). No code reads them.
8. **Every deployment takes a backup first** and puts it back by itself if a migration fails or the site answers 500.
9. **Tests run on SQLite in memory** from a fresh install (seeders + upgrades), so they also prove a new server works.
10. **Production is MySQL/MariaDB.** Anything that works on SQLite only (or the reverse) is a bug.
