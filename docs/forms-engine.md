# The forms engine

Every form (PTR, JAR, Advance...) is a JSON document in `form_types.schema`. The same schema drives the entry page,
the server calculation, validation, the print and the reports. Administrators change it in **Administration → Form builder**.

## Schema shape

```json
{
  "version": 3,
  "blocks": [
    { "type": "section", "title": "Payment", "fields": [
        { "key": "invoice_currency_id", "type": "lookup", "source": "currencies", "required": true, "width": 3 },
        { "key": "inv_per_usd", "type": "number", "decimals": 4, "lock_when": "LOOKUP(invoice_currency_id,\"code\")=\"USD\"", "lock_value": "1" },
        { "key": "exchange_rate", "type": "formula", "formula": "IF(OR(inv_per_usd=0,pay_per_usd=0),0, ...)" }
    ]},
    { "type": "table", "key": "lines", "columns": [
        { "key": "invoice_amount", "type": "money" },
        { "key": "expected_amount", "type": "formula", "formula": "IFERROR(ROUND(invoice_amount*IF(ISBLANK(allocation),1,allocation)*exchange_rate,2),0)" }
    ]}
  ],
  "settings": { "analytics": { "table": "lines", "amount": "expected_amount", "budget_line": "budget_line_id" } }
}
```

Field types: `text`, `textarea`, `number`, `money`, `date`, `lookup` (drop-down from a master table or list),
`check`, `formula` (read-only, calculated), `table` (rows with columns). Common options: `required`, `width` (1–12),
`default`, `hint`, `decimals`, `lock_when` + `lock_value` (fixed value under a condition, e.g. the USD rate is 1).

`settings.analytics` tells the system which table is the expense lines, which column is the amount and which column
is the budget line. Only forms with analytics write `spend_lines`.

## Calculation (`DynamicForm::compute`)

1. Header pass: locked inputs get their `lock_value`, then every header formula is evaluated in schema order.
2. Each table row: every column formula twice (so a column may use one defined after it).
3. Header pass twice more (totals like `SUM(lines.expected_amount)` see the finished rows).

The browser posts the data to `/forms/{code}/compute` on every change and shows the answer: there is one engine only,
on the server, so a print or a report can never disagree with the screen.

## The formula language (`app/Support/Formula`)

Excel-like: `Parser` → AST → `Evaluator` with a `Context` (the document data, the current row, meta values like `_date`).

| Group | Functions |
|---|---|
| Logic | `IF`, `IFERROR`, `AND`, `OR`, `NOT`, `ISBLANK`, `ISNUMBER`, `SWITCH`, `COALESCE` |
| Tables | `SUM`, `AVERAGE`, `MIN`, `MAX`, `COUNT`, `SUMIF`, `COUNTIF`, `LAST`, `CUMSUM`, `PREV`, `ROWNUM` |
| Numbers | `ROUND`, `ROUNDUP`, `ROUNDDOWN`, `ABS`, `INT`, `MOD`, `VALUE` |
| Data | `LOOKUP(field,"column")` — a column of the record chosen in a lookup field; `FIND("table","column",value,"return column")` |
| Text | `CONCAT`, `TEXTJOIN`, `TRIM`, `UPPER`, `LOWER`, `LEFT`, `RIGHT`, `LEN`, `TEXT`, `&` |
| Dates | `TODAY`, `YEAR`, `MONTH`, `DAY`, `DAYS`, `ADDDAYS`, `DATEFORMAT` |

`lines.amount` inside an aggregate means "the column of every row". An error inside a formula makes that field empty
and is listed under the form (never a page error).

## Changing a form in code (`SchemaPatch`)

Never edit a schema by hand in the database. In an upgrade class:

```php
$p = new SchemaPatch($ft->schema);
$p->insertAfter('currency_id', ['key' => 'adv_market', 'type' => 'number', ...], '');   // '' = header, or a table key
$p->set('advance_rate', ['type' => 'formula', 'formula' => '...'], '');
if ($p->changed) {
    FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v9']);
    $ft->forceFill(['schema' => $p->schema])->save();
}
```

Check `$p->has()` first so the step does nothing on a form an administrator already changed. Saved documents keep their
data: if a field changes meaning, convert the stored values in the same step (V7 and V9 do this for the rates).

## Prints

`form_prints` holds the print layouts of each form; `resources/views/print/*` render them; `app/Support/Print`
turns HTML into PDF (wkhtmltopdf) and merges copies (qpdf). The English copy carries the signatures; the Arabic copy is a translation.
