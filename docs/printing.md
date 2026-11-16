# Printing (PDF)

`app/Support/Print/Pdf.php` turns a printout into a PDF: Blade (`resources/views/print/document.blade.php`) → HTML → PDF.

## Engines

| Engine | When | Notes |
|---|---|---|
| **Chrome headless** (default) | Chrome or Chromium is installed on the server | Maintained, same rendering family (WebKit/Blink) as the old engine; ~1 s per page set |
| wkhtmltopdf | Chrome missing, or `PDF_ENGINE=wkhtmltopdf` | No longer maintained (archived in 2023); kept only as a fallback |

`.env`: `PDF_ENGINE=auto|chrome|wkhtmltopdf` (default `auto`), `CHROME_BIN=/usr/bin/google-chrome-stable` (optional; found by itself).

Install on the server: `sudo bash deploy/install-server-tools.sh` (adds Google's apt source and `google-chrome-stable`, plus qpdf).
Check: `sudo -u <site user> php8.3 artisan violet:pdf-check` → `Engine: chrome ... Test page OK`.

## How the Chrome engine keeps the same layout

- Page size and margins (A4, 8 / 14 / 8 / 8 mm, portrait or landscape) are written as a CSS `@page` rule.
- The footer (left / center / right text with `[page]` and `[pages]`) becomes CSS page-margin boxes
  (`@bottom-left`, `@bottom-center`, `@bottom-right`, `counter(page)`, `counter(pages)`) — `Pdf::pageCss()`.
- Fonts are the same local files (`public/fonts`, Carlito / Noto Sans Arabic / Zilla Slab); nothing is downloaded.
- Each run uses a fresh temporary HOME (throw-away profile). Do **not** pass `--user-data-dir`: Chrome then hangs.
- Attachments are merged as before (qpdf → pdfunite → Ghostscript). Images become pages through the same engine.

## If printing fails

`violet log live` shows `PDF generation failed (Chrome): ...`. Usual causes: Chrome missing (run the installer),
`/tmp` full, or the site user cannot write `storage/app/tmp`. Quick way back to the old engine: `PDF_ENGINE=wkhtmltopdf` in `.env`
(needs `WITH_WKHTMLTOPDF=1 bash deploy/install-server-tools.sh`), then `php artisan config:cache`.
