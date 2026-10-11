<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  SafeStatementExport
//
//  Exports the Safe Statement (Financial Institutions > Statement) —
//  the unpaginated LedgerStatementService::all() rows for one 131xxx
//  safe account's own date range. Same shape as BankStatementExport's
//  non-facility branch (a safe never has a Limit/Room column — only
//  Overdraft-family facilities do).
//
//  Columns: Date | Beginning Balance | Debit | Credit | End Balance |
//  Comment
//
//  @layer    Export
//  @depends  LedgerStatementService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SafeStatementExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    public function __construct(
        private Collection $rows,
        private string     $accountLabel,
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => [
            'date'              => \Carbon\Carbon::parse($row->entry_date)->format('d-m-Y'),
            'beginning_balance' => Money::round($row->beginning_balance, 2),
            'debit'             => Money::round($row->debit, 2),
            'credit'            => Money::round($row->credit, 2),
            'end_balance'       => Money::round($row->end_balance, 2),
            'comment'           => $row->description ?: $row->narration,
        ]);
    }

    public function headings(): array
    {
        return ['Date', 'Beginning Balance', 'Debit', 'Credit', 'End Balance', 'Comment'];
    }

    public function title(): string
    {
        // Excel sheet titles cap at 31 chars and forbid several
        // characters (: \ / ? * [ ]) — trim and sanitize defensively,
        // same as BankStatementExport.
        return substr(preg_replace('/[:\\\\\/\?\*\[\]]/', '-', $this->accountLabel), 0, 31);
    }

    public function columnWidths(): array
    {
        return ['A' => 14, 'B' => 20, 'C' => 18, 'D' => 18, 'E' => 20, 'F' => 50];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 1;

        $sheet->getStyle("A1:F1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:F{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("B2:E{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
                ]);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(24);
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        return [];
    }
}
