# Testing

## Run the tests

```bash
tests/run.sh                                  # everything (about 15 seconds)
tests/run.sh --filter PtrRates                # one test class (or part of a test name)
tests/run.sh tests/Feature/NumberingTest.php  # one file
```

The first run installs PHPUnit and Mockery into `tests/tools/vendor` (needs internet, once). They are kept apart from
the application's `composer.json` so the server never installs them.

Every test starts from an **empty SQLite database in memory**, runs every migration, the seeders and the upgrades
(exactly like a new installation), then runs. Nothing touches MySQL or the live data. Settings are in `phpunit.xml`.

On GitHub, `.github/workflows/tests.yml` runs on every push: PHP syntax of every file, route and view compilation,
then all tests. A green tick next to the commit = safe to deploy; a red cross = open it, read the failing test.

## What is covered

| File | Proves |
|---|---|
| `Unit/TotpTest` | Two-factor codes match the authenticator apps (RFC 6238 vector), time window, base32. |
| `Feature/PtrRatesTest` | EUR→USD (1,500 × 1.16 = 1,740), USD→SYP (1,622 × 122 = 197,884), allocation split, treasury rate from the paid total. |
| `Feature/JarRatesTest` | JAR market rates → QuickBooks rates, rate check text. |
| `Feature/NumberingTest` | T2600001 then T2600002, a new year restarts, deleted number not reused, no cap after 99,999, RP numbers past 9,999. |
| `Feature/CurrencyRateTest` | Dated rates (rate in force on a date), pasting rates from Excel, audit row written. |
| `Feature/ProjectStatusTest` | Pipeline / Ongoing / Ended from the dates, numbers of the year. |
| `Feature/SpendLinesTest` | A final PTR writes its lines in US dollars; cancelling removes them. |
| `Feature/AuditTest` | The chain verifies; a row changed in the database is found; the model refuses edits and deletes. |
| `Feature/TwoFactorTest` | Password alone is not enough; wrong code refused; recovery code works once; required roles are sent to set it up. |
| `Feature/PagesTest` | Every main page opens for the administrator (catches errors 500 before the server does). |
| `Feature/DocumentFlowTest` | A PTR through the real pages: number, save = final, spend lines, treasury, unlock/lock, cancel, PDF print, uploader carries the number. |
| `Feature/ReceiptFlowTest` | Received payments: inform → project → record, same-currency shortcut, Dekont rule, audited delete. |
| `Feature/PermissionsTest` | A clerk cannot open admin, audit, rates, reports, reconciliation; cannot change money, unlock or delete; disabled users are signed out. |
| `Feature/SecurityTest` | Login lock after 6 wrong passwords, uploads checked by content (a script named .pdf is refused), password length, no error details shown. |
| `Feature/QbReconcileTest` | QuickBooks check: every status, CSV and Excel exports, detail reports, money and date formats. |
| `Feature/PerformanceTest` | **Volume** (not in the normal run): 400 projects, 6,000 forms, 12,000 lines; time of the busiest pages. |

## Three runs

| Run | Command | Where |
|---|---|---|
| Normal (SQLite in memory) | `tests/run.sh` | your machine, GitHub (with a coverage summary) |
| MySQL, like the server | `DB_CONNECTION=mysql DB_DATABASE=violet_test DB_USERNAME=... DB_PASSWORD=... tests/run.sh` (empty database, it is wiped) | GitHub job `mysql` |
| Volume | `tests/run.sh --group performance` | GitHub job `mysql`, after the tests |

The first volume run found the projects page took **17 s** with 400 projects (the budget lines were searched once per project);
grouping them once brought it to **1 s** with the same figures (checked number by number against the old code).

## Write a new test

Rule of thumb: **every bug gets a test that fails before the fix and passes after it.**

```php
<?php

namespace Tests\Feature;

use App\Forms\FormRegistry;
use Tests\TestCase;

// example: the field names (amount, total) are illustrative - use the keys of the real form
class RefundTest extends TestCase
{
    public function test_refund_total_is_the_sum_of_the_lines(): void
    {
        // 1. prepare: data, records (the seeded master data and the administrator already exist)
        $d = ['date' => '2026-11-01', 'currency_id' => $this->cur('USD')->id,
              'lines' => [['amount' => 100], ['amount' => 50.5]]];

        // 2. act: call the code
        $out = FormRegistry::get('refund')->compute($d);

        // 3. check
        $this->assertEqualsWithDelta(150.5, $out['total'], 0.001);
    }
}
```

Helpers in `tests/TestCase.php`: `$this->admin()` (the seeded super user), `$this->cur('EUR')` (a currency, created if
missing, `in_usd` set for strong currencies). Two-factor is off in tests unless a test turns it on.

Useful Laravel assertions:

```php
$this->actingAs($this->admin())->get('/projects')->assertOk();                 // a page opens
$this->post('/admin/rates', [...])->assertSessionHasNoErrors();                 // a form saves
$this->post('/login', [...])->assertRedirect(route('login.2fa'));               // where it goes
$this->assertDatabaseHas('reservations', ['number' => 'T2600001']);             // a row exists
$this->assertSame(1, \App\Models\AuditLog::count());                            // exact value
$this->assertEqualsWithDelta(1740, $value, 0.001);                              // money (never assertSame on floats)
```

Name tests as a sentence: `test_a_deleted_number_is_not_reused`. One behaviour per test.
Put pure calculations (no database) in `tests/Unit` extending `PHPUnit\Framework\TestCase` — they run in milliseconds.

## Before each push

```bash
tests/run.sh && git push
```
