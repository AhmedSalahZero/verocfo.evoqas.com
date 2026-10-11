<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BuySellCurrencyService
//
//  Records a currency conversion — one currency sold, a different
//  currency bought, at a bank's negotiated deal rate — across the
//  same four leg shapes as Internal Money Transfer (Bank or Safe on
//  each side). Unlike a same-currency transfer, this is a realization
//  event under IAS 21: the bank's deal rate is never the day's "fair"
//  system reference rate, and that spread is a real, immediately-
//  recognized realized FX gain (431) or loss (571) — never deferred.
//
//  THE VALUATION PRINCIPLE (generalizes cleanly to FX→functional,
//  functional→FX, and FX→FX — confirmed with Mahmoud):
//    1. Value the currency LEAVING the business (the sell leg) at
//       the day's system reference rate → its fair functional-
//       currency value. sellFairValue = sellAmount × sellReferenceRate.
//    2. Value the currency ENTERING the business (the buy leg) the
//       same way, at ITS OWN reference rate → buyFairValue =
//       buyAmount × buyReferenceRate. buyAmount is what the deal rate
//       says actually lands in the destination account
//       (sellAmount × dealRate, or entered directly). When the buy
//       currency IS the functional currency, buyReferenceRate is
//       always 1.000000 (per ExchangeRate's own convention), so
//       buyFairValue collapses to exactly "the actual functional-
//       currency cash received" — the FX→EGP worked example is just
//       this general formula's simplest case, not a special path.
//    3. The plug = buyFairValue − sellFairValue. Negative → realized
//       loss (571), positive → realized gain (431). This is what
//       gets journaled; the deal rate itself is never journaled
//       directly, only used to derive buyAmount.
//
//  Both reference rates are auto-pulled from ExchangeRate::rateFor()
//  for transaction_date, shown editable — the user can override if
//  their own source differs (confirmed with Mahmoud), but the field
//  is always pre-filled so nobody has to look it up by hand.
//
//  DRAFT/POSTED, never instant — unlike Customer Payment/Internal
//  Money Transfer's "cleared" default, a conversion always needs the
//  reference-rate valuation (and any override) confirmed before it
//  can post. create() only ever saves a draft; post() is the single
//  entry point that values both legs, computes the plug, and writes
//  the real journal entry. setToDraft() reverses everything post()
//  did and reopens the row for editing — same reverse-then-repost
//  pattern as CustomerPaymentService::setToDraft() /
//  InternalMoneyTransferService::cancel() — never a silent edit of a
//  posted entry. delete() only ever operates on a draft, same "no
//  hard deletes on financial data" rule as everywhere else in the
//  app (Rule: can_reverse replaces can_delete).
//
//  Functional pattern re-architected from CashVero's own
//  buy_or_sell_currencies (see CashVero → Vero CFO Treasury Gap
//  Analysis) — the four-leg-shape UX is worth keeping; CashVero has
//  no GL and posts no reference-rate valuation or gain/loss at all,
//  so the entire accounting layer here is new, not a schema copy.
//
//  AUDIT M28 FIX (Oct 2026):
//    • The "from" account must hold the currency being sold and the
//      "to" account the currency being bought (ChecksLegCurrency).
//    • A reference rate that differs from the system rate needs a
//      written reason and may not be more than 5% away from the system
//      rate. Unchanged rates need nothing. The reason and both rates
//      are kept in the audit log.
//
//  @layer    Service
//  @depends  BuySellCurrency, BankAccount, ChartOfAccount, Currency,
//            ExchangeRate, CompanyActiveCurrency, JournalEntryService,
//            FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\BuySellCurrency;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BuySellCurrencyService
{
    use \App\Services\FinancialInstitutions\Concerns\ChecksLegCurrency;

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return BuySellCurrency::with([
            'fromBankAccount.bank', 'fromSafeAccount', 'toBankAccount.bank', 'toSafeAccount',
            'currencyToSell', 'currencyToBuy',
        ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('transaction_code', 'like', "%{$s}%"))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Saves a draft only — no cash-ledger movement, no journal entry,
     * no FX plug computed yet. post() is a separate, deliberate step.
     */
    public function create(int $companyId, array $data, User $actor): BuySellCurrency
    {
        $this->assertLegsValid($data);
        $this->assertDifferentCurrencies($data);
        $this->assertLegCurrencies($companyId, $data);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $transaction = BuySellCurrency::create($this->mapFields($companyId, $data) + [
                'company_id'       => $companyId,
                'transaction_code' => $this->generateTransactionCode($companyId, $data['transaction_date'] ?? null),
                'status'           => BuySellCurrency::STATUS_DRAFT,
                'created_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'buy_sell_currency.created', $transaction, null, $transaction->fresh()->toArray());

            return $transaction->fresh();
        });
    }

    /**
     * Only ever callable on a draft — same rule as CustomerPayment::
     * update(). setToDraft() is the only way a posted transaction
     * becomes editable again.
     */
    public function update(int $companyId, BuySellCurrency $transaction, array $data, User $actor): BuySellCurrency
    {
        if (! $transaction->isDraft()) {
            throw new \DomainException('Only a draft transaction can be edited — use "Set to Draft" first.');
        }

        $this->assertLegsValid($data);
        $this->assertDifferentCurrencies($data);
        $this->assertLegCurrencies($companyId, $data);

        return DB::transaction(function () use ($companyId, $transaction, $data, $actor) {
            $before = $transaction->toArray();

            $transaction->update($this->mapFields($companyId, $data) + ['updated_by' => $actor->id]);

            AuditLog::record($actor, 'buy_sell_currency.updated', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh();
        });
    }

    /**
     * The single entry point that values both legs at their own
     * system reference rate, computes the realized FX gain/loss plug,
     * and posts the real journal entry. Only callable on a draft.
     */
    public function post(BuySellCurrency $transaction, User $actor, array $rateOverrides = []): BuySellCurrency
    {
        if (! $transaction->isDraft()) {
            throw new \DomainException('Only a draft transaction can be posted.');
        }
        if ($this->fiscalYearService->isPeriodClosed($transaction->company_id, (string) $transaction->transaction_date)) {
            throw new \DomainException('This accounting period is closed — the transaction cannot be posted.');
        }

        // Audit M28: re-check the accounts at posting time too — they could have
        // been edited since the draft was saved.
        $this->assertLegCurrencies((int) $transaction->company_id, [
            'from_leg_type' => $transaction->from_leg_type, 'from_bank_account_id' => $transaction->from_bank_account_id, 'from_safe_account_id' => $transaction->from_safe_account_id,
            'to_leg_type' => $transaction->to_leg_type, 'to_bank_account_id' => $transaction->to_bank_account_id, 'to_safe_account_id' => $transaction->to_safe_account_id,
            'currency_to_sell_id' => $transaction->currency_to_sell_id, 'currency_to_buy_id' => $transaction->currency_to_buy_id,
        ]);

        return DB::transaction(function () use ($transaction, $actor, $rateOverrides) {
            $before = $transaction->toArray();
            $companyId = $transaction->company_id;
            $functionalCurrencyId = $this->functionalCurrencyId($companyId);
            $reason = trim((string) ($rateOverrides['rate_override_reason'] ?? ''));

            $sellReferenceRate = $this->resolveReferenceRate(
                $companyId, $transaction->currency_to_sell_id, (string) $transaction->transaction_date,
                $functionalCurrencyId, $rateOverrides['sell_reference_rate'] ?? null, $reason, 'sell'
            );
            $buyReferenceRate = $this->resolveReferenceRate(
                $companyId, $transaction->currency_to_buy_id, (string) $transaction->transaction_date,
                $functionalCurrencyId, $rateOverrides['buy_reference_rate'] ?? null, $reason, 'buy'
            );

            $sellFairValue = bcmul((string) $transaction->currency_to_sell_amount, $sellReferenceRate, 2);
            $buyFairValue  = bcmul((string) $transaction->currency_to_buy_amount, $buyReferenceRate, 2);
            $plug          = bcsub($buyFairValue, $sellFairValue, 2); // + gain, − loss

            $fromAccount = $this->resolveLegAccount($transaction, 'from');
            $toAccount   = $this->resolveLegAccount($transaction, 'to');

            $lines = [
                // Audit H12/H15: each leg records its exact foreign amount.
                ['chart_of_account_id' => $toAccount->id,   'debit' => $buyFairValue, 'credit' => '0', 'description' => 'Buy/Sell Currency '.$transaction->transaction_code,
                    'currency_id' => (int) $transaction->currency_to_buy_id, 'amount_currency' => (string) $transaction->currency_to_buy_amount, 'exchange_rate' => $buyReferenceRate],
                ['chart_of_account_id' => $fromAccount->id, 'debit' => '0', 'credit' => $sellFairValue, 'description' => 'Buy/Sell Currency '.$transaction->transaction_code,
                    'currency_id' => (int) $transaction->currency_to_sell_id, 'amount_currency' => (string) $transaction->currency_to_sell_amount, 'exchange_rate' => $sellReferenceRate],
            ];

            if (bccomp($plug, '0', 2) < 0) {
                $lossAccount = $this->resolveGainLossAccount($companyId, '571');
                $lines[] = ['chart_of_account_id' => $lossAccount->id, 'debit' => bcmul($plug, '-1', 2), 'credit' => '0', 'description' => 'Realized FX Loss — '.$transaction->transaction_code];
            } elseif (bccomp($plug, '0', 2) > 0) {
                $gainAccount = $this->resolveGainLossAccount($companyId, '431');
                $lines[] = ['chart_of_account_id' => $gainAccount->id, 'debit' => '0', 'credit' => $plug, 'description' => 'Realized FX Gain — '.$transaction->transaction_code];
            }

            $entry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $transaction->transaction_date,
                lines: $lines,
                sourceType: 'buy_sell_currency',
                sourceId: $transaction->id,
                narration: "Buy/Sell Currency {$transaction->transaction_code} — sold {$transaction->currency_to_sell_amount} ".$transaction->currencyToSell->code
                    .", bought {$transaction->currency_to_buy_amount} ".$transaction->currencyToBuy->code,
                actor: $actor,
            );

            $transaction->update([
                'sell_reference_rate'        => $sellReferenceRate,
                'buy_reference_rate'         => $buyReferenceRate,
                'sell_fair_value_functional' => $sellFairValue,
                'buy_fair_value_functional'  => $buyFairValue,
                'realized_fx_gain_loss'      => $plug,
                'status'                     => BuySellCurrency::STATUS_POSTED,
                'journal_entry_id'           => $entry->id,
                'posted_by'                  => $actor->id,
                'posted_at'                  => now(),
                'updated_by'                 => $actor->id,
            ]);

            AuditLog::record($actor, 'buy_sell_currency.posted', $transaction, $before, array_merge(
                $transaction->fresh()->toArray(),
                $reason !== '' ? ['rate_override_reason' => $reason] : []
            ));

            return $transaction->fresh(['fromBankAccount', 'fromSafeAccount', 'toBankAccount', 'toSafeAccount', 'currencyToSell', 'currencyToBuy', 'journalEntry.lines']);
        });
    }

    /**
     * Reopens a posted transaction for editing. Reverses the real
     * journal entry it posted (mirror-image, via JournalEntryService::
     * reverse() — never a silent edit or delete) and resets the row
     * to a clean, fully-editable draft. Re-posting via post() creates
     * a brand-new journal entry reflecting whatever the person
     * changes this time — the reference rates and plug are always
     * recomputed fresh, never carried over from the reversed post.
     */
    public function setToDraft(BuySellCurrency $transaction, User $actor): BuySellCurrency
    {
        if (! $transaction->isPosted()) {
            throw new \DomainException('Only a posted transaction can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($transaction->company_id, (string) $transaction->transaction_date)) {
            throw new \DomainException('This transaction\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($transaction, $actor) {
            $before = $transaction->toArray();

            if ($transaction->journal_entry_id) {
                $entry = JournalEntry::findOrFail($transaction->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$transaction->transaction_code);
            }

            $transaction->update([
                'status'                     => BuySellCurrency::STATUS_DRAFT,
                'journal_entry_id'           => null,
                'posted_by'                  => null,
                'posted_at'                  => null,
                // TRS-003 fix (8 Sep 2026) — reset to '0', not null,
                // matching CustomerPaymentService::setToDraft()'s own
                // treatment of fx_gain_loss_functional: a draft always
                // carries real, safe numbers (zero = "not calculated
                // yet"), never an empty value.
                'sell_reference_rate'        => '0',
                'buy_reference_rate'         => '0',
                'sell_fair_value_functional' => '0',
                'buy_fair_value_functional'  => '0',
                'realized_fx_gain_loss'      => '0',
                'updated_by'                 => $actor->id,
            ]);

            AuditLog::record($actor, 'buy_sell_currency.set_to_draft', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh();
        });
    }

    /**
     * Deletes a transaction outright — only ever allowed on a draft
     * (never posted). The only way a posted transaction becomes a
     * draft is setToDraft(), which has already fully reversed its
     * journal entry — by the time a row reaches here it carries zero
     * financial residue, so a genuine hard delete is safe and
     * consistent with "no hard deletes on financial data" (the rule
     * protects records that still represent real money; this one no
     * longer does). Same two-step philosophy as CustomerPaymentService
     * ::delete() — not a special exception invented here.
     */
    public function delete(BuySellCurrency $transaction, User $actor): void
    {
        if (! $transaction->isDraft()) {
            throw new \DomainException('Only a draft transaction can be deleted — use "Set to Draft" first.');
        }
        if ($transaction->journal_entry_id) {
            throw new \DomainException('This transaction still has a journal entry attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($transaction, $actor) {
            $before = $transaction->toArray();

            $transaction->delete();

            AuditLog::record($actor, 'buy_sell_currency.deleted', $transaction, $before, null);
        });
    }

    // ── Private ────────────────────────────────────────────────────

    private function mapFields(int $companyId, array $data): array
    {
        $sellAmount = (string) $data['currency_to_sell_amount'];
        $dealRate   = (string) $data['deal_rate'];

        // Buy amount auto-calculates from sell amount × deal rate when
        // not entered directly — same "auto-calculated Buy Amount"
        // convention as CashVero's form.
        $buyAmount = isset($data['currency_to_buy_amount']) && $data['currency_to_buy_amount'] !== ''
            ? (string) $data['currency_to_buy_amount']
            : bcmul($sellAmount, $dealRate, 2);

        return [
            'transaction_date'      => $data['transaction_date'],
            'from_leg_type'         => $data['from_leg_type'],
            'from_bank_account_id'  => $data['from_leg_type'] === BuySellCurrency::LEG_BANK ? $data['from_bank_account_id'] : null,
            'from_safe_account_id'  => $data['from_leg_type'] === BuySellCurrency::LEG_SAFE ? $data['from_safe_account_id'] : null,
            'to_leg_type'           => $data['to_leg_type'],
            'to_bank_account_id'    => $data['to_leg_type'] === BuySellCurrency::LEG_BANK ? $data['to_bank_account_id'] : null,
            'to_safe_account_id'    => $data['to_leg_type'] === BuySellCurrency::LEG_SAFE ? $data['to_safe_account_id'] : null,
            'currency_to_sell_id'   => $data['currency_to_sell_id'],
            'currency_to_sell_amount' => $sellAmount,
            'currency_to_buy_id'    => $data['currency_to_buy_id'],
            'currency_to_buy_amount' => $buyAmount,
            'deal_rate'             => $dealRate,
            'comment'               => $data['comment'] ?? null,
        ];
    }

    private function assertLegsValid(array $data): void
    {
        foreach (['from', 'to'] as $side) {
            $legType = $data["{$side}_leg_type"] ?? null;

            if (! in_array($legType, [BuySellCurrency::LEG_BANK, BuySellCurrency::LEG_SAFE], true)) {
                throw new \DomainException("Invalid {$side} leg type.");
            }
            if ($legType === BuySellCurrency::LEG_BANK && empty($data["{$side}_bank_account_id"])) {
                throw new \DomainException(ucfirst($side)." bank account is required.");
            }
            if ($legType === BuySellCurrency::LEG_SAFE && empty($data["{$side}_safe_account_id"])) {
                throw new \DomainException(ucfirst($side)." safe account is required.");
            }
        }
    }

    /**
     * A conversion requires two different currencies by definition —
     * same-currency movement is Internal Money Transfer, not this.
     */
    private function assertDifferentCurrencies(array $data): void
    {
        if ((int) $data['currency_to_sell_id'] === (int) $data['currency_to_buy_id']) {
            throw new \DomainException('Currency to sell and currency to buy must be different — a same-currency movement is an Internal Money Transfer, not a conversion.');
        }
    }

    private function resolveLegAccount(BuySellCurrency $transaction, string $side): ChartOfAccount
    {
        $legType = $transaction->{"{$side}_leg_type"};

        if ($legType === BuySellCurrency::LEG_BANK) {
            $bankAccount = BankAccount::where('id', $transaction->{"{$side}_bank_account_id"})
                ->where('company_id', $transaction->company_id)->first();

            if (! $bankAccount || ! $bankAccount->coa_account_id) {
                throw new \DomainException('The selected bank account is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
        }

        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($transaction->company_id)
            ->where('id', $transaction->{"{$side}_safe_account_id"})->first();

        return $account ?? throw new \DomainException('The selected safe account does not belong to this company.');
    }

    /**
     * Resolves 431 (Realized FX Gain) or 571 (Realized FX Loss) —
     * both postable directly at Level 3, per COA v1.4 Rule 2 (no
     * sub-account needed), resolved fresh by code every time, never
     * a cached/assumed ID (Rule 35.4's pattern).
     */
    private function resolveGainLossAccount(int $companyId, string $code): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} — check the seeder.");
    }

    /**
     * Audit M28 — the rate used for a leg's fair value.
     *
     * • Functional currency: always 1.000000.
     * • No rate typed, or the typed rate equals the system rate: the
     *   system rate (a missing system rate is an error).
     * • A different typed rate: needs a written reason, and must stay
     *   within 5% of the system rate (when one exists).
     */
    private function resolveReferenceRate(int $companyId, int $currencyId, string $date, int $functionalCurrencyId, ?string $override, string $reason = '', string $label = ''): string
    {
        if ($currencyId === $functionalCurrencyId) {
            return '1.000000';
        }

        $system = ExchangeRate::rateFor($companyId, $currencyId, $date);

        if ($override === null || $override === '') {
            return $system
                ?? throw new \DomainException('No system exchange rate found for this currency on or before the transaction date — add one under Settings → Currencies first.');
        }

        if (! is_numeric($override) || bccomp($override, '0', 10) <= 0) {
            throw new \DomainException("The {$label} reference rate must be a number greater than zero.");
        }

        // Same rate as the system's (allowing for decimals the form adds) → no override.
        if ($system !== null) {
            $relative = bcdiv(ltrim(bcsub($override, $system, 10), '-'), $system, 10);
            if (bccomp($relative, '0.000001', 10) <= 0) {
                return $system;
            }
        }

        if (mb_strlen($reason) < 5) {
            throw new \DomainException("The {$label} reference rate differs from the system rate. Write the reason for changing it before posting.");
        }

        $this->assertRateWithinTolerance($override, $system, $label.' reference');

        return $override;
    }

    /** Audit M28 — both accounts must hold the currency this conversion moves. */
    private function assertLegCurrencies(int $companyId, array $data): void
    {
        $this->assertLegCurrency($companyId, 'from', (string) $data['from_leg_type'],
            isset($data['from_bank_account_id']) ? (int) $data['from_bank_account_id'] : null,
            isset($data['from_safe_account_id']) ? (int) $data['from_safe_account_id'] : null,
            (int) $data['currency_to_sell_id']);
        $this->assertLegCurrency($companyId, 'to', (string) $data['to_leg_type'],
            isset($data['to_bank_account_id']) ? (int) $data['to_bank_account_id'] : null,
            isset($data['to_safe_account_id']) ? (int) $data['to_safe_account_id'] : null,
            (int) $data['currency_to_buy_id']);
    }

    private function functionalCurrencyId(int $companyId): int
    {
        return (int) (CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id')
            ?? throw new \DomainException('This company has no functional currency configured.'));
    }

    private function generateTransactionCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\FinancialInstitutions\BuySellCurrency::class, 'transaction_code', 'BSC', $date);
    }
}
