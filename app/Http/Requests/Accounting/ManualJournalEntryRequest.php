<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ManualJournalEntryRequest
//
//  Manual Journal Entry has no draft/edit lifecycle — store() posts
//  a real GL entry immediately (JournalEntryService::createManual()),
//  same discipline as AccrualRequest. Validates the two repeaters
//  (Debit lines / Credit lines) the Vue form collects, and — as a
//  fast UX check before the request even reaches the service — that
//  they sum to the same total. The authoritative balance check still
//  lives in JournalEntryService::assertBalanced(); this is a
//  friendlier, earlier version of the same rule, not a replacement
//  for it.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ManualJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ModulePermission middleware gates the route itself
    }

    public function rules(): array
    {
        return [
            'entry_date'  => ['required', 'date'],
            'narration'   => ['required', 'string', 'max:500'],

            'debit_lines'                           => ['required', 'array', 'min:1'],
            'debit_lines.*.chart_of_account_id'     => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'debit_lines.*.amount'                  => ['required', 'numeric', 'min:0.01'],
            'debit_lines.*.cost_center_id'          => ['nullable', 'integer', 'exists:cost_centers,id'],
            'debit_lines.*.description'             => ['nullable', 'string', 'max:255'],

            'credit_lines'                          => ['required', 'array', 'min:1'],
            'credit_lines.*.chart_of_account_id'    => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'credit_lines.*.amount'                 => ['required', 'numeric', 'min:0.01'],
            'credit_lines.*.cost_center_id'         => ['nullable', 'integer', 'exists:cost_centers,id'],
            'credit_lines.*.description'            => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'entry_date' => 'Entry Date',
            'narration'  => 'Narration',
        ];
    }

    /**
     * Sum(debit_lines.amount) must equal sum(credit_lines.amount) —
     * checked here, in the currency of "friendly form error", before
     * the request ever reaches JournalEntryService::assertBalanced(),
     * which would otherwise throw UnbalancedJournalEntryException
     * (a 500-style domain exception, not a per-field validation
     * message the Vue form can display next to the totals row).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $debitTotal  = bcadd('0', $this->sumAmounts($this->input('debit_lines', [])), 2);
            $creditTotal = bcadd('0', $this->sumAmounts($this->input('credit_lines', [])), 2);

            if (bccomp($debitTotal, $creditTotal, 2) !== 0) {
                $validator->errors()->add(
                    'debit_lines',
                    "Total debit ({$debitTotal}) must equal total credit ({$creditTotal})."
                );
            }
        });
    }

    private function sumAmounts(array $lines): string
    {
        $total = '0';
        foreach ($lines as $line) {
            $total = bcadd($total, (string) ($line['amount'] ?? '0'), 2);
        }

        return $total;
    }
}
