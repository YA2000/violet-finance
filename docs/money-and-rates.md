# Money, currencies and exchange rates

## Two ways of writing the same rate

| Name | Meaning | Example | Where |
|---|---|---|---|
| **Market way** (typed by people) | strong currencies: US dollars for 1 unit; the others: units for 1 US dollar | `1 EUR = 1.16 USD`, `1 USD = 122 SYP` | every rate field on a form, the rates page |
| **QuickBooks way** (stored, calculated) | US dollars for 1 unit, always | EUR `1.16`, SYP `0.0081967` | `currencies.usd_rate`, `currency_rates.usd_rate`, `home_rate`, JAR `advance_rate` / `invoice_rate` |

`currencies.in_usd = true` marks the strong currencies (EUR, GBP, KWD, CHF, JOD, BHD, OMR, KYD, GIP, FKP — `V7::IN_USD`).
Conversion: `qb = in_usd ? typed : 1 / typed`. `Currency::say($usdRate)` writes a stored rate back the market way.

## PTR

- `inv_per_usd` = invoice currency rate, `pay_per_usd` = payment currency rate (both market way; locked to 1 for USD).
- `exchange_rate` = invoice → payment units = `qb(invoice) / qb(payment)`.
- Line `expected_amount` = invoice amount × allocation × exchange_rate (in the payment currency) — this is what is paid.
- Line `home_amount` = invoice amount × allocation × `home_rate` (US dollars at the rate of this transaction).
- Examples (they are tests): 1,500 EUR at 1.16 paid in USD → 1,740.00; 1,622 USD paid in SYP at 122 → 197,884.

**Treasury** (`payments.pay`): when the amount really paid differs, the treasury types the paid total;
`TreasuryRate` works out the new rate (the payment rate, or the invoice rate when paying in USD) so that the lines add up
to the paid total. The change is recorded in the document history and the audit trail and `spend_lines` is rewritten.

## JAR

Since v9: `adv_market` and `inv_market` are typed the market way; `advance_rate` and `invoice_rate` (QuickBooks way,
used by the rest of the form) are calculated from them. Saved JARs were converted (the typed values were rebuilt from the
stored QuickBooks rates, so no amount changed). `jar_rate_check` shows "1 USD = 122 SYP · 1 USD = 125 SYP".

## Dated rates

`currency_rates` (Administration → Exchange rates; paste "date, code, rate" lines from Excel).
`Currency::usdRate($code, $date)` = the newest dated rate on or before the date; without one, `currencies.usd_rate`.
Saving rates also sets `currencies.usd_rate` to the newest rate. Used for: received payments without their own
project amount, `spend_lines` rows whose form has no `home_amount`.

## Budget figures (`LineBudget`)

- Spent per budget line = `spent_before` + `spend_lines` (+ approved allocations of the old payroll/shared-cost runs).
- A project's rate (project currency → USD): the average rate of its received payments; before the first payment the
  `agreement_rate`; else the rate table. `LineBudget::rateSource()` says which one was used (shown on the page).
- If figures ever look wrong after a direct database change or a form-builder change of the analytics:
  `php artisan violet:expenses-rebuild`.
