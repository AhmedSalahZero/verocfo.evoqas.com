<?php

namespace App\Exports\Support;

// ══════════════════════════════════════════════════════════════════
//  SafeValueBinder
//
//  Decides how every value is written into an Excel cell, for ALL
//  exports at once (registered in config/excel.php). Audit L4 — two
//  problems fixed in this one place:
//
//  1. AMOUNTS WERE TEXT. The exports format money with number_format(),
//     which produces a string such as "1250.50"; Excel then stores it as
//     text, so the column cannot be summed, sorted or charted. A plain
//     decimal string ("1250.50", "-300.0000", "0.045000") is now written
//     as a real number, shown with the same number of decimals it had
//     (#,##0.00 / #,##0.0000 / ...). It is only a decimal string that is
//     converted — never integers or codes such as "000123", an account
//     number or a cheque number, which stay exactly as typed.
//
//  2. SPREADSHEET FORMULA INJECTION. Text a user typed (a comment, a
//     name, a description) that starts with = + - @ (or a tab / carriage
//     return) would be run as a formula when the file is opened — for
//     example a comment of  =HYPERLINK("http://evil",...)  . Such text is
//     now stored as plain text and flagged so Excel never evaluates it.
//     Genuine negative numbers ("-5") are not affected.
//
//  Everything else is delegated to Laravel Excel's own binder, so
//  behaviour is unchanged for dates, words, blanks and arrays.
//
//  Note on digits: a number written to Excel is held by Excel as a
//  double. That is a property of the file format itself, not something
//  the application can change; the exact decimal string is what the
//  database and the books keep.
//
//  @layer    Export (support)
//  @depends  Maatwebsite\Excel\DefaultValueBinder
// ══════════════════════════════════════════════════════════════════

use Maatwebsite\Excel\DefaultValueBinder as LaravelExcelBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\IValueBinder;

class SafeValueBinder implements IValueBinder
{
    /** A plain decimal: optional minus, no leading zeros, up to 15 integer digits, 1–6 decimals. */
    private const DECIMAL = '/^-?(0|[1-9]\d{0,14})\.(\d{1,6})$/';

    private LaravelExcelBinder $inner;

    public function __construct()
    {
        $this->inner = new LaravelExcelBinder();
    }

    public function bindValue(Cell $cell, $value = null): bool
    {
        if (is_string($value)) {
            // 1. Money / rates → a real number with a matching display format.
            if (preg_match(self::DECIMAL, $value, $m) === 1) {
                $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.'.str_repeat('0', strlen($m[2])));

                return true;
            }

            // 2. Text that Excel would run as a formula → keep it as text.
            if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false && ! is_numeric($value)) {
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
                $cell->getStyle()->setQuotePrefix(true);

                return true;
            }
        }

        $this->inner->bindValue($cell, $value);

        return true;
    }
}
