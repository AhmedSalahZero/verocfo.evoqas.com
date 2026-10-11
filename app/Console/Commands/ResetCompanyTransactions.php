<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  ResetCompanyTransactions
//
//  Deletes every TRANSACTION for one company — invoices, bills,
//  payments, notes, orders, GRNs, journal entries, cheques, treasury
//  facility activity, fixed assets, projects, production/job orders,
//  stock movements — while keeping every SETTING (per Mahmoud,
//  September 2026):
//
//    KEPT — Users & permissions, Chart of Accounts, Cost Centers,
//    Product Categories, Products (+ prices/UOM/BOM), Payment Terms,
//    Inventory Locations, Partners (+ contacts), Currencies &
//    Exchange Rates, Banks & Bank Accounts, Credit Facilities /
//    Leasing Companies / Factoring Companies (the CONTAINER only —
//    a fresh, undrawn facility with zero history), Sales Channels,
//    Units of Measure, Tax Settings, Job Order Templates, Fiscal
//    Years & Accounting Periods (kept as-is), Opening Balances (kept
//    — the company returns to its day-one starting position, not a
//    true blank slate). HR: Employee Payroll Profiles (the CONTAINER
//    only — same treatment as a credit facility container: the
//    employee's salary/category setup survives, its history doesn't)
//    and Deduction Reasons (a configurable lookup list, same
//    category as Payment Terms / Sales Channels) are also KEPT.
//
//    DELETED — everything else, INCLUDING Fixed Assets, Projects,
//    Purchase Orders and Sales Orders (explicitly, per Mahmoud —
//    these are NOT treated as settings here, unlike a first read of
//    "keep master data" might suggest). HR: every Payroll Run
//    (payroll_runs, payroll_run_lines, payroll_run_line_items,
//    payroll_run_postings) and every Salary Revision
//    (employee_salary_revisions) are DELETED — these are the
//    employee's transaction history, not their setup.
//
//  Notifications and the Audit Log are also cleared — both exist
//  only to describe the transactions being deleted, and would
//  otherwise be left pointing at rows that no longer exist. If that
//  assumption is wrong, tell me and I'll change it — it's the one
//  judgment call I made without asking, to avoid a fourth round of
//  questions on top of the three we already went through.
//
//  ANOTHER JUDGMENT CALL — partner_employee_history (job title/level
//  changes logged against a Partner) is also being DELETED here, on
//  the same reasoning as bank_account_terms_history and
//  credit_facility_terms_histories just below: it's a change LOG
//  belonging to a kept container (the Partner), not the container
//  itself. If Mahmoud wants employment history treated as part of
//  the Partner's master data instead (i.e. KEPT), this is the one
//  line to remove from the table list below.
//
//  SAFETY — this is IRREVERSIBLE. The command:
//    1. Always shows a full per-table row count first.
//    2. Requires --dry-run to be OFF explicitly (dry-run is the
//       default behaviour with no flags — see handle()).
//    3. On a real run, requires TYPING the company's exact name to
//       proceed — a --force flag does not skip this, only skips the
//       initial "are you sure" prompt.
//    4. Runs inside one DB transaction — a failure partway rolls
//       everything back, never leaves the company half-wiped.
//
//  Usage:
//    php artisan company:reset-transactions {company_id}
//        Dry run — shows row counts only, deletes nothing.
//    php artisan company:reset-transactions {company_id} --live
//        The real thing — deletes everything listed above, after
//        typed confirmation.
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  Audit H8 (Sept 2026):
//    - The audit trail is no longer deleted; the reset is logged.
//    - The opening-balance journal entry and its lines are kept, so
//      "posted and locked" opening balances still match the ledger.
//    - Refuses to run on the production server.
//
//  @layer    Console Command
//  @depends  Company, every transactional model's underlying table,
//            including PayrollRun / PayrollRunLine /
//            PayrollRunLineItem / PayrollRunPosting /
//            EmployeeSalaryRevision (HR module)
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetCompanyTransactions extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'company:reset-transactions
        {company_id : The company to reset}
        {--live : Actually delete. Without this flag, the command only shows what WOULD be deleted.}
        {--force : Skip the initial confirmation prompt (the typed-name confirmation on a --live run still applies).}';

    protected $description = 'Deletes every transaction for one company (invoices, bills, payments, orders, journal entries, treasury activity, fixed assets, projects, stock movements, etc.) while keeping every setting (users, COA, partners, products, currencies, banks/facilities, payment terms, inventory locations, fiscal years, opening balances).';

    /**
     * Deepest children first, headers after, journal_entries dead
     * last. Every table here was individually confirmed to carry its
     * own company_id column.
     *
     * This exact order was NOT hand-reasoned a second time — the
     * first version of this command failed on a real run (delivery_
     * note_lines → sales_order_lines, deleted in the wrong order).
     * This order was instead generated by parsing every migration's
     * actual ->constrained()/->on() foreign key definitions into a
     * dependency graph and running a real topological sort against
     * it, then re-verified by walking every single edge in that
     * graph and confirming the referencing table's position comes
     * before the referenced table's position. Both steps passed
     * clean. If a table is ever added to this list by hand later,
     * re-run that same check rather than guessing at the position —
     * this schema is far too interconnected to eyeball safely (see
     * this command's chat history for the exact script used).
     */
    private const TABLES_IN_DELETE_ORDER = [
        // ── Deepest children: lines, allocations, movements, history ──
        'credit_note_lines',
        'customer_debit_note_lines',
        'customer_invoice_lines',
        'supplier_credit_note_lines',
        'supplier_debit_note_lines',
        'delivery_note_lines',
        'cash_expense_payment_project_allocations',
        'cash_expense_payment_lines',
        'customer_refunds',                // Audit M9
        'customer_credit_applications',    // Audit M9
        'customer_payment_allocations',
        'supplier_payment_allocations',
        'internal_settlement_customer_allocations',
        'internal_settlement_supplier_allocations',
        'landing_cost_allocations',
        'grn_landing_cost_selections',
        'lg_issuance_movements',
        'lg_issuance_history',
        'lc_issuance_movements',
        'lc_issuance_history',
        'lc_issuance_expenses',
        'lease_amortization_lines',
        'lease_cheques',
        'lease_wear_down_entries',
        'facility_withdrawals',
        'facility_liability_charges',
        'facility_daily_balances',
        'facility_period_end_entries',     // Audit M21–M23
        'facility_rate_trueups',           // Audit M24
        'facility_contract_assignments',
        'factoring_transactions',
        'mtl_settlements',
        'mtl_schedule_lines',
        'bank_account_terms_history',
        'bank_account_td_events',
        'credit_facility_terms_histories',
        'factoring_agreement_terms_histories',

        // ── HR — leaf history tables (no dependents of their own).
        // employee_payroll_profiles itself is the CONTAINER and is
        // KEPT (same treatment as a credit facility) — only its
        // history/activity is deleted here. See header comment for
        // the partner_employee_history judgment call.
        'employee_salary_revisions',
        'partner_employee_history',
        'payroll_run_line_items', // child of payroll_run_lines — deduction_reasons (kept) is untouched

        'fixed_asset_cost_lines',
        'fixed_asset_depreciation_entries',
        'prepaid_amortization_entries',
        'supplier_bill_lines',
        'goods_receipt_note_lines',
        'purchase_order_lines',
        'production_order_cost_entries',
        'job_order_cost_entries',
        'job_order_lines', // actual job orders' own lines — NOT job_order_template_lines, which is kept
        'stock_reservations',
        'sales_order_lines',
        'stock_movements',
        'product_location_stock',
        'product_stock_balances',

        // ── Headers / parents ──
        'customer_credit_balances',        // Audit M9 — before credit_notes it points at
        'credit_notes',
        'customer_debit_notes',
        'customer_invoices',
        'supplier_credit_notes',
        'supplier_debit_notes',
        'internal_settlements',
        'delivery_notes',
        'landing_costs',
        'goods_receipt_notes',
        'accruals',
        'cheques_receivable',
        'customer_payments',
        'cheques_payable',
        'reverse_factoring_transactions',
        'supplier_payments',
        'cash_expense_payments',
        'buy_sell_currencies',
        'lg_issuances',
        'letter_of_credit_issuances',
        'supplier_bills',
        'purchase_orders',
        'leasing_contracts',
        'factoring_agreements',
        'internal_money_transfers',
        'other_collections',
        'other_payments',
        'production_orders',
        'job_orders', // actual job orders — NOT job_order_templates, which is kept
        'sales_orders',
        'fixed_assets',
        'projects',

        // ── HR — Payroll Run headers. payroll_run_postings references
        // BOTH payroll_runs and journal_entries, so it must be deleted
        // before either — payroll_run_lines is payroll_runs' other
        // child (via payroll_run_line_items above), so both clear
        // before payroll_runs itself deletes last of the three.
        'payroll_run_postings',
        'payroll_run_lines',
        'payroll_runs',

        // ── Dead last: nothing above still references these ──
        'journal_entry_lines',
        'journal_entries',

        // ── System/log tables that only describe the above ──
        // audit_log REMOVED from this list (Audit H8): the audit trail
        // is never deleted — the reset itself is recorded in it.
        'notifications',
        'document_sequences', // Audit L-7: numbering restarts with the documents
    ];

    /**
     * Audit H8 — rows that must SURVIVE a reset. Opening balances are a
     * setting this command promises to keep, and they stay "posted and
     * locked" — so their journal entry (and its lines) must stay too,
     * otherwise the ledger loses every opening balance while the
     * Opening Balances screen still says "posted".
     */
    private function keepCondition(string $table, int $companyId): ?\Closure
    {
        $obSource = \App\Models\JournalEntry::SOURCE_OPENING_BALANCE;

        return match ($table) {
            'journal_entries' => fn ($q) => $q->where('source_type', '!=', $obSource),
            'journal_entry_lines' => fn ($q) => $q->whereNotIn('journal_entry_id', DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->where('source_type', $obSource)
                ->select('id')),
            default => null,
        };
    }

    private function scopedQuery(string $table, int $companyId)
    {
        $query = DB::table($table)->where('company_id', $companyId);
        $keep  = $this->keepCondition($table, $companyId);

        return $keep ? $keep($query) ?? $query : $query;
    }

    public function handle(): int
    {
        // Audit H8 — never on the live server
        if ($this->refuseInProduction()) {
            return 1;
        }

        $company = Company::withoutGlobalScopes()->find($this->argument('company_id'));

        if (! $company) {
            $this->error("No company found with id {$this->argument('company_id')}.");
            return self::FAILURE;
        }

        $this->info("Company: [{$company->id}] {$company->name_en}");
        $this->newLine();

        $counts = $this->countAll($company->id);
        $this->renderCounts($counts);

        $totalRows = array_sum($counts);
        if ($totalRows === 0) {
            $this->info('Nothing to delete — this company has no transactional data.');
            return self::SUCCESS;
        }

        if (! $this->option('live')) {
            $this->newLine();
            $this->comment('DRY RUN — nothing was deleted. Re-run with --live to actually delete the rows above.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This will PERMANENTLY delete every row counted above. Settings (users, Chart of Accounts, partners, products, currencies, banks/facilities, payment terms, inventory locations, fiscal years, and opening balances) are NOT touched.');

        if (! $this->option('force') && ! $this->confirm('Do you want to continue?')) {
            $this->comment('Cancelled — nothing was deleted.');
            return self::SUCCESS;
        }

        $typed = $this->ask("Type the company's exact name to confirm ({$company->name_en}):");
        if ($typed !== $company->name_en) {
            $this->error('Name did not match — nothing was deleted.');
            return self::FAILURE;
        }

        $this->newLine();
        $bar = $this->output->createProgressBar(count(self::TABLES_IN_DELETE_ORDER));
        $bar->start();

        DB::transaction(function () use ($company, $bar, $counts) {
            foreach (self::TABLES_IN_DELETE_ORDER as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                    $this->scopedQuery($table, $company->id)->delete();
                }
                $bar->advance();
            }

            // Audit H8 — the reset itself goes into the audit trail.
            \App\Models\AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => null,
                'action'         => 'company.transactions_reset',
                'auditable_type' => Company::class,
                'auditable_id'   => $company->id,
                'before'         => $counts,
                'after'          => null,
                'ip_address'     => 'console',
                'user_agent'     => 'artisan company:reset-transactions',
            ]);
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done — every transaction for [{$company->id}] {$company->name_en} has been deleted. Settings were kept.");

        return self::SUCCESS;
    }

    /** @return array<string, int> table name => row count for this company */
    private function countAll(int $companyId): array
    {
        $counts = [];
        foreach (self::TABLES_IN_DELETE_ORDER as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                $counts[$table] = $this->scopedQuery($table, $companyId)->count();
            }
        }
        return $counts;
    }

    private function renderCounts(array $counts): void
    {
        $rows = [];
        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $rows[] = [$table, number_format($count)];
            }
        }

        if (empty($rows)) {
            return;
        }

        $this->table(['Table', 'Rows to delete'], $rows);
    }
}