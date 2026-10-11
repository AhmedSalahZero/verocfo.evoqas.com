<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  LcStatementExport
//
//  Exports the LC Statement (Financial Institutions > Statement) —
//  the unpaginated LcStatementService::movements() rows.
//  Columns: Date | LC Code | Supplier | LC Type | Bank | Event |
//  Amount | Cash Cover | Commission Rate % | Due Date | Comment
//
//  @layer    Export
//  @depends  LcStatementService
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

class LcStatementExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($r) => [
            'date'            => \Carbon\Carbon::parse($r['date'])->format('d-m-Y'),
            'lc_code'         => $r['lc_code'],
            'supplier'        => $r['supplier_name'],
            'lc_type'         => $r['lc_type'],
            'bank'            => $r['bank_name'],
            'event'           => ucfirst(str_replace('_', ' ', $r['event_type'])),
            'amount'          => Money::round($r['amount'], 2),
            'cash_cover'      => Money::round($r['cash_cover'], 2),
            'commission_rate' => Money::round($r['commission_rate'], 4),
            'due_date'        => \Carbon\Carbon::parse($r['due_date'])->format('d-m-Y'),
            'comment'         => $r['comment'],
        ]);
    }

    public function headings(): array
    {
        return ['Date', 'LC Code', 'Supplier', 'LC Type', 'Bank', 'Event', 'Amount', 'Cash Cover', 'Commission Rate %', 'Due Date', 'Comment'];
    }

    public function title(): string
    {
        return 'LC Statement';
    }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 16, 'C' => 24, 'D' => 18, 'E' => 18, 'F' => 16, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 14, 'K' => 55];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 1;

        $sheet->getStyle("A1:K1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:K{$lastRow}")->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("G2:I{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:K{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
                ]);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(24);

        return [];
    }
}
