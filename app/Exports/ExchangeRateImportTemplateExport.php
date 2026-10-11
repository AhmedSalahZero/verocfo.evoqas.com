<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  ExchangeRateImportTemplateExport
//
//  A downloadable, ready-to-fill template for the Exchange Rate
//  Excel import (CurrencyService::parseRatesExcel()). Gives people
//  the exact column layout instead of asking them to guess or infer
//  it from Export Excel's fuller 5-column report format.
//
//  Deliberately only 3 columns — CurrencyService::parseRatesExcel()
//  matches columns by HEADER NAME, not position, and ignores any
//  columns it doesn't recognize, so a file exported from this
//  template AND a file exported from Export Excel (which adds
//  Currency Name and Entered By) both import correctly.
//
//  Ships with two example rows — filled in with plausible values,
//  not header-only — so someone opening this in Excel immediately
//  sees the expected format (currency code, decimal rate, DD/MM/YYYY
//  date) rather than a blank sheet.
//
//  @layer    Export
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ExchangeRateImportTemplateExport implements
    FromArray,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithTitle
{
    public function __construct(private string $functionalCurrencyCode = 'EGP') {}

    // ── Data — two example rows, delete before filling in real data ──

    public function array(): array
    {
        return [
            ['USD', '50.250000', '05/07/2026'],
            ['EUR', '54.100000', '05/07/2026'],
        ];
    }

    // ── Headings ──────────────────────────────────────────────────

    public function headings(): array
    {
        return [
            'Currency Code',
            "Rate (1 Foreign = ? {$this->functionalCurrencyCode})",
            'Effective Date',
        ];
    }

    public function title(): string
    {
        return 'Exchange Rate Import';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,
            'B' => 34,
            'C' => 20,
        ];
    }

    // ── Styles ────────────────────────────────────────────────────

    public function styles(Worksheet $sheet): array
    {
        // Header row — navy background, white bold text (matches ExchangeRateExport)
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
                'name'  => 'Arial',
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0F2554'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Example rows — italic, muted grey, so they read as
        // "sample data to replace" rather than real rates
        $sheet->getStyle('A2:C3')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10, 'italic' => true, 'color' => ['rgb' => '94A3B8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(18);

        // A short instructional note a few rows below the example data
        $sheet->setCellValue('A5', 'Delete the two example rows above and enter your own data.');
        $sheet->setCellValue('A6', 'Currency Code must match a currency already active in Settings → Currencies.');
        $sheet->setCellValue('A7', 'Effective Date format: DD/MM/YYYY.');
        $sheet->getStyle('A5:A7')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => ['rgb' => '94A3B8']],
        ]);

        return [];
    }
}
