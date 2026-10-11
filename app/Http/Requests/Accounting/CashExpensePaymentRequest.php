<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePaymentRequest
//
//  Validates CashExpensePaymentController::store()/update() — payable
//  mirror of OtherPaymentRequest. Only THREE money_type values, same
//  as Other Payment / Supplier Payment.
//
//  MULTI-LINE (August 2026, see migration 000181) — `lines` replaces
//  the old single expense_account_id/subtotal/vat_rate/cost_center_id
//  fields: each line is FULLY FLEXIBLE (own Expense Account, own Cost
//  Center, own amount + VAT/WHT), exactly like a Supplier Bill line.
//  At least one line is required. Each line's true Cost Center
//  requirement (only when its own expense account is flagged
//  requires_cost_center) depends on a database lookup, so it's
//  enforced in CashExpensePaymentService, not here — same division of
//  responsibility as everywhere else in this app.
//
//  partner_id is OPTIONAL in general (see migration 000177) — EXCEPT
//  when money_type = payable_cheque, where it becomes required: a
//  physical cheque needs a named payee, and cheques_payable.partner_id
//  is NOT NULL.
//
//  allocations — "Allocating with Customer Contracts" (August 2026).
//  Reporting-only (see migration 000179): never required to add up
//  to the full payment, but the sum can never EXCEED the SUM OF ALL
//  LINES' subtotal (there's no single payment-level subtotal anymore
//  now that lines exist), and each (line_index, project_id) pair can
//  only appear once. line_index (migration 000183 / 000181) is the
//  0-based position of the line it was drawn from within the
//  submitted `lines` array — validated here only for range, resolved
//  to a real line ID in CashExpensePaymentService::syncAllocations().
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CashExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'          => ['nullable', 'integer', 'exists:partners,id'],
            'payee_description'   => ['nullable', 'string', 'max:255'],

            'payment_date'        => ['required', 'date'],
            'money_type'          => ['required', 'in:cash_payment,outgoing_transfer,payable_cheque'],
            'reference_number'    => ['nullable', 'string', 'max:100'],

            'currency_id'    => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate'  => ['nullable', 'numeric', 'min:0.000001'],

            // ── Lines — at least one, each fully flexible ──────────
            'lines'                          => ['required', 'array', 'min:1'],
            'lines.*.description'            => ['nullable', 'string', 'max:255'],
            'lines.*.expense_account_id'     => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.cost_center_id'          => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lines.*.subtotal'                => ['required', 'numeric', 'min:0.01'],
            'lines.*.vat_rate'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.is_deductible'           => ['nullable', 'boolean'],
            'lines.*.withholding_tax_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Outgoing Transfer / Payable Cheque (source fixed at issue time)
            'source_type'               => ['nullable', 'in:bank_account,credit_facility'],
            'source_bank_account_id'    => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'source_credit_facility_id' => ['nullable', 'integer', 'exists:credit_facilities,id'],

            // Cash Payment
            'safe_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'receipt_number'  => ['nullable', 'string', 'max:100'],

            // Payable Cheque
            'cheque'                => ['nullable', 'array'],
            'cheque.cheque_number'  => ['nullable', 'required_if:money_type,payable_cheque', 'string', 'max:100'],
            'cheque.due_date'       => ['nullable', 'required_if:money_type,payable_cheque', 'date'],

            // Allocating with Customer Contracts — reporting-only, never
            // required to add up to the full payment (see migration 000179).
            'allocations'                       => ['nullable', 'array'],
            'allocations.*.line_index'          => ['nullable', 'integer', 'min:0'],
            'allocations.*.project_id'          => ['required_with:allocations', 'integer', 'exists:projects,id'],
            'allocations.*.allocated_amount'    => ['required_with:allocations', 'numeric', 'min:0.01'],

            'internal_notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'payment_date' => 'Payment Date',
            'lines'        => 'Expense Lines',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $moneyType = $this->input('money_type');
            $lines = $this->input('lines') ?? [];

            if (in_array($moneyType, ['outgoing_transfer', 'payable_cheque'], true)) {
                if (! $this->input('source_type')) {
                    $validator->errors()->add('source_type', 'Select which account or facility the money is paid from.');
                } elseif ($this->input('source_type') === 'bank_account' && ! $this->input('source_bank_account_id')) {
                    $validator->errors()->add('source_bank_account_id', 'Select the paying bank account.');
                } elseif ($this->input('source_type') === 'credit_facility' && ! $this->input('source_credit_facility_id')) {
                    $validator->errors()->add('source_credit_facility_id', 'Select the paying Overdraft facility.');
                }
            }

            if ($moneyType === 'cash_payment' && ! $this->input('safe_account_id')) {
                $validator->errors()->add('safe_account_id', 'Select which Safe the cash was paid from.');
            }

            // A physical cheque needs a named payee — cheques_payable.partner_id is NOT NULL.
            if ($moneyType === 'payable_cheque' && ! $this->input('partner_id')) {
                $validator->errors()->add('partner_id', 'A Partner must be selected to issue a Payable Cheque.');
            }

            // Without a Partner, the payee description carries the
            // name for the journal narration — require one or the other.
            if (! $this->input('partner_id') && ! trim((string) $this->input('payee_description'))) {
                $validator->errors()->add('payee_description', 'Enter who this expense was paid to, or select a Partner.');
            }

            // Allocating with Customer Contracts — never required to add
            // up to 100% (confirmed with Mahmoud), but the sum must never
            // EXCEED the sum of every line's own subtotal, and the same
            // (line, Project) pair can only appear once.
            $allocations = array_filter($this->input('allocations') ?? [], fn ($row) => ! empty($row['project_id']));
            if ($allocations) {
                // Audit L5: exact decimal comparison, no floats.
                $sum = Money::sum(array_map(fn ($row) => $row['allocated_amount'] ?? 0, $allocations));
                $linesSubtotal = Money::sum(array_map(fn ($l) => $l['subtotal'] ?? 0, $lines));
                if (bccomp($sum, bcadd($linesSubtotal, '0.01', 2), 2) > 0) {
                    $validator->errors()->add('allocations', 'Allocated amounts add up to more than this payment\'s total Amount (before tax) — reduce one or more rows.');
                }

                $pairKeys = array_map(fn ($row) => ($row['line_index'] ?? 'none').':'.$row['project_id'], $allocations);
                if (count($pairKeys) !== count(array_unique($pairKeys))) {
                    $validator->errors()->add('allocations', 'The same expense line and Project combination appears more than once — combine those rows into one.');
                }
            }
        });
    }
}
