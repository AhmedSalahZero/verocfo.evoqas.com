<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  OpeningBalances
//
//  How a company that already exists gets INTO this system.
//
//  Nobody starts using an ERP on the day they incorporate. They
//  arrive mid-life with a trial balance, a list of who owes them
//  money, a drawer of post-dated cheques and a warehouse full of
//  stock — and every number that follows is built on top of those
//  opening figures. Get them wrong and every statement the system
//  ever produces is wrong by the same amount, permanently.
//
//  This runs on its OWN company, deliberately. Opening balances are a
//  one-time migration step guarded against being posted twice; running
//  them against a company that has already traded would be a scenario
//  bug rather than a test.
//
//  THE QUESTION THAT MATTERS is not whether the screens save. It is
//  whether posting an opening trial balance actually reaches the
//  general ledger — because a migration that records 8 million in
//  assets and leaves the ledger empty is not a migration, and the
//  balance sheet that follows opens at zero.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\ObSession;
use App\Models\User;
use App\Services\CompanyService;
use App\Services\OpeningBalanceService;

class OpeningBalances
{
    public const COMPANY_NAME = 'Cycle Test Co. C (Opening Balances)';

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(User $actor, int $startYear): void
    {
        $this->log->enterCompany(self::COMPANY_NAME);
        $this->log->enterPeriod('opening balances');
        $this->log->note('Migrating an existing company in, mid-life, with a trial balance to carry over.');

        $company = $this->gate->submit(
            \App\Http\Requests\CompanyRequest::class,
            [
                'name_en'                 => self::COMPANY_NAME,
                'name_ar'                 => self::COMPANY_NAME,
                'company_type'            => ['Trading'],
                'grn_policy'              => 'po_required',
                'base_currency'           => 'EGP',
                'fiscal_year_start_month' => 1,
                'opening_balance_date'    => $startYear.'-01-01',
                'country'                 => 'Egypt',
                'subscription_tier'       => 'Enterprise',
                'subscription_expires_at' => min($startYear + 20, 2037).'-12-31',
                'is_active'               => true,
            ],
            fn (array $data) => app(CompanyService::class)->create($data, $actor),
            'Create the company being migrated in',
            'company'
        );

        if (! $company) {
            return;
        }

        $companyId = $company->id;

        app()->instance('currentCompanyId', $companyId);

        $verify  = new Verify($this->log, $companyId);
        $service = app(OpeningBalanceService::class);

        $session = $service->getOrCreateSession($companyId);
        $this->log->operation('opening balance session');

        // ── The trial balance being carried over ──────────────────
        // Deliberately balanced: 8,000,000 of assets against
        // 3,000,000 of payables and 5,000,000 of capital. Everything
        // below is checked against these figures, so they are stated
        // once, here.
        // The chart has no cash LEAF until a bank account creates one
        // — 132 is a parent — so the migration opens one, exactly as
        // an implementation consultant would.
        $cashLeaf = app(\App\Services\ChartOfAccountService::class)->create($companyId, [
            'parent_id'     => ChartOfAccount::findByCode($companyId, '132')?->id,
            'use_auto_code' => true,
            'name_en'       => 'Bank — Migrated Balance',
        ], $actor);
        $this->log->operation('chart of accounts leaf');

        $lines = [
            [$cashLeaf->code, 'debit',  '2500000'],   // Cash at bank
            ['135001',        'debit',  '1500000'],   // Inventory
            ['133001',        'debit',  '4000000'],   // Receivables
            ['221001',        'credit', '3000000'],   // Payables
            ['311',           'credit', '5000000'],   // Paid up capital
        ];

        $debits  = '0.00';
        $credits = '0.00';
        $saved   = 0;
        $probed  = false;

        foreach ($lines as [$code, $side, $amount]) {
            $account = ChartOfAccount::findByCode($companyId, $code);

            if (! $account) {
                $this->log->note("  (no account {$code} in this company's chart — skipping that opening line)");

                continue;
            }

            // The FIRST line goes through the real Form Request,
            // because the shape of what reaches the service is itself
            // the defect: validated() returns only the keys that were
            // PRESENT in the request, and the service indexes optional
            // ones without a default. Proving that once is enough;
            // repeating it for every account would file the same
            // finding five times and never reach the part that
            // matters, which is whether any of this reaches the
            // ledger.
            if (! $probed) {
                $probed = true;

                try {
                    $service->saveCoa($companyId, [
                        'account_id' => $account->id,
                        'entry_side' => $side,
                        'amount'     => $amount,
                    ], $actor);
                } catch (\Throwable $e) {
                    $this->log->finding(
                        prefix: 'OB',
                        severity: Finding::SEVERITY_MEDIUM,
                        confidence: Finding::CONFIDENCE_CONFIRMED,
                        title: 'Saving an opening balance without the optional foreign-currency fields is a server error',
                        operation: 'Opening balances',
                        expected: 'a local-currency opening balance to save with only the fields it needs',
                        actual: get_class($e).': '.$e->getMessage(),
                        source: 'OpeningBalanceService::saveCoa() and SaveObCoaRequest',
                        rootCause: 'amount_foreign and exchange_rate are nullable in the Form Request, so '
                            .'validated() omits them entirely when the client does not send them — Laravel '
                            .'returns only keys that were present in the input. saveCoa() then reads '
                            .'$data[\'amount_foreign\'] directly instead of $data[\'amount_foreign\'] ?? null, '
                            .'which is how every other optional field in the same method is read. Any client '
                            .'that omits the field rather than sending it empty gets a 500, not a validation '
                            .'message.',
                    );
                }
            }

            // Recorded above, then worked within: the optional keys are
            // supplied explicitly so the rest of the migration is
            // actually reached.
            $service->saveCoa($companyId, [
                'account_id'     => $account->id,
                'entry_side'     => $side,
                'amount'         => $amount,
                'amount_foreign' => null,
                'exchange_rate'  => null,
            ], $actor);

            $this->log->operation('opening balance line');
            $saved++;

            $side === 'debit'
                ? $debits = bcadd($debits, $amount, 2)
                : $credits = bcadd($credits, $amount, 2);
        }

        if ($saved === 0) {
            return;
        }

        // ── It must refuse to post while it does not balance ──────
        $unbalancedAccount = ChartOfAccount::findByCode($companyId, $cashLeaf->code);

        if ($unbalancedAccount) {
            $service->saveCoa($companyId, [
                'account_id'     => $unbalancedAccount->id,
                'entry_side'     => 'debit',
                'amount'         => bcadd('2500000', '111111', 2),
                'amount_foreign' => null,
                'exchange_rate'  => null,
            ], $actor);

            $this->gate->expectRefused(
                fn () => $service->post($companyId, $actor),
                '/does not net to zero|validation errors/i',
                'Post an opening trial balance that does not balance',
                'a trial balance that does not net to zero must never reach the books'
            );

            // Put it back.
            $service->saveCoa($companyId, [
                'account_id'     => $unbalancedAccount->id,
                'entry_side'     => 'debit',
                'amount'         => '2500000',
                'amount_foreign' => null,
                'exchange_rate'  => null,
            ], $actor);
        }

        // ── The validator's own verdict ───────────────────────────
        $validation = $service->validate($companyId);
        $this->log->operation('opening balance validation');

        $verify->money(
            $debits,
            (string) $validation['debits'],
            'The opening balance validator totals the debits differently from what was entered',
            'Opening balances',
            ['entered' => $debits, 'validator' => (string) $validation['debits']],
            prefix: 'OB'
        );

        $verify->money(
            $credits,
            (string) $validation['credits'],
            'The opening balance validator totals the credits differently from what was entered',
            'Opening balances',
            ['entered' => $credits, 'validator' => (string) $validation['credits']],
            prefix: 'OB'
        );

        $verify->truth(
            (bool) $validation['balanced'],
            'A balanced opening trial balance was reported as unbalanced',
            'Opening balances',
            ['debits' => $debits, 'credits' => $credits],
            prefix: 'OB'
        );

        // ── Post it ───────────────────────────────────────────────
        $posted = $this->gate->submit(
            null,
            [],
            fn () => $service->post($companyId, $actor),
            'Post the opening balances',
            'opening balance posting'
        );

        if (! $posted) {
            return;
        }

        // ── THE QUESTION ──────────────────────────────────────────
        // Eight million pounds of assets have just been declared. Are
        // they in the general ledger?
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->count();

        if ($entries === 0) {
            $this->log->finding(
                prefix: 'OB',
                severity: Finding::SEVERITY_CRITICAL,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Posting opening balances writes nothing to the general ledger',
                operation: 'Opening balances',
                expected: "an opening journal entry of {$debits} debits against {$credits} credits",
                actual: 'the session is marked posted and no journal entry exists',
                difference: $debits,
                source: 'OpeningBalanceService::post()',
                rootCause: 'post() validates the trial balance, flips is_posted to true, writes the audit log '
                    .'and returns. The line that would create the entry is present only as a comment: '
                    .'"// TODO (Stream 4): Create opening balance journal entry". The journal service it '
                    .'refers to exists and is used everywhere else in the application.',
                evidence: 'A company migrated in through the Opening Balances screens shows a completed, posted '
                    .'migration in the UI and an empty general ledger behind it. Its trial balance, balance '
                    .'sheet and every statement built on them open at zero, so the first invoice it raises '
                    .'appears to be the first transaction the business has ever had.',
                context: [
                    'opening debits'  => $debits,
                    'opening credits' => $credits,
                    'journal entries' => '0',
                    'session posted'  => $posted->is_posted ? 'yes' : 'no',
                ],
            );
        } else {
            $this->log->checkPassed();
            $verify->ledgerBalanced('Opening balances');

            // Every migrated figure must be readable back off the
            // ledger, account by account — a single opening entry that
            // balances but posts to the wrong accounts would pass a
            // trial balance check and still be wrong.
            foreach ($lines as [$code, $side, $amount]) {
                $accountId = $verify->accountId($code);

                if (! $accountId) {
                    continue;
                }

                $expected = $side === 'debit' ? $amount : bcmul($amount, '-1', 2);

                $verify->money(
                    $expected,
                    $verify->reportedBalance($accountId),
                    "Account {$code} does not carry the balance that was migrated into it",
                    'Opening balances',
                    ['migrated' => $amount, 'side' => $side],
                    prefix: 'OB'
                );
            }
        }

        // ── It must not be postable twice ─────────────────────────
        $this->gate->expectRefused(
            fn () => $service->saveCoa($companyId, [
                'account_id'     => $unbalancedAccount?->id,
                'entry_side'     => 'debit',
                'amount'         => '1',
                'amount_foreign' => null,
                'exchange_rate'  => null,
            ], $actor),
            '/posted|locked|already/i',
            'Change an opening balance after the migration has been posted',
            'a posted migration is history and must not be editable'
        );

        $verify->documentCodesAreCompanyScoped('Opening balances');

        $this->log->note(
            'Opening balances migrated: '.number_format((float) $debits, 2).' debits against '
            .number_format((float) $credits, 2).' credits.'
        );
    }

    /** So the command can clean this company up like the other two. */
    public static function sessionFor(int $companyId): ?ObSession
    {
        return ObSession::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }
}
