<?php

namespace App\Support;

/**
 * A tiny PDF writer for plain statements (Phase B9): text and ruled tables on A4 pages in the built-in Helvetica fonts. It exists so
 * statements can be downloaded without adding a library to the server. It draws no images and runs no scripts, and every string is
 * escaped. Text outside Latin-1 is replaced with "?" (names and notes are shown as best the standard fonts allow).
 */
class SimplePdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const MARGIN = 40;

    /** @var array<int, string> content stream of each page */
    private $pages = [];

    /** @var float the current line, from the top of the page */
    private $y = 0.0;

    private $title;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->pages[] = '';
        $this->y = self::MARGIN;
    }

    /** Makes sure $height points fit on this page, or starts a new one */
    public function ensure(float $height): void
    {
        if ($this->y + $height > self::H - self::MARGIN - 18) {
            $this->newPage();
        }
    }

    /** Approximate width of a string in Helvetica at $size points (digits and punctuation are exact enough for right-aligning figures) */
    public function width(string $text, float $size, bool $bold = false): float
    {
        $units = 0.0;
        $text = $this->latin($text);
        if ($text === '') {
            return 0.0;   // (str_split('') is [''] on PHP 7.4 and [] from 8.2; either way there is nothing to measure)
        }
        foreach (str_split($text) as $ch) {
            if (ctype_digit($ch)) {
                $units += 0.556;
            } elseif (strpos(',.:;\'|!i l', $ch) !== false) {
                $units += 0.278;
            } elseif (strpos('-()', $ch) !== false) {
                $units += 0.333;
            } elseif (ctype_upper($ch)) {
                $units += 0.69;
            } elseif ($ch === ' ') {
                $units += 0.278;
            } else {
                $units += 0.53;
            }
        }

        return $units * $size * ($bold ? 1.06 : 1.0);
    }

    private function latin(string $text): string
    {
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}", "\u{00B7}"], ["'", "'", '"', '"', '-', '-', '...', '-'], $text);
        $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        $out = $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $out;

        return preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $out);
    }

    private function esc(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->latin($text));
    }

    /** Draws text with its left edge at $x on the current line (or its right edge when $right) */
    public function text(string $text, float $x, float $size = 10, bool $bold = false, bool $right = false, ?float $y = null): void
    {
        $y = $y ?? $this->y;
        if ($right) {
            $x -= $this->width($text, $size, $bold);
        }
        $this->pages[count($this->pages) - 1] .= sprintf("BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, self::H - $y - $size, $this->esc($text));
    }

    public function rule(float $y = null, float $gray = 0.8): void
    {
        $y = self::H - ($y ?? $this->y);
        $this->pages[count($this->pages) - 1] .= sprintf("%.2f G 0.5 w %.2f %.2f m %.2f %.2f l S\n", $gray, self::MARGIN, $y, self::W - self::MARGIN, $y);
    }

    public function gap(float $points): void
    {
        $this->y += $points;
    }

    public function heading(string $text, float $size = 16): void
    {
        $this->ensure($size + 10);
        $this->text($text, self::MARGIN, $size, true);
        $this->y += $size + 8;
    }

    public function line(string $text, float $size = 10, bool $bold = false): void
    {
        $this->ensure($size + 6);
        $this->text($text, self::MARGIN, $size, $bold);
        $this->y += $size + 5;
    }

    /** A label and its value on one line */
    public function pair(string $label, string $value, bool $bold = false): void
    {
        $this->ensure(16);
        $this->text($label, self::MARGIN, 10);
        $this->text($value, self::W - self::MARGIN, 10, $bold, true);
        $this->y += 15;
    }

    /**
     * A table. $cols: [['label' => 'Date', 'w' => 80, 'align' => 'l'|'r'], ...] (widths in points, summing to about 515). The header
     * repeats on every page. Long text is shortened to fit its column.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    public function table(array $cols, iterable $rows): void
    {
        $header = function () use ($cols) {
            $this->ensure(30);
            $x = self::MARGIN;
            foreach ($cols as $c) {
                $this->text($c['label'], $c['align'] === 'r' ? $x + $c['w'] - 4 : $x + 2, 8.5, true, $c['align'] === 'r');
                $x += $c['w'];
            }
            $this->y += 13;
            $this->rule();
            $this->y += 4;
        };
        $header();
        foreach ($rows as $row) {
            if ($this->y + 14 > self::H - self::MARGIN - 18) {
                $this->newPage();
                $header();
            }
            $x = self::MARGIN;
            foreach ($cols as $i => $c) {
                $cell = $this->fit((string) ($row[$i] ?? ''), $c['w'] - 6, 8.5);
                $this->text($cell, $c['align'] === 'r' ? $x + $c['w'] - 4 : $x + 2, 8.5, false, $c['align'] === 'r');
                $x += $c['w'];
            }
            $this->y += 13;
        }
        $this->rule();
        $this->y += 6;
    }

    private function fit(string $text, float $max, float $size): string
    {
        $text = $this->latin($text);
        if ($this->width($text, $size) <= $max) {
            return $text;
        }
        while ($text !== '' && $this->width($text.'...', $size) > $max) {
            $text = substr($text, 0, -1);
        }

        return $text.'...';
    }

    /** The finished file (binary string) */
    public function output(): string
    {
        $count = count($this->pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (5 + $i * 2).' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            // The page number is added last, when the number of pages is known
            $content .= sprintf("BT /F1 8 Tf %.2f %.2f Td (%s) Tj ET\n", self::W / 2 - 25, 24, 'Page '.($i + 1).' of '.$count);
            $stream = 5 + $i * 2 + 1;
            $objects[5 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::W.' '.self::H.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$stream.' 0 R >>';
            $objects[$stream] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
        }
        $info = count($objects) + 1;
        $objects[$info] = '<< /Title ('.$this->esc($this->title).') /Producer (WorkMate) /CreationDate (D:'.gmdate('YmdHis').'Z) >>';

        $out = "%PDF-1.4\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= $n." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R /Info '.$info." 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $out;
    }
}
