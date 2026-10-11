<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FullySecuredOverdraftRequest
//
//  Validates create and update requests for Fully Secured Overdraft
//  facilities (facility_type = 'fully_secured', COA 223006).
//  Mirrors CleanOverdraftRequest's shape — same OD financing terms
//  (borrowing_rate, bank_margin_rate, hdb_rate, admin_fee_rate,
//  settled_max_days, follows_corridor_rate) and the same Outstanding
//  Breakdown section — since this is still a revolving OD facility
//  once real money moves through it (Layer 6, not yet built), it
//  just gets there differently.
//
//  What's different from Clean Overdraft:
//    - No facility_limit input. The limit is never typed by the
//      user — it's computed server-side by
//      FullySecuredOverdraftService from fsod_td_amount ×
//      fsod_td_lending_pct ÷ 100 (same formula/pattern already
//      confirmed and built for Letter of Credit's fully-secured
//      variant — see LetterOfCreditService::applyComputedLimit()).
//      A facility_limit submitted by the client is always ignored,
//      never validated as an input, so there's deliberately no rule
//      for it here.
//    - fsod_td_account_id / fsod_td_currency_id / fsod_td_amount /
//      fsod_td_lending_pct required — all drive the computed limit.
//      Validated the same way LetterOfCreditRequest validates its
//      lc_td_* equivalents: exists:bank_accounts,id only, no
//      enforcement here that the chosen account is actually of type
//      'time_deposit'. Flagging this as a gap worth closing (on both
//      this form and LC's) in a later pass, but keeping this request
//      consistent with the established pattern rather than making
//      Fully Secured OD stricter than its sibling facility on its
//      first build.
//    - fsod_td_interest_rate is informational only (what the TD
//      itself earns) — nullable, never used in any calculation here.
//
//  @layer    Request
//  @depends  App\Rules\UniqueBankAccountNumber
// ══════════════════════════════════════════════════════════════════

use App\Rules\UniqueBankAccountNumber;
use Illuminate\Foundation\Http\FormRequest;

class FullySecuredOverdraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $route = $this->route('facility');
        $excludeId = is_object($route) ? $route->id : $route;

        return [
            // ── Section 1 — Contract Main Information ─────────────
            'bank_id'               => ['required', 'exists:banks,id'],
            'start_date'            => ['required', 'date'],
            'review_date'           => ['required', 'date', 'after:start_date'],
            'account_number'        => [
                'required', 'string', 'max:100',
                new UniqueBankAccountNumber($companyId, $this->input('bank_id'), 'credit_facilities', $excludeId),
            ],
            'currency_id'           => ['required', 'exists:currencies,id'],
            'coa_account_id'        => ['required', 'exists:chart_of_accounts,id'],

            // ── Section 2 — CD/TD Collateral ───────────────────────
            // The limit itself is never accepted from the client —
            // see the class header note above.
            'fsod_td_account_id'    => ['required', 'exists:bank_accounts,id'],
            'fsod_td_currency_id'   => ['required', 'exists:currencies,id'],
            'fsod_td_amount'        => ['required', 'numeric', 'gt:0'],
            'fsod_td_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fsod_td_lending_pct'   => ['required', 'numeric', 'gt:0', 'max:100'],

            // ── Section 3 — Financing Terms & Conditions ───────────
            'outstanding_balance'   => ['required', 'numeric', 'min:0'],
            'balance_date'          => ['required', 'date'],
            'borrowing_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'bank_margin_rate'      => ['required', 'numeric', 'min:0', 'max:100'],
            'min_interest_rate'     => ['required', 'numeric', 'min:0', 'max:100'],
            'hdb_rate'              => ['required', 'numeric', 'min:0', 'max:100'],
            'admin_fee_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'settled_max_days'      => ['required', 'integer', 'min:0'],
            'follows_corridor_rate' => ['boolean'],
            'purpose'               => ['nullable', 'string', 'max:500'],

            // ── Section 4 — Outstanding Breakdown ──────────────────
            // Only required rows when outstanding_balance > 0 — enforced in service
            'breakdown'             => ['nullable', 'array'],
            'breakdown.*.amount'    => ['required_with:breakdown', 'numeric', 'min:0.01'],
            'breakdown.*.due_date'  => ['required_with:breakdown', 'date'],
        ];
    }
}
