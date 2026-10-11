<?php

namespace App\Exports\Analytics;

// ══════════════════════════════════════════════════════════════════
//  SalesAnalysisExport
//
//  Exports any Sales Analysis report result (Analytics > Sales
//  Analysis > Reports) to Excel. One class handles all 8 report
//  shapes returned by SalesAnalysisService — the shape switch mirrors
//  SalesAnalysisController::buildReport()'s match statement.
//
//  Follows the same FromArray/WithHeadings/WithStyles pattern as
//  TrialBalanceExport — same navy header / alternating row look.
//
//  @layer    Export
//  @depends  SalesAnalysisService (produces the $result array)
// ══════════════════════════════════════════════════════════════════

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SalesAnalysisExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        return match ($this->result['type']) {
            'single_dimension'  => $this->fromSingleDimension(),
            'matrix'            => $this->fromMatrix(),
            'ranking'           => $this->fromRanking(),
            'customer_nature'   => $this->fromCustomerNature(),
            'period_comparison' => $this->fromPeriodComparison(),
            'trend'             => $this->fromTrend(),
            'two_factors_trend' => $this->fromTwoFactorsTrend(),
            'invoice_analysis'  => $this->fromInvoiceAnalysis(),
            default             => [],
        };
    }

    public function headings(): array
    {
        return match ($this->result['type']) {
            'single_dimension'  => [ucfirst($this->result['dimension']), 'Value', 'Transactions', '% Share', 'Accumulated %'],
            'matrix'            => array_merge([ucfirst($this->result['dim1'])], $this->result['columns']->toArray(), ['Total']),
            'ranking'           => [ucfirst($this->result['dim1']), 'Total', ucfirst($this->result['dim2']), 'Value'],
            'customer_nature'   => ['Category', 'Customer Count', 'Value'],
            'period_comparison' => array_merge(['Item'], $this->result['periods']),
            'trend'             => ['Period', 'Value', 'Transactions', 'Growth %'],
            'two_factors_trend' => array_merge([ucfirst($this->result['dimension'])], $this->result['periods']->toArray()),
            'invoice_analysis'  => ['Metric', 'Value'],
            default             => [],
        };
    }

    private function fromSingleDimension(): array
    {
        return $this->result['rows']->map(fn ($r) => [$r['label'], $r['value'], $r['transactions'], $r['share'] . '%', $r['accumulated_share'] . '%'])->toArray();
    }

    private function fromMatrix(): array
    {
        return $this->result['rows']->map(function ($r) {
            $line = [$r['label']];
            foreach ($this->result['columns'] as $col) $line[] = $r[$col] ?? 0;
            $line[] = $r['total'];
            return $line;
        })->toArray();
    }

    private function fromRanking(): array
    {
        $lines = [];
        foreach ($this->result['rows'] as $row) {
            $lines[] = [$row['label'], $row['total'], '', ''];
            foreach ($row['breakdown'] as $b) $lines[] = ['', '', $b['label'], $b['value']];
        }
        return $lines;
    }

    private function fromCustomerNature(): array
    {
        $lines = [];
        foreach ($this->result['buckets'] as $key => $bucket) {
            $lines[] = [ucfirst(str_replace('_', ' ', $key)), $bucket['count'], $bucket['value']];
        }
        return $lines;
    }

    private function fromPeriodComparison(): array
    {
        return $this->result['rows']->map(function ($r) {
            $line = [$r['label']];
            foreach ($this->result['periods'] as $p) $line[] = $r[$p] ?? 0;
            return $line;
        })->toArray();
    }

    private function fromTrend(): array
    {
        return $this->result['rows']->map(fn ($r) => [$r['period'], $r['value'], $r['transactions'], $r['growth_rate'] !== null ? $r['growth_rate'] . '%' : ''])->toArray();
    }

    private function fromTwoFactorsTrend(): array
    {
        return $this->result['series']->map(function ($s) {
            $line = [$s['label']];
            foreach ($s['points'] as $p) $line[] = $p['value'];
            return $line;
        })->toArray();
    }

    private function fromInvoiceAnalysis(): array
    {
        $lines = [];
        foreach ($this->result['snapshot'] as $key => $value) {
            $lines[] = [ucfirst(str_replace('_', ' ', $key)), is_numeric($value) ? round($value, 2) : $value];
        }
        return $lines;
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
