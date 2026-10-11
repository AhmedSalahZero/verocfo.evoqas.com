<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  ContractDashboardExport
//
//  Excel export for Projects & Contracts > Contract Dashboard.
//  Exports the same contract rows the page is built from, at the
//  same as-of date the page shows, so a number questioned on screen
//  can be traced line by line in the sheet — same reasoning as every
//  other Statement export in this app.
//
//  Remaining is the one column worth drawing attention to: negative
//  means the contract has been invoiced beyond its value.
//  Utilization % is deliberately NOT in the summable/totals set —
//  averaging percentages by summing them is meaningless.
//
//  @layer    Export
//  @depends  ContractDashboardService (via ContractDashboardController)
// ══════════════════════════════════════════════════════════════════

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ContractDashboardExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    private const NUMERIC_COLUMNS = ['J', 'K', 'L', 'M', 'N', 'O', 'P']; // Contract Value..Uncollected
    private const REMAINING_COLUMN = 'L';

    public function __construct(private array $headings, private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return 'Contract Dashboard';
    }

    public function columnWidths(): array
    {
        return ['A' => 5, 'B' => 22, 'C' => 14, 'D' => 24, 'E' => 10, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 10, 'J' => 16, 'K' => 16, 'L' => 16, 'M' => 12, 'N' => 18, 'O' => 16, 'P' => 16];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 1;
        $lastCol = 'P';

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        foreach (self::NUMERIC_COLUMNS as $col) {
            $sheet->getStyle("{$col}2:{$col}{$lastRow}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => '#,##0.00'],
            ]);
        }

        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
                ]);
            }

            // Negative Remaining (over-billed) — draw the eye to it.
            $remainingCell = self::REMAINING_COLUMN.$row;
            $value = $sheet->getCell($remainingCell)->getValue();
            if (is_numeric($value) && \App\Support\Money::compare($value, '0') < 0) {
                $sheet->getStyle($remainingCell)->applyFromArray([
                    'font' => ['color' => ['rgb' => 'DC2626'], 'bold' => true],
                ]);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(24);

        return [];
    }
}
