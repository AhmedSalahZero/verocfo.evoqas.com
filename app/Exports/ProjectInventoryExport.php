<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  ProjectInventoryExport
//
//  Exports the Project Inventory Statement (Projects > Project
//  Inventory) to Excel — one sheet, one block per item, matching the
//  on-screen layout: Receipts, Total Received, Transfers, Remaining.
//  Simple FromArray/WithHeadings implementation rather than a
//  strict tabular export, since the report is naturally a set of
//  small per-item tables, not one flat grid — same spirit as the
//  on-screen Result page.
//
//  @layer    Export
//  @depends  ProjectInventoryService
// ══════════════════════════════════════════════════════════════════

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ProjectInventoryExport implements FromArray, WithStyles, WithTitle, WithColumnWidths
{
    private int $boldRowCount = 0;
    private array $boldRows = [];

    public function __construct(private array $statement, private string $label) {}

    public function array(): array
    {
        $rows = [['Project Inventory Statement — '.$this->label]];
        $this->boldRows[] = 1;

        foreach ($this->statement['items'] as $item) {
            $rows[] = [];
            $rows[] = ['Item: '.$item['product_code'].' — '.$item['product_name_en']];
            $this->boldRows[] = count($rows);

            $rows[] = ['Receipt Date', 'Qty', 'Unit Cost', 'Total Cost', 'Location'];
            $this->boldRows[] = count($rows);

            foreach ($item['receipts'] as $r) {
                $rows[] = [$r['date'], $r['quantity'], $r['unit_cost'], $r['total_cost'], $r['location']];
            }

            $rows[] = ['Total Received', $item['total_received_qty'], '', $item['total_received_cost'], ''];
            $this->boldRows[] = count($rows);

            if (! empty($item['transfers'])) {
                $rows[] = ['Transfers', '', '', '', ''];
                $this->boldRows[] = count($rows);
                foreach ($item['transfers'] as $tr) {
                    $rows[] = [$tr['date'], $tr['quantity'], '', '', $tr['location']];
                }
            }

            $rows[] = ['Remaining', $item['remaining_qty'], '', '', ''];
            $this->boldRows[] = count($rows);
        }

        return $rows;
    }

    public function title(): string
    {
        return substr(preg_replace('/[:\\\\\/\?\*\[\]]/', '-', $this->label), 0, 31);
    }

    public function columnWidths(): array
    {
        return ['A' => 24, 'B' => 14, 'C' => 14, 'D' => 16, 'E' => 26];
    }

    public function styles(Worksheet $sheet): array
    {
        foreach ($this->boldRows as $rowNum) {
            $sheet->getStyle("A{$rowNum}:E{$rowNum}")->applyFromArray([
                'font' => ['bold' => true, 'name' => 'Arial', 'size' => 10],
            ]);
        }

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
        ]);

        return [];
    }
}
