<?php

namespace App\Support\Print;

class Assets
{
    public static function logoDataUri(): string
    {
        static $uri;
        return $uri ??= 'data:image/jpeg;base64,' . base64_encode(file_get_contents(public_path('img/logo.jpeg')));
    }

    /** @font-face rules with local file URLs (wkhtmltopdf runs with local file access). */
    public static function fontCss(): string
    {
        $faces = [['Zilla Slab', 'ZillaSlab-Light.ttf', 300], ['Zilla Slab', 'ZillaSlab-Regular.ttf', 400],
                  ['Zilla Slab', 'ZillaSlab-SemiBold.ttf', 500], ['Zilla Slab', 'ZillaSlab-Bold.ttf', 700],
                  ['Carlito', 'Carlito-Regular.ttf', 400], ['Carlito', 'Carlito-Bold.ttf', 700]];
        $css = '';
        foreach ($faces as [$fam, $file, $w]) {
            $css .= sprintf("@font-face{font-family:'%s';font-weight:%d;font-style:normal;src:url('file://%s') format('truetype');}\n",
                $fam, $w, public_path('fonts/' . $file));
        }
        return $css;
    }
}
