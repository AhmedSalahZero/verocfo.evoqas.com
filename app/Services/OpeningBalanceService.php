<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  OpeningBalanceService
//
//  All business logic for Opening Balances.
//
//  getOrCreateSession():
//    Returns the OB session for the company, creating it if needed.
//
//  saveCoa() / savePartnerInvoice() / saveNote*() / saveStock():
//    Upsert individual records. All blocked once session is posted.
//
//  deletePartnerInvoice() / deleteNote*() / deleteStock():
//    Remove records. All blocked once session is posted.
//
//  syncArApToCoA():
//    Recalculates and updates AR/AP entries in ob_coa_balances
//    from ob_partner_invoices. Called after every invoice save/delete.
//
//  syncInventoryToCoA():
//    Recalculates and updates inventory account entries in ob_coa_balances
//    from ob_inventory_stock. Called after every stock save/delete.
//
//  validate():
//    Runs all pre-posting checks. Returns array of errors.
//    Stores totals + trial_balance_ok in ob_sessions.
//
//  post():
//    Posts all opening balances to the general ledger.
//    Creates journal entries in journal_entries table (Stream 4).
//    Sets is_posted = true. One-time, irreversible.
//
//  v1.1 — Added AuditLog::record() to every financial write in this
//    file — this service was one of only 2 left in the whole
//    codebase without it (audit finding, Tier 3). Most save*()
//    methods already accepted a User $actor parameter but never
//    used it; the 6 delete*() methods gained that parameter for the
//    first time. post() gets special treatment — see its own note
//    below, since it's a one-time irreversible action, not a normal
//    save/delete.
//
//  AUDIT C7 FIX (Sept 2026) — opening balances now create the real
//  open items behind the journal entry, not just the entry itself.
//  post() → createOpenItems() creates, in the same transaction:
//    • one open Customer Invoice per customer opening invoice
//      (is_opening_balance = true, code OB-CI-00001…, no lines, no SO)
//    • one open Supplier Bill per supplier opening invoice
//      (is_opening_balance = true, code OB-SB-00001…)
//    • one Cheques Receivable row per cheque in the safe / under
//      collection, one Cheques Payable row per cheque issued
//    • one 'opening_balance' stock movement per product/location at
//      the stated quantity and total value (WAC starts from it).
//  None of these posts a second journal entry — the single opening
//  entry is still the only GL posting; these are its subledger.
//  createOpenItems() is idempotent (keyed on the ob_* row ids) so the
//  one-off backfill command can reuse it for companies posted before
//  this fix.
//
//  Also fixed here:
//    • syncArApToCoA() deleted EVERY auto row whose code started with
//      "13" — including inventory 135xxx — because of an unbracketed
//      OR. It now only replaces AR (133) / AP (221) rows.
//    • Cheques (134 / 222) never reached ob_coa_balances, so they were
//      never in the opening entry. syncNotesToCoA() now puts them there.
//    • syncInventoryToCoA() never removed a stale row after the last
//      stock line of an account was deleted.
//    • post() did not refuse a second post of an already-posted
//      session.
//    • validate() now checks what the open items need: foreign
//      amounts that agree with the EGP figure, the right AR / AP
//      account for the currency, a bank for every issued cheque, and a
//      valid unit for every stock line.
//
//  @layer    Service
//  @depends  ObSession, ObCoaBalance, ObPartnerInvoice,
//            ObNoteReceivable, ObNotePayable, ObInventoryStock
//            ChartOfAccount, Partner, Product, InventoryLocation,
//            AuditLog, CustomerInvoice, SupplierBill, ChequeReceivable,
//            ChequePayable, StockLedgerService, FxService
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\ProductUomLine;
use App\Models\StockMovement;
use App\Models\SupplierBill;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\ObCoaBalance;
use App\Models\ObInventoryStock;
use App\Models\ObNotePayable;
use App\Models\ObNoteReceivable;
use App\Models\ObPartnerInvoice;
use App\Models\ObSession;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    // ── Session ───────────────────────────────────────────────────

    public function getOrCreateSession(int $companyId): ObSession
    {
        $company = Company::find($companyId);

        return ObSession::firstOrCreate(
            ['company_id' => $companyId],
            [
                'as_of_date'       => $company->opening_balance_date,
                'is_posted'        => false,
                'trial_balance_ok' => false,
                'total_debits'     => 0,
                'total_credits'    => 0,
            ]
        );
    }

    // ── Load all data for Index page ──────────────────────────────

    public function loadAll(int $companyId): array
    {
        $session = $this->getOrCreateSession($companyId);

        return [
            'session'          => $session,
            'coa_balances'     => ObCoaBalance::with(['account'])
                                    ->where('auto_populated', false)
                                    ->orderBy('account_id')
                                    ->get(),
            'ar_ap_summary'    => $this->arApSummary($companyId),
            'partner_invoices' => ObPartnerInvoice::with(['partner', 'account', 'currency'])
                                    ->orderBy('partner_id')
                                    ->orderBy('invoice_date')
                                    ->get(),
            'notes_receivable' => ObNoteReceivable::with(['partner', 'currency', 'bankAccount'])
                                    ->orderBy('due_date')
                                    ->get(),
            'notes_payable'    => ObNotePayable::with(['partner', 'currency', 'bankAccount'])
                                    ->orderBy('due_date')
                                    ->get(),
            'inventory_stock'  => ObInventoryStock::with(['product.primaryUom', 'location'])
                                    ->orderBy('product_id')
                                    ->orderBy('location_id')
                                    ->get(),
        ];
    }

    // Moved out of OpeningBalanceController (July 2026 audit — was five
    // private controller methods plus several inline queries in index(),
    // all querying the database directly). One method, all the reference
    // data the 6-tab page needs beyond the actual opening-balance rows
    // themselves (which loadAll() already provides).
    public function referenceData(int $companyId): array
    {
        return [
            'postable_accounts'  => $this->postableAccounts($companyId),
            'ar_accounts'        => $this->arAccounts($companyId),
            'ap_accounts'        => $this->apAccounts($companyId),
            'bank_accounts'      => $this->bankAccountsForOb($companyId),
            'customers'          => Partner::where('company_id', $companyId)
                                    ->whereJsonContains('types', 'customer')
                                    ->active()->orderBy('name_en')
                                    ->get(['id', 'name_en', 'name_ar', 'code']),
            'suppliers'          => Partner::where('company_id', $companyId)
                                    ->whereJsonContains('types', 'supplier')
                                    ->active()->orderBy('name_en')
                                    ->get(['id', 'name_en', 'name_ar', 'code']),
            'stocked_products'   => Product::where('company_id', $companyId)
                                    ->where('has_stock', true)
                                    ->active()
                                    ->with('primaryUom')
                                    ->orderBy('name_en')
                                    ->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'category_id']),
            'product_categories' => ProductCategory::where('company_id', $companyId)
                                    ->where('is_active', true)
                                    ->orderBy('name_en')
                                    ->get(['id', 'name_en', 'parent_id', 'level']),
            'locations'          => InventoryLocation::where('company_id', $companyId)
                                    ->where('is_active', true)
                                    ->orderByRaw("FIELD(type, 'master', 'warehouse', 'project_site')")
                                    ->orderBy('code')
                                    ->get(['id', 'code', 'name_en', 'name_ar', 'type']),
        ];
    }

    /**
     * COA Opening Balances tab — ALL postable accounts EXCEPT
     * those with dedicated tabs:
     *   133xxx = AR (Accounts Receivable) → Customer tab
     *   134xxx = NR (Notes Receivable)    → Notes Receivable tab
     *   135xxx = Inventory                → Inventory tab
     *   221xxx = AP (Accounts Payable)    → Supplier tab
     *   222xxx = NP (Notes Payable)       → Notes Payable tab
     *
     * Cash (131xxx) and Banks (132xxx) ARE included here.
     * User enters opening balance with optional foreign currency.
     */
    private function postableAccounts(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('is_postable', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('code', 'not like', '133%')  // AR — Customer tab
                  ->where('code', 'not like', '134%')  // NR — Notes Receivable tab
                  ->where('code', 'not like', '135%')  // Inventory — Stock tab
                  ->where('code', 'not like', '221%')  // AP — Supplier tab
                  ->where('code', 'not like', '222%'); // NP — Notes Payable tab
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'account_type', 'normal_balance', 'section', 'allow_currency', 'currency_id']);
    }

    /**
     * AR accounts = code 133xxx (Accounts Receivable).
     */
    private function arAccounts(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('code', 'like', '133%')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'is_postable']);
    }

    /**
     * AP accounts = code 221xxx (Accounts Payable).
     */
    private function apAccounts(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('code', 'like', '221%')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'is_postable']);
    }

    /**
     * Bank accounts for Notes under collection / presented.
     * Only Cash (131xxx) and Banks (132xxx).
     * Named *ForOb — this controller/service already has an unrelated
     * bank ACCOUNTS concept (FinancialInstitutions\BankAccount) elsewhere
     * in the app; this one is purely a COA lookup for the OB check tabs.
     */
    private function bankAccountsForOb(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('is_postable', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '131%')   // Cash accounts
                  ->orWhere('code', 'like', '132%'); // Bank accounts
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

    // ── COA Balance ───────────────────────────────────────────────

    public function saveCoa(int $companyId, array $data, User $actor): ObCoaBalance
    {
        $this->guardPosted($companyId);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $before = ObCoaBalance::where('company_id', $companyId)
                ->where('account_id', $data['account_id'])
                ->first()?->toArray();

            $balance = ObCoaBalance::updateOrCreate(
                ['company_id' => $companyId, 'account_id' => $data['account_id']],
                [
                    'entry_side'     => $data['entry_side'],
                    'amount'         => bcadd((string)$data['amount'], '0', 4),
                    'currency_id'    => $data['currency_id']    ?? null,
                    'amount_foreign' => ($data['amount_foreign'] ?? null) ? bcadd((string)$data['amount_foreign'], '0', 4) : null,
                    'exchange_rate'  => ($data['exchange_rate']  ?? null) ? bcadd((string)$data['exchange_rate'], '0', 6) : null,
                    'auto_populated' => false,
                    'notes'          => $data['notes'] ?? null,
                ]
            );

            AuditLog::record(
                $actor,
                $before ? 'ob_coa_balance.updated' : 'ob_coa_balance.created',
                $balance,
                $before,
                $balance->fresh()->toArray()
            );

            return $balance;
        });
    }

    public function deleteCoa(int $companyId, int $coaBalanceId, User $actor): void
    {
        $this->guardPosted($companyId);

        $balance = ObCoaBalance::where('company_id', $companyId)
            ->where('id', $coaBalanceId)
            ->where('auto_populated', false)
            ->first();

        if (! $balance) return;

        $before = $balance->toArray();
        $balance->delete();
        AuditLog::record($actor, 'ob_coa_balance.deleted', $balance, $before, null);
    }

    // ── Partner Invoice ───────────────────────────────────────────

    public function savePartnerInvoice(int $companyId, array $data, User $actor): ObPartnerInvoice
    {
        $this->guardPosted($companyId);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $isNew = ! isset($data['id']);

            if (! $isNew) {
                $invoice = ObPartnerInvoice::findOrFail($data['id']);
                $before  = $invoice->toArray();
                $invoice->update($data);
            } else {
                $before  = null;
                $invoice = ObPartnerInvoice::create(array_merge($data, ['company_id' => $companyId]));
            }
            $this->syncArApToCoA($companyId);

            AuditLog::record(
                $actor,
                $isNew ? 'ob_partner_invoice.created' : 'ob_partner_invoice.updated',
                $invoice,
                $before,
                $invoice->fresh()->toArray()
            );

            return $invoice->fresh(['partner', 'account', 'currency']);
        });
    }

    public function deletePartnerInvoice(int $companyId, int $invoiceId, User $actor): void
    {
        $this->guardPosted($companyId);
        DB::transaction(function () use ($companyId, $invoiceId, $actor) {
            $invoice = ObPartnerInvoice::where('company_id', $companyId)->where('id', $invoiceId)->first();
            if (! $invoice) return;

            $before = $invoice->toArray();
            $invoice->delete();
            $this->syncArApToCoA($companyId);
            AuditLog::record($actor, 'ob_partner_invoice.deleted', $invoice, $before, null);
        });
    }

    // ── Notes Receivable ──────────────────────────────────────────

    public function saveNoteReceivable(int $companyId, array $data, User $actor): ObNoteReceivable
    {
        $this->guardPosted($companyId);
        $this->validateNoteReceivable($data);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $isNew = ! isset($data['id']);

            if (! $isNew) {
                $note   = ObNoteReceivable::findOrFail($data['id']);
                $before = $note->toArray();
                $note->update($data);
            } else {
                $before = null;
                $note   = ObNoteReceivable::create(array_merge($data, ['company_id' => $companyId]));
            }
            $this->syncNotesToCoA($companyId);

            AuditLog::record(
                $actor,
                $isNew ? 'ob_note_receivable.created' : 'ob_note_receivable.updated',
                $note,
                $before,
                $note->fresh()->toArray()
            );

            return $note->fresh(['partner', 'currency', 'bankAccount']);
        });
    }

    public function deleteNoteReceivable(int $companyId, int $noteId, User $actor): void
    {
        $this->guardPosted($companyId);
        DB::transaction(function () use ($companyId, $noteId, $actor) {
            $note = ObNoteReceivable::where('company_id', $companyId)->where('id', $noteId)->first();
            if (! $note) return;

            $before = $note->toArray();
            $note->delete();
            $this->syncNotesToCoA($companyId);
            AuditLog::record($actor, 'ob_note_receivable.deleted', $note, $before, null);
        });
    }

    // ── Notes Payable ─────────────────────────────────────────────

    public function saveNotePayable(int $companyId, array $data, User $actor): ObNotePayable
    {
        $this->guardPosted($companyId);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $isNew = ! isset($data['id']);

            if (! $isNew) {
                $note   = ObNotePayable::findOrFail($data['id']);
                $before = $note->toArray();
                $note->update($data);
            } else {
                $before = null;
                $note   = ObNotePayable::create(array_merge($data, ['company_id' => $companyId]));
            }
            $this->syncNotesToCoA($companyId);

            AuditLog::record(
                $actor,
                $isNew ? 'ob_note_payable.created' : 'ob_note_payable.updated',
                $note,
                $before,
                $note->fresh()->toArray()
            );

            return $note->fresh(['partner', 'currency', 'bankAccount']);
        });
    }

    public function deleteNotePayable(int $companyId, int $noteId, User $actor): void
    {
        $this->guardPosted($companyId);
        DB::transaction(function () use ($companyId, $noteId, $actor) {
            $note = ObNotePayable::where('company_id', $companyId)->where('id', $noteId)->first();
            if (! $note) return;

            $before = $note->toArray();
            $note->delete();
            $this->syncNotesToCoA($companyId);
            AuditLog::record($actor, 'ob_note_payable.deleted', $note, $before, null);
        });
    }

    // ── Inventory Stock ───────────────────────────────────────────

    public function saveStock(int $companyId, array $data, User $actor): ObInventoryStock
    {
        $this->guardPosted($companyId);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $before = ObInventoryStock::where('company_id', $companyId)
                ->where('product_id', $data['product_id'])
                ->where('location_id', $data['location_id'])
                ->first()?->toArray();

            $stock = ObInventoryStock::updateOrCreate(
                [
                    'company_id'  => $companyId,
                    'product_id'  => $data['product_id'],
                    'location_id' => $data['location_id'],
                ],
                [
                    'uom_id'      => $data['uom_id'],
                    'quantity'    => bcadd((string)$data['quantity'], '0', 4),
                    'total_value' => bcadd((string)$data['total_value'], '0', 4),
                    'notes'       => $data['notes'] ?? null,
                ]
            );
            $this->syncInventoryToCoA($companyId);

            AuditLog::record(
                $actor,
                $before ? 'ob_inventory_stock.updated' : 'ob_inventory_stock.created',
                $stock,
                $before,
                $stock->fresh()->toArray()
            );

            return $stock->fresh(['product.primaryUom', 'location']);
        });
    }

    public function deleteStock(int $companyId, int $stockId, User $actor): void
    {
        $this->guardPosted($companyId);
        DB::transaction(function () use ($companyId, $stockId, $actor) {
            $stock = ObInventoryStock::where('company_id', $companyId)->where('id', $stockId)->first();
            if (! $stock) return;

            $before = $stock->toArray();
            $stock->delete();
            $this->syncInventoryToCoA($companyId);
            AuditLog::record($actor, 'ob_inventory_stock.deleted', $stock, $before, null);
        });
    }

    /**
     * The opening balances, as one journal entry.
     *
     * One line per ob_coa_balances row, on the side that row states.
     * validate() has already refused to let posting reach this point
     * unless debits equal credits, so assertBalanced() inside post()
     * is a second lock on a door that is already bolted — which is
     * the right place for it to be.
     *
     * Returns null when there is nothing to post: a company can be
     * migrated in with notes, invoices and stock but no chart-of-
     * accounts balances, and an empty journal entry is not a record
     * of that, it is noise.
     *
     * NOT reversible (confirmed with Mahmoud, 2026-09). Opening
     * balances are posted once, the session locks itself behind
     * is_posted, and any later correction is an ordinary adjusting
     * entry with its own explanation — not a rewrite of history the
     * company was migrated in on. JournalEntryService::reverse()
     * enforces that by refusing this source_type.
     */
    private function postOpeningEntry(int $companyId, ObSession $session, User $actor): ?\App\Models\JournalEntry
    {
        $balances = ObCoaBalance::where('company_id', $companyId)->get();

        if ($balances->isEmpty()) {
            return null;
        }

        $asOf = $session->as_of_date instanceof \DateTimeInterface
            ? $session->as_of_date->format('Y-m-d')
            : (string) $session->as_of_date;

        // Audit H12/H15: a foreign-currency opening balance (e.g. a USD
        // bank account) carries its foreign amount into the ledger, so
        // the account's USD balance is known from day one.
        $lines = $balances->map(fn (ObCoaBalance $b) => [
            'chart_of_account_id' => $b->account_id,
            'debit'               => $b->entry_side === 'debit'  ? (string) $b->amount : '0',
            'credit'              => $b->entry_side === 'credit' ? (string) $b->amount : '0',
            'description'         => 'Opening balance as of '.$asOf,
        ] + ($b->currency_id && $b->amount_foreign !== null ? [
            'currency_id'     => (int) $b->currency_id,
            'amount_currency' => (string) $b->amount_foreign,
            'exchange_rate'   => $b->exchange_rate !== null ? (string) $b->exchange_rate : null,
        ] : []))->all();

        return $this->journalEntries()->post(
            companyId:  $companyId,
            entryDate:  $asOf,
            lines:      $lines,
            sourceType: \App\Models\JournalEntry::SOURCE_OPENING_BALANCE,
            sourceId:   $session->id,
            narration:  "Opening balances migrated as of {$asOf}",
            actor:      $actor,
        );
    }

    /**
     * Resolved from the container rather than injected, deliberately:
     * this service is constructed in a dozen places, several of them
     * in migrations and console commands, and adding a constructor
     * argument to it would break every one of them for the sake of a
     * single call site.
     */
    private function journalEntries(): \App\Services\Accounting\JournalEntryService
    {
        return app(\App\Services\Accounting\JournalEntryService::class);
    }

    // ── Validate ──────────────────────────────────────────────────

    public function validate(int $companyId): array
    {
        $errors   = [];
        $warnings = [];

        // C7: rebuild the automatic rows first, so the check always
        // runs on current detail (and picks up cheques for sessions
        // saved before 134 / 222 were synced at all).
        if (! ObSession::where('company_id', $companyId)->where('is_posted', true)->exists()) {
            $this->syncArApToCoA($companyId);
            $this->syncNotesToCoA($companyId);
            $this->syncInventoryToCoA($companyId);
        }

        // Calculate totals
        $debits  = ObCoaBalance::where('company_id', $companyId)
            ->where('entry_side', 'debit')->sum('amount');
        $credits = ObCoaBalance::where('company_id', $companyId)
            ->where('entry_side', 'credit')->sum('amount');

        $diff = bcsub((string)$debits, (string)$credits, 4);
        $balanced = bccomp($diff, '0', 4) === 0;

        if (! $balanced) {
            $errors[] = "Trial balance does not net to zero. Difference: " . number_format(abs($diff), 4) . " EGP. "
                . "(It includes cheques in the safe / under collection in 134 and cheques issued in 222.)";
        }

        // Foreign currency accounts must have exchange rates
        $missingRates = ObCoaBalance::where('company_id', $companyId)
            ->whereNotNull('currency_id')
            ->where(function ($q) {
                $q->whereNull('exchange_rate')->orWhere('exchange_rate', 0);
            })->count();

        if ($missingRates > 0) {
            $errors[] = "{$missingRates} foreign currency account(s) are missing exchange rates.";
        }

        // Inventory stock must have cost > 0
        $zeroCost = ObInventoryStock::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('total_value', 0)->orWhere('unit_cost', 0);
            })->count();

        if ($zeroCost > 0) {
            $warnings[] = "{$zeroCost} inventory item(s) have zero cost. This will affect WAC calculation.";
        }

        // Notes under collection must have bank account
        $missingBank = ObNoteReceivable::where('company_id', $companyId)
            ->where('status', ObNoteReceivable::STATUS_UNDER_COLLECTION)
            ->whereNull('bank_account_id')
            ->count();

        if ($missingBank > 0) {
            $errors[] = "{$missingBank} check(s) under collection are missing a bank account assignment.";
        }

        // C7: what the open items created at posting need.
        [$openItemErrors, $openItemWarnings] = $this->validateOpenItems($companyId);
        $errors   = array_merge($errors, $openItemErrors);
        $warnings = array_merge($warnings, $openItemWarnings);

        // Update session
        ObSession::where('company_id', $companyId)->update([
            'total_debits'     => $debits,
            'total_credits'    => $credits,
            'trial_balance_ok' => $balanced && empty($errors),
        ]);

        return [
            'errors'   => $errors,
            'warnings' => $warnings,
            'debits'   => $debits,
            'credits'  => $credits,
            'balanced' => $balanced,
            'can_post' => empty($errors),
        ];
    }

    // ── Post (Stream 4 — will wire to journal engine) ─────────────

    /**
     * Posts all opening balances to the general ledger.
     * The actual journal entry creation is wired in Stream 4
     * when the accounting engine is built.
     * For now: marks the session as posted and locks all data.
     */
    public function post(int $companyId, User $actor): ObSession
    {
        // C7: a posted session must never post a second time.
        $this->guardPosted($companyId);

        $validation = $this->validate($companyId);

        if (! $validation['can_post']) {
            throw new \DomainException(
                'Cannot post opening balances — validation errors exist: ' .
                implode(' | ', $validation['errors'])
            );
        }

        return DB::transaction(function () use ($companyId, $actor, $validation) {
            $session = $this->getOrCreateSession($companyId);
            $before  = $session->toArray();

            $session->update([
                'is_posted' => true,
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ]);

            // OB-001 fix (confirmed with Mahmoud, 2026-09) — the opening
            // balances now reach the general ledger as a real journal
            // entry, dated the session's as_of_date, instead of living
            // only in ob_coa_balances.
            //
            // Before this, five reporting services folded ob_coa_balances
            // into an account's balance by hand. That made individual
            // account balances look right while the Trial Balance's own
            // debit and credit TOTALS stayed at zero, and the General
            // Ledger showed nothing at all for a migrated company — the
            // balance existed but no transaction explained it. Posting a
            // real entry fixes all of that in one place, so those five
            // hand-folds were removed at the same time; keeping either
            // one alone would double every opening balance.
            //
            // Deliberately posted through JournalEntryService::post()
            // like every other module, which means it is period-guarded
            // and balance-checked on the way in rather than trusted.
            $openingEntry = $this->postOpeningEntry($companyId, $session, $actor);

            // C7: the open items behind that entry — invoices, bills,
            // cheques and stock — so they can be collected, paid, aged
            // and sold from on day one. No second journal entry.
            $created = $this->createOpenItems($companyId, $session, $actor, $openingEntry?->id);

            // This one gets the trial-balance totals in the "after"
            // state too, not just is_posted/posted_at/posted_by —
            // since this is a one-time, irreversible action, the
            // audit trail should capture exactly what balances were
            // in effect at the moment of posting, not just that
            // posting happened.
            AuditLog::record(
                $actor,
                'ob_session.posted',
                $session,
                $before,
                array_merge($session->fresh()->toArray(), [
                    'total_debits'  => $validation['debits'],
                    'total_credits' => $validation['credits'],
                    'open_items'    => $created,
                ])
            );

            return $session->fresh();
        });
    }

    // ── AR/AP Sync ────────────────────────────────────────────────

    /**
     * Recalculates AR/AP totals from partner invoices and
     * upserts them into ob_coa_balances (auto_populated = true).
     */
    private function syncArApToCoA(int $companyId): void
    {
        // Group net balances by account_id
        $totals = ObPartnerInvoice::where('company_id', $companyId)
            ->selectRaw('account_id, SUM(net_balance) as total')
            ->groupBy('account_id')
            ->get();

        // Remove old auto-populated AR/AP entries — AR (133) and AP
        // (221) ONLY. C7 fix: this used to match every code starting
        // "13" or "22" (unbracketed OR), wiping the inventory (135)
        // and cheque (134 / 222) auto rows every time an invoice was
        // saved.
        $this->deleteAutoRows($companyId, ['133', '221']);

        // Insert fresh totals
        foreach ($totals as $row) {
            $account = ChartOfAccount::find($row->account_id);
            if (!$account) continue;

            $isAsset = $account->normal_balance === 'debit';

            ObCoaBalance::updateOrCreate(
                ['company_id' => $companyId, 'account_id' => $row->account_id],
                [
                    'entry_side'     => $isAsset ? 'debit' : 'credit',
                    'amount'         => bcadd((string)$row->total, '0', 4),
                    'auto_populated' => true,
                ]
            );
        }
    }

    /**
     * Recalculates inventory account totals from ob_inventory_stock
     * and upserts them into ob_coa_balances (auto_populated = true).
     */
    private function syncInventoryToCoA(int $companyId): void
    {
        // Group total_value by the product's inventory_account_id
        $totals = DB::table('ob_inventory_stock as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.company_id', $companyId)
            ->selectRaw('p.inventory_account_id as account_id, SUM(s.total_value) as total')
            ->groupBy('p.inventory_account_id')
            ->get();

        // C7: clear the previous inventory auto rows first, so an
        // account whose last stock line was deleted does not keep a
        // stale balance.
        $this->deleteAutoRows($companyId, ['135']);

        foreach ($totals as $row) {
            if (!$row->account_id) continue;
            ObCoaBalance::updateOrCreate(
                ['company_id' => $companyId, 'account_id' => $row->account_id],
                [
                    'entry_side'     => 'debit',
                    'amount'         => bcadd((string)$row->total, '0', 4),
                    'auto_populated' => true,
                ]
            );
        }
    }

    /**
     * C7: cheques in the safe / under collection → 134001 (debit);
     * cheques issued and not yet cleared → 222001 (credit). Amounts in
     * functional currency, same as every other ob_coa_balances row.
     */
    private function syncNotesToCoA(int $companyId): void
    {
        $this->deleteAutoRows($companyId, ['134', '222']);

        $receivable = (string) ObNoteReceivable::where('company_id', $companyId)->sum('amount');
        $payable    = (string) ObNotePayable::where('company_id', $companyId)->sum('amount');

        foreach ([['134001', 'debit', $receivable], ['222001', 'credit', $payable]] as [$code, $side, $total]) {
            if (bccomp($total, '0', 4) <= 0) {
                continue;
            }

            $account = ChartOfAccount::findByCode($companyId, $code);
            if (! $account) {
                throw new \DomainException("Chart of Accounts is missing system account {$code} — cannot record opening cheques.");
            }

            ObCoaBalance::updateOrCreate(
                ['company_id' => $companyId, 'account_id' => $account->id],
                ['entry_side' => $side, 'amount' => bcadd($total, '0', 4), 'auto_populated' => true]
            );
        }
    }

    /** Deletes this company's AUTO rows whose account code starts with any of $prefixes. */
    private function deleteAutoRows(int $companyId, array $prefixes): void
    {
        ObCoaBalance::where('company_id', $companyId)
            ->where('auto_populated', true)
            ->whereIn('account_id', function ($q) use ($prefixes) {
                $q->select('id')->from('chart_of_accounts')
                  ->where(function ($w) use ($prefixes) {
                      foreach ($prefixes as $prefix) {
                          $w->orWhere('code', 'like', $prefix.'%');
                      }
                  });
            })->delete();
    }

    // ── Open items (Audit C7) ─────────────────────────────────────

    /**
     * Checks the detail rows can become real open items. Returns
     * [errors, warnings]. Everything here is something posting would
     * otherwise have to guess at.
     */
    private function validateOpenItems(int $companyId): array
    {
        $errors = [];
        $warnings = [];
        $fx = $this->fx();

        foreach (ObPartnerInvoice::where('company_id', $companyId)->with('account:id,code')->get() as $inv) {
            $label = ($inv->partner_type === ObPartnerInvoice::TYPE_CUSTOMER ? 'Customer' : 'Supplier')." invoice {$inv->invoice_ref}";
            $foreign = ! $fx->isFunctional($companyId, $inv->currency_id);

            $expected = $inv->partner_type === ObPartnerInvoice::TYPE_CUSTOMER
                ? ($foreign ? '133002' : '133001')
                : ($foreign ? '221002' : '221001');
            if ($inv->account?->code !== $expected) {
                $errors[] = "{$label}: must be booked to account {$expected} (".($foreign ? 'foreign currency' : 'local currency').
                    ") — that is the account its payments will clear.";
            }

            if ($foreign && ($msg = $this->foreignAmountProblem($inv->amount_foreign, $inv->exchange_rate, (string) $inv->net_balance))) {
                $errors[] = "{$label}: {$msg}";
            }
        }

        foreach (ObNoteReceivable::where('company_id', $companyId)->get() as $note) {
            if (! $fx->isFunctional($companyId, $note->currency_id) && ($msg = $this->foreignAmountProblem($note->amount_foreign, $note->exchange_rate, (string) $note->amount))) {
                $errors[] = "Cheque received {$note->check_number}: {$msg}";
            }
            if ($note->isUnderCollection() && $note->bank_account_id && ! $this->collectionDestinationFor($companyId, (int) $note->bank_account_id)) {
                $warnings[] = "Cheque received {$note->check_number} is under collection at an account with no Bank Account / facility record — it will be registered as 'in the safe'; send it for collection from Cheques Receivable.";
            }
        }

        foreach (ObNotePayable::where('company_id', $companyId)->get() as $note) {
            if (! $fx->isFunctional($companyId, $note->currency_id) && ($msg = $this->foreignAmountProblem($note->amount_foreign, $note->exchange_rate, (string) $note->amount))) {
                $errors[] = "Cheque issued {$note->check_number}: {$msg}";
            }
            if (! $note->bank_account_id) {
                $errors[] = "Cheque issued {$note->check_number}: choose the bank account it is drawn on — it is needed to mark the cheque paid.";
            }
        }

        foreach (ObInventoryStock::where('company_id', $companyId)->with('product')->get() as $stock) {
            $name = $stock->product?->code ?? "#{$stock->product_id}";
            if (bccomp((string) $stock->quantity, '0', 4) <= 0) {
                $errors[] = "Opening stock for {$name}: quantity must be greater than zero.";
                continue;
            }
            if ($stock->product && $this->primaryQuantity($stock) === null) {
                $errors[] = "Opening stock for {$name}: the unit entered is not one of the product's units — no conversion to its main unit.";
            }
        }

        return [$errors, $warnings];
    }

    /** Null when foreign amount × rate equals the EGP figure to the cent; otherwise the problem. */
    private function foreignAmountProblem($amountForeign, $rate, string $functional): ?string
    {
        if (! $amountForeign || bccomp((string) $amountForeign, '0', 4) <= 0 || ! $rate || bccomp((string) $rate, '0', 6) <= 0) {
            return 'a foreign-currency item needs both the foreign amount and the exchange rate.';
        }

        $calc = $this->fx()->toFunctional($amountForeign, $rate);
        if (bccomp($calc, bcadd($functional, '0', 2), 2) !== 0) {
            return "foreign amount × rate = {$calc}, but the EGP amount is ".bcadd($functional, '0', 2).' — they must agree to the cent.';
        }

        return null;
    }

    /**
     * Creates the open items behind the opening entry. Idempotent:
     * anything already created for an ob_* row is skipped. Returns
     * counts of what was created.
     */
    public function createOpenItems(int $companyId, ObSession $session, User $actor, ?int $openingEntryId, bool $includeStock = true, bool $includeCheques = true): array
    {
        $asOf = $session->as_of_date instanceof \DateTimeInterface
            ? $session->as_of_date->format('Y-m-d')
            : substr((string) $session->as_of_date, 0, 10);

        $created = ['customer_invoices' => 0, 'supplier_bills' => 0, 'cheques_receivable' => 0, 'cheques_payable' => 0, 'stock_lines' => 0];

        foreach (ObPartnerInvoice::where('company_id', $companyId)->orderBy('id')->get() as $inv) {
            if (bccomp((string) $inv->net_balance, '0', 2) <= 0) {
                continue;
            }
            $created[$inv->partner_type === ObPartnerInvoice::TYPE_CUSTOMER ? 'customer_invoices' : 'supplier_bills']
                += $this->createOpenInvoice($companyId, $inv, $actor, $openingEntryId) ? 1 : 0;
        }

        if ($includeCheques) {
            foreach (ObNoteReceivable::where('company_id', $companyId)->orderBy('id')->get() as $note) {
                $created['cheques_receivable'] += $this->createOpenChequeReceivable($companyId, $note, $asOf, $actor) ? 1 : 0;
            }

            foreach (ObNotePayable::where('company_id', $companyId)->orderBy('id')->get() as $note) {
                $created['cheques_payable'] += $this->createOpenChequePayable($companyId, $note, $actor) ? 1 : 0;
            }
        }

        if ($includeStock) {
            foreach (ObInventoryStock::where('company_id', $companyId)->with('product')->orderBy('id')->get() as $stock) {
                $created['stock_lines'] += $this->createOpeningStock($companyId, $stock, $asOf, $actor) ? 1 : 0;
            }
        }

        return $created;
    }

    private function createOpenInvoice(int $companyId, ObPartnerInvoice $inv, User $actor, ?int $openingEntryId): bool
    {
        $isCustomer = $inv->partner_type === ObPartnerInvoice::TYPE_CUSTOMER;
        $model = $isCustomer ? CustomerInvoice::class : SupplierBill::class;

        if ($model::withoutGlobalScopes()->where('ob_partner_invoice_id', $inv->id)->exists()) {
            return false;
        }

        $fx = $this->fx();
        $functional = bcadd((string) $inv->net_balance, '0', 2);
        $foreign    = ! $fx->isFunctional($companyId, $inv->currency_id);
        $currencyId = $foreign ? (int) $inv->currency_id : (int) $fx->functionalCurrencyId($companyId);
        $rate       = $foreign ? (string) $inv->exchange_rate : '1';
        $native     = $foreign ? bcadd((string) $inv->amount_foreign, '0', 2) : $functional;

        $note = 'Opening balance — original invoice '.$inv->invoice_ref
            .' (invoice '.bcadd((string) $inv->invoice_amount, '0', 2)
            .', previously settled '.bcadd((string) ($inv->previous_collection ?? 0), '0', 2).' EGP).';

        $common = [
            'company_id'         => $companyId,
            'partner_id'         => $inv->partner_id,
            'original_due_date'  => $inv->due_date,
            'due_date'           => $inv->due_date,
            'currency_id'        => $currencyId,
            'exchange_rate'      => $rate,
            'subtotal_amount'    => $native,
            'vat_amount'         => '0',
            'subtotal_amount_functional' => $functional,
            'vat_amount_functional'      => '0',
            'amount_paid'        => '0',
            'amount_credited'    => '0',
            'amount_debited'     => '0',
            'amount_outstanding' => $functional,
            'status'             => 'posted',
            'is_opening_balance' => true,
            'ob_partner_invoice_id' => $inv->id,
            'journal_entry_id'   => $openingEntryId,
            'internal_notes'     => $note,
            'created_by'         => $actor->id,
            'posted_by'          => $actor->id,
            'posted_at'          => now(),
        ];

        $record = $isCustomer
            ? CustomerInvoice::create($common + [
                'invoice_code'             => $this->nextOpeningCode(CustomerInvoice::class, 'invoice_code', 'OB-CI-', $companyId),
                'sales_order_id'           => null,
                'invoice_date'             => $inv->invoice_date,
                'invoice_value'            => $native,
                'invoice_value_functional' => $functional,
                'amount_wht_deducted'      => '0',
            ])
            : SupplierBill::create($common + [
                'bill_code'               => $this->nextOpeningCode(SupplierBill::class, 'bill_code', 'OB-SB-', $companyId),
                'bill_type'               => 'expense',
                'supplier_invoice_number' => mb_substr((string) $inv->invoice_ref, 0, 100),
                'supplier_invoice_date'   => $inv->invoice_date,
                'bill_date'               => $inv->invoice_date,
                'bill_value'              => $native,
                'bill_value_functional'   => $functional,
                'withholding_tax_amount'  => '0',
                'withholding_tax_amount_functional' => '0',
            ]);

        AuditLog::record($actor, $isCustomer ? 'ob.open_invoice_created' : 'ob.open_bill_created', $record, null, $record->toArray());

        return true;
    }

    private function createOpenChequeReceivable(int $companyId, ObNoteReceivable $note, string $asOf, User $actor): bool
    {
        if (ChequeReceivable::withoutGlobalScopes()->where('ob_note_receivable_id', $note->id)->exists()) {
            return false;
        }

        $fx = $this->fx();
        $foreign = ! $fx->isFunctional($companyId, $note->currency_id);
        $destination = ($note->isUnderCollection() && $note->bank_account_id)
            ? $this->collectionDestinationFor($companyId, (int) $note->bank_account_id)
            : null;

        $cheque = ChequeReceivable::create([
            'company_id'            => $companyId,
            'ob_note_receivable_id' => $note->id,
            'partner_id'            => $note->partner_id,
            'drawee_bank_name'      => mb_substr((string) $note->drawee_bank, 0, 150),
            'cheque_amount'         => $foreign ? bcadd((string) $note->amount_foreign, '0', 2) : bcadd((string) $note->amount, '0', 2),
            'currency_id'           => $foreign ? (int) $note->currency_id : $fx->functionalCurrencyId($companyId),
            'exchange_rate'         => $foreign ? (string) $note->exchange_rate : '1',
            'amount_functional'     => bcadd((string) $note->amount, '0', 2),
            'cheque_number'         => mb_substr((string) $note->check_number, 0, 100),
            'due_date'              => $note->due_date,
            'status'                => $destination ? ChequeReceivable::STATUS_SENT_FOR_COLLECTION : ChequeReceivable::STATUS_RECEIVED,
            'collection_destination_type'               => $destination['type'] ?? null,
            'collection_destination_bank_account_id'    => ($destination['type'] ?? null) === 'bank_account' ? $destination['id'] : null,
            'collection_destination_credit_facility_id' => ($destination['type'] ?? null) === 'credit_facility' ? $destination['id'] : null,
            'sent_for_collection_at' => $destination ? $asOf : null,
            'created_by'            => $actor->id,
        ]);

        AuditLog::record($actor, 'ob.cheque_recv_created', $cheque, null, $cheque->toArray());

        return true;
    }

    private function createOpenChequePayable(int $companyId, ObNotePayable $note, User $actor): bool
    {
        if (ChequePayable::withoutGlobalScopes()->where('ob_note_payable_id', $note->id)->exists()) {
            return false;
        }

        $fx = $this->fx();
        $foreign = ! $fx->isFunctional($companyId, $note->currency_id);

        $cheque = ChequePayable::create([
            'company_id'           => $companyId,
            'ob_note_payable_id'   => $note->id,
            'ob_source_account_id' => $note->bank_account_id,
            'partner_id'           => $note->partner_id,
            'cheque_amount'        => $foreign ? bcadd((string) $note->amount_foreign, '0', 2) : bcadd((string) $note->amount, '0', 2),
            'currency_id'          => $foreign ? (int) $note->currency_id : $fx->functionalCurrencyId($companyId),
            'exchange_rate'        => $foreign ? (string) $note->exchange_rate : '1',
            'amount_functional'    => bcadd((string) $note->amount, '0', 2),
            'cheque_number'        => mb_substr((string) $note->check_number, 0, 100),
            'due_date'             => $note->due_date,
            'status'               => ChequePayable::STATUS_ISSUED,
            'created_by'           => $actor->id,
        ]);

        AuditLog::record($actor, 'ob.cheque_pay_created', $cheque, null, $cheque->toArray());

        return true;
    }

    /** One opening-stock line — used by the C7 backfill command, which picks products one by one. */
    public function createOpeningStockLine(int $companyId, ObInventoryStock $stock, ObSession $session, User $actor): bool
    {
        $asOf = $session->as_of_date instanceof \DateTimeInterface
            ? $session->as_of_date->format('Y-m-d')
            : substr((string) $session->as_of_date, 0, 10);

        return $this->createOpeningStock($companyId, $stock, $asOf, $actor);
    }

    private function createOpeningStock(int $companyId, ObInventoryStock $stock, string $asOf, User $actor): bool
    {
        $exists = StockMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('reference_type', 'ob_inventory_stock')
            ->where('reference_id', $stock->id)
            ->exists();

        if ($exists || ! $stock->product) {
            return false;
        }

        $quantity = $this->primaryQuantity($stock);
        if ($quantity === null || bccomp($quantity, '0', 4) <= 0) {
            throw new \DomainException("Opening stock for {$stock->product->code}: the unit entered has no conversion to the product's main unit.");
        }

        $totalValue = bcadd((string) $stock->total_value, '0', 2);
        $unitCost   = bcdiv($totalValue, $quantity, 4);

        $this->stockLedger()->receive($companyId, $stock->product, (int) $stock->location_id, $quantity, $unitCost, $actor, [
            'movement_type'  => StockMovement::TYPE_OPENING_BALANCE,
            'reference_type' => 'ob_inventory_stock',
            'reference_id'   => $stock->id,
            'movement_date'  => $asOf,
            'total_value'    => $totalValue,
            'notes'          => 'Opening balance',
        ]);

        return true;
    }

    /** Opening-stock quantity in the product's PRIMARY unit; null if the unit has no conversion. */
    private function primaryQuantity(ObInventoryStock $stock): ?string
    {
        $product = $stock->product;
        if (! $product || ! $stock->uom_id || (int) $stock->uom_id === (int) $product->primary_uom_id) {
            return bcadd((string) $stock->quantity, '0', 4);
        }

        $rate = ProductUomLine::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('uom_id', $stock->uom_id)
            ->value('conversion_rate');

        return $rate && bccomp((string) $rate, '0', 6) > 0
            ? bcmul((string) $stock->quantity, (string) $rate, 4)
            : null;
    }

    /** A 131/132 GL account → the Bank Account or credit facility that uses it, if any. */
    private function collectionDestinationFor(int $companyId, int $coaAccountId): ?array
    {
        $bankAccountId = BankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('coa_account_id', $coaAccountId)->value('id');
        if ($bankAccountId) {
            return ['type' => 'bank_account', 'id' => (int) $bankAccountId];
        }

        $facilityId = CreditFacility::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('coa_account_id', $coaAccountId)->value('id');

        return $facilityId ? ['type' => 'credit_facility', 'id' => (int) $facilityId] : null;
    }

    /** OB-CI-00001 style code, next free number for this company. */
    private function nextOpeningCode(string $model, string $column, string $prefix, int $companyId): string
    {
        $n = $model::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where($column, 'like', $prefix.'%')
            ->count() + 1;

        do {
            $code = $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
            $n++;
        } while ($model::withoutGlobalScopes()->where('company_id', $companyId)->where($column, $code)->exists());

        return $code;
    }

    /** Resolved from the container — see journalEntries() for why this service avoids constructor injection. */
    private function fx(): \App\Services\Accounting\FxService
    {
        return app(\App\Services\Accounting\FxService::class);
    }

    private function stockLedger(): \App\Services\Inventory\StockLedgerService
    {
        return app(\App\Services\Inventory\StockLedgerService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function guardPosted(int $companyId): void
    {
        if (ObSession::where('company_id', $companyId)->where('is_posted', true)->exists()) {
            throw new \DomainException(
                'Opening balances have been posted and are permanently locked. Use journal entries to make corrections.'
            );
        }
    }

    private function validateNoteReceivable(array $data): void
    {
        if (($data['status'] ?? '') === ObNoteReceivable::STATUS_UNDER_COLLECTION
            && empty($data['bank_account_id'])) {
            throw new \DomainException(
                'Checks under collection must have a bank account assigned.'
            );
        }
    }

    // BUG FIX: this previously called whereJsonContains('types', ...),
    // copied from Partner's scopeOfType() pattern (correct there —
    // partners.types really is a JSON array, since one partner can be
    // Customer + Supplier at once). ObPartnerInvoice is a different
    // table: each opening-balance invoice row is inherently ONE type
    // only, so it has a plain partner_type column instead — and this
    // model already defines TYPE_CUSTOMER/TYPE_SUPPLIER constants for
    // exactly this comparison, unused until now. whereJsonContains()
    // on a plain string column doesn't just return wrong results —
    // it fails outright, since json_contains() needs a JSON column.
    private function arApSummary(int $companyId): array
    {
        $ar = ObPartnerInvoice::where('company_id', $companyId)
            ->where('partner_type', ObPartnerInvoice::TYPE_CUSTOMER)
            ->sum('net_balance');
        $ap = ObPartnerInvoice::where('company_id', $companyId)
            ->where('partner_type', ObPartnerInvoice::TYPE_SUPPLIER)
            ->sum('net_balance');
        return ['total_ar' => $ar, 'total_ap' => $ap];
    }
}