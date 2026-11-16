# Architecture

## Stack

| Layer | Technology |
|---|---|
| Language / framework | PHP 8.3, Laravel 13 |
| Database | MySQL 8 / MariaDB 10.6+ in production; SQLite in memory for tests |
| Pages | Blade templates + Alpine.js (no build step: plain files in `public/`) |
| Excel | OpenSpout (`openspout/openspout`) — import and export of `.xlsx` |
| PDF | wkhtmltopdf (HTML → PDF) + qpdf (merging) — see "Known limits" |
| Icons / fonts | Font Awesome, served from `public/vendor` |
| QR codes (two-factor) | `public/vendor/qrcode.js` (qrcode-generator, MIT) |

There is no queue worker and no Node build: everything runs inside the web request or a console command.

## Folders

```
app/
  Forms/            the forms engine: FormRegistry, DynamicForm (schema → fields, compute), FormContext
  Support/Formula/  the formula language (Parser → Evaluator → Functions), Excel-like
  Support/          Upgrade + V3..V9 (versioned database upgrades), SchemaPatch, Numbering, Perms, Audit, Totp,
                    TreasuryRate, Print/ (PDF building)
  Services/         Workflow (save / lock / cancel), Expenses (spend_lines), LineBudget (budget figures),
                    FormView (what a page shows), Lookup (drop-down data), Files (uploads)
  Http/Controllers/ thin-ish controllers, Admin/ for administration pages
  Http/Middleware/  RequireTwoFactor, idle logout
  Models/           Eloquent models (one per table)
config/master.php   the master-data screens (columns, imports) — generic CRUD driven by config
database/migrations every schema change, in date order; the last one is always ..._violet_upgrade.php
database/seeders    first install only: master data (JSON in seeders/data), forms, the administrator
deploy/             violet (deployment tool), server-setup.sh
docs/               this documentation
resources/views/    Blade pages; print/ = PDF templates
routes/web.php      every page; routes/console.php = artisan commands
tests/              PHPUnit (Unit = pure functions, Feature = with database)
```

## A request, end to end (saving a PTR)

1. `GET /forms/ptr/new` → `DocumentController@create` → `FormView` builds the fields from `form_types.schema`.
2. The browser calculates live: every change posts the data to `POST /forms/ptr/compute`, which runs
   `DynamicForm::compute()` on the server (the browser never calculates money itself — one engine, one truth).
3. `POST /forms/ptr` → `DocumentController@store` → validation from the schema → `Workflow::save()` →
   `Workflow::finalize()`: the number is attached (`Reservation` → used), `state = done`, the document is locked.
4. `Expenses::sync($doc)` writes one `spend_lines` row per analytics line (budget line, amount, US dollars).
5. The print (`/documents/{id}/print`) renders `resources/views/print/*` through wkhtmltopdf.

## Main services

| Class | Responsibility |
|---|---|
| `Forms\FormRegistry` | Loads each `form_types` row once per request and returns a `DynamicForm`. `FormType::flush()` clears it. |
| `Forms\DynamicForm` | Field / table maps of a schema, `compute()` (formulas, locks), validation rules, `evaluate()` for one formula. |
| `Support\Formula\*` | The formula language — see forms-engine.md. |
| `Services\Workflow` | `save`, `finalize`, `cancel`, numbering of a document, document events (history). |
| `Services\Expenses` | `sync(doc)` / `rebuild()` of `spend_lines`. Called by Workflow and the treasury action. |
| `Services\LineBudget` | Budget-line figures: budget, spent, remaining in project currency and US dollars; project rate. |
| `Support\TreasuryRate` | Which rate the treasury changes when the paid amount differs from the expected one. |
| `Support\Numbering` | Yearly numbers of other records (received payments...). Transaction numbers: `Reservation::takeNumber`. |
| `Support\Audit` | Append-only, hash-chained audit trail. |
| `Support\Perms` | The permission catalogue (groups → abilities) and the default roles. |
| `Support\Upgrade` | Brings any database to the current version (see upgrades.md). |

## Scheduled work

There is no cron entry. Daily jobs run lazily on the first page view of the day:
`Project::refreshStatuses()` (guarded by the `projects_refreshed_on` setting) moves projects to Ongoing / Ended / Closed.

## Known limits (accepted for now)

- **wkhtmltopdf is no longer maintained.** It works and is pinned on the server. The replacement path is
  Chromium headless (`spatie/browsershot`) or Gotenberg; only `app/Support/Print` would change.
- **Form data is JSON** (`documents.data`). Reports that need lines read `spend_lines` (a flat table) instead —
  add more flat tables the same way rather than querying JSON.
- **Some controllers are long** (`DocumentController`, `ProjectController`). New logic goes to a Service class.
- **E-mail** depends on the `MAIL_*` values in `.env`; without them, "send by e-mail" buttons fail with a clear message.
