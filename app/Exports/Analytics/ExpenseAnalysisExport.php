<?php

namespace App\Exports\Analytics;

// ══════════════════════════════════════════════════════════════════
//  ExpenseAnalysisExport
//
//  Exports any Expense Analysis report result (Analytics > Expense
//  Analysis > Reports) to Excel — same FromArray/WithHeadings/
//  WithStyles pattern as SalesAnalysisExport / TrialBalanceExport.
//
//  @layer    Export
//  @depends  ExpenseAnalysisService (produces the $result array)
// ══════════════════════════════════════════════════════════════════

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ExpenseAnalysisExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        return match ($this->result['type']) {
            'category_breakdown', 'account_breakdown' => $this->fromBreakdown(),
            'trend'              => $this->fromTrend(),
            'min_avg_max'        => $this->fromMinAvgMax(),
            'period_comparison'  => $this->fromPeriodComparison(),
            default              => [],
        };
    }

    public function headings(): array
    {
        return match ($this->result['type']) {
            'category_breakdown', 'account_breakdown' => ['Item', 'Value', '% of Expense', '% of Revenue'],
            'trend'              => ['Period', 'Value', 'Growth %', '% of Revenue'],
            'min_avg_max'        => ['Item', 'Min', 'Avg', 'Max', 'Months Active'],
            'period_comparison'  => array_merge(['Item'], $this->result['periods']),
            default              => [],
        };
    }

    private function fromBreakdown(): array
    {
        return $this->result['rows']->map(fn ($r) => [$r['label'], $r['value'], $r['pct_of_expense'] . '%', $r['pct_of_revenue'] . '%'])->toArray();
    }

    private function fromTrend(): array
    {
        return $this->result['rows']->map(fn ($r) => [$r['period'], $r['value'], $r['growth_rate'] !== null ? $r['growth_rate'] . '%' : '', $r['revenue_pct'] . '%'])->toArray();
    }

    private function fromMinAvgMax(): array
    {
        return $this->result['rows']->map(fn ($r) => [$r['label'], $r['min'], $r['avg'], $r['max'], $r['months_active']])->toArray();
    }

    private function fromPeriodComparison(): array
    {
        return $this->result['rows']->map(function ($r) {
            $line = [$r['label']];
            foreach ($this->result['periods'] as $p) $line[] = $r[$p] ?? 0;
            return $line;
        })->toArray();
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('1:1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2554']],
        ]);
        foreach (range('A', 'Z') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        return [];
    }
}
