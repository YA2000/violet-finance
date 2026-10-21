<?php

namespace App\Support\Print;

use App\Models\Document;
use App\Models\FormPrint;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Renders a printout (Blade -> HTML -> wkhtmltopdf), same engine and settings as the Excel-identical Odoo version. */
class Pdf
{
    public static function binary(): string
    {
        return config('services.wkhtmltopdf') ?: '/usr/local/bin/wkhtmltopdf';
    }

    public static function printFor(Document $doc, string $report): FormPrint
    {
        $p = FormPrint::find((int) $report);
        if (!$p || $p->form_type_id !== $doc->formType()?->id) {
            throw new RuntimeException('Unknown printout ' . $report);
        }
        return $p;
    }

    public static function html(Document $doc, string $report): string
    {
        $doc->loadMissing('signatures.individual');
        return view('print.document', ['o' => $doc, 'p' => static::printFor($doc, $report), 'fontCss' => Assets::fontCss()])->render();
    }

    public static function render(Document $doc, string $report): string
    {
        $p = static::printFor($doc, $report);
        $tmp = storage_path('app/tmp/' . uniqid('pdf_', true));
        @mkdir($tmp, 0775, true);
        file_put_contents("$tmp/body.html", static::html($doc, $report));
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

    /** Image -> one-page PDF (through wkhtmltopdf). */
    public static function imageToPdf(string $image, string $out): void
    {
        $tmp = dirname($out) . '/' . uniqid('img_') . '.html';
        $src = 'data:' . mime_content_type($image) . ';base64,' . base64_encode(file_get_contents($image));
        file_put_contents($tmp, '<html><body style="margin:0;text-align:center"><img src="' . $src . '" style="max-width:100%;max-height:270mm"/></body></html>');
        $p = new Process([static::binary(), '--quiet', '--page-size', 'A4', '--margin-top', '10mm', '--margin-bottom', '10mm', $tmp, $out], null, null, null, 120);
        $p->run();
        @unlink($tmp);
    }
}
