# Data model

All money columns are `decimal`. Dates are `date`; times are stored in the application time zone (Europe/Istanbul, `+03:00`).
JSON columns: `documents.data`, `documents.extra`, `form_types.schema`, `audit_logs.before/after`.

## Forms and documents

| Table | What it holds |
|---|---|
| `form_types` | One row per form (ptr, jar, advance, journal, refcover, recon, bank, refund...). `schema` = the whole form (see forms-engine.md). `active=false` hides it. |
| `form_versions` | A copy of `schema` before every change (builder or upgrade). Restore from the builder. |
| `form_prints`, `form_numbers`, `form_labels`, `form_slots`, `form_roles`, `form_fields`, `form_field_configs` | Print layouts, the official form number printed in the footer, signature boxes (roles/slots), field metadata of the older forms. |
| `documents` | A filled form. `form_code`, `number` (T2600001), `state` (`draft`, `done`, `cancel`), `edit_open` (unlocked by a manager), `data` (all field values, JSON), `amount` + `currency` (header total, for lists), `paid_*` (treasury). |
| `document_events` | History shown on a document (saved, unlocked, value changed...). |
| `document_signatures` | Who signs which box on the print (names only — no drawn signatures any more). |
| `attachments` | Uploaded files (documents and received payments). Files live in `storage/app/private`. |
| `spend_lines` | **Flat copy of every final analytics line**: document, number, date, line no, budget_line_id, amount + currency, `usd`, note. Rebuilt by `Expenses::sync()`. Read by the budget figures. |

## Numbers

| Table | What it holds |
|---|---|
| `txn_types` | Transaction types (PTR, JAR, Journal...): which form, whether project / cost center / PR number are required. |
| `reservations` | Every transaction number ever given: `number`, `state` (`reserved`, `used`, `cancel`), the document that used it. |
| `txn_counters` | Next number per year. Only goes up — this is what guarantees "never reused". |

## Projects, budget lines, money in

| Table | What it holds |
|---|---|
| `projects` | `code` (298), `name` (2026-084-ALRAHMA), `year`, `year_no`, dates, `currency_id`, `agreement_rate`, `status` + `status_mode` (`auto` / `manual`), focal points (`*_officer_id` → individuals). |
| `project_amendments` | Extensions / modifications of a project (new end date, new budget) — same project, history kept. |
| `budget_lines` | Budget per line: `project_code`, `total_amount` (project currency), `spent_before` (before the system), `remaining`. |
| `receipts` | Received payments: `number` (RP-26-0001), bank account, amount + currency, `status` (`project` → `record` → `recorded`), project + amount in project currency. |
| `receipt_events` | History of a received payment. |

## Master data

`accounts`, `accounting_centers`, `cost_centers`, `individuals` (employees and focal points), `suppliers`, `partners`
(`short` = tag used in project codes), `list_items` (drop-down lists), `currencies` (`usd_rate`, `in_usd`),
`currency_rates` (dated rates: `currency_id`, `date`, `usd_rate` = US dollars for 1 unit, unique per day).
All of them are edited through the generic screen driven by `config/master.php`.

## Users and security

| Table | What it holds |
|---|---|
| `users` | `role` (legacy single role), `is_super`, `active`, `individual_id`, two-factor: `two_factor_secret` (encrypted), `two_factor_recovery` (encrypted list of hashes), `two_factor_confirmed_at`. |
| `roles`, `role_user` | Roles = list of abilities (see security.md). A user can have several roles. |
| `audit_logs` | Append-only trail: action, subject, summary, before/after, user, IP, `hash` (chained). |
| `settings` | Key/value: organisation names, e-mail target, `two_factor_required`, `upgrade_v<n>_done` markers, `projects_refreshed_on`... |
| `alerts`, `review_comments` | Notifications; comments of the review screen. |

## Tables kept from removed modules (no code reads them)

`advances`, `advance_*`, `payroll_*`, `expense_runs`, `expense_lines`, `expense_allocations`, `cost_categories`,
`cost_category_lines`, `funding_lines`, `hr_contracts`, `loe_entries`, `lab_*`, `remittance_items`.
`LineBudget::allocations()` still reads `payroll_allocations` / `expense_allocations` (approved runs) if the tables
exist, so historical budget figures do not change. Drop them only after exporting them — see removed-modules.md.
