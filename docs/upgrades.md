# Upgrades: bringing a database to the current version

Two mechanisms, both run by `php artisan migrate` (which `violet update` calls):

## 1. Migrations — the shape of the tables

`database/migrations/*.php`, in file-name order. Rules:

- Guard every change (`if (!Schema::hasColumn(...))`) — some servers were installed from older snapshots.
- Never drop a table or column that holds data in the same release that stops using it. Stop using it first;
  drop it in a later release after an export.
- Must run on MySQL/MariaDB **and** SQLite (tests). Avoid raw SQL; if unavoidable, branch on `DB::getDriverName()`.
- The last migration is always `2026_11_16_000900_violet_upgrade.php` (it only calls `Upgrade::run()`).
  **New migrations must sort before it** — name them `2026_11_16_0005xx_...` or rename the upgrade file to stay last.

## 2. `App\Support\Upgrade::run()` — the content (forms, roles, settings)

Called by early migrations, the seeder and the last migration. It is safe to run any number of times:

1. `installForms()`, `configurePrints()`, `roles()`, `users()`, `printLabels()` — add what is missing, never overwrite
   what an administrator changed.
2. `schemaReady()` — stops here if a column needed by the steps below does not exist yet (fresh install in progress).
   **Add your new column to the list** when a V class needs it.
3. `V3::run()` … `V9::run()` — one class per release. Each starts with
   `if (Setting::get('upgrade_v9_done')) return;` and ends with `Setting::put('upgrade_v9_done', date('c'))`.

| Step | What it did |
|---|---|
| V3–V5 | Older form changes (number fields, signature boxes, print labels, advance form fields). |
| V6 | PTR: QuickBooks-style currency logic, treasury value to pay. |
| V7 | Rates typed the market way per currency (`in_usd`), rate check line, conversion of saved PTRs. |
| V8 | Projects: Code-Year-No-Partner, automatic status, extensions, agreement rate. |
| V9 | JAR rates typed the market way (saved JARs converted); `spend_lines` built for every final form. |

## Adding a release (checklist)

1. Migration(s) for new columns, sorted before `..._violet_upgrade.php`, guarded.
2. `app/Support/V10.php` with the `upgrade_v10_done` guard; `SchemaPatch` for form changes, a `FormVersion` copy first.
3. Register `V10::run();` at the end of `Upgrade::run()`; add new columns to `schemaReady()`.
4. A test that installs from scratch (all tests do) and one that checks the new behaviour.
5. Try the update on a copy of the live data: `violet sync-test` then `violet update test`.
6. Write the change in `WHATS_NEW_AR.md`.

## Re-running a step

Delete its marker: `DELETE FROM settings WHERE `key` = 'upgrade_v9_done';` then `php artisan migrate --force`
does nothing (no new migration) — run it with tinker instead: `php artisan tinker --execute="App\Support\Upgrade::run()"`.
