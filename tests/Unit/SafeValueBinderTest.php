<?php

namespace Tests\Unit;

// ══════════════════════════════════════════════════════════════════
//  SafeValueBinderTest
//
//  Audit L4. Writes real values through the export value binder into a
//  throw-away spreadsheet and checks what Excel would receive:
//    - decimal strings become numbers (so they can be summed) and keep
//      their number of decimals;
//    - codes, leading-zero numbers and integers are left alone;
//    - text starting with = + - @ is stored as text, never as a formula;
//    - real negative numbers are still numbers.
//
//  No database needed.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Exports\Support\SafeValueBinder;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class SafeValueBinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cell::setValueBinder(new SafeValueBinder());
    }

    protected function tearDown(): void
    {
        Cell::setValueBinder(new DefaultValueBinder());
        parent::tearDown();
    }

    private function cell(string|int|float $value): Cell
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setCellValue('A1', $value);

        return $sheet->getCell('A1');
    }

    public function test_a_money_string_becomes_a_number_with_two_decimals(): void
    {
        $c = $this->cell('1250.50');
        $this->assertSame(DataType::TYPE_NUMERIC, $c->getDataType());
        $this->assertSame(1250.5, $c->getValue());
        $this->assertSame('#,##0.00', $c->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_negative_amounts_and_rates_keep_their_decimals(): void
    {
        $neg = $this->cell('-300.00');
        $this->assertSame(DataType::TYPE_NUMERIC, $neg->getDataType());
        $this->assertSame(-300.0, $neg->getValue());

        $rate = $this->cell('0.045000');
        $this->assertSame(DataType::TYPE_NUMERIC, $rate->getDataType());
        $this->assertSame('#,##0.000000', $rate->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_codes_and_leading_zero_numbers_stay_text(): void
    {
        foreach (['000123', '00.5', 'INV-2026-0001', '131001-A'] as $text) {
            $c = $this->cell($text);
            $this->assertSame(DataType::TYPE_STRING, $c->getDataType(), "{$text} must stay text");
            $this->assertSame($text, $c->getValue());
        }
    }

    public function test_text_that_looks_like_a_formula_is_stored_as_text(): void
    {
        foreach (['=HYPERLINK("http://evil.example","click")', '=1+1', '@SUM(A1:A2)', '+cmd|calc', '-2+3'] as $evil) {
            $c = $this->cell($evil);
            $this->assertSame(DataType::TYPE_STRING, $c->getDataType(), "{$evil} must not become a formula");
            $this->assertSame($evil, $c->getValue());
            $this->assertTrue($c->getStyle()->getQuotePrefix(), "{$evil} must be flagged as text");
        }
    }

    public function test_a_plain_negative_integer_is_not_treated_as_an_attack(): void
    {
        $c = $this->cell('-5');
        $this->assertFalse($c->getStyle()->getQuotePrefix());
    }

    public function test_ordinary_text_and_real_numbers_are_unchanged(): void
    {
        $this->assertSame(DataType::TYPE_STRING, $this->cell('Paid to supplier')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $this->cell(42)->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $this->cell(12.5)->getDataType());
    }
}
