<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  TrialBalanceExport
//
//  Exports the Trial Balance report (Accounting > Financial Reports)
//  to Excel — same $report shape TrialBalanceService::build() returns
//  and the Vue Result page renders: one row per account, a repeating
//  4-column block (Beginning/Debit/Credit/Ending) per period, and a
//  grand-total row proving Debit total == Credit total for every
//  period shown.
//
//  Unlike BankStatementExport/GeneralLedgerExport (fixed column
//  count), the column count here is dynamic — 1 (single date) to 12
//  (monthly) periods, each contributing 4 columns — so headings and
//  styling are built from $report['periods'] rather than hardcoded.
//
//  @layer    Export
//  @depends  TrialBalanceService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class TrialBalanceExport implements FromArray, WithHeadings, WithStyles, WithEvents
{
    public function __construct(private array $report) {}

    public function array(): array
    {
        $rows = [];

        foreach ($this->report['rows'] as $row) {
            $line = [$row['code'], $row['name_en']];
            foreach ($this->report['periods'] as $period) {
                $p = $row['periods'][$period['key']];
                $line[] = Money::round($p['beginning'], 2);
                $line[] = Money::round($p['debit'], 2);
                $line[] = Money::round($p['credit'], 2);
                $line[] = Money::round($p['ending'], 2);
            }
            $rows[] = $line;
        }

        // Grand-total row — Debit/Credit only (Beginning/Ending have
        // no single meaningful grand total across mixed-polarity
        // accounts, same reason the Vue page leaves them blank).
        $totalLine = ['', 'TOTAL'];
        foreach ($this->report['periods'] as $period) {
            $t = $this->report['totals'][$period['key']] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $totalLine[] = '';
            $totalLine[] = Money::round($t['debit'], 2);
            $totalLine[] = Money::round($t['credit'], 2);
            $totalLine[] = '';
        }
        $rows[] = $totalLine;

        // L-26: closing (and opening) balances on their real Dr/Cr side.
        foreach ([['TOTAL Dr balances', 'debit'], ['TOTAL Cr balances', 'credit']] as [$label, $side]) {
            $line = ['', $label];
            foreach ($this->report['periods'] as $period) {
                $t = $this->report['totals'][$period['key']] ?? [];
                $line[] = Money::round($t['beginning_'.$side] ?? '0.00', 2);
                $line[] = '';
                $line[] = '';
                $line[] = Money::round($t['ending_'.$side] ?? '0.00', 2);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    public function headings(): array
    {
        $headings = ['Code', 'Account'];
        foreach ($this->report['periods'] as $period) {
            $headings[] = $period['label'].' - Beginning';
            $headings[] = $period['label'].' - Debit';
            $headings[] = $period['label'].' - Credit';
            $headings[] = $period['label'].' - Ending';
        }
        return $headings;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = $this->columnLetter(2 + count($this->report['periods']) * 4);
        $lastRow = count($this->report['rows']) + 4; // +1 header, +1 total row, +2 Dr/Cr balance rows

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10],
        ]);

        $sheet->getStyle("C2:{$lastCol}{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        // Bold the grand-total row (last row).
        $sheet->getStyle("A".($lastRow - 2).":{$lastCol}{$lastRow}")->applyFromArray([
            'font' => ['bold' => true, 'name' => 'Arial', 'size' => 10],
            'borders' => ['top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(32);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->freezePane('C2');
            },
        ];
    }

    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
