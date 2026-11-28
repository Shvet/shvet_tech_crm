<?php

namespace App\Support;

/** CSV helpers. */
final class CsvCell
{
    /**
     * Neutralizes spreadsheet formula injection (CWE-1236): a cell that a spreadsheet would evaluate
     * (=, +, -, @, tab, carriage return) is prefixed with an apostrophe so it is shown as text.
     */
    public static function safe(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
