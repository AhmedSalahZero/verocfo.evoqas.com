<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  ExchangeRateExport
//
//  Exports all exchange rates for a company to Excel.
//  Uses maatwebsite/excel with FromCollection + WithHeadings.
//
//  Columns: Currency Code | Currency Name | Rate | Effective Date | Entered By
//
//  @layer    Export
//  @depends  App\Services\CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Services\CurrencyService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Collection;

class ExchangeRateExport implements
    FromCollection,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithTitle
{
    public function __construct(
        private Collection $rates,
        private string     $functionalCurrencyCode
    ) {}

    // ── Data ──────────────────────────────────────────────────────

    public function collection(): Collection
    {
        return $this->rates->map(fn ($rate) => [
            'currency_code' => $rate->currency_code ?? '—',
            'currency_name' => $rate->currency_name_en ?? '—',
            'rate'          => Money::round($rate->rate, 6),
            'rate_date'     => $rate->rate_date
                                ? \Carbon\Carbon::parse($rate->rate_date)->format('d/m/Y')
                                : '—',
            'entered_by'    => $rate->entered_by_name ?? 'System',
        ]);
    }

    // ── Headings ──────────────────────────────────────────────────

    public function headings(): array
    {
        return [
            'Currency Code',
            'Currency Name',
            "Rate (1 Foreign = ? {$this->functionalCurrencyCode})",
            'Effective Date',
            'Entered By',
        ];
    }

    // ── Sheet Title ───────────────────────────────────────────────

    public function title(): string
    {
        return 'Exchange Rates';
    }

    // ── Column Widths ─────────────────────────────────────────────

    public function columnWidths(): array
    {
        return [
            'A' => 16,
            'B' => 28,
            'C' => 36,
            'D' => 18,
            'E' => 22,
        ];
    }

    // ── Styles ────────────────────────────────────────────────────

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rates->count() + 1;

        // Header row — navy background, white bold text
        $sheet->getStyle('A1:E1')->applyFromArray([
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

        // Data rows
        $sheet->getStyle("A2:E{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Rate column — center aligned, monospace feel
        $sheet->getStyle("C2:C{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'font'      => ['name' => 'Courier New', 'size' => 10, 'color' => ['rgb' => '1D4ED8']],
        ]);

        // Date column — center
        $sheet->getStyle("D2:D{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Alternating row colors
        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F0F4FF'],
                    ],
                ]);
            }
        }

        // Row height
        $sheet->getRowDimension(1)->setRowHeight(24);
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        return [];
    }
}