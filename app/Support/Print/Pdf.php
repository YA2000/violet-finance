<?php

namespace App\Support\Print;

use App\Models\Document;
use App\Models\FormPrint;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders a printout: Blade -> HTML -> PDF.
 * Engine: Chrome headless (maintained, same rendering family as wkhtmltopdf) when the server has it, else wkhtmltopdf.
 * PDF_ENGINE=chrome|wkhtmltopdf|auto (default auto). See docs/printing.md.
 */
class Pdf
{
    public static function binary(): string
    {
        return config('services.wkhtmltopdf') ?: '/usr/local/bin/wkhtmltopdf';
    }

    /** Path of Chrome / Chromium, or null. */
    public static function chrome(): ?string
    {
        $set = config('services.chrome');
        if ($set) {
            return is_executable($set) ? $set : null;
        }
        foreach (['google-chrome-stable', 'google-chrome', 'chromium', 'chromium-browser'] as $b) {
            $path = trim((string) @shell_exec('command -v ' . $b . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }
        return null;
    }

    /** 'chrome' or 'wkhtmltopdf' */
    public static function engine(): string
    {
        $want = config('services.pdf_engine', 'auto');
        if ($want === 'wkhtmltopdf') {
            return 'wkhtmltopdf';
        }
        if (static::chrome()) {
            return 'chrome';
        }
        if ($want === 'chrome') {
            throw new RuntimeException('PDF_ENGINE=chrome but Chrome is not installed on the server (see docs/printing.md).');
        }
        return 'wkhtmltopdf';
    }

    public static function printFor(Document $doc, string $report): FormPrint
    {
        $p = FormPrint::find((int) $report);
        if (!$p || $p->form_type_id !== $doc->formType()?->id) {
            throw new RuntimeException('Unknown printout ' . $report);
        }
        return $p;
    }

    /** $noSig: leave the signatures table out (the merged print signs once, on the English copy). */
    public static function html(Document $doc, string $report, bool $noSig = false): string
    {
        $doc->loadMissing('signatures.individual');
        return view('print.document', ['o' => $doc, 'p' => static::printFor($doc, $report), 'fontCss' => Assets::fontCss(), 'noSig' => $noSig])->render();
    }

    public static function render(Document $doc, string $report, bool $noSig = false): string
    {
        if (static::engine() === 'chrome') {
            return static::chromePdf(static::withCss(static::html($doc, $report, $noSig), static::pageCss(static::printFor($doc, $report))));
        }
        $p = static::printFor($doc, $report);
        $tmp = storage_path('app/tmp/' . uniqid('pdf_', true));
        @mkdir($tmp, 0775, true);
        file_put_contents("$tmp/body.html", static::html($doc, $report, $noSig));
        file_put_contents("$tmp/footer.html", view('print.footer', ['o' => $doc, 'p' => $p, 'fontCss' => Assets::fontCss()])->render());
        $args = [static::binary(), '--quiet', '--encoding', 'utf-8', '--page-size', 'A4',
            '--orientation', $p->orientation === 'landscape' ? 'Landscape' : 'Portrait',
            '--margin-top', '8mm', '--margin-bottom', '14mm', '--margin-left', '8mm', '--margin-right', '8mm',
            '--dpi', '96', '--disable-smart-shrinking', '--enable-local-file-access',
            '--footer-html', "$tmp/footer.html", "$tmp/body.html", "$tmp/out.pdf"];
        $proc = new Process($args, $tmp, null, null, 120);
        $proc->run();
        if (!is_file("$tmp/out.pdf")) {
            throw new RuntimeException('PDF generation failed: ' . $proc->getErrorOutput());
        }
        $pdf = file_get_contents("$tmp/out.pdf");
        array_map('unlink', glob("$tmp/*"));
        @rmdir($tmp);
        return $pdf;
    }

    /** Merge PDFs (printout + attachments) with qpdf, pdfunite or Ghostscript - whichever the server has. */
    public static function merge(array $files, string $out): void
    {
        $tools = [
            'qpdf' => fn ($b) => array_merge([$b, '--empty', '--pages'], $files, ['--', $out]),
            'pdfunite' => fn ($b) => array_merge([$b], $files, [$out]),
            'gs' => fn ($b) => array_merge([$b, '-dBATCH', '-dNOPAUSE', '-q', '-sDEVICE=pdfwrite', '-sOutputFile=' . $out], $files),
        ];
        foreach ($tools as $tool => $cmd) {
            $bin = trim((string) shell_exec('command -v ' . $tool . ' 2>/dev/null'));
            if ($bin) {
                $p = new Process($cmd($bin), null, null, null, 180);
                $p->run();
                if (is_file($out) && filesize($out) > 0) {
                    return;
                }
            }
        }
        throw new RuntimeException('Install qpdf or poppler-utils on the server to merge PDF files.');
    }

    /** Image -> one-page PDF. */
    public static function imageToPdf(string $image, string $out): void
    {
        $tmp = dirname($out) . '/' . uniqid('img_') . '.html';
        $src = 'data:' . mime_content_type($image) . ';base64,' . base64_encode(file_get_contents($image));
        if (static::engine() === 'chrome') {
            file_put_contents($out, static::chromePdf('<html><head><style>@page { size: A4; margin: 10mm; }</style></head><body style="margin:0;text-align:center"><img src="' . $src . '" style="max-width:100%;max-height:270mm"/></body></html>'));
            return;
        }
        file_put_contents($tmp, '<html><body style="margin:0;text-align:center"><img src="' . $src . '" style="max-width:100%;max-height:270mm"/></body></html>');
        $p = new Process([static::binary(), '--quiet', '--page-size', 'A4', '--margin-top', '10mm', '--margin-bottom', '10mm', $tmp, $out], null, null, null, 120);
        $p->run();
        @unlink($tmp);
    }

    /**
     * Page size, margins and the footer (left / center / right, with page numbers) as CSS page-margin boxes:
     * the same layout wkhtmltopdf made with --footer-html.
     */
    public static function pageCss(FormPrint $p): string
    {
        $land = $p->orientation === 'landscape';
        $box = function (string $part, string $align, string $width) use ($p): string {
            $raw = (string) $p->subst($p->{'footer_' . $part});
            $items = [];
            foreach (preg_split('/(\[pages\]|\[page\])/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) as $bit) {
                if ($bit === '[page]') {
                    $items[] = 'counter(page)';
                } elseif ($bit === '[pages]') {
                    $items[] = 'counter(pages)';
                } elseif ($bit !== '') {
                    $t = str_replace(["\\", '"', "\r\n", "\r", "\n"], ['\\\\', '\\"', "\n", "\n", '\\A '], $bit);
                    $items[] = '"' . $t . '"';
                }
            }
            $content = $items ? implode(' ', $items) : '""';
            return "@bottom-$part { content: $content; white-space: pre-wrap; font-family: 'Carlito', 'Noto Sans Arabic', sans-serif; font-size: 8pt;"
                . " color: #333; vertical-align: top; text-align: $align; width: $width; border-top: 1px solid #999; padding-top: 1.5mm; }";
        };
        return '@page { size: A4 ' . ($land ? 'landscape' : 'portrait') . '; margin: 8mm 8mm 14mm 8mm; '
            . $box('left', 'left', '33%') . ' ' . $box('center', 'center', '34%') . ' ' . $box('right', 'right', '33%') . ' }';
    }

    protected static function withCss(string $html, string $css): string
    {
        $tag = '<style>' . $css . '</style>';
        return stripos($html, '</head>') !== false ? preg_replace('~</head>~i', $tag . '</head>', $html, 1) : $tag . $html;
    }

    /** HTML -> PDF with Chrome headless (HOME points to a fresh folder, so each run has its own throw-away profile; --user-data-dir makes Chrome hang). */
    public static function chromePdf(string $html): string
    {
        $tmp = storage_path('app/tmp/' . uniqid('chr_', true));
        @mkdir($tmp, 0775, true);
        file_put_contents("$tmp/body.html", $html);
        $args = [static::chrome(), '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run',
            '--disable-extensions', '--disable-background-networking', '--no-pdf-header-footer', '--run-all-compositor-stages-before-draw',
            '--print-to-pdf=' . "$tmp/out.pdf", 'file://' . "$tmp/body.html"];
        $proc = new Process($args, $tmp, ['HOME' => $tmp], null, 120);
        $proc->run();
        if (!is_file("$tmp/out.pdf") || filesize("$tmp/out.pdf") === 0) {
            $err = trim($proc->getErrorOutput());
            static::rmTree($tmp);
            throw new RuntimeException('PDF generation failed (Chrome): ' . mb_substr($err, -500));
        }
        $pdf = file_get_contents("$tmp/out.pdf");
        static::rmTree($tmp);
        return $pdf;
    }

    protected static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
