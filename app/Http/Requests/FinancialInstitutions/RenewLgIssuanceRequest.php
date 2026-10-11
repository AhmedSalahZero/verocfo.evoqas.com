<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewLgIssuanceRequest
//
//  Validates LgIssuanceController::renew(). The new_end_date rule
//  depends on the specific issuance being renewed (must be after its
//  current end_date) — resolved here via $this->route('issuance'),
//  the same route-bound model the controller receives, since the
//  route parameter is named {issuance} (see routes/web.php).
//
//  cash_cover_rate_pct / commission_rate_pct (added — LG Issuance
//  Renewal, NOT Facility Renewal, per Mahmoud): both optional, both
//  left blank when the bank isn't changing that rate at this renewal.
//
//  cash_cover_rate_pct is prohibited when the issuance has no cash
//  cover requirement at all (cash_cover_pct is null on the issuance
//  — Time-Deposit-collateralized issuances have nothing to change
//  here). commission_rate_pct has no such restriction — every
//  issuance type carries a commission.
//
//  transaction_date (added): the real event date of this renewal —
//  a renewal is very often processed today for an LG that actually
//  expired weeks ago, so it must NOT default to today silently (that
//  was the bug — LgIssuanceService::renew() used to fall back to
//  now() because nothing on the form ever populated it). Required,
//  chosen explicitly by the user every time. Drives the fiscal-period-
//  closed check, the history event_date, and the journal entry date —
//  all three need to agree on when this renewal actually happened.
//
//  @layer    Request
//  @depends  LgIssuance
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewLgIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $issuance = $this->route('issuance');

        return [
            'transaction_date'     => ['required', 'date'],
            'new_end_date'         => ['required', 'date', 'after:' . $issuance->end_date],
            'renewal_fee_amount'   => ['required', 'numeric', 'min:0'],
            'cash_cover_rate_pct'  => [
                'nullable', 'numeric', 'min:0', 'max:100',
                Rule::prohibitedIf($issuance->cash_cover_pct === null),
            ],
            'commission_rate_pct'  => ['nullable', 'numeric', 'min:0'],
            'notes'                => ['nullable', 'string'],
        ];
    }
}
