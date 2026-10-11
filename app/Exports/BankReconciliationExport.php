<?php

namespace App\Exports;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliationExport
//
//  Audit H-25 — the reconciling-items report as an Excel sheet, laid
//  out the way an auditor reads a bank reconciliation: the bank side,
//  the book side, the difference, then the three item lists
//  (outstanding deposits, outstanding payments, bank items not yet in
//  the books). All amounts are in the bank account's own currency.
//
//  @layer    Export
//  @depends  BankReconciliation (+ the detail array from BankReconciliationService::reportDetail)
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankReconciliation;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BankReconciliationExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** @var int[] rows to print in bold (section headings) */
    private array $boldRows = [1];

    public function __construct(
        private BankReconciliation $rec,
        private array $detail,
    ) {}

    public function array(): array
    {
        $r   = $this->detail['report'];
        $cur = $this->detail['currency_code'] ?? '';
        $out = [];

        $out[] = ['Bank Reconciliation — statement date '.$this->rec->statement_date->format('Y-m-d').($cur ? "  ({$cur})" : '')];
        $out[] = ['Status', ucfirst($this->rec->status)];
        $out[] = [];

        $this->heading($out, 'Bank side');
        $out[] = ['Bank statement closing balance', $r['statement_balance']];
        $out[] = ['+ Outstanding deposits (in the books, not yet at the bank)', $r['outstanding_deposits']];
        $out[] = ['− Outstanding payments (in the books, not yet at the bank)', $r['outstanding_payments']];
        $out[] = ['= Adjusted bank balance', $r['adjusted_bank_balance']];
        $out[] = [];

        $this->heading($out, 'Book side');
        $out[] = ['Ledger balance at the statement date', $r['book_balance']];
        $out[] = ['+ Bank items not yet in the books', $r['unbooked_bank_items']];
        $out[] = ['+ Cleared entries dated after the statement date', $r['booked_after_date']];
        $out[] = ['= Adjusted book balance', $r['adjusted_book_balance']];
        $out[] = [];
        $out[] = ['DIFFERENCE (must be 0.00)', $r['difference']];
        $this->boldRows[] = count($out);
        $out[] = [];

        $this->heading($out, 'Outstanding deposits');
        $out[] = ['Date', 'Entry no.', 'Description', 'Amount'];
        foreach ($this->detail['outstanding_deposits'] as $o) {
            $out[] = [substr((string) $o->entry_date, 0, 10), $o->entry_number, $o->description, $o->amount];
        }
        $out[] = [];

        $this->heading($out, 'Outstanding payments');
        $out[] = ['Date', 'Entry no.', 'Description', 'Amount'];
        foreach ($this->detail['outstanding_payments'] as $o) {
            $out[] = [substr((string) $o->entry_date, 0, 10), $o->entry_number, $o->description, bcmul((string) $o->amount, '-1', 2)];
        }
        $out[] = [];

        $this->heading($out, 'Bank items not yet in the books (need a journal entry)');
        $out[] = ['Date', 'Reference', 'Description', 'Amount (+ in / − out)'];
        foreach ($this->detail['unbooked_bank_items'] as $s) {
            $out[] = [$s->line_date->format('Y-m-d'), $s->reference, $s->description, (string) $s->amount];
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [];
        foreach ($this->boldRows as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    public function title(): string
    {
        return 'Bank Reconciliation';
    }

    private function heading(array &$out, string $text): void
    {
        $out[] = [$text];
        $this->boldRows[] = count($out);
    }
}
