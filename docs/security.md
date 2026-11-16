# Security

## Sign-in

- E-mail + password (minimum 10 characters for new passwords). Login is rate-limited (6 wrong passwords → 2 minutes wait).
- Idle sessions are closed (`IdleTimeout` middleware, minutes in Settings).
- **Two-factor sign-in (TOTP)** — Google Authenticator, Microsoft Authenticator, 1Password...
  - `My account → Two-factor sign-in`: scan the QR code, type a code to confirm, keep the 8 recovery codes.
  - Required (setting `two_factor_required`, on by default) for administrators and for every user whose roles include a
    sensitive ability (`User::SENSITIVE`: users, roles, settings, form builder, documents.manage, numbers.manage,
    receipts.manage, payments.pay). They are sent to their account page until it is on.
  - Login flow: password checked → `/login/code` (valid 5 minutes; 5 wrong codes → 5 minutes wait) → signed in. A recovery code works once.
  - Secret stored encrypted with `APP_KEY` (`users.two_factor_secret`). **Losing `APP_KEY` = every user must set it up again.**
  - Lost phone: an administrator presses *Reset two-factor* on the user page, or on the server
    `php artisan violet:2fa-reset user@example.org`.
  - Code: `app/Support/Totp.php` (RFC 6238, checked against the RFC test vector), `AuthController`, `AccountController`,
    `RequireTwoFactor` middleware.

## Permissions

- Abilities are strings (`documents.manage`, `master.currencies.manage`...). The catalogue and default roles are in
  `app/Support/Perms.php`; roles are edited in **Administration → Roles & permissions**.
- `User::hasPerm()` / `hasAnyPerm('master.*')`; `is_super` users have everything.
- `Gate::before` (AppServiceProvider) answers every ability from `hasPerm()`, so routes use `->middleware('can:users.manage')` and views use `@can`.
  Route groups in `routes/web.php` are the main guard; controllers re-check only for record-level rules
  (e.g. only the preparer or a manager can cancel).

| Group | Abilities |
|---|---|
| Transaction numbers | numbers.reserve, numbers.manage |
| Documents | documents.manage, review.access |
| Treasury | payments.pay |
| Received payments | receipts.inform, receipts.assign, receipts.record, receipts.view, receipts.manage |
| Reports | reports.view |
| Master data | master.<table>.manage (projects, budget_lines, accounts, accounting_centers, cost_centers, individuals, suppliers, partners, currencies, list_items, txn_types) |
| Administration | forms.design, users.manage, roles.manage, settings.manage |

## Audit trail

`audit_logs` + `App\Support\Audit::log($action, $subject, $summary, $before, $after)`.

- Written for: document unlock / cancel / delete / treasury value; reservations edited, cancelled, deleted; received
  payments corrected or deleted; project edits, extensions; users, roles, two-factor resets, the two-factor setting;
  exchange rates; password changes.
- **Append-only**: the model refuses update and delete. Each row stores `hash = sha256(previous hash + this row)`,
  so a row changed or removed directly in the database breaks the chain.
- Check it: Administration → Audit trail → *Check the chain*, or `php artisan violet:audit-verify` (exit code 1 if broken).
- Writing the audit row never stops the action: an error is reported to the log instead.

## Secrets and files

- `.env` holds `APP_KEY`, database and mail passwords. It is in every backup folder — keep `/root/violet-backups` private (700).
- Uploaded files are in `storage/app/private`, served only through controllers that check access.
- `APP_DEBUG=false` on live (a 500 shows the friendly page; details go to `storage/logs`).
