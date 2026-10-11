<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  BankStatementExport
//
//  Exports the Bank Statement report (Financial Institutions >
//  Statement) to Excel — the unpaginated LedgerStatementService::all()
//  rows for one account's own date range.
//
//  Bank/Safe account columns: Date | Beginning Balance | Debit |
//  Credit | End Balance | Comment
//
//  v1.1 (2026-09, Mahmoud) — a Credit Facility export gets two extra
//  columns, Limit and Room, per row — $facilityLimit passed in when
//  the source is a facility (null for a bank account, which has
//  neither). Room per row = facilityLimit - that row's own end
//  balance (already the correct sign — LedgerStatementService returns
//  a facility's end_balance as a positive "amount owed" via
//  SIGN_LIABILITY, see that class).
//
//  (No Reviewed/Actions columns — those are CashVero-only, dropped
//  per Mahmoud: reconciliation postponed, inline edit never ported.)
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

class BankStatementExport implements
    FromCollection,
    WithHeadings,
    WithStyles,
    WithColumnWidths,
    WithTitle
{
    private bool $isFacility;

    public function __construct(
        private Collection $rows,
        private string     $accountLabel,
        private ?string    $facilityLimit = null,
    ) {
        $this->isFacility = $this->facilityLimit !== null;
    }

    public function collection(): Collection
    {
        return $this->rows->map(function ($row) {
            $line = [
                'date'              => \Carbon\Carbon::parse($row->entry_date)->format('d-m-Y'),
                'beginning_balance' => Money::round($row->beginning_balance, 2),
                'debit'             => Money::round($row->debit, 2),
                'credit'            => Money::round($row->credit, 2),
                'end_balance'       => Money::round($row->end_balance, 2),
            ];

            if ($this->isFacility) {
                $line['limit'] = Money::round($this->facilityLimit, 2);
                $line['room']  = Money::round(bcsub($this->facilityLimit, (string) $row->end_balance, 2), 2);
            }

            $line['comment'] = $row->description ?: $row->narration;

            return $line;
        });
    }

    public function headings(): array
    {
        $headings = ['Date', 'Beginning Balance', 'Debit', 'Credit', 'End Balance'];

        if ($this->isFacility) {
            $headings[] = 'Limit';
            $headings[] = 'Room';
        }

        $headings[] = 'Comment';

        return $headings;
    }

    public function title(): string
    {
        // Excel sheet titles cap at 31 chars and forbid several
        // characters (: \ / ? * [ ]) — trim and sanitize defensively
        // since $accountLabel is built from free-text bank/account
        // names.
        return substr(preg_replace('/[:\\\\\/\?\*\[\]]/', '-', $this->accountLabel), 0, 31);
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 14, 'B' => 20, 'C' => 18, 'D' => 18, 'E' => 20];

        if ($this->isFacility) {
            $widths['F'] = 20;
            $widths['G'] = 20;
            $widths['H'] = 50;
        } else {
            $widths['F'] = 50;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 1;
        $lastCol = $this->isFacility ? 'H' : 'F';
        $numLastCol = $this->isFacility ? 'G' : 'E';

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("B2:{$numLastCol}{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
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
