<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  BackfillOpeningBalanceOpenItems
//
//  One-off console command for Audit C7 (Sept 2026). Posting opening
//  balances now creates the real open items behind the opening
//  journal entry (OpeningBalanceService::createOpenItems()). Companies
//  that posted BEFORE this fix have the journal entry but none of the
//  open items. This command creates what can safely be created after
//  the fact:
//
//    • Opening customer invoices / supplier bills — always. Their
//      AR / AP was already in the opening entry, so the new open items
//      simply match the ledger.
//    • Opening stock — only for a product that has had NO stock
//      movement since. If a product has already been sold or moved,
//      its costs were already posted and adding opening stock now
//      would re-average them wrongly; those products are listed for
//      you to handle with a stock adjustment instead.
//    • Opening cheques — NOT created. Before this fix cheques never
//      reached the opening entry (134 / 222 were skipped), so a
//      cheque created now would clear an amount that was never
//      booked. They are listed; record them with an adjusting journal
//      entry first, then enter them as ordinary collections/payments.
//
//  Safe to re-run: anything already created is skipped.
//
//  Usage:
//    php artisan opening-balances:backfill-open-items            (all posted companies)
//    php artisan opening-balances:backfill-open-items --company=5
//    php artisan opening-balances:backfill-open-items --dry-run
//
//  @layer    Console Command
//  @depends  OpeningBalanceService, ObSession, ObInventoryStock,
//            ObNoteReceivable, ObNotePayable, StockMovement, User
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\ObInventoryStock;
use App\Models\ObNotePayable;
use App\Models\ObNoteReceivable;
use App\Models\ObSession;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OpeningBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOpeningBalanceOpenItems extends Command
{
    protected $signature = 'opening-balances:backfill-open-items {--company= : Only this company id} {--dry-run : Report only, change nothing}';

    protected $description = 'Creates the open invoices, bills and (where safe) opening stock for companies that posted opening balances before the Audit C7 fix.';

    public function handle(OpeningBalanceService $service): int
    {
        $actor = User::withoutGlobalScopes()->where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->first();
        if (! $actor) {
            $this->error('No Super Admin user found to record as the creator.');
            return self::FAILURE;
        }

        $sessions = ObSession::withoutGlobalScopes()
            ->where('is_posted', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->get();

        foreach ($sessions as $session) {
            $companyId = (int) $session->company_id;
            $this->line("Company #{$companyId}");

            $cheques = ObNoteReceivable::withoutGlobalScopes()->where('company_id', $companyId)->count()
                + ObNotePayable::withoutGlobalScopes()->where('company_id', $companyId)->count();
            if ($cheques > 0) {
                $this->warn("  {$cheques} opening cheque(s) NOT created — they were never in the opening entry. Book them with an adjusting journal entry first.");
            }

            $blocked = $this->productsAlreadyMoved($companyId);
            foreach ($blocked as $code) {
                $this->warn("  Opening stock for {$code} skipped — the product already has stock movements. Use a stock adjustment.");
            }

            if ($this->option('dry-run')) {
                continue;
            }

            $entryId = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('source_type', JournalEntry::SOURCE_OPENING_BALANCE)
                ->value('id');

            // Audit H9: run inside the company's own context so every
            // query the services make is filtered to this company.
            $created = \App\Support\TenantContext::runAs($companyId, fn () => DB::transaction(function () use ($service, $companyId, $session, $actor, $entryId, $blocked) {
                // Invoices and bills (cheques deliberately excluded — see header).
                $result = $service->createOpenItems($companyId, $session, $actor, $entryId, includeStock: false, includeCheques: false);

                $result['stock_lines'] = 0;
                foreach (ObInventoryStock::withoutGlobalScopes()->where('company_id', $companyId)->with('product')->get() as $stock) {
                    if ($stock->product && ! in_array($stock->product->code, $blocked, true)) {
                        $result['stock_lines'] += $service->createOpeningStockLine($companyId, $stock, $session, $actor) ? 1 : 0;
                    }
                }

                return $result;
            }));

            $this->info('  Created: '.json_encode($created));
        }

        return self::SUCCESS;
    }

    /** Product codes with any stock movement other than their own opening one. */
    private function productsAlreadyMoved(int $companyId): array
    {
        $productIds = ObInventoryStock::withoutGlobalScopes()->where('company_id', $companyId)->pluck('product_id')->unique();

        return StockMovement::withoutGlobalScopes()
            ->where('stock_movements.company_id', $companyId)
            ->whereIn('stock_movements.product_id', $productIds)
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '!=', 'ob_inventory_stock'))
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->distinct()
            ->pluck('products.code')
            ->all();
    }
}
