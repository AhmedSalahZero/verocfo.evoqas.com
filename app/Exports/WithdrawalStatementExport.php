<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  WithdrawalStatementExport
//
//  Exports the Withdrawal Statement (Financial Institutions >
//  Statement) — the unpaginated WithdrawalStatementService rows.
//  Columns: Withdrawal Date | Bank | Facility | Due Date | Amount |
//  Settled | Remaining | Status | Description
//
//  @layer    Export
//  @depends  WithdrawalStatementService
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

class WithdrawalStatementExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($r) => [
            'withdrawal_date' => \Carbon\Carbon::parse($r['withdrawal_date'])->format('d-m-Y'),
            'bank'            => $r['bank_name'],
            'facility'        => $r['facility_reference'] ?: $r['facility_type'],
            'due_date'        => \Carbon\Carbon::parse($r['due_date'])->format('d-m-Y'),
            'amount'          => Money::round($r['amount'], 2),
            'settled'         => Money::round($r['settled_amount'], 2),
            'remaining'       => Money::round($r['remaining_amount'], 2),
            'status'          => $r['is_overdue'] ? 'Overdue' : ucfirst($r['status']),
            'description'     => $r['description'],
        ]);
    }

    public function headings(): array
    {
        return ['Withdrawal Date', 'Bank', 'Facility', 'Due Date', 'Amount', 'Settled', 'Remaining', 'Status', 'Description'];
    }

    public function title(): string
    {
        return 'Withdrawal Statement';
    }

    public function columnWidths(): array
    {
        return ['A' => 16, 'B' => 18, 'C' => 22, 'D' => 14, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 12, 'I' => 45];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 1;

        $sheet->getStyle("A1:I1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:I{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("E2:G{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
                ]);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(24);

        return [];
    }
}
