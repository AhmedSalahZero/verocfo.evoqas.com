<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccountSeeder
//
//  Seeds the complete Vero CFO Chart of Accounts.
//  All system accounts have company_id = NULL and is_system = TRUE.
//  Available to every company on the platform.
//
//  Changes in v1.1:
//  — 311 renamed: Share Capital → Paid Up Capital
//  — 223 sub-accounts fully revised per banking expertise:
//      Clean Overdraft / OD Against Commercial Paper /
//      OD Against Contract Assignment /
//      Cheque Discounting With Recourse / Import LC
//      Export LC removed — not a facility, used as collateral only
//      LG Margin removed — cash cover lives under 132 Banks (Assets)
//  — 224 Current Portion of LT Loans renumbered to 225
//  — 224 NEW: Factoring Liabilities (own Level 3 account)
//      224001 Factoring Liabilities With Recourse
//      224002 Reverse Factoring Liabilities
//  — 225 Taxes renumbered to 226
//  — 227 NEW: LG Contingent Liabilities (contra — nets to zero)
//  — 228 Accrued Expenses renumbered to 228 (was 226)
//  — Remaining accounts renumbered accordingly
//  — Assets: 134 Notes Receivable gets sub-accounts for
//      discounted notes with recourse tracking
//  — Assets: 138 NEW: Cash Cover (138001 LG Cash Cover /
//      138002 LC Cash Cover) — real restricted cash placed against
//      LG/LC facilities, not a contingent-asset contra pair. (Note,
//      July 2026: this line originally described 138 as "LG
//      Contingent Asset (contra pair for 227)" — that was the
//      pre-launch design; it was replaced by the Cash Cover design
//      actually built and confirmed with Mahmoud before 227 below
//      was ever used. Corrected here so this changelog matches what
//      the seeder has always actually created.)
//  — Assets: 139 renamed Intercompany Receivables
//  — Assets: 140 NEW: Other Debtors (was 139)
//
//  Changes in Audit H19 (Sept 2026) — see auditH19Accounts():
//  — 124 Deferred Tax Asset / 215 Deferred Tax Liability (IAS 12)
//  — 149 Allowance for Expected Credit Losses (IFRS 9) and
//    150 Provision for Inventory Obsolescence (IAS 2) — CONTRA
//    current assets (credit-normal); the Balance Sheet and Cash Flow
//    subtract them instead of adding them
//  — 424 Gain on Disposal of Fixed Assets, 545 Loss on Disposal,
//    546 Impairment Loss (IAS 16 / IAS 36), 544 Expected Credit Loss
//    expense, 517 Inventory Write-down
//  — 59 Income Tax (new account_type 'Income Tax' — shown BELOW
//    Profit Before Tax, never inside the six expense categories)
//    with 591 Current Income Tax and 592 Deferred Tax
//  Existing installations receive them through migration 000301.
//
//  Idempotent — uses updateOrCreate on code so re-running
//  updates existing records without duplicating.
//
//  @layer    Seeder
//  @depends  ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedAssets();
            $this->seedLiabilities();
            $this->seedEquity();
            $this->seedRevenue();
            $this->seedExpenses();
            $this->seedAuditH19Accounts();
        });

        $this->command->info('ChartOfAccountSeeder: Full COA seeded successfully.');
    }

    // ── Helper: idempotent upsert ─────────────────────────────────

    private function upsert(array $data): ChartOfAccount
    {
        return ChartOfAccount::updateOrCreate(
            ['code' => $data['code'], 'company_id' => null],
            array_merge($data, ['company_id' => null, 'is_system' => true, 'is_active' => true])
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  CLASS 1 — ASSETS
    // ══════════════════════════════════════════════════════════════

    private function seedAssets(): void
    {
        // ── Level 1 ───────────────────────────────────────────────
        $assets = $this->upsert([
            'code' => '1', 'name_en' => 'Assets', 'name_ar' => 'الأصول',
            'level' => 1, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Assets', 'normal_balance' => 'debit',
            'parent_id' => null, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // ── Level 2 — Groups ──────────────────────────────────────
        $fixedAssets = $this->upsert([
            'code' => '11', 'name_en' => 'Fixed Assets', 'name_ar' => 'الأصول الثابتة',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
            'parent_id' => $assets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $longTermAssets = $this->upsert([
            'code' => '12', 'name_en' => 'Long-Term Assets', 'name_ar' => 'أصول طويلة الأجل',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Long-Term Assets', 'normal_balance' => 'debit',
            'parent_id' => $assets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $currentAssets = $this->upsert([
            'code' => '13', 'name_en' => 'Current Assets', 'name_ar' => 'الأصول المتداولة',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $assets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // ── Level 3 — Fixed Assets ────────────────────────────────
        $tangibleFA = $this->upsert([
            'code' => '111', 'name_en' => 'Tangible Fixed Assets', 'name_ar' => 'الأصول الثابتة الملموسة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
            'parent_id' => $fixedAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $intangibleFA = $this->upsert([
            'code' => '112', 'name_en' => 'Intangible Assets', 'name_ar' => 'الأصول غير الملموسة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
            'parent_id' => $fixedAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $accumDepr = $this->upsert([
            'code' => '113', 'name_en' => 'Accumulated Depreciation', 'name_ar' => 'مجمع الإهلاك',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Accumulated Depreciation', 'normal_balance' => 'credit',
            'parent_id' => $fixedAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 114/115 — added COA v1.2 for IFRS 16 Leasing (Capital Leases +
        // Operational Lease Treatment B). Both are non-postable parents —
        // one sub-account per lease contract is auto-created under each
        // when a lease is activated (Building Bible Rule 29.4). Nothing
        // ever posts directly to 114 or 115 themselves.
        $this->upsert([
            'code' => '114', 'name_en' => 'Right-of-Use Assets', 'name_ar' => 'أصول حق الاستخدام',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
            'parent_id' => $fixedAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '115', 'name_en' => 'Accumulated Amortization — ROU Assets', 'name_ar' => 'مجمع إطفاء — أصول حق الاستخدام',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Accumulated Depreciation', 'normal_balance' => 'credit',
            'parent_id' => $fixedAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // ── Level 3 — Long-Term Assets ────────────────────────────
        $this->upsert([
            'code' => '121', 'name_en' => 'Long-Term Investments', 'name_ar' => 'استثمارات طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Long-Term Assets', 'normal_balance' => 'debit',
            // is_postable corrected true — August 2026, same audit that
            // fixed 139/140/230/231/232. No sub-accounts, no auto-creation
            // mechanism anywhere in the app — this was unusable exactly the
            // same way. See migration 000177 / ChartOfAccountsMarkPostable
            // command for the one-time fix on already-seeded companies.
            'parent_id' => $longTermAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '122', 'name_en' => 'Projects Under Progress', 'name_ar' => 'مشاريع تحت التنفيذ',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Long-Term Assets', 'normal_balance' => 'debit',
            // is_postable corrected true — same reasoning as 121 above.
            'parent_id' => $longTermAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '123', 'name_en' => 'Other Long-Term Assets', 'name_ar' => 'أصول أخرى طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Long-Term Assets', 'normal_balance' => 'debit',
            // is_postable corrected true — same reasoning as 121 above.
            'parent_id' => $longTermAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // ── Level 3 — Current Assets ──────────────────────────────
        $this->upsert([
            'code' => '131', 'name_en' => 'Cash', 'name_ar' => 'النقدية',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => true,
        ]);

        $this->upsert([
            'code' => '132', 'name_en' => 'Banks', 'name_ar' => 'البنوك',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => true,
        ]);

        $arAccount = $this->upsert([
            'code' => '133', 'name_en' => 'Accounts Receivable', 'name_ar' => 'ذمم مدينة تجارية',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $notesRec = $this->upsert([
            'code' => '134', 'name_en' => 'Notes Receivable', 'name_ar' => 'أوراق قبض',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $inventory = $this->upsert([
            'code' => '135', 'name_en' => 'Inventory', 'name_ar' => 'المخزون',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '136', 'name_en' => 'Prepaid Expenses', 'name_ar' => 'مصروفات مدفوعة مقدماً',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            // is_postable corrected true — August 2026, same audit as 121/
            // 122/123 above.
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '137', 'name_en' => 'VAT Input', 'name_ar' => 'ضريبة القيمة المضافة — مدخلات',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 138 — Cash Cover (non-postable parent)
        // Restricted deposits placed with banks as collateral for:
        //   138001 — LG Cash Cover: collateral for Letters of Guarantee
        //   138002 — LC Cash Cover: collateral for Letters of Credit
        // Parent is non-postable — all transactions go to sub-accounts.
        $cashCover = $this->upsert([
            'code' => '138', 'name_en' => 'Cash Cover', 'name_ar' => 'ودائع الضمان النقدية',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        foreach ([
            ['138001', 'LG Cash Cover', 'ودائع خطابات الضمان'],
            ['138002', 'LC Cash Cover', 'ودائع خطابات الاعتماد'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Current Assets', 'normal_balance' => 'debit',
                'parent_id' => $cashCover->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        $this->upsert([
            'code' => '139', 'name_en' => 'Intercompany Receivables', 'name_ar' => 'ذمم شركات شقيقة — مدينة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            // is_postable corrected true — August 2026, Other Collection/
            // Other Payment build. Was left non-postable with no
            // sub-accounts ever created underneath it — an unintentional
            // gap, not a deliberate group header (compare 141 Goods In
            // Transit / 233 GRNI, which are the correct pattern for a
            // Level 3 account with no children: directly postable, per
            // Building Bible Rule 2's exception list). See migration
            // 000176 for the one-time fix on already-seeded companies.
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 140 — Other Debtors
        // Was briefly made a directly-postable leaf account (August 2026,
        // Other Collection/Other Payment build — see migration 000176)
        // because it had no children yet. Reversed here: Factoring
        // Without Recourse needs to trace "money owed to us by factoring
        // companies" as its OWN traceable balance (Trial Balance /
        // Account Statement line), not buried inside a shared bucket —
        // same reasoning that gave 224 its own 224001/224002 children
        // instead of a generic liability account. 140 goes back to
        // being a non-postable Level 3 parent; postings now go to its
        // child 140001 below. See migration 2026_01_01_000188 for the
        // one-time fix on already-seeded companies (flips 140, adds
        // 140001, and moves any rows already posted to bare 140).
        $otherDebtors = $this->upsert([
            'code' => '140', 'name_en' => 'Other Debtors', 'name_ar' => 'مدينون آخرون',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 140001 — Due From Factoring Companies
        // The retained percentage on a Direct Factoring — Without
        // Recourse sale (e.g. factor 90% of an invoice, the other 10%
        // is owed back by the factoring company once it settles). See
        // COA doc Section 8.1.
        $this->upsert([
            'code' => '140001', 'name_en' => 'Due From Factoring Companies', 'name_ar' => 'مستحق من شركات التخصيم',
            'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $otherDebtors->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 141 — Goods In Transit
        // Records ownership of goods shipped FOB Origin but not yet received.
        // is_postable = true at Level 3 — no sub-accounts. System account.
        $this->upsert([
            'code' => '141', 'name_en' => 'Goods In Transit', 'name_ar' => 'بضاعة في الطريق',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 142 — Debit WHT Receivable (ضريبة الخصم تحت الحساب)
        // Records WHT withheld by customers from payments to the company.
        // This is an advance payment of Corporate Income Tax.
        // Accumulated during the year and offset against CIT at year-end.
        // is_postable = true — system account, no sub-accounts.
        $this->upsert([
            'code' => '142', 'name_en' => 'Debit WHT Receivable', 'name_ar' => 'ضريبة الخصم تحت الحساب',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 143 — added July 2026, Delivery & Revenue Recognition v1.0
        // (see VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md
        // Section 2.1). Revenue recognized at delivery (the real IFRS
        // 15 transfer-of-control trigger) BEFORE the invoice that
        // formalizes it as AR has been raised — the normal sequence
        // for physical-goods Sales Order lines. Clears at invoice
        // time: Dr AR / Cr 143, with any price variance flowing to
        // Revenue (Section 4 of the same doc).
        $this->upsert([
            'code' => '143', 'name_en' => 'Accrued Revenue', 'name_ar' => 'إيرادات مستحقة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 144 — Advances to Suppliers (Supplier Payment, August 2026)
        // Mirrors 235 Advances from Customers on the payable side: money
        // paid to a supplier with no bill behind it yet, whether a
        // deliberately-tagged Down Payment or simply an over-payment
        // beyond what a Settlement needed. Debits here, never AP —
        // see SupplierPaymentService.
        $this->upsert([
            'code' => '144', 'name_en' => 'Advances to Suppliers', 'name_ar' => 'دفعات مقدمة لموردين',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 145 — Cash in Transit (Internal Money Transfer, August 2026)
        // Holds a transfer's value between its two legs whenever
        // transfer_days > 0 — mirrors 141 Goods In Transit's own
        // pattern (a system clearing account, no sub-accounts) but for
        // cash rather than goods. Dr 145/Cr [source] on initiate,
        // Dr [destination]/Cr 145 on Confirm Received. Unused when a
        // transfer clears same-day. See InternalMoneyTransferService.
        $this->upsert([
            'code' => '145', 'name_en' => 'Cash in Transit', 'name_ar' => 'نقدية في الطريق',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 146 — Landing Cost Clearing (non-postable parent, August 2026)
        // See VeroCFO_Landing_Cost_Design_v1_0.md. Holds logistics costs
        // (customs dues, transportation, etc.) that are capitalized into
        // Inventory once attached to a Goods Receipt Note — same
        // non-postable-parent-with-user-named-children pattern as 138
        // Cash Cover. Unlike 138, no children are seeded here — the user
        // adds their own (Customs Dues, Transportation, ...) via the
        // normal Chart of Accounts screen, same as any other Level 4
        // addition.
        $this->upsert([
            'code' => '146', 'name_en' => 'Landing Cost Clearing', 'name_ar' => 'تكاليف الشحن قيد التحصيل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 147 added 2026-09, Payroll GL Posting — mirrors 144 Advances
        // to Suppliers. Confirmed with Mahmoud: a dedicated line
        // rather than folding into generic 140 Other Debtors, so an
        // employee salary advance is visible on its own. Debited by
        // an Other Payment to the employee; relieved against a
        // future Payroll Accrual run.
        $this->upsert([
            'code' => '147', 'name_en' => 'Advances to Employees', 'name_ar' => 'دفعات مقدمة لموظفين',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 148 added 2026-09, Advances & Settlements. Sibling of 147 —
        // Custody money is a different animal from a salary advance
        // (settled by receipts, not payroll deduction), so it gets
        // its own account rather than sharing 147's balance.
        $this->upsert([
            'code' => '148', 'name_en' => 'Custody Advances to Employees', 'name_ar' => 'عهدة موظفين',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
            'account_type' => 'Current Assets', 'normal_balance' => 'debit',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // ── Level 4 — Tangible Fixed Assets ──────────────────────
        foreach ([
            ['111001', 'Land',                   'أراضي'],
            ['111002', 'Buildings',              'مباني'],
            ['111003', 'Machinery & Equipment',  'آلات ومعدات'],
            ['111004', 'Vehicles',               'سيارات ووسائل نقل'],
            ['111005', 'Office Furniture',       'أثاث ومفروشات'],
            ['111006', 'Computer Equipment',     'أجهزة وتقنية معلومات'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
                'parent_id' => $tangibleFA->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Accumulated Depreciation — one sub-account per 111xxx category
        // above, same pairing (Fixed Assets module, Phase 1 — confirmed
        // with Mahmoud: individual assets live in a subsidiary Asset
        // Register, not as their own COA row; only the category-level
        // total posts here).
        foreach ([
            ['113001', 'Accum. Depreciation — Land',                  'مجمع إهلاك — أراضي'],
            ['113002', 'Accum. Depreciation — Buildings',             'مجمع إهلاك — مباني'],
            ['113003', 'Accum. Depreciation — Machinery & Equipment', 'مجمع إهلاك — آلات ومعدات'],
            ['113004', 'Accum. Depreciation — Vehicles',              'مجمع إهلاك — سيارات ووسائل نقل'],
            ['113005', 'Accum. Depreciation — Office Furniture',      'مجمع إهلاك — أثاث ومفروشات'],
            ['113006', 'Accum. Depreciation — Computer Equipment',    'مجمع إهلاك — أجهزة وتقنية معلومات'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Accumulated Depreciation', 'normal_balance' => 'credit',
                'parent_id' => $accumDepr->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — Intangible Assets ───────────────────────────
        foreach ([
            ['112001', 'Software Licenses',    'تراخيص برامج'],
            ['112002', 'Patents & Trademarks',  'براءات اختراع وعلامات تجارية'],
            ['112003', 'Goodwill',              'شهرة المحل'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Fixed Assets', 'normal_balance' => 'debit',
                'parent_id' => $intangibleFA->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — AR sub-accounts ─────────────────────────────
        foreach ([
            ['133001', 'AR — Local Customers',  'ذمم عملاء محليون'],
            ['133002', 'AR — Export Customers', 'ذمم عملاء تصدير'],
        ] as [$code, $en, $arName]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $arName,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Current Assets', 'normal_balance' => 'debit',
                'parent_id' => $arAccount->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — Notes Receivable sub-accounts ───────────────
        // Discounted notes WITH recourse stay on the books as a
        // contra entry until the cheque matures and is collected.
        foreach ([
            ['134001', 'Notes Receivable — Customers',          'أوراق قبض — عملاء'],
            ['134002', 'Discounted Notes — With Recourse',       'أوراق قبض مخصومة — بضمان'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Current Assets', 'normal_balance' => 'debit',
                'parent_id' => $notesRec->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — Inventory sub-accounts ─────────────────────
        // Shown per company type — system seeds all four.
        // Application filters by company type at runtime.
        foreach ([
            ['135001', 'Raw Materials',            'المواد الخام'],
            ['135002', 'Work In Progress',         'إنتاج تحت التشغيل'],
            ['135003', 'Finished Goods',           'البضاعة المنتهية'],
            ['135004', 'Project Work In Progress', 'مشاريع تحت التنفيذ'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Current Assets', 'normal_balance' => 'debit',
                'parent_id' => $inventory->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CLASS 2 — LIABILITIES
    // ══════════════════════════════════════════════════════════════

    private function seedLiabilities(): void
    {
        $liabilities = $this->upsert([
            'code' => '2', 'name_en' => 'Liabilities', 'name_ar' => 'الالتزامات',
            'level' => 1, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Liabilities', 'normal_balance' => 'credit',
            'parent_id' => null, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // ── Level 2 ───────────────────────────────────────────────
        $ltLiab = $this->upsert([
            'code' => '21', 'name_en' => 'Long-Term Liabilities', 'name_ar' => 'التزامات طويلة الأجل',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $liabilities->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $currentLiab = $this->upsert([
            'code' => '22', 'name_en' => 'Current Liabilities', 'name_ar' => 'الالتزامات المتداولة',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $liabilities->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // ── Level 3 — Long-Term ───────────────────────────────────
        $this->upsert([
            'code' => '211', 'name_en' => 'Long-Term Loans', 'name_ar' => 'قروض طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — August 2026. Note: this doesn't
            // by itself give MTL loans automatic GL posting — MtlService
            // tracks the loan schedule but doesn't post journal entries at
            // all yet (a separate, larger gap). Marking this postable at
            // least allows a manual Journal Entry against it in the
            // meantime, same reasoning as every other account in this fix.
            'parent_id' => $ltLiab->id, 'is_postable' => true, 'allow_currency' => true,
        ]);

        $this->upsert([
            'code' => '212', 'name_en' => 'Bonds Payable', 'name_ar' => 'سندات مستحقة الدفع',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — August 2026, same audit as 211.
            'parent_id' => $ltLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 213 — RENAMED in COA v1.2 (was "Other Long-Term Liabilities" —
        // that meaning moved to the new 214 below, so nothing that used
        // 213 for general "other" liabilities silently changes meaning).
        // Now the long-term portion of a Capital Lease or Operational
        // Lease Treatment B liability. is_postable = true, no sub-accounts —
        // same "system-managed, auto-split, no manual subs" pattern as
        // GRNI (233): the current/long-term split is auto-calculated from
        // the lease amortization schedule (Building Bible Rule 29.5), the
        // user never manually reclassifies it.
        $this->upsert([
            'code' => '213', 'name_en' => 'Lease Liabilities — Long-Term', 'name_ar' => 'التزامات إيجار — طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $ltLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 214 — added COA v1.2. Holds the general "other long-term
        // liabilities" meaning that code 213 carried before this version.
        $this->upsert([
            'code' => '214', 'name_en' => 'Other Long-Term Liabilities', 'name_ar' => 'التزامات أخرى طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — August 2026, same audit as 211/212.
            'parent_id' => $ltLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // ── Level 3 — Current ─────────────────────────────────────
        $apAccount = $this->upsert([
            'code' => '221', 'name_en' => 'Accounts Payable', 'name_ar' => 'ذمم دائنة تجارية',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $notesPayable = $this->upsert([
            'code' => '222', 'name_en' => 'Notes Payable', 'name_ar' => 'أوراق دفع',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 223 — Short-Term Credit Facilities
        // RENAMED in COA v1.2 (was "Short-Term Banking Facilities") —
        // more precise now that Financial Institutions covers banks,
        // leasing companies, and factoring companies separately.
        // Revised per banking expertise:
        // Export LC removed — not a cash facility, used as collateral
        // LG cash cover removed — lives under 132 Banks (Assets side)
        $stBanking = $this->upsert([
            'code' => '223', 'name_en' => 'Short-Term Credit Facilities', 'name_ar' => 'تسهيلات ائتمانية قصيرة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => true,
        ]);

        // 224 — Factoring Liabilities (separate from banking — factoring companies)
        $factoring = $this->upsert([
            'code' => '224', 'name_en' => 'Factoring Liabilities', 'name_ar' => 'التزامات التخصيم',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 225 — Current Portion of Long-Term Loans
        $this->upsert([
            'code' => '225', 'name_en' => 'Current Portion of Long-Term Loans', 'name_ar' => 'الجزء الجاري من القروض طويلة الأجل',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => true,
        ]);

        // 226 — Taxes Payable
        $taxes = $this->upsert([
            'code' => '226', 'name_en' => 'Taxes Payable', 'name_ar' => 'ضرائب مستحقة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 227 — LG Contingent Liabilities
        // DORMANT (confirmed with Mahmoud, July 2026 audit): Vero CFO
        // never journals a contingent liability for an LG — gross LG
        // exposure is disclosed via the LG register / Facility
        // Dashboard (a live query), never booked. Nothing in the
        // codebase posts to this account. Left seeded rather than
        // removed, in case a future disclosure-only report wants a
        // placeholder; candidate for removal if it stays unused.
        //
        // (Note: this comment originally described 227 as a "contra
        // pair to 138 LG Contingent Asset," netting to zero and
        // converting to a real liability on LG liquidation. That was
        // the pre-launch design — it was replaced by the Cash Cover
        // design (see account 138 above) before this account was ever
        // wired into LgIssuanceService. Corrected here to match what
        // the code actually does.)
        $this->upsert([
            'code' => '227', 'name_en' => 'LG Contingent Liabilities', 'name_ar' => 'التزامات محتملة — خطابات ضمان',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '228', 'name_en' => 'Accrued Expenses', 'name_ar' => 'مصروفات مستحقة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '229', 'name_en' => 'Dividend Payable', 'name_ar' => 'أرباح موزعة مستحقة الدفع',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '230', 'name_en' => 'Shareholders Current Accounts', 'name_ar' => 'حسابات جارية — مساهمون',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — August 2026, same reasoning as
            // 139/140 above. See migration 000176 for the one-time fix on
            // already-seeded companies.
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '231', 'name_en' => 'Intercompany Payables', 'name_ar' => 'ذمم شركات شقيقة — دائنة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — same reasoning as 230 above.
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '232', 'name_en' => 'Other Creditors', 'name_ar' => 'دائنون آخرون',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            // is_postable corrected true — same reasoning as 230 above.
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 233 — Goods Received Not Invoiced (GRNI)
        // Transit/clearing account for the purchase 3-way match cycle:
        //   Step 1 — Goods received, no supplier bill yet:
        //     DR  Inventory / Raw Materials
        //     CR  233 GRNI  ← this account
        //   Step 2 — Supplier bill arrives and is matched:
        //     DR  233 GRNI  ← reverses step 1
        //     CR  221 Accounts Payable ← real supplier liability
        // is_postable = true at Level 3 — no sub-accounts allowed.
        // Keeping this as one clearing account ensures clean matching.
        // System account — cannot be renamed or deleted.
        $this->upsert([
            'code' => '233', 'name_en' => 'Goods Received Not Invoiced (GRNI)', 'name_ar' => 'بضاعة مستلمة بدون فاتورة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 234 — added COA v1.2. The current-portion (due within 12
        // months) counterpart to 213 Lease Liabilities — Long-Term.
        // Same "system-managed, auto-split, no manual subs" pattern as
        // GRNI above — the current/long-term split is auto-calculated
        // from the lease amortization schedule at every Balance Sheet
        // date (Building Bible Rule 29.5).
        $this->upsert([
            'code' => '234', 'name_en' => 'Lease Liabilities — Current Portion', 'name_ar' => 'التزامات إيجار — الجزء الجاري',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 235 — added July 2026, Customer Collection v1.1 (see
        // VeroCFO_Customer_Collection_Design_v1_1.md Section 1).
        // Money received from a customer with no invoice behind it —
        // whether a deliberately-tagged Down Payment or simply an
        // over-collection beyond what a Settlement needed — must
        // never sit inside AR (133). It's a real liability: the
        // company owes goods, services, or a refund, not a reduction
        // of a receivable that doesn't exist yet. Clears via
        // CustomerPaymentService::allocate() posting
        // Dr 235 / Cr 133xxx once a real invoice is created against it.
        $this->upsert([
            'code' => '235', 'name_en' => 'Advances from Customers', 'name_ar' => 'دفعات مقدمة من العملاء',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 236 — added July 2026, Delivery & Revenue Recognition v1.0
        // (see VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md
        // Section 2.2). Distinct from 235 above — this is what a
        // Customer Invoice credits when it's raised BEFORE the goods
        // it covers have been delivered (advance billing): a real AR
        // claim exists, but revenue can't be recognized yet since
        // delivery (the IFRS 15 transfer-of-control trigger) hasn't
        // happened. Clears at delivery: Dr 236 / Cr Revenue, alongside
        // that same delivery's Dr COGS / Cr Inventory.
        $this->upsert([
            'code' => '236', 'name_en' => 'Deferred Revenue', 'name_ar' => 'إيرادات مؤجلة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // 237/238 added 2026-09, Manufacturing GL Posting fix.
        // Confirmed with Mahmoud: both are liability CLEARING
        // accounts, same family as 228 Accrued Expenses / 233 GRNI —
        // "we've recognized this cost into WIP, we owe the real
        // settlement later." 237 is credited by ProductionOrderService/
        // JobOrderService::recordLaborCost() (Dr 135002 WIP), settled
        // by real Payroll postings.
        //
        // 238 — CORRECTED Sept 2026 (Section 7, Applied vs Actual
        // variance closing): originally "kept as ONE account, any
        // breakdown comes from OverheadPool, not COA sub-accounts."
        // That was right before per-pool variance reconciliation was
        // a confirmed requirement — Mahmoud has since confirmed
        // overhead variance closes PER POOL (e.g. "Factory Electricity"
        // and "Machine Maintenance" each get their own variance line),
        // which needs each pool's Applied balance separately readable.
        // 238 is now a non-postable PARENT; each Overhead Pool gets
        // its own postable Level 4 child auto-created underneath the
        // moment the pool itself is created
        // (OverheadPoolService::create() → OverheadPool.
        // applied_account_id) — same auto-creation pattern already
        // used for Machine/Lease sub-accounts, not a company-wide
        // seeded list, since pools are themselves user-created.
        $this->upsert([
            'code' => '237', 'name_en' => 'Direct Labor Clearing', 'name_ar' => 'مصروفات أجور مباشرة معلقة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '238', 'name_en' => 'Manufacturing Overhead Absorbed', 'name_ar' => 'أعباء صناعية غير مباشرة محملة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 239-241 added 2026-09, Payroll GL Posting. Confirmed with
        // Mahmoud: 239 is credited by the monthly Payroll Accrual and
        // relieved by a direct payment — no reversing entry, since
        // salaries in this market are known precisely by month-end,
        // not estimated. 240 is kept separate from 226 Taxes Payable —
        // Social Insurance is owed to the Social Insurance Authority,
        // a different authority from the Tax Authority, and isn't a
        // tax. 241 is the monthly End of Service Benefits set-aside.
        $this->upsert([
            'code' => '239', 'name_en' => 'Salaries Payable', 'name_ar' => 'رواتب مستحقة الدفع',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '240', 'name_en' => 'Social Insurance Payable', 'name_ar' => 'تأمينات اجتماعية مستحقة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $currentLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        $this->upsert([
            'code' => '241', 'name_en' => 'End of Service Benefits Provision', 'name_ar' => 'مخصص مكافأة نهاية الخدمة',
            'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Long-term Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $ltLiab->id, 'is_postable' => true, 'allow_currency' => false,
        ]);

        // ── Level 4 — Short-Term Banking Facilities ───────────────
        // 5 overdraft types + Import LC (Export LC removed)
        // 223006 added August 2026 — Fully Secured Overdraft (see
        // migration 000158). Secured against a Time Deposit account
        // rather than unsecured (223001) or against paper/contracts
        // (223002/223003) — still a plain Current Liability like its
        // siblings, so it belongs in this same foreach block, not a
        // separate one.
        foreach ([
            ['223001', 'Clean Overdraft',                     'حد جاري مدين — بدون ضمان'],
            ['223002', 'OD Against Commercial Paper',         'حد جاري مدين — مقابل أوراق تجارية'],
            ['223003', 'OD Against Contract Assignment',      'حد جاري مدين — مقابل تنازل عن عمليات'],
            ['223004', 'Cheque Discounting — With Recourse',  'خصم أوراق تجارية — بضمان'],
            ['223005', 'Import Letters of Credit',            'اعتمادات مستندية استيراد'],
            ['223006', 'Fully Secured Overdraft',             'حد جاري مدين — مضمون بالكامل بوديعة'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'liabilities',
                'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
                'parent_id' => $stBanking->id, 'is_postable' => true, 'allow_currency' => true,
            ]);
        }

        // ── Level 4 — Factoring Liabilities ──────────────────────
        // With recourse = liability stays until invoice collected
        // Without recourse = no liability (settled at point of sale)
        foreach ([
            ['224001', 'Factoring Liabilities — With Recourse',  'التزامات تخصيم — بضمان'],
            ['224002', 'Reverse Factoring Liabilities',          'التزامات تخصيم عكسي'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'liabilities',
                'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
                'parent_id' => $factoring->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — Taxes Payable ───────────────────────────────
        foreach ([
            ['226001', 'VAT Output',              'ض.ق.م — مخرجات'],
            ['226002', 'Withholding Tax Payable',  'ضريبة الخصم والإضافة'],
            ['226003', 'Income Tax Payable',       'ضريبة الدخل المستحقة'],
            ['226004', 'Stamp Duty Payable',       'دمغة مستحقة'],
            // 226005 added 2026-09, Payroll GL Posting — withheld
            // from Salaries Payable at accrual time. Kept separate
            // from Social Insurance (240), which is owed to a
            // different authority and isn't a tax.
            ['226005', 'Salaries Tax Payable',     'ضريبة كسب العمل المستحقة'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'liabilities',
                'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
                'parent_id' => $taxes->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — AP sub-accounts ─────────────────────────────
        foreach ([
            ['221001', 'AP — Local Suppliers',  'ذمم موردون محليون'],
            ['221002', 'AP — Import Suppliers', 'ذمم موردون استيراد'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 4, 'section' => 'balance_sheet', 'class' => 'liabilities',
                'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
                'parent_id' => $apAccount->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // ── Level 4 — Notes Payable sub-account (Supplier Payment,
        //    August 2026) — mirrors 134001 Notes Receivable — Customers
        //    on the payable side: a Payable Cheque settles AP into
        //    here at issue time, then Mark as Paid moves it to real
        //    cash. See ChequePayableService.
        $this->upsert([
            'code' => '222001', 'name_en' => 'Notes Payable — Suppliers', 'name_ar' => 'أوراق دفع — موردين',
            'level' => 4, 'section' => 'balance_sheet', 'class' => 'liabilities',
            'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
            'parent_id' => $notesPayable->id, 'is_postable' => true, 'allow_currency' => false,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  CLASS 3 — EQUITY
    // ══════════════════════════════════════════════════════════════

    private function seedEquity(): void
    {
        $equity = $this->upsert([
            'code' => '3', 'name_en' => 'Equity', 'name_ar' => 'حقوق الملكية',
            'level' => 1, 'section' => 'balance_sheet', 'class' => 'equity',
            'account_type' => 'Equity', 'normal_balance' => 'credit',
            'parent_id' => null, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $ownersEquity = $this->upsert([
            'code' => '31', 'name_en' => "Owners' Equity", 'name_ar' => 'حقوق المساهمين',
            'level' => 2, 'section' => 'balance_sheet', 'class' => 'equity',
            'account_type' => 'Equity', 'normal_balance' => 'credit',
            'parent_id' => $equity->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // 311 renamed: Share Capital → Paid Up Capital
        // is_postable corrected true for 311/312/313 — August 2026, same
        // audit as 121/122/123/136/211/212/214/139/140/230/231/232. None
        // of the three ever had a sub-account or any dedicated module
        // (no Share Capital screen, no Reserves/Appropriations screen) —
        // genuinely unusable as seeded, the same broken pattern found
        // everywhere else in this fix. 314/315 were already correctly
        // postable and are untouched.
        foreach ([
            ['311', 'Paid Up Capital',            'رأس المال المدفوع',           true],
            ['312', 'Legal Reserve',              'الاحتياطي القانوني',          true],
            ['313', 'General Reserve',            'الاحتياطي العام',             true],
            ['314', 'Retained Earnings',          'الأرباح المرحلة',             true],
            ['315', 'Current Year Profit / Loss', 'أرباح / خسائر العام الجاري',  true],
        ] as [$code, $en, $ar, $postable]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'balance_sheet', 'class' => 'equity',
                'account_type' => 'Equity', 'normal_balance' => 'credit',
                'parent_id' => $ownersEquity->id,
                'is_postable' => $postable, 'allow_currency' => false,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CLASS 4 — REVENUE
    // ══════════════════════════════════════════════════════════════

    private function seedRevenue(): void
    {
        $revenue = $this->upsert([
            'code' => '4', 'name_en' => 'Revenue', 'name_ar' => 'الإيرادات',
            'level' => 1, 'section' => 'income_statement', 'class' => 'revenue',
            'account_type' => 'Revenue', 'normal_balance' => 'credit',
            'parent_id' => null, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $salesRev = $this->upsert([
            'code' => '41', 'name_en' => 'Sales Revenue', 'name_ar' => 'إيرادات المبيعات',
            'level' => 2, 'section' => 'income_statement', 'class' => 'revenue',
            'account_type' => 'Revenue', 'normal_balance' => 'credit',
            'parent_id' => $revenue->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $otherRev = $this->upsert([
            'code' => '42', 'name_en' => 'Other Operating Revenue', 'name_ar' => 'إيرادات تشغيلية أخرى',
            'level' => 2, 'section' => 'income_statement', 'class' => 'revenue',
            'account_type' => 'Revenue', 'normal_balance' => 'credit',
            'parent_id' => $revenue->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $fxGain = $this->upsert([
            'code' => '43', 'name_en' => 'FX Exchange Gain', 'name_ar' => 'أرباح فروق العملة',
            'level' => 2, 'section' => 'income_statement', 'class' => 'revenue',
            'account_type' => 'Revenue', 'normal_balance' => 'credit',
            'parent_id' => $revenue->id, 'is_postable' => false, 'allow_currency' => false,
        ]);

        // Level 3 — Sales Revenue
        // Fix (July 2026 — Delivery & Revenue Recognition): these were
        // seeded is_postable = false, requiring a company to create its
        // own Level 4 sub-account before ANY ordinary sale could be
        // posted at all — the Customer Invoice Revenue Account
        // dropdown was effectively empty out of the box. This was a
        // genuine inconsistency, not a deliberate design: their exact
        // mirror on the expense side (511-513 COGS) is directly
        // postable per the COA doc's own "no sub-account needed"
        // exception list, and their revenue siblings right below
        // (421-423) were already seeded is_postable = true. Corrected
        // to match both precedents — 411/412/413 now work immediately;
        // a company can still create Level 4 subs underneath them for
        // finer reporting if it wants to, same as any other account.
        foreach ([
            ['411', 'Sales — Local',   'مبيعات محلية'],
            ['412', 'Sales — Export',  'مبيعات تصدير'],
            ['413', 'Service Revenue', 'إيرادات خدمات'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'revenue',
                'account_type' => 'Revenue', 'normal_balance' => 'credit',
                'parent_id' => $salesRev->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — Other Revenue
        // Fix (July 2026 — Time Deposit Lifecycle): 423 was still
        // seeded as "Rental Income" even though COA doc v1.2 renamed
        // it to "Interest & Investment Income" specifically to cover
        // TD interest, bank deposit interest, and other investment
        // income — the doc and the seeder had drifted apart. Corrected
        // here to match the doc, since TimeDepositService now posts
        // real TD interest to this account.
        foreach ([
            ['421', 'Scrap Sales',       'مبيعات خردة'],
            ['422', 'Commission Income', 'إيرادات عمولات'],
            ['423', 'Interest & Investment Income', 'إيرادات الفوائد والاستثمار'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'revenue',
                'account_type' => 'Revenue', 'normal_balance' => 'credit',
                'parent_id' => $otherRev->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — FX Gain
        foreach ([
            ['431', 'Realized FX Gain',   'أرباح فروق عملة محققة'],
            ['432', 'Unrealized FX Gain', 'أرباح فروق عملة غير محققة'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'revenue',
                'account_type' => 'Revenue', 'normal_balance' => 'credit',
                'parent_id' => $fxGain->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CLASS 5 — EXPENSES
    // ══════════════════════════════════════════════════════════════

    private function seedExpenses(): void
    {
        $expenses = $this->upsert([
            'code' => '5', 'name_en' => 'Expenses', 'name_ar' => 'المصروفات',
            'level' => 1, 'section' => 'income_statement', 'class' => 'expenses',
            'account_type' => 'Expenses', 'normal_balance' => 'debit',
            'parent_id' => null, 'is_postable' => false, 'allow_currency' => false,
        ]);

        $groups = [
            ['51', 'Cost of Goods Sold',                'تكلفة البضاعة المباعة',         'Cost of Goods Sold'],
            ['52', 'Sales & Distribution Expenses',     'مصروفات المبيعات والتوزيع',     'Sales Expenses'],
            ['53', 'Marketing Expenses',                'مصروفات التسويق',               'Marketing Expenses'],
            ['54', 'General & Administrative Expenses', 'مصروفات عمومية وإدارية',       'General & Administrative Expenses'],
            ['55', 'Finance Costs',                     'تكاليف تمويلية',                'Finance Expenses'],
            ['56', 'Depreciation & Amortization',       'إهلاك واستهلاك',               'Depreciation & Amortization'],
            ['57', 'FX Exchange Loss',                  'خسائر فروق العملة',             'Finance Expenses'],
        ];

        $groupModels = [];
        foreach ($groups as [$code, $en, $ar, $type]) {
            $groupModels[$code] = $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 2, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => $type, 'normal_balance' => 'debit',
                'parent_id' => $expenses->id, 'is_postable' => false, 'allow_currency' => false,
            ]);
        }

        // Level 3 — COGS
        foreach ([
            ['511', 'Cost of Goods Sold — Trading',  'تكلفة بضاعة مباعة — تجارة'],
            ['512', 'Cost of Manufactured Goods',    'تكلفة إنتاج مباع'],
            ['513', 'Cost of Services Delivered',    'تكلفة خدمات مقدمة'],
            // 514 added 2026-09, Payroll GL Posting. Manufacturing
            // salaries never get their own expense line — direct
            // labor is already capitalized into WIP via 237 Direct
            // Labor Clearing at recordLaborCost() time, and expensed
            // later, automatically, through COGS when Finished Goods
            // sells. When Payroll clears 237, the two numbers — what
            // Production accrued vs. what Payroll actually calculated
            // — will almost never match exactly. 514 absorbs that
            // difference (Dr = unfavorable, Cr = favorable), confirmed
            // with Mahmoud: a real, visible variance, never silently
            // absorbed into either account.
            ['514', 'Manufacturing Labor Variance',  'انحراف تكلفة العمالة الصناعية'],
            // 515 added 2026-09, Section 7 (Applied vs Actual — month-
            // end variance closing). Same principle as 514, one level
            // deeper: overhead reconciles PER Overhead Pool, not as one
            // company-wide number (confirmed with Mahmoud) — every
            // pool with a variance this period gets its OWN labeled
            // line under this ONE account, inside the same monthly
            // Payroll Run posting that already closes 514. Actual cost
            // per pool is never posted to its own GL account — it's
            // computed directly each run from real Accrual rows tagged
            // to that pool plus real payroll cost for that pool's
            // Overhead-role employees (Employee Manufacturing Role,
            // Section 2), exactly mirroring how labor's Actual was
            // never a posted balance either.
            ['515', 'Manufacturing Overhead Variance', 'انحراف التكاليف الصناعية غير المباشرة'],

            // INV-007 (confirmed with Mahmoud, 2026-09) — where a
            // stock adjustment's difference lands. A physical count
            // difference, a scrap, a write-off of damaged goods: the
            // inventory asset moves, and this is the account that
            // absorbs the other side of it.
            //
            // Under COGS rather than G&A, and its own line rather than
            // 511/512, deliberately: it belongs in gross margin
            // (it IS a cost of the goods) but must stay separable from
            // real cost of sales, so a month where shrinkage spiked is
            // visible instead of hiding inside COGS. Same reasoning
            // and same placement as the two variance accounts above.
            //
            // Debit-normal, so a write-OFF is a positive expense and a
            // write-ON (a count that found MORE than the books showed)
            // is a credit that reduces it.
            ['516', 'Inventory Adjustment', 'تسويات المخزون'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Cost of Goods Sold', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['51']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — Sales & Distribution (2026-09, Payroll GL Posting
        // — first line items ever seeded under this group).
        foreach ([
            ['521', 'Salaries — Sales & Distribution',                'رواتب — المبيعات والتوزيع'],
            ['522', 'Employee Reimbursements — Sales & Distribution', 'تعويضات موظفين — المبيعات والتوزيع'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Sales Expenses', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['52']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — Marketing (2026-09, Payroll GL Posting — first
        // line items ever seeded under this group).
        foreach ([
            ['531', 'Salaries — Marketing',                'رواتب — التسويق'],
            ['532', 'Employee Reimbursements — Marketing', 'تعويضات موظفين — التسويق'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Marketing Expenses', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['53']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — G&A (2026-09, Payroll GL Posting).
        foreach ([
            ['541', 'Salaries — General & Administrative',              'رواتب — عمومية وإدارية'],
            ['542', 'End of Service Benefits Expense',                   'مصروف مكافأة نهاية الخدمة'],
            ['543', 'Employee Reimbursements — General & Administrative', 'تعويضات موظفين — عمومية وإدارية'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'General & Administrative Expenses', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['54']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — Finance Costs
        foreach ([
            ['551', 'Interest on Loans',           'فوائد على قروض'],
            ['552', 'Bank Charges & Commissions',  'رسوم وعمولات بنكية'],
            ['553', 'Letter of Guarantee Fees',    'عمولة خطابات ضمان'],
            ['554', 'Letter of Credit Fees',       'عمولة اعتمادات مستندية'],
            ['555', 'Overdraft Interest',          'فوائد مكشوف'],
            // 556/557 added COA v1.2. Building Bible Rules 29.8/8.4:
            // 556 must always carry lease interest — never 551 (loan
            // interest) or 555 (OD interest); 557 covers both the
            // factoring discount and any admin fees a factoring
            // company charges, for Direct and Reverse Factoring alike.
            ['556', 'Lease Finance Charges',       'تكاليف تمويل عقود الإيجار'],
            ['557', 'Factoring Fees & Discount',   'عمولة وخصم التخصيم'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Finance Expenses', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['55']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — FX Loss
        foreach ([
            ['571', 'Realized FX Loss',   'خسائر فروق عملة محققة'],
            ['572', 'Unrealized FX Loss', 'خسائر فروق عملة غير محققة'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Finance Expenses', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['57']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }

        // Level 3 — D&A
        foreach ([
            ['561', 'Depreciation — Fixed Assets', 'إهلاك الأصول الثابتة'],
            ['562', 'Amortization — Intangibles',   'استهلاك الأصول غير الملموسة'],
            // 563 added COA v1.2. Building Bible Rule 29.9: ROU asset
            // amortization must always post here — never 561, which is
            // reserved for owned-asset depreciation. Keeps IFRS 16
            // disclosure separate from ordinary fixed-asset depreciation.
            ['563', 'Amortization — Right-of-Use Assets', 'إستهلاك أصول حق الاستخدام'],
        ] as [$code, $en, $ar]) {
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Depreciation & Amortization', 'normal_balance' => 'debit',
                'parent_id' => $groupModels['56']->id, 'is_postable' => true, 'allow_currency' => false,
            ]);
        }
    }


    // ══════════════════════════════════════════════════════════════
    //  AUDIT H19 (Sept 2026) — missing accounts
    // ══════════════════════════════════════════════════════════════

    /**
     * The accounts Audit H19 found missing. One list, used both by
     * this seeder (fresh installs) and by migration 000301 (existing
     * installs), so the two can never drift apart.
     * Each: [code, parent_code, name_en, name_ar, level, section,
     *        class, account_type, normal_balance, is_postable]
     */
    public static function auditH19Accounts(): array
    {
        return [
            // IAS 12 — deferred tax
            ['124', '12', 'Deferred Tax Asset', 'أصول ضريبية مؤجلة', 3, 'balance_sheet', 'assets', 'Long-Term Assets', 'debit', true],
            ['215', '21', 'Deferred Tax Liability', 'التزامات ضريبية مؤجلة', 3, 'balance_sheet', 'liabilities', 'Long-term Liabilities', 'credit', true],
            // Contra current assets (credit-normal) — IFRS 9 / IAS 2
            ['149', '13', 'Allowance for Expected Credit Losses', 'مخصص خسائر ائتمانية متوقعة', 3, 'balance_sheet', 'assets', 'Current Assets', 'credit', true],
            ['150', '13', 'Provision for Inventory Obsolescence', 'مخصص تقادم المخزون', 3, 'balance_sheet', 'assets', 'Current Assets', 'credit', true],
            // IAS 16 — disposal gain
            ['424', '42', 'Gain on Disposal of Fixed Assets', 'أرباح بيع أصول ثابتة', 3, 'income_statement', 'revenue', 'Revenue', 'credit', true],
            // Expenses inside the existing categories
            ['517', '51', 'Inventory Write-down & Obsolescence', 'تخفيض قيمة المخزون وتقادمه', 3, 'income_statement', 'expenses', 'Cost of Goods Sold', 'debit', true],
            ['544', '54', 'Expected Credit Loss Expense', 'مصروف خسائر ائتمانية متوقعة', 3, 'income_statement', 'expenses', 'General & Administrative Expenses', 'debit', true],
            ['545', '54', 'Loss on Disposal of Fixed Assets', 'خسائر بيع واستبعاد أصول ثابتة', 3, 'income_statement', 'expenses', 'General & Administrative Expenses', 'debit', true],
            ['546', '54', 'Impairment Loss — Fixed Assets', 'خسائر اضمحلال الأصول الثابتة', 3, 'income_statement', 'expenses', 'General & Administrative Expenses', 'debit', true],
            // IAS 12 — the year's tax charge, shown below Profit Before Tax
            ['59', '5', 'Income Tax', 'ضريبة الدخل', 2, 'income_statement', 'expenses', 'Income Tax', 'debit', false],
            ['591', '59', 'Current Income Tax', 'ضريبة الدخل الجارية', 3, 'income_statement', 'expenses', 'Income Tax', 'debit', true],
            ['592', '59', 'Deferred Tax (Expense) / Income', 'الضريبة المؤجلة', 3, 'income_statement', 'expenses', 'Income Tax', 'debit', true],
        ];
    }

    private function seedAuditH19Accounts(): void
    {
        foreach (self::auditH19Accounts() as [$code, $parentCode, $en, $ar, $level, $section, $class, $type, $normal, $postable]) {
            $parent = ChartOfAccount::whereNull('company_id')->where('code', $parentCode)->firstOrFail();
            $this->upsert([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar,
                'level' => $level, 'section' => $section, 'class' => $class,
                'account_type' => $type, 'normal_balance' => $normal,
                'parent_id' => $parent->id, 'is_postable' => $postable, 'allow_currency' => false,
            ]);
        }
    }
}
