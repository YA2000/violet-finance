# Removed modules (November 2026)

These parts were replaced or abandoned and their code was deleted. **Their tables are still in the database** with the
old data; nothing reads them except where noted.

| Module | Replaced by | Tables kept |
|---|---|---|
| Advances (holders, payments, settlements) | The Advance and JAR forms + transaction numbers | `advances`, `advance_holders`, `advance_payments`, `advance_expenses`, `advance_settlements` |
| Payroll & allocation | QuickBooks + allocation done outside the system | `payroll_runs`, `payroll_lines`, `payroll_allocations`, `hr_contracts`, `loe_entries` |
| Shared costs / cost recovery / allocation lab | — | `expense_runs`, `expense_lines`, `expense_allocations`, `cost_categories`, `cost_category_lines`, `funding_lines`, `lab_*` |
| Remittance statement | The PTR print | `remittance_items` |
| Multi-step signing (submit / sign / return) and drawn signatures | One save = final; names printed on the English copy | `document_signatures` (names), `users.signature` (unused) |

Still reading old data: `LineBudget::allocations()` adds the *approved* rows of `payroll_allocations` and
`expense_allocations` to the spent figures so historical budgets do not change. If you drop those tables, first add
their totals to `budget_lines.spent_before`.

To drop the tables one day: export them (`mysqldump DB table1 table2 > old-modules.sql`), keep the file with the backups,
then add a migration with `Schema::dropIfExists(...)` — after removing the read in `LineBudget::allocations()`.

`spend_lines` is new (v9) and is not the old `expense_lines` table (which belonged to shared costs and keeps its data).
