<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetDisposalService
//
//  Audit H17 (Sept 2026) — the end of a fixed asset's life, and the
//  events in between. Fixed assets could only be bought and
//  depreciated before this.
//
//  DISPOSE — sale or scrapping (IAS 16.67–72)
//    1. FULL MONTH in the month of disposal (confirmed with Mahmoud):
//       that month's depreciation is posted first if it has not been.
//       Every earlier month must already be posted (the register would
//       otherwise be understated) and nothing may be posted for a later
//       month — both are refused with a clear message.
//    2. The asset leaves the books in one journal entry:
//         Dr  accumulated depreciation (+ impairment)   what has built up
//         Dr  proceeds account (bank / safe / debtor)    proceeds + VAT
//         Cr  fixed asset account                        cost
//         Cr  226001 VAT Output                          VAT on the sale
//         Cr  424 Gain on Disposal   or  Dr 545 Loss on Disposal
//       Gain / loss = proceeds (excluding VAT) − net book value.
//       Scrapping = a sale with no proceeds: the whole net book value
//       is a loss.
//    3. The asset becomes 'disposed' — no further depreciation.
//    Cancel: reverses the journal entry, reverses the month's
//    depreciation if the disposal posted it, and puts the asset back
//    to its previous status.
//
//  IMPAIR — IAS 36 write-down of the carrying amount
//       Dr 546 Impairment Loss / Cr the asset's accumulated
//       depreciation account. Limited to the net book value. Later
//       depreciation is spread over what is left (FixedAsset::
//       monthlyDepreciationAmount()). Cancel reverses it — only while
//       no depreciation has been posted after it (that depreciation
//       was calculated on the lower amount; reverse it first).
//
//  TRANSFER — a move to another location. No ledger effect (one GL
//       account per category); recorded for the audit trail.
//
//  Proceeds are in the main currency (EGP). bcmath (Rule 4), AuditLog
//  (Rule 5), company_id checked explicitly (H9).
//
//  @layer    Service
//  @depends  FixedAsset, FixedAssetDisposal, FixedAssetImpairment,
//            FixedAssetTransfer, FixedAssetDepreciationEntry,
//            FixedAssetService, JournalEntryService, ChartOfAccount,
//            InventoryLocation, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetImpairment;
use App\Models\FixedAssetTransfer;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetDisposalService
{
    public const CODE_GAIN = '424';
    public const CODE_LOSS = '545';
    public const CODE_IMPAIRMENT = '546';
    public const CODE_VAT_OUTPUT = '226001';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FixedAssetService $fixedAssetService,
    ) {}

    // ── Read ─────────────────────────────────────────────────────

    /** Everything the asset's "Manage" page shows. */
    public function history(int $companyId, FixedAsset $asset): FixedAsset
    {
        abort_unless((int) $asset->company_id === $companyId, 404);

        return $asset->load([
            'product:id,name_en,code', 'fixedAssetAccount:id,code,name_en',
            'accumulatedDepreciationAccount:id,code,name_en', 'inventoryLocation:id,code,name_en',
            'depreciationEntries', 'disposals.journalEntry:id,entry_number', 'disposals.proceedsAccount:id,code,name_en',
            'disposals.partner:id,name_en', 'impairments.journalEntry:id,entry_number',
            'transfers.fromLocation:id,name_en', 'transfers.toLocation:id,name_en',
        ]);
    }

    /**
     * Preview of a disposal on a date, before anything is posted:
     * the month's depreciation still to post, and the resulting net
     * book value. Proceeds / gain are worked out on the page.
     */
    public function preview(int $companyId, FixedAsset $asset, string $date): array
    {
        abort_unless((int) $asset->company_id === $companyId, 404);
        $this->assertDisposable($asset);
        $monthToPost = $this->monthDepreciationToPost($asset, $date);

        $accumulated = bcadd(bcadd((string) $asset->accumulated_depreciation, (string) $asset->accumulated_impairment, 2), $monthToPost, 2);

        return [
            'cost_basis'               => bcadd((string) $asset->cost_basis, '0', 2),
            'month_depreciation'       => $monthToPost,
            'accumulated_depreciation' => $accumulated,
            'net_book_value'           => bcsub((string) $asset->cost_basis, $accumulated, 2),
        ];
    }

    // ── Dispose ──────────────────────────────────────────────────

    public function dispose(int $companyId, FixedAsset $asset, array $data, User $actor): FixedAssetDisposal
    {
        abort_unless((int) $asset->company_id === $companyId, 404);

        $date     = substr((string) $data['disposal_date'], 0, 10);
        $type     = $data['disposal_type'];
        $proceeds = $type === FixedAssetDisposal::TYPE_SCRAP ? '0.00' : bcadd((string) ($data['proceeds'] ?? '0'), '0', 2);
        $vat      = $type === FixedAssetDisposal::TYPE_SCRAP ? '0.00' : bcadd((string) ($data['vat_amount'] ?? '0'), '0', 2);
        $received = bcadd($proceeds, $vat, 2);

        if (bccomp($proceeds, '0', 2) < 0 || bccomp($vat, '0', 2) < 0) {
            throw new \DomainException('Proceeds and VAT cannot be negative.');
        }
        if ($type === FixedAssetDisposal::TYPE_SALE && bccomp($proceeds, '0', 2) === 0) {
            throw new \DomainException('A sale needs its proceeds — use Scrap if nothing was received.');
        }

        $proceedsAccountId = null;
        if (bccomp($received, '0', 2) > 0) {
            $proceedsAccountId = $this->assertProceedsAccount($companyId, (int) ($data['proceeds_account_id'] ?? 0));
        }

        return DB::transaction(function () use ($companyId, $asset, $data, $actor, $date, $type, $proceeds, $vat, $received, $proceedsAccountId) {
            $asset = FixedAsset::where('company_id', $companyId)->lockForUpdate()->findOrFail($asset->id);
            $this->assertDisposable($asset);

            // 1. Full month of depreciation in the month of disposal.
            $depreciationEntry = null;
            if (bccomp($this->monthDepreciationToPost($asset, $date), '0', 2) > 0) {
                $depreciationEntry = $this->fixedAssetService->postMonthlyDepreciation($asset, $actor, Carbon::parse($date));
                $asset = $asset->fresh();
            }

            // 2. Take it off the books.
            $cost        = bcadd((string) $asset->cost_basis, '0', 2);
            $accumulated = bcadd((string) $asset->accumulated_depreciation, (string) $asset->accumulated_impairment, 2);
            $nbv         = bcsub($cost, $accumulated, 2);
            $gainLoss    = bcsub($proceeds, $nbv, 2);
            $label       = ($type === FixedAssetDisposal::TYPE_SALE ? 'Sale' : 'Scrapping')." of {$asset->asset_code} {$asset->name}";

            $lines = [];
            if (bccomp($accumulated, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $asset->accumulated_depreciation_account_id, 'debit' => $accumulated, 'credit' => '0', 'description' => $label.' — accumulated depreciation'];
            }
            if ($proceedsAccountId) {
                $lines[] = ['chart_of_account_id' => $proceedsAccountId, 'debit' => $received, 'credit' => '0', 'description' => $label.' — proceeds'];
            }
            $lines[] = ['chart_of_account_id' => $asset->fixed_asset_account_id, 'debit' => '0', 'credit' => $cost, 'description' => $label.' — cost'];
            if (bccomp($vat, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_VAT_OUTPUT), 'debit' => '0', 'credit' => $vat, 'description' => $label.' — VAT'];
            }
            if (bccomp($gainLoss, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_GAIN), 'debit' => '0', 'credit' => $gainLoss, 'description' => $label.' — gain'];
            } elseif (bccomp($gainLoss, '0', 2) < 0) {
                $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_LOSS), 'debit' => ltrim($gainLoss, '-'), 'credit' => '0', 'description' => $label.' — loss'];
            }

            $disposal = FixedAssetDisposal::create([
                'company_id' => $companyId, 'fixed_asset_id' => $asset->id, 'disposal_date' => $date,
                'disposal_type' => $type, 'partner_id' => $data['partner_id'] ?? null,
                'proceeds' => $proceeds, 'vat_amount' => $vat, 'proceeds_account_id' => $proceedsAccountId,
                'cost_basis' => $cost, 'accumulated_depreciation' => $accumulated, 'net_book_value' => $nbv,
                'gain_loss' => $gainLoss, 'previous_status' => $asset->status,
                'depreciation_entry_id' => $depreciationEntry?->id, 'status' => FixedAssetDisposal::STATUS_POSTED,
                'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);

            $je = $this->journalEntryService->post(
                companyId: $companyId, entryDate: $date, lines: $lines,
                sourceType: 'fixed_asset_disposal', sourceId: $disposal->id,
                narration: $label, actor: $actor,
            );

            $disposal->update(['journal_entry_id' => $je->id]);

            $before = $asset->toArray();
            $asset->update(['status' => FixedAsset::STATUS_DISPOSED]);
            AuditLog::record($actor, 'fixed_asset.disposed', $asset, $before, $asset->fresh()->toArray());

            return $disposal->fresh();
        });
    }

    public function cancelDisposal(int $companyId, FixedAssetDisposal $disposal, string $reason, User $actor): FixedAssetDisposal
    {
        abort_unless((int) $disposal->company_id === $companyId, 404);
        $this->assertCancellable($disposal->status, $reason);

        return DB::transaction(function () use ($disposal, $reason, $actor) {
            $disposal = FixedAssetDisposal::lockForUpdate()->findOrFail($disposal->id);
            $this->assertCancellable($disposal->status, $reason);
            $before = $disposal->toArray();

            $this->reverseJournal($disposal->journal_entry_id, $actor, "Cancelled disposal — {$reason}");

            if ($disposal->depreciation_entry_id) {
                $dep = FixedAssetDepreciationEntry::find($disposal->depreciation_entry_id);
                if ($dep && $dep->status === FixedAssetDepreciationEntry::STATUS_POSTED) {
                    $this->fixedAssetService->reverseDepreciationEntry($dep, $actor);
                }
            }

            $asset = $disposal->fixedAsset()->lockForUpdate()->first();
            $asset->update(['status' => $disposal->previous_status]);

            $disposal->update([
                'status' => FixedAssetDisposal::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancel_reason' => $reason,
            ]);
            AuditLog::record($actor, 'fixed_asset.disposal_cancel', $disposal, $before, $disposal->fresh()->toArray());

            return $disposal->fresh();
        });
    }

    // ── Impair ───────────────────────────────────────────────────

    public function impair(int $companyId, FixedAsset $asset, array $data, User $actor): FixedAssetImpairment
    {
        abort_unless((int) $asset->company_id === $companyId, 404);

        $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 2);
        $date   = substr((string) $data['impairment_date'], 0, 10);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException('Enter the impairment amount.');
        }
        if ($reason === '') {
            throw new \DomainException('Explain the reason for the impairment (IAS 36 indicator).');
        }

        return DB::transaction(function () use ($companyId, $asset, $amount, $date, $reason, $actor) {
            $asset = FixedAsset::where('company_id', $companyId)->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== FixedAsset::STATUS_IN_SERVICE) {
                throw new \DomainException('Only an asset in service can be impaired.');
            }
            $nbv = $asset->netBookValue();
            if (bccomp($amount, $nbv, 2) > 0) {
                throw new \DomainException("The impairment cannot exceed the net book value ({$nbv}).");
            }

            $label = "Impairment of {$asset->asset_code} {$asset->name}";
            $impairment = FixedAssetImpairment::create([
                'company_id' => $companyId, 'fixed_asset_id' => $asset->id, 'impairment_date' => $date,
                'amount' => $amount, 'carrying_amount_before' => $nbv, 'reason' => $reason,
                'status' => FixedAssetImpairment::STATUS_POSTED, 'created_by' => $actor->id,
            ]);

            $je = $this->journalEntryService->post(
                companyId: $companyId, entryDate: $date,
                lines: [
                    ['chart_of_account_id' => $this->account($companyId, self::CODE_IMPAIRMENT), 'debit' => $amount, 'credit' => '0', 'description' => $label],
                    ['chart_of_account_id' => $asset->accumulated_depreciation_account_id, 'debit' => '0', 'credit' => $amount, 'description' => $label],
                ],
                sourceType: 'fixed_asset_impairment', sourceId: $impairment->id,
                narration: $label.' — '.$reason, actor: $actor,
            );
            $impairment->update(['journal_entry_id' => $je->id]);

            $before = $asset->toArray();
            $asset->update(['accumulated_impairment' => bcadd((string) $asset->accumulated_impairment, $amount, 2)]);
            AuditLog::record($actor, 'fixed_asset.impaired', $asset, $before, $asset->fresh()->toArray());

            return $impairment->fresh();
        });
    }

    public function cancelImpairment(int $companyId, FixedAssetImpairment $impairment, string $reason, User $actor): FixedAssetImpairment
    {
        abort_unless((int) $impairment->company_id === $companyId, 404);
        $this->assertCancellable($impairment->status, $reason);

        return DB::transaction(function () use ($impairment, $reason, $actor) {
            $impairment = FixedAssetImpairment::lockForUpdate()->findOrFail($impairment->id);
            $this->assertCancellable($impairment->status, $reason);
            $asset = $impairment->fixedAsset()->lockForUpdate()->first();

            if ($asset->status === FixedAsset::STATUS_DISPOSED) {
                throw new \DomainException('This asset has been disposed — cancel the disposal first.');
            }
            $laterDepreciation = $asset->depreciationEntries()
                ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
                ->where('period_date', '>', $impairment->impairment_date->copy()->startOfMonth()->toDateString())
                ->exists();
            if ($laterDepreciation) {
                throw new \DomainException('Depreciation was posted after this impairment on the reduced amount — reverse those months first.');
            }

            $before = $impairment->toArray();
            $this->reverseJournal($impairment->journal_entry_id, $actor, "Cancelled impairment — {$reason}");
            $asset->update(['accumulated_impairment' => bcsub((string) $asset->accumulated_impairment, (string) $impairment->amount, 2)]);
            $impairment->update([
                'status' => FixedAssetImpairment::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancel_reason' => $reason,
            ]);
            AuditLog::record($actor, 'fixed_asset.impair_cancel', $impairment, $before, $impairment->fresh()->toArray());

            return $impairment->fresh();
        });
    }

    // ── Transfer ─────────────────────────────────────────────────

    public function transfer(int $companyId, FixedAsset $asset, array $data, User $actor): FixedAssetTransfer
    {
        abort_unless((int) $asset->company_id === $companyId, 404);

        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw new \DomainException('A disposed asset cannot be moved.');
        }
        $toId = (int) $data['to_location_id'];
        $to = InventoryLocation::withoutGlobalScopes()->where('company_id', $companyId)->find($toId)
            ?? throw new \DomainException('Choose one of this company\'s locations.');
        if ((int) $asset->inventory_location_id === $to->id) {
            throw new \DomainException('The asset is already at that location.');
        }

        return DB::transaction(function () use ($companyId, $asset, $data, $actor, $to) {
            $transfer = FixedAssetTransfer::create([
                'company_id' => $companyId, 'fixed_asset_id' => $asset->id,
                'transfer_date' => substr((string) $data['transfer_date'], 0, 10),
                'from_location_id' => $asset->inventory_location_id, 'to_location_id' => $to->id,
                'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);

            $before = $asset->toArray();
            $asset->update(['inventory_location_id' => $to->id]);
            AuditLog::record($actor, 'fixed_asset.transferred', $asset, $before, $asset->fresh()->toArray());

            return $transfer;
        });
    }

    // ── Private ──────────────────────────────────────────────────

    private function assertDisposable(FixedAsset $asset): void
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw new \DomainException("Asset {$asset->asset_code} is already disposed.");
        }
    }

    /**
     * The depreciation the disposal month still needs (full-month
     * rule), after checking the register is up to date. '0.00' when
     * nothing is due (not in service yet, not started, already posted,
     * or fully depreciated).
     */
    private function monthDepreciationToPost(FixedAsset $asset, string $date): string
    {
        $month = Carbon::parse($date)->startOfMonth();
        $posted = $asset->depreciationEntries()->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)->get();

        if ($posted->contains(fn ($e) => Carbon::parse($e->period_date)->gt($month))) {
            throw new \DomainException('Depreciation is posted for a month after the disposal date — reverse it first.');
        }

        if ($asset->status !== FixedAsset::STATUS_IN_SERVICE || ! $asset->depreciation_start_date) {
            return '0.00';
        }

        $start = Carbon::parse($asset->depreciation_start_date)->startOfMonth();
        if ($start->gt($month)) {
            return '0.00';
        }

        $expectedBefore = min((int) $start->diffInMonths($month), $asset->totalDepreciationPeriods());
        $postedBefore   = $posted->filter(fn ($e) => Carbon::parse($e->period_date)->lt($month))->count();
        if ($postedBefore < $expectedBefore) {
            throw new \DomainException('Depreciation is missing for months before the disposal — run depreciation up to '.$month->copy()->subMonth()->format('M Y').' first.');
        }

        $postedThisMonth = $posted->contains(fn ($e) => Carbon::parse($e->period_date)->eq($month));
        if ($postedThisMonth || $posted->count() >= $asset->totalDepreciationPeriods()) {
            return '0.00';
        }

        $amount = $posted->count() + 1 === $asset->totalDepreciationPeriods()
            ? bcsub($asset->depreciableBase(), (string) $asset->accumulated_depreciation, 2)
            : $asset->monthlyDepreciationAmount();

        return bccomp($amount, '0', 2) > 0 ? $amount : '0.00';
    }

    private function assertProceedsAccount(int $companyId, int $accountId): int
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('id', $accountId)->where('is_postable', true)
            ->where('class', ChartOfAccount::CLASS_ASSETS)->first();

        if (! $account) {
            throw new \DomainException('Choose where the proceeds were received — a bank, safe or debtor account of this company.');
        }

        return (int) $account->id;
    }

    private function assertCancellable(string $status, string $reason): void
    {
        if ($status !== 'posted') {
            throw new \DomainException('Already cancelled.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling.');
        }
    }

    private function reverseJournal(?int $journalEntryId, User $actor, string $narration): void
    {
        $je = $journalEntryId ? JournalEntry::find($journalEntryId) : null;
        if ($je && $je->status === JournalEntry::STATUS_POSTED) {
            $this->journalEntryService->reverse($je, $actor, $narration);
        }
    }

    private function account(int $companyId, string $code): int
    {
        $account = ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} — run the latest migrations (000301).");

        return (int) $account->id;
    }
}
