# QuickBooks reconciliation

Menu **Control → QuickBooks check** (`/reconcile`, ability `reports.view`). Code: `app/Services/QbReconcile.php`,
`app/Http/Controllers/ReconcileController.php`, table `qb_reconciliations`.

## The key

Every uploader the system makes writes the transaction number as `RefNumber`, so in QuickBooks the bill or journal entry has
**No. = T2600123**. The check matches on that number — not on amounts or names.

## Types of transactions (how Violet works)

| Form in the system | In QuickBooks |
|---|---|
| PTR, JAR | **Bill** |
| Journal | **Journal Entry**, or a **Payment** (bill payment) |

`QbReconcile::KINDS` holds this map; `QbReconcile::kind()` reads the QuickBooks *Transaction Type* (Bill / Journal Entry /
Bill Payment (Check) / Payment...). A number recorded as another type gets the status *Wrong type in QuickBooks*.
The result page shows matched / to check per type (Bills, Journal entries, Payments).

## The file

Any QuickBooks report exported to Excel or CSV with a number column and an amount column. Recommended:
**Reports → Transaction List by Date → choose the period → Export to Excel** (one row per transaction). With more than one
currency, add the columns **Foreign Amount** and **Currency** (Customize → Rows/Columns).

Detected by header name (first 30 rows): number (`No.`, `Num`, `RefNumber`...), date, type, name, memo, account, amount
(`Amount`, `Total`...), foreign amount, currency. Title rows, group headers and `Total` rows are skipped. Detail reports
(several rows per transaction) work too: rows on an *Accounts Payable* account are set aside, the expense rows are summed.
A number written only in the memo is found too.

## The comparison

System side: final forms (`state = done`) of the forms that have an uploader, dated in the period (default: first to last
date of the file). Amount = header amount in the payment currency; USD = sum of `spend_lines.usd` (or amount × dated rate).

- With a *Foreign Amount* column: compared with the amount in the payment currency.
- Without it: QuickBooks *Amount* (home currency, USD) compared with the system USD.
- Allowed difference (default 1.00) covers rate rounding.

| Status | Meaning | What to do |
|---|---|---|
| Matched | same number and amount | nothing |
| Amount differs | in both, different amount | open the form; correct QuickBooks or the form (treasury) |
| Not in QuickBooks | final in the system, not in the file | upload it (a note says if it was marked as uploaded) |
| Cancelled, still in QuickBooks | cancelled in the system | void/delete it in QuickBooks |
| Unknown number | a T-number the system never gave | entered by hand in QuickBooks with a wrong number |
| Wrong type in QuickBooks | e.g. a PTR recorded as a Journal Entry, a Journal as a Bill | re-enter it as the right type |
| Twice in QuickBooks | one number, several transactions (another date or another type) | duplicate upload: delete one |
| Not final in the system | in QuickBooks before the form was saved | save the form or remove it from QuickBooks |
| Other period | final in the system with a date outside the period | dates differ between the two |

QuickBooks rows without a system number (payments, deposits, transfers) are counted and listed apart, not compared.

Every check is stored (period, file name, columns used, full result) and written to the audit trail; the result can be
downloaded as Excel. Tests: `tests/Feature/QbReconcileTest.php`.

## Next steps (not built)

Line-level check (budget line / item and customer per line) once the QuickBooks report with *Item* and *Customer* columns is agreed.
