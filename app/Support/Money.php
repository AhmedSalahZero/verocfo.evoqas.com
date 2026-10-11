<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  Money
//
//  Exact-decimal helpers for the places that used to turn money into a
//  PHP float just to round it, format it or compare it (Audit L5,
//  Rule 4: never use floating-point arithmetic on money).
//
//  Everything works on decimal STRINGS with bcmath, so 0.1 + 0.2 is
//  0.30, a total of a million rows is exact, and rounding is the usual
//  "half away from zero" (2.345 → 2.35, −2.345 → −2.35), the same result
//  number_format() gives for ordinary amounts — without ever passing
//  through a binary float.
//
//  Usage:
//    Money::round('1234.5678')            → '1234.57'
//    Money::round($row->amount, 4)        → 4 decimals
//    Money::sum([$a, $b, $c])             → exact total, 2 decimals
//    Money::display('1234567.5')          → '1,234,567.50'   (for messages)
//    Money::isPositive($x) / isZero($x)   → exact comparisons
//
//  Anything that is not a plain number (null, '', 'abc') counts as 0,
//  so a blank cell or missing field never raises an error.
//
//  @layer    Support
//  @depends  bcmath
// ══════════════════════════════════════════════════════════════════

final class Money
{
    /** Turns int / float / string / null into a plain decimal string bcmath accepts. */
    public static function normalize(mixed $value): string
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Fixed notation (never 1.0E+25); 10 places is far beyond any money scale.
            $value = number_format($value, 10, '.', '');
        }

        $value = trim(str_replace(',', '', (string) $value));

        return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) === 1
            ? ltrim($value, '+')
            : '0';
    }

    /** Rounds half away from zero to $scale decimals, exactly. */
    public static function round(mixed $value, int $scale = 2): string
    {
        $number   = self::normalize($value);
        $negative = str_starts_with($number, '-');
        $absolute = ltrim($number, '-');

        $half    = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $half, $scale);

        // Never return "-0.00".
        if (bccomp($rounded, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }

        return $negative ? '-'.$rounded : $rounded;
    }

    /** Exact total of any list of amounts. */
    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, self::normalize($value), 10);
        }

        return self::round($total, $scale);
    }

    /** "1,234,567.50" — for messages and labels, never for storing. */
    public static function display(mixed $value, int $scale = 2): string
    {
        $rounded = self::round($value, $scale);
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');

        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-' : '').$whole.($fraction !== '' ? '.'.$fraction : '');
    }

    public static function compare(mixed $a, mixed $b, int $scale = 2): int
    {
        return bccomp(self::normalize($a), self::normalize($b), $scale);
    }

    public static function isPositive(mixed $value, int $scale = 2): bool
    {
        return self::compare($value, '0', $scale) > 0;
    }

    public static function isZero(mixed $value, int $scale = 2): bool
    {
        return self::compare($value, '0', $scale) === 0;
    }
}
