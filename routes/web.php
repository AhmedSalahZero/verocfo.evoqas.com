<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Settings\PaymentTermController;
use App\Http\Controllers\Settings\PartnerController;
use App\Http\Controllers\Settings\SalesChannelController;
use App\Http\Controllers\Settings\ChartOfAccountController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\CostCenterController;
use App\Http\Controllers\Settings\FiscalYearController;
use App\Http\Controllers\Settings\InventoryLocationController;
use App\Http\Controllers\Settings\ProductController;
use App\Http\Controllers\Settings\FixedAssetTypeController;
use App\Http\Controllers\Settings\ProductCategoryController;
use App\Http\Controllers\Settings\OpeningBalanceController;
use App\Http\Controllers\Settings\TaxController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\SalesOrderController;
use App\Http\Controllers\Projects\ContractDashboardController;
use App\Http\Controllers\FinancialInstitutions\BankController;
use App\Http\Controllers\FinancialInstitutions\InternalMoneyTransferController;
use App\Http\Controllers\FinancialInstitutions\BuySellCurrencyController;
use App\Http\Controllers\FinancialInstitutions\TimeDepositController;
use App\Http\Controllers\FinancialInstitutions\LeasingController;
use App\Http\Controllers\FinancialInstitutions\FactoringController;
use App\Http\Controllers\FinancialInstitutions\CleanOverdraftController;
use App\Http\Controllers\FinancialInstitutions\FullySecuredOverdraftController;
use App\Http\Controllers\FinancialInstitutions\CommercialPaperOverdraftController;
use App\Http\Controllers\FinancialInstitutions\BorrowingRateController;
use App\Http\Controllers\FinancialInstitutions\ContractAssignmentOdController;
use App\Http\Controllers\FinancialInstitutions\LetterOfGuaranteeController;
use App\Http\Controllers\FinancialInstitutions\FacilitySettlementController;
use App\Http\Controllers\FinancialInstitutions\DashboardController as FiDashboardController;
use App\Http\Controllers\FinancialInstitutions\BankReconciliationController;
use App\Http\Controllers\FinancialInstitutions\BankStatementController;
use App\Http\Controllers\FinancialInstitutions\SafeStatementController;
use App\Http\Controllers\FinancialInstitutions\LgStatementController;
use App\Http\Controllers\FinancialInstitutions\LcStatementController;
use App\Http\Controllers\FinancialInstitutions\WithdrawalStatementController;
use App\Http\Controllers\FinancialInstitutions\LgIssuanceController;
use App\Http\Controllers\FinancialInstitutions\FactoringWithRecourseController;
use App\Http\Controllers\FinancialInstitutions\FactoringWithoutRecourseController;
use App\Http\Controllers\FinancialInstitutions\FactoringTransactionController;
use App\Http\Controllers\FinancialInstitutions\ReverseFactoringController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\FinancialInstitutions\LcIssuanceController;
use App\Http\Controllers\FinancialInstitutions\LetterOfCreditController;
use App\Http\Controllers\Accounting\ExpenseClaimController;
use App\Http\Controllers\Accounting\LeaveHolidayController;
use App\Http\Controllers\Accounting\LeaveRequestController;
use App\Http\Controllers\Accounting\LeaveTypeController;
use App\Http\Controllers\Accounting\PurchaseOrderController;
use App\Http\Controllers\Accounting\GoodsReceiptNoteController;
use App\Http\Controllers\Accounting\LandingCostController;
use App\Http\Controllers\Accounting\FixedAssetReceiptController;
use App\Http\Controllers\Accounting\FixedAssetController;
use App\Http\Controllers\Accounting\AccrualController;
use App\Http\Controllers\Accounting\SupplierBillController;
use App\Http\Controllers\Accounting\SupplierBalanceController;
use App\Http\Controllers\Accounting\CustomerInvoiceController;
use App\Http\Controllers\Accounting\DeliveryNoteController;
use App\Http\Controllers\Accounting\CreditNoteController;
use App\Http\Controllers\Accounting\CustomerCreditController;
use App\Http\Controllers\Accounting\CustomerBalanceController;
use App\Http\Controllers\Accounting\InternalSettlementController;
use App\Http\Controllers\Accounting\CustomerDebitNoteController;
use App\Http\Controllers\Accounting\SupplierDebitNoteController;
use App\Http\Controllers\Accounting\SupplierCreditNoteController;
use App\Http\Controllers\Accounting\CustomerPaymentController;
use App\Http\Controllers\Accounting\OtherCollectionController;
use App\Http\Controllers\Accounting\ChequeReceivableController;
use App\Http\Controllers\Accounting\SupplierPaymentController;
use App\Http\Controllers\Accounting\OtherPaymentController;
use App\Http\Controllers\Accounting\CashExpensePaymentController;
use App\Http\Controllers\Accounting\ChequePayableController;
use App\Http\Controllers\Accounting\StockBalanceController;
use App\Http\Controllers\Accounting\StockTransferController;
use App\Http\Controllers\Accounting\StockAdjustmentController;
use App\Http\Controllers\Accounting\BillOfMaterialController;
use App\Http\Controllers\Accounting\KitRecipeController;
use App\Http\Controllers\Accounting\KitAssemblyController;
use App\Http\Controllers\Accounting\OverheadPoolController;
use App\Http\Controllers\Accounting\LaborCategoryController;
use App\Http\Controllers\Accounting\ProcessController;
use App\Http\Controllers\Accounting\MachineController;
use App\Http\Controllers\Accounting\StandardRateController;
use App\Http\Controllers\Accounting\ProductionOrderController;
use App\Http\Controllers\Accounting\JobOrderTemplateController;
use App\Http\Controllers\Accounting\JobOrderController;
use App\Http\Controllers\Accounting\AdvancesSettlementsController;
use App\Http\Controllers\Accounting\EosbProvisionRunController;
use App\Http\Controllers\Accounting\GovernmentRemittanceController;
use App\Http\Controllers\Accounting\EmployeePayrollProfileController;
use App\Http\Controllers\Accounting\DeductionReasonController;
use App\Http\Controllers\Accounting\PayrollRunController;
use App\Http\Controllers\Accounting\VarianceClosingController;
use App\Http\Controllers\FinancialInstitutions\MtlController;
use App\Http\Controllers\Analytics\SalesAnalysisController;
use App\Http\Controllers\Analytics\ExpenseAnalysisController;
use App\Http\Controllers\Planning\AnnualPlanController;
use App\Http\Controllers\Valuation\BusinessPlanController;

// ══════════════════════════════════════════════════════════════════
//  VERO CFO — Web Routes
//
//  Middleware stack:
//    auth            → must be logged in
//    active.company  → resolves company ID, checks company + user active
//    module:x,y      → subscription tier includes module x,
//                      user has permission y on module x
//
//  Three route contexts:
//    1. guest                    → login, password reset (auth.php)
//    2. auth only                → Super Admin platform routes
//    3. auth + active.company    → all authenticated app routes
//
//  ROUTE ORDERING RULE — always place static segments BEFORE {param}:
//    GET /facilities/clean-od          ← must come BEFORE
//    GET /facilities/{facility}/edit   ← {facility} would catch clean-od otherwise
// ══════════════════════════════════════════════════════════════════


// ── Root redirect ─────────────────────────────────────────────────
Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }
    $user = auth()->user();
    if ($user->isSuperAdmin()) {
        return session()->has('impersonate_company_id')
            ? redirect()->route('dashboard')
            : redirect()->route('companies.index');
    }
    return redirect()->route('dashboard');
});


// ══════════════════════════════════════════════════════════════════
//  AUTH ONLY — No company context required
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth'])->group(function () {

    Route::post('/lang', function () {
        $lang = in_array(request('lang'), ['en', 'ar']) ? request('lang') : 'en';
        auth()->user()->update(['lang_pref' => $lang]);
        return response()->json(['lang' => $lang]);
    })->name('lang.update');

    Route::post('/impersonate/{company}/enter', [ImpersonateController::class, 'enter'])->name('impersonate.enter')->middleware('admin');
    Route::post('/impersonate/exit',            [ImpersonateController::class, 'exit'])->name('impersonation.exit')->middleware('admin');

    /**
     * المراسلة : دردشة بين المستخدمين + تذاكر دعم للسوبر أدمن .
     * شوف app/Http/Controllers/MessagingController.php
     */
    Route::group(['prefix' => 'messages', 'as' => 'messages.'], function () {
        Route::get('/', [MessagingController::class, 'index'])->name('index');
        /**
         * ⚠️ لازم تفضل فوق GET /{conversation} : لارافيل بيطابق بترتيب
         * * التعريف ، فلو اتكتبت تحتها كان الراوت بتاع {conversation}
         * * هيبلعها و يرجّع "المحادثة مش موجودة"
         */
        Route::get('/poll', [MessagingController::class, 'poll'])->name('poll');
        Route::get('/{conversation}', [MessagingController::class, 'show'])->name('show');
        Route::get('/{conversation}/updates', [MessagingController::class, 'updates'])->name('updates');
        Route::post('/start', [MessagingController::class, 'startDirect'])->name('start');
        Route::post('/support', [MessagingController::class, 'startSupport'])->name('support.start');
        Route::post('/{conversation}/send', [MessagingController::class, 'send'])->name('send');
        Route::delete('/{conversation}/messages/{message}', [MessagingController::class, 'deleteMessage'])->name('delete');
        Route::post('/{conversation}/read', [MessagingController::class, 'markRead'])->name('read');
        Route::patch('/{conversation}/status', [MessagingController::class, 'updateStatus'])->name('status');
    });

    // ── Companies — platform owner only (Audit C3, Sept 2026) ─────
    // Every Companies screen is Super Admin only. Before this fix the
    // list and edit page had no guard at all, and toggle/update/delete
    // accepted Company Admins — letting one client switch off another.
    Route::middleware('super.admin')->group(function () {
        Route::get('/companies',                    [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/create',             [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies',                   [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}/edit',     [CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('/companies/{company}',          [CompanyController::class, 'update'])->name('companies.update');
        Route::patch('/companies/{company}/toggle', [CompanyController::class, 'toggleActive'])->name('companies.toggle');
        Route::delete('/companies/{company}',       [CompanyController::class, 'destroy'])->name('companies.destroy');
    });

}); // end auth-only group


// ══════════════════════════════════════════════════════════════════
//  AUTH + ACTIVE COMPANY
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'active.company'])->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────
    Route::get('/dashboard', function () {
        return inertia('Dashboard');
    })->name('dashboard');

    // ── Notifications (bell icon) — NotificationController, Audit M43 ──
    Route::get('/notifications',           [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread',    [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');

    // ── Users — admins only (Audit C2, Sept 2026) ─────────────────
    // 'admin'             → the whole section is closed to regular
    //                       users (per Mahmoud: Company Admin and
    //                       Super Admin only, including view pages).
    // 'user.same.company' → any {user} in the URL must belong to the
    //                       current company and never be a Super Admin.
    Route::prefix('users')->name('users.')->middleware(['admin', 'user.same.company'])->group(function () {
        Route::get('/',                   [UserController::class, 'index'])->name('index');
        Route::get('/create',             [UserController::class, 'create'])->name('create');
        Route::post('/',                  [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit',        [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}',             [UserController::class, 'update'])->name('update');
        Route::patch('/{user}/toggle',    [UserController::class, 'toggleActive'])->name('toggle');
        Route::get('/{user}/permissions', [UserController::class, 'permissions'])->name('permissions');
        Route::put('/{user}/permissions', [UserController::class, 'savePermissions'])->name('permissions.save');
    });

    // ── Profile ───────────────────────────────────────────────────
    Route::get('/profile',            [App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',          [App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [App\Http\Controllers\ProfileController::class, 'password'])->name('profile.password');

    // ── Settings ──────────────────────────────────────────────────
    // FIX: settings.index stub — referenced by AppLayout pillar nav
    Route::get('/settings', function () {
        return redirect()->route('settings.payment-terms.index');
    })->name('settings.index');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::resource('payment-terms', PaymentTermController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit');
        Route::patch('payment-terms/{paymentTerm}/toggle', [PaymentTermController::class, 'toggleActive'])->name('payment-terms.toggle')->middleware('module:accounting,edit');
        Route::resource('partners', PartnerController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit');
        Route::patch('partners/{partner}/toggle', [PartnerController::class, 'toggleActive'])->name('partners.toggle')->middleware('module:accounting,edit');
        Route::get('sales-channels',  [SalesChannelController::class, 'index'])->name('sales-channels.index')->middleware('module:accounting,view');
        Route::post('sales-channels', [SalesChannelController::class, 'store'])->name('sales-channels.store')->middleware('module:accounting,create');
        Route::resource('coa', ChartOfAccountController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit,acc_chart_of_accounts');
        Route::patch('coa/{coa}/toggle',   [ChartOfAccountController::class, 'toggleActive'])->name('coa.toggle')->middleware('module:accounting,edit,acc_chart_of_accounts');
        Route::get('coa-postable',         [ChartOfAccountController::class, 'postable'])->name('coa.postable')->middleware('module:accounting,view');

        // ── Currencies & Exchange Rates ───────────────────────────
        Route::get('currencies',                      [CurrencyController::class, 'index'])->name('currencies.index')->middleware('module:accounting,view,acc_currencies');
        Route::get('currencies/history',              [CurrencyController::class, 'history'])->name('currencies.history')->middleware('module:accounting,view,acc_currencies');
        Route::get('currencies/export',               [CurrencyController::class, 'exportRates'])->name('currencies.export')->middleware('module:accounting,view,acc_currencies');
        Route::post('currencies/rates',               [CurrencyController::class, 'addRate'])->name('currencies.rate')->middleware('module:accounting,create,acc_currencies');
        Route::post('currencies/rates/import-preview', [CurrencyController::class, 'importRatesPreview'])->name('currencies.rates.import-preview')->middleware('module:accounting,create,acc_currencies');
        Route::post('currencies/rates/import',         [CurrencyController::class, 'importRates'])->name('currencies.rates.import')->middleware('module:accounting,create,acc_currencies');
        Route::get('currencies/rates/template',        [CurrencyController::class, 'downloadRateTemplate'])->name('currencies.rates.template')->middleware('module:accounting,view,acc_currencies');
        Route::post('currencies/{currency}/toggle',   [CurrencyController::class, 'toggle'])->name('currencies.toggle')->middleware('module:accounting,create,acc_currencies');
        Route::get('currencies/{currency}/rates',     [CurrencyController::class, 'rates'])->name('currencies.rates')->middleware('module:accounting,view');

        // ── Cost Centers ──────────────────────────────────────────
        Route::resource('cost-centers', CostCenterController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit,acc_cost_centers');
        Route::patch('cost-centers/{costCenter}/toggle', [CostCenterController::class, 'toggleActive'])->name('cost-centers.toggle')->middleware('module:accounting,edit,acc_cost_centers');

        // ── Fiscal Years & Periods ────────────────────────────────
        // Static segments BEFORE {fiscalYear} param
        Route::get('fiscal-years',                              [FiscalYearController::class, 'index'])->name('fiscal-years.index')->middleware('module:accounting,view,acc_fiscal_periods');
        Route::post('fiscal-years/next',                        [FiscalYearController::class, 'createNext'])->name('fiscal-years.next')->middleware('module:accounting,create,acc_fiscal_periods');
        Route::post('fiscal-years/periods/{period}/close',      [FiscalYearController::class, 'closePeriod'])->name('fiscal-years.periods.close')->middleware('module:accounting,create,acc_fiscal_periods');
        Route::post('fiscal-years/periods/{period}/reopen',     [FiscalYearController::class, 'reopenPeriod'])->name('fiscal-years.periods.reopen')->middleware('module:accounting,create,acc_fiscal_periods');
        Route::post('fiscal-years/{fiscalYear}/close',          [FiscalYearController::class, 'close'])->name('fiscal-years.close')->middleware('module:accounting,create,acc_fiscal_periods');

        // ── Inventory Locations ───────────────────────────────────
        Route::resource('locations', InventoryLocationController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit,inventory_items');
        Route::patch('locations/{location}/toggle', [InventoryLocationController::class, 'toggleActive'])->name('locations.toggle')->middleware('module:accounting,edit,inventory_items');

        // ── Product Categories ────────────────────────────────────
        // quick must be BEFORE {productCategory} param
        Route::post('product-categories/quick',            [ProductCategoryController::class, 'quickCreate'])->name('product-categories.quick')->middleware('module:accounting,create,inventory_items');
        Route::resource('product-categories', ProductCategoryController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit,inventory_items');
        Route::patch('product-categories/{productCategory}/toggle', [ProductCategoryController::class, 'toggleActive'])->name('product-categories.toggle')->middleware('module:accounting,edit,inventory_items');

        // ── Products & Services ───────────────────────────────────
        // prices and toggle must be BEFORE {product} param
        Route::post('products/prices',             [ProductController::class, 'addPrice'])->name('products.prices.store')->middleware('module:accounting,create,inventory_items');
        Route::resource('products', ProductController::class)->except(['show', 'destroy'])->middleware('module:accounting,edit,inventory_items');
        Route::patch('products/{product}/toggle',  [ProductController::class, 'toggleActive'])->name('products.toggle')->middleware('module:accounting,edit,inventory_items');
        Route::get('products/{product}/prices',    [ProductController::class, 'prices'])->name('products.prices')->middleware('module:accounting,view');

        // ── Fixed Asset Types — own dedicated page, separate from
        // Products & Services (confirmed with Mahmoud). Reuses the
        // ProductController's route shape for consistency.
        // ->parameters(...) keeps the route parameter named {product}
        // (not Laravel's default {fixed_asset_type} for this resource
        // name) since FixedAssetTypeController type-hints Product
        // $product throughout — these are real Product rows under the
        // hood, just presented through their own dedicated page.
        Route::resource('fixed-asset-types', FixedAssetTypeController::class)
            ->except(['show', 'destroy'])
            ->parameters(['fixed-asset-types' => 'product'])->middleware('module:accounting,edit,fixed_assets');
        Route::patch('fixed-asset-types/{product}/toggle', [FixedAssetTypeController::class, 'toggleActive'])->name('fixed-asset-types.toggle')->middleware('module:accounting,edit,fixed_assets');

        // ── Opening Balances ──────────────────────────────────────
        // AUDIT M4 FIX: guarded by the dedicated "Opening Balances"
        // right (Accounting → Acc. Settings) — was: any accounting
        // right. Posting is one-time and irreversible, so it requires
        // "edit" on that feature, not just "create".
        Route::get('opening-balances',                              [OpeningBalanceController::class, 'index'])->name('opening-balances.index')->middleware('module:accounting,view,opening_balances');
        Route::post('opening-balances/coa',                         [OpeningBalanceController::class, 'saveCoa'])->name('opening-balances.coa.save')->middleware('module:accounting,create,opening_balances');
        Route::delete('opening-balances/coa/{id}',                  [OpeningBalanceController::class, 'deleteCoa'])->name('opening-balances.coa.delete')->middleware('module:accounting,edit,opening_balances');
        Route::post('opening-balances/invoices',                    [OpeningBalanceController::class, 'saveInvoice'])->name('opening-balances.invoices.save')->middleware('module:accounting,create,opening_balances');
        Route::delete('opening-balances/invoices/{id}',             [OpeningBalanceController::class, 'deleteInvoice'])->name('opening-balances.invoices.delete')->middleware('module:accounting,edit,opening_balances');
        Route::post('opening-balances/nr',                          [OpeningBalanceController::class, 'saveNr'])->name('opening-balances.nr.save')->middleware('module:accounting,create,opening_balances');
        Route::delete('opening-balances/nr/{id}',                   [OpeningBalanceController::class, 'deleteNr'])->name('opening-balances.nr.delete')->middleware('module:accounting,edit,opening_balances');
        Route::post('opening-balances/np',                          [OpeningBalanceController::class, 'saveNp'])->name('opening-balances.np.save')->middleware('module:accounting,create,opening_balances');
        Route::delete('opening-balances/np/{id}',                   [OpeningBalanceController::class, 'deleteNp'])->name('opening-balances.np.delete')->middleware('module:accounting,edit,opening_balances');
        Route::post('opening-balances/stock',                       [OpeningBalanceController::class, 'saveStock'])->name('opening-balances.stock.save')->middleware('module:accounting,create,opening_balances');
        Route::delete('opening-balances/stock/{id}',                [OpeningBalanceController::class, 'deleteStock'])->name('opening-balances.stock.delete')->middleware('module:accounting,edit,opening_balances');
        Route::post('opening-balances/validate',                    [OpeningBalanceController::class, 'validateOb'])->name('opening-balances.validate')->middleware('module:accounting,create,opening_balances');
        Route::post('opening-balances/post',                        [OpeningBalanceController::class, 'post'])->name('opening-balances.post')->middleware('module:accounting,edit,opening_balances');

        // ── Tax Settings ──────────────────────────────────────────
        Route::get('taxes',                      [TaxController::class, 'index'])->name('taxes.index')->middleware('module:accounting,view');
        Route::post('taxes/settings',            [TaxController::class, 'saveSettings'])->name('taxes.settings')->middleware('module:accounting,create');
        Route::post('taxes/overrides',           [TaxController::class, 'saveOverride'])->name('taxes.overrides.save')->middleware('module:accounting,create');
        Route::delete('taxes/overrides/{id}',    [TaxController::class, 'deleteOverride'])->name('taxes.overrides.delete')->middleware('module:accounting,edit');
    });

    // ══════════════════════════════════════════════════════════════
    //  PROJECTS & CONTRACTS
    // ══════════════════════════════════════════════════════════════
    Route::prefix('projects')->name('projects.')->group(function () {

        // Static segments BEFORE {project} param
        Route::get('/',                              [ProjectController::class, 'index'])->name('index')->middleware('module:accounting,view,sales_orders');
        Route::get('/create',                        [ProjectController::class, 'create'])->name('create')->middleware('module:accounting,view,sales_orders');
        Route::post('/',                             [ProjectController::class, 'store'])->name('store')->middleware('module:accounting,create,sales_orders');

        // Contract Dashboard — static segment BEFORE {project} param
        Route::get('/dashboard',                     [ContractDashboardController::class, 'index'])->name('dashboard')->middleware('module:accounting,view,sales_orders');
        Route::get('/dashboard/export',               [ContractDashboardController::class, 'export'])->name('dashboard.export')->middleware('module:accounting,view,sales_orders');

        Route::get('/{project}/edit',                [ProjectController::class, 'edit'])->name('edit')->middleware('module:accounting,view,sales_orders');
        Route::put('/{project}',                     [ProjectController::class, 'update'])->name('update')->middleware('module:accounting,edit,sales_orders');
        Route::patch('/{project}/status',            [ProjectController::class, 'changeStatus'])->name('status')->middleware('module:accounting,edit,sales_orders');

        // Sales Orders — static segments BEFORE {salesOrder} param
        Route::prefix('sales-orders')->name('sales-orders.')->group(function () {
            Route::get('/',                          [SalesOrderController::class, 'index'])->name('index')->middleware('module:accounting,view,sales_orders');
            Route::get('/dashboard',                 [\App\Http\Controllers\Projects\SalesOrderDashboardController::class, 'index'])->name('dashboard')->middleware('module:accounting,view,sales_orders');
            Route::get('/create',                    [SalesOrderController::class, 'create'])->name('create')->middleware('module:accounting,view,sales_orders');
            Route::post('/',                         [SalesOrderController::class, 'store'])->name('store')->middleware('module:accounting,create,sales_orders');
            Route::get('/{salesOrder}/edit',         [SalesOrderController::class, 'edit'])->name('edit')->middleware('module:accounting,view,sales_orders');
            Route::put('/{salesOrder}',              [SalesOrderController::class, 'update'])->name('update')->middleware('module:accounting,edit,sales_orders');
            Route::patch('/{salesOrder}/activate',   [SalesOrderController::class, 'activate'])->name('activate')->middleware('module:accounting,edit,sales_orders');
            Route::patch('/{salesOrder}/close',      [SalesOrderController::class, 'close'])->name('close')->middleware('module:accounting,edit,sales_orders');
            Route::get('/{salesOrder}/delivered-quantities', [SalesOrderController::class, 'deliveredQuantities'])->name('delivered-quantities')->middleware('module:accounting,view,sales_orders');
        });

        // Project Inventory Statement — real controller (2026-09),
        // replaces the stub that pointed nowhere (sidebar link
        // existed, route did not — see ProjectInventoryController
        // header). P&L stub left as-is; still genuinely not built.
        Route::get('/inventory',         [\App\Http\Controllers\Projects\ProjectInventoryController::class, 'index'])->name('inventory.index')->middleware('module:accounting,view,stock_ledger');
        Route::get('/inventory/result',  [\App\Http\Controllers\Projects\ProjectInventoryController::class, 'result'])->name('inventory.result')->middleware('module:accounting,view,stock_ledger');
        Route::get('/inventory/export',  [\App\Http\Controllers\Projects\ProjectInventoryController::class, 'exportExcel'])->name('inventory.export')->middleware('module:accounting,view,stock_ledger');
        Route::get('/pl',        ComingSoonController::class)->name('pl.index')->middleware('module:accounting,view,sales_orders');
    });

    // ══════════════════════════════════════════════════════════════
    //  FINANCIAL INSTITUTIONS
    //
    //  ORDERING RULE — inside this group all static/specific routes
    //  must come BEFORE routes with {param} segments to prevent
    //  Laravel matching a named segment as a route parameter.
    //
    //  Correct order for facilities:
    //    1. /facilities/clean-od/...       ← specific — first
    //    2. /facilities/create             ← static   — before {facility}
    //    3. /facilities/{facility}/edit    ← param    — last
    // ══════════════════════════════════════════════════════════════
    Route::prefix('financial-institutions')->name('fi.')->group(function () {

        // ── Dashboard (Cash Status / LG & LC Status / Cash Forecast) ──
        Route::get('/', [FiDashboardController::class, 'cashStatus'])->name('dashboard')->middleware('module:banking,view,cash_position');
        Route::get('/dashboard/lglc-status', [FiDashboardController::class, 'lglcStatus'])->name('dashboard.lglc')->middleware('module:banking,view,cash_position');
        Route::get('/dashboard/forecast',    [FiDashboardController::class, 'forecast'])->name('dashboard.forecast')->middleware('module:banking,view,cash_position');
        Route::get('/dashboard/cash-status/facilities', [FiDashboardController::class, 'movementFacilities'])->name('dashboard.cash-status.facilities')->middleware('module:banking,view,cash_position');
        Route::get('/dashboard/cash-status/movement',   [FiDashboardController::class, 'movementChart'])->name('dashboard.cash-status.movement')->middleware('module:banking,view,cash_position');

        // ── Banks ─────────────────────────────────────────────────
        Route::get('/banks',                    [BankController::class, 'banksIndex'])->name('banks.index')->middleware('module:banking,view,banks_lenders');
        Route::get('/banks/create',             [BankController::class, 'banksCreate'])->name('banks.create')->middleware('module:banking,view,banks_lenders');
        Route::post('/banks',                   [BankController::class, 'banksStore'])->name('banks.store')->middleware('module:banking,create,banks_lenders');
        Route::get('/banks/{bank}/edit',        [BankController::class, 'banksEdit'])->name('banks.edit')->middleware('module:banking,view,banks_lenders');
        Route::put('/banks/{bank}',             [BankController::class, 'banksUpdate'])->name('banks.update')->middleware('module:banking,edit,banks_lenders');
        Route::patch('/banks/{bank}/toggle',    [BankController::class, 'banksToggle'])->name('banks.toggle')->middleware('module:banking,edit,banks_lenders');

        // ── Bank Accounts ─────────────────────────────────────────
        Route::get('/bank-accounts',                        [BankController::class, 'accountsIndex'])->name('bank-accounts.index')->middleware('module:banking,view,banks_lenders');
        Route::get('/bank-accounts/create',                 [BankController::class, 'accountsCreate'])->name('bank-accounts.create')->middleware('module:banking,view,banks_lenders');
        Route::post('/bank-accounts',                       [BankController::class, 'accountsStore'])->name('bank-accounts.store')->middleware('module:banking,create,banks_lenders');
        Route::get('/bank-accounts/{bankAccount}/edit',     [BankController::class, 'accountsEdit'])->name('bank-accounts.edit')->middleware('module:banking,view,banks_lenders');
        Route::put('/bank-accounts/{bankAccount}',          [BankController::class, 'accountsUpdate'])->name('bank-accounts.update')->middleware('module:banking,edit,banks_lenders');
        Route::patch('/bank-accounts/{bankAccount}/toggle', [BankController::class, 'accountsToggle'])->name('bank-accounts.toggle')->middleware('module:banking,edit,banks_lenders');

        // ── Time Deposit Lifecycle (July 2026) ──────────────────────
        Route::post('/bank-accounts/{bankAccount}/td/interest', [TimeDepositController::class, 'applyInterest'])->name('bank-accounts.td.interest')->middleware('module:banking,create,banks_lenders');
        Route::post('/bank-accounts/{bankAccount}/td/break',    [TimeDepositController::class, 'breakTd'])->name('bank-accounts.td.break')->middleware('module:banking,create,banks_lenders');
        Route::post('/bank-accounts/{bankAccount}/td/mature',   [TimeDepositController::class, 'matureTd'])->name('bank-accounts.td.mature')->middleware('module:banking,create,banks_lenders');
        Route::post('/bank-accounts/{bankAccount}/td/renew',    [TimeDepositController::class, 'renew'])->name('bank-accounts.td.renew')->middleware('module:banking,create,banks_lenders');
        Route::get('/bank-accounts/{bankAccount}/td/history',   [TimeDepositController::class, 'history'])->name('bank-accounts.td.history')->middleware('module:banking,view,banks_lenders');

        // ══════════════════════════════════════════════════════════
        //  CREDIT FACILITIES
        //
        //  CRITICAL ORDER — specific facility type routes FIRST,
        //  then generic /facilities/{facility} routes LAST.
        //  Laravel matches top-to-bottom. If {facility} comes first,
        //  it swallows all specific routes below it.
        //
        //  Current specific types (in order):
        //    1. /facilities/clean-od/...
        //    2. /facilities/commercial-paper-od/...
        //    3. /facilities/contract-od/...   ← OD Against Contract Assignment
        //    4. /facilities/lg/...            ← Letter of Guarantee
        //    5. /facilities/{facility}/...    ← generic — always last
        //
        //  Within each type, static sub-segments (collateral, assign)
        //  must come BEFORE the {facility}/edit param route.
        // ══════════════════════════════════════════════════════════

        // ── Clean Overdraft (specific — BEFORE generic {facility}) ─
        Route::get('/facilities/clean-od',                     [CleanOverdraftController::class, 'index'])->name('facilities.clean-od.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/clean-od/create/{bank}',       [CleanOverdraftController::class, 'create'])->name('facilities.clean-od.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/clean-od',                    [CleanOverdraftController::class, 'store'])->name('facilities.clean-od.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/clean-od/{facility}/edit',     [CleanOverdraftController::class, 'edit'])->name('facilities.clean-od.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/clean-od/{facility}',          [CleanOverdraftController::class, 'update'])->name('facilities.clean-od.update')->middleware('module:banking,edit,credit_facilities');
        Route::delete('/facilities/clean-od/{facility}',       [CleanOverdraftController::class, 'destroy'])->name('facilities.clean-od.destroy')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/clean-od/{facility}/renew',   [CleanOverdraftController::class, 'renew'])->name('facilities.clean-od.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/clean-od/{facility}/renew', [CleanOverdraftController::class, 'deleteRenewal'])->name('facilities.clean-od.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/clean-od',        [CleanOverdraftController::class, 'bankIndex'])->name('banks.clean-od.index')->middleware('module:banking,view,credit_facilities');

        // ── Fully Secured Overdraft (specific — BEFORE generic {facility}) ─
        // Secured against a pledged Time Deposit — same CRUD/renewal
        // shape as Clean OD, plus the CD/TD picker pattern already
        // proven on the Letter of Credit facility.
        Route::get('/facilities/fully-secured-od',                     [FullySecuredOverdraftController::class, 'index'])->name('facilities.fully-secured-od.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/fully-secured-od/create/{bank}',       [FullySecuredOverdraftController::class, 'create'])->name('facilities.fully-secured-od.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/fully-secured-od',                    [FullySecuredOverdraftController::class, 'store'])->name('facilities.fully-secured-od.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/fully-secured-od/{facility}/edit',     [FullySecuredOverdraftController::class, 'edit'])->name('facilities.fully-secured-od.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/fully-secured-od/{facility}',          [FullySecuredOverdraftController::class, 'update'])->name('facilities.fully-secured-od.update')->middleware('module:banking,edit,credit_facilities');
        Route::delete('/facilities/fully-secured-od/{facility}',       [FullySecuredOverdraftController::class, 'destroy'])->name('facilities.fully-secured-od.destroy')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/fully-secured-od/{facility}/renew',   [FullySecuredOverdraftController::class, 'renew'])->name('facilities.fully-secured-od.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/fully-secured-od/{facility}/renew', [FullySecuredOverdraftController::class, 'deleteRenewal'])->name('facilities.fully-secured-od.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/fully-secured-od',        [FullySecuredOverdraftController::class, 'bankIndex'])->name('banks.fully-secured-od.index')->middleware('module:banking,view,credit_facilities');

        // ── OD Against Commercial Paper (specific — BEFORE generic {facility}) ─
        Route::get('/facilities/commercial-paper-od',                 [CommercialPaperOverdraftController::class, 'index'])->name('facilities.commercial-paper-od.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/commercial-paper-od/create/{bank}',   [CommercialPaperOverdraftController::class, 'create'])->name('facilities.commercial-paper-od.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/commercial-paper-od',                [CommercialPaperOverdraftController::class, 'store'])->name('facilities.commercial-paper-od.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/commercial-paper-od/{facility}/edit', [CommercialPaperOverdraftController::class, 'edit'])->name('facilities.commercial-paper-od.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/commercial-paper-od/{facility}',      [CommercialPaperOverdraftController::class, 'update'])->name('facilities.commercial-paper-od.update')->middleware('module:banking,edit,credit_facilities');
        Route::delete('/facilities/commercial-paper-od/{facility}',     [CommercialPaperOverdraftController::class, 'destroy'])->name('facilities.commercial-paper-od.destroy')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/commercial-paper-od/{facility}/renew',   [CommercialPaperOverdraftController::class, 'renew'])->name('facilities.commercial-paper-od.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/commercial-paper-od/{facility}/renew', [CommercialPaperOverdraftController::class, 'deleteRenewal'])->name('facilities.commercial-paper-od.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/commercial-paper-od', [CommercialPaperOverdraftController::class, 'bankIndex'])->name('banks.commercial-paper-od.index')->middleware('module:banking,view,credit_facilities');

        // ── OD Against Contract Assignment (specific — BEFORE generic {facility}) ─
        // ORDERING: collateral and assign are static segments — must come BEFORE
        // the {facility}/edit param route so Laravel does not swallow them.
        Route::get('/facilities/contract-od',                              [ContractAssignmentOdController::class, 'index'])->name('facilities.contract-od.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/contract-od/create/{bank}',               [ContractAssignmentOdController::class, 'create'])->name('facilities.contract-od.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/contract-od',                             [ContractAssignmentOdController::class, 'store'])->name('facilities.contract-od.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/contract-od/{facility}/collateral',        [ContractAssignmentOdController::class, 'eligibleCollateral'])->name('facilities.contract-od.collateral')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/contract-od/{facility}/assign',           [ContractAssignmentOdController::class, 'addAssignment'])->name('facilities.contract-od.assign')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/contract-od/{facility}/edit',              [ContractAssignmentOdController::class, 'edit'])->name('facilities.contract-od.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/contract-od/{facility}',                   [ContractAssignmentOdController::class, 'update'])->name('facilities.contract-od.update')->middleware('module:banking,edit,credit_facilities');
        Route::delete('/facilities/contract-od/{facility}',                [ContractAssignmentOdController::class, 'destroy'])->name('facilities.contract-od.destroy')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/contract-od/{facility}/renew',            [ContractAssignmentOdController::class, 'renew'])->name('facilities.contract-od.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/contract-od/{facility}/renew',          [ContractAssignmentOdController::class, 'deleteRenewal'])->name('facilities.contract-od.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::delete('/facility-assignments/{assignment}',                [ContractAssignmentOdController::class, 'removeAssignment'])->name('facilities.contract-od.unassign')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/contract-od',                 [ContractAssignmentOdController::class, 'bankIndex'])->name('banks.contract-od.index')->middleware('module:banking,view,credit_facilities');

        // ── Letter of Guarantee (specific — BEFORE generic {facility}) ─
        Route::get('/facilities/lg',                   [LetterOfGuaranteeController::class, 'index'])->name('facilities.lg.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/lg/create/{bank}',     [LetterOfGuaranteeController::class, 'create'])->name('facilities.lg.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/lg',                  [LetterOfGuaranteeController::class, 'store'])->name('facilities.lg.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/lg/{facility}/edit',   [LetterOfGuaranteeController::class, 'edit'])->name('facilities.lg.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/lg/{facility}',        [LetterOfGuaranteeController::class, 'update'])->name('facilities.lg.update')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/lg/{facility}/renew',   [LetterOfGuaranteeController::class, 'renew'])->name('facilities.lg.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/lg/{facility}/renew', [LetterOfGuaranteeController::class, 'deleteRenewal'])->name('facilities.lg.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/lg', [LetterOfGuaranteeController::class, 'bankIndex'])->name('banks.lg.index')->middleware('module:banking,view,credit_facilities');

        // ── Facility Settlement Engine (Rule 34) — shared across all four OD-family types ──
        Route::get('/facilities/{facility}/settlements',                     [FacilitySettlementController::class, 'index'])->name('facilities.settlements.index')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/{facility}/charges',                       [FacilitySettlementController::class, 'postCharge'])->name('facilities.settlements.post-charge')->middleware('module:banking,create,credit_facilities');
        // No standalone reverse route — reversal only ever happens via the
        // source transaction's own reopen/cancel (Mahmoud confirmed 2026-09).

        // ── Future facility types (stubs — add real controllers when built) ──
        // These share the generic create route — controller reads ?type= query param
        // Each will get its own named routes when its form is built (like clean-od above)

        // NOTE: the old generic fi.facilities.index/create/store/edit/update routes
        // (pointing to BankController::facilitiesIndex/Create/Store/Edit/Update) were
        // removed here — they rendered Vue pages that were never built
        // (FinancialInstitutions/Banks/FacilitiesIndex and FacilityForm) and were
        // fully superseded by the per-type routes above (facilities.clean-od.*,
        // facilities.lg.*, etc.).

        // ── Leasing Companies ─────────────────────────────────────
        Route::get('/leasing-companies',                           [LeasingController::class, 'companiesIndex'])->name('leasing-companies.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/leasing-companies/create',                    [LeasingController::class, 'companiesCreate'])->name('leasing-companies.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/leasing-companies',                          [LeasingController::class, 'companiesStore'])->name('leasing-companies.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/leasing-companies/{leasingCompany}/edit',     [LeasingController::class, 'companiesEdit'])->name('leasing-companies.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/leasing-companies/{leasingCompany}',          [LeasingController::class, 'companiesUpdate'])->name('leasing-companies.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/leasing-companies/{leasingCompany}/toggle', [LeasingController::class, 'companiesToggle'])->name('leasing-companies.toggle')->middleware('module:banking,edit,treasury_operations');

        // ── Leasing Contracts ─────────────────────────────────────
        Route::get('/leasing-contracts',                           [LeasingController::class, 'contractsIndex'])->name('leasing-contracts.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/leasing-contracts/create',                    [LeasingController::class, 'contractsCreate'])->name('leasing-contracts.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/leasing-contracts',                          [LeasingController::class, 'contractsStore'])->name('leasing-contracts.store')->middleware('module:banking,create,treasury_operations');
        Route::post('/leasing-contracts/import-cheques',           [LeasingController::class, 'importCheques'])->name('leasing-contracts.import-cheques')->middleware('module:banking,create,treasury_operations');
        Route::get('/leasing-contracts/{leasingContract}/edit',    [LeasingController::class, 'contractsEdit'])->name('leasing-contracts.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/leasing-contracts/{leasingContract}',         [LeasingController::class, 'contractsUpdate'])->name('leasing-contracts.update')->middleware('module:banking,edit,treasury_operations');

        // Lease Amortization Engine (Piece 2-5, July 2026)
        Route::post('/leasing-contracts/{leasingContract}/activate',        [LeasingController::class, 'activate'])->name('leasing-contracts.activate')->middleware('module:banking,create,treasury_operations');
        Route::get('/leasing-contracts/{leasingContract}/schedule',         [LeasingController::class, 'schedule'])->name('leasing-contracts.schedule')->middleware('module:banking,view,treasury_operations');
        Route::post('/leasing-contracts/{leasingContract}/post-wear-down',  [LeasingController::class, 'postWearDown'])->name('leasing-contracts.post-wear-down')->middleware('module:banking,create,treasury_operations');
        Route::post('/leasing-contracts/{leasingContract}/final-purchase',  [LeasingController::class, 'finalPurchase'])->name('leasing-contracts.final-purchase')->middleware('module:banking,create,treasury_operations');
        Route::post('/lease-amortization-lines/{amortizationLine}/record-payment', [LeasingController::class, 'recordPayment'])->name('lease-amortization-lines.record-payment')->middleware('module:banking,create,treasury_operations');
        Route::post('/lease-wear-down-entries/{wearDownEntry}/reverse',    [LeasingController::class, 'reverseWearDown'])->name('lease-wear-down-entries.reverse')->middleware('module:banking,reverse,treasury_operations');

        // ── Factoring Companies ───────────────────────────────────
        Route::get('/factoring-companies',                             [FactoringController::class, 'companiesIndex'])->name('factoring-companies.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/factoring-companies/create',                      [FactoringController::class, 'companiesCreate'])->name('factoring-companies.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/factoring-companies',                            [FactoringController::class, 'companiesStore'])->name('factoring-companies.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/factoring-companies/{factoringCompany}/edit',     [FactoringController::class, 'companiesEdit'])->name('factoring-companies.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/factoring-companies/{factoringCompany}',          [FactoringController::class, 'companiesUpdate'])->name('factoring-companies.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/factoring-companies/{factoringCompany}/toggle', [FactoringController::class, 'companiesToggle'])->name('factoring-companies.toggle')->middleware('module:banking,edit,treasury_operations');

        // ── Factoring Agreements ──────────────────────────────────
        Route::get('/factoring-agreements',                               [FactoringController::class, 'agreementsIndex'])->name('factoring-agreements.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/factoring-agreements/create',                        [FactoringController::class, 'agreementsCreate'])->name('factoring-agreements.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/factoring-agreements',                              [FactoringController::class, 'agreementsStore'])->name('factoring-agreements.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/factoring-agreements/{factoringAgreement}/edit',     [FactoringController::class, 'agreementsEdit'])->name('factoring-agreements.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/factoring-agreements/{factoringAgreement}',          [FactoringController::class, 'agreementsUpdate'])->name('factoring-agreements.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/factoring-agreements/{factoringAgreement}/toggle', [FactoringController::class, 'agreementsToggle'])->name('factoring-agreements.toggle')->middleware('module:banking,edit,treasury_operations');
        Route::post('/factoring-agreements/{factoringAgreement}/renew',   [FactoringController::class, 'renew'])->name('factoring-agreements.renew')->middleware('module:banking,create,treasury_operations');
        Route::delete('/factoring-agreements/{factoringAgreement}/renew', [FactoringController::class, 'deleteRenewal'])->name('factoring-agreements.delete-renewal')->middleware('module:banking,edit,treasury_operations');

        // ── Factoring With Recourse — its own dedicated list/create/store ──
        Route::get('/factoring-with-recourse',                               [FactoringWithRecourseController::class, 'index'])->name('factoring-with-recourse.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/factoring-with-recourse/create',                        [FactoringWithRecourseController::class, 'create'])->name('factoring-with-recourse.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/factoring-with-recourse',                              [FactoringWithRecourseController::class, 'store'])->name('factoring-with-recourse.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/factoring-with-recourse-lookup/agreements/{factoringCompany}', [FactoringWithRecourseController::class, 'agreements'])->name('factoring-with-recourse.agreements')->middleware('module:banking,view,treasury_operations');

        // ── Factoring Without Recourse — its own dedicated list/create/store ──
        // Fully separate from With Recourse (confirmed with Mahmoud —
        // different products, different lifecycle, must never share
        // one screen). Only the agreements lookup differs per type;
        // everything else genuinely IS shared — see
        // fi.factoring-transactions.* below.
        Route::get('/factoring-without-recourse',                            [FactoringWithoutRecourseController::class, 'index'])->name('factoring-without-recourse.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/factoring-without-recourse/create',                     [FactoringWithoutRecourseController::class, 'create'])->name('factoring-without-recourse.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/factoring-without-recourse',                           [FactoringWithoutRecourseController::class, 'store'])->name('factoring-without-recourse.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/factoring-without-recourse-lookup/agreements/{factoringCompany}', [FactoringWithoutRecourseController::class, 'agreements'])->name('factoring-without-recourse.agreements')->middleware('module:banking,view,treasury_operations');

        // ── Factoring Transactions — actions genuinely shared by both types ──
        // Collect/Reject/Set to Draft/Delete all operate on a single
        // FactoringTransaction regardless of recourse type (the
        // branching lives in FactoringTransactionService, not in
        // routing) — same for the customers/invoices/calculate
        // lookups both Create forms call.
        Route::get('/factoring-transactions-lookup/customers/{agreement}',   [FactoringTransactionController::class, 'customers'])->name('factoring-transactions.customers')->middleware('module:banking,view,treasury_operations');
        Route::get('/factoring-transactions-lookup/invoices/{partnerId}',    [FactoringTransactionController::class, 'invoices'])->name('factoring-transactions.invoices')->middleware('module:banking,view,treasury_operations');
        Route::post('/factoring-transactions-lookup/calculate',              [FactoringTransactionController::class, 'calculate'])->name('factoring-transactions.calculate')->middleware('module:banking,create,treasury_operations');
        Route::post('/factoring-transactions/{transaction}/collect',         [FactoringTransactionController::class, 'collect'])->name('factoring-transactions.collect')->middleware('module:banking,create,treasury_operations');
        Route::delete('/factoring-transactions/{transaction}/collect',       [FactoringTransactionController::class, 'undoCollect'])->name('factoring-transactions.collect.undo')->middleware('module:banking,edit,treasury_operations');
        Route::post('/factoring-transactions/{transaction}/reject',          [FactoringTransactionController::class, 'reject'])->name('factoring-transactions.reject')->middleware('module:banking,create,treasury_operations');
        Route::delete('/factoring-transactions/{transaction}/reject',        [FactoringTransactionController::class, 'undoReject'])->name('factoring-transactions.reject.undo')->middleware('module:banking,edit,treasury_operations');
        Route::post('/factoring-transactions/{transaction}/set-to-draft',    [FactoringTransactionController::class, 'setToDraft'])->name('factoring-transactions.set-to-draft')->middleware('module:banking,reverse,treasury_operations');
        Route::delete('/factoring-transactions/{transaction}',               [FactoringTransactionController::class, 'destroy'])->name('factoring-transactions.destroy')->middleware('module:banking,edit,treasury_operations');

        // ── Reverse Factoring — its own dedicated list/create/store ──
        Route::get('/reverse-factoring',                               [ReverseFactoringController::class, 'index'])->name('reverse-factoring.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/reverse-factoring/create',                        [ReverseFactoringController::class, 'create'])->name('reverse-factoring.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/reverse-factoring',                              [ReverseFactoringController::class, 'store'])->name('reverse-factoring.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/reverse-factoring-lookup/agreements/{factoringCompany}', [ReverseFactoringController::class, 'agreements'])->name('reverse-factoring.agreements')->middleware('module:banking,view,treasury_operations');
        Route::get('/reverse-factoring-lookup/suppliers/{agreement}',   [ReverseFactoringController::class, 'suppliers'])->name('reverse-factoring.suppliers')->middleware('module:banking,view,treasury_operations');
        Route::get('/reverse-factoring-lookup/bills/{partnerId}',       [ReverseFactoringController::class, 'bills'])->name('reverse-factoring.bills')->middleware('module:banking,view,treasury_operations');
        Route::post('/reverse-factoring-lookup/calculate',              [ReverseFactoringController::class, 'calculate'])->name('reverse-factoring.calculate')->middleware('module:banking,create,treasury_operations');
        Route::post('/reverse-factoring/{transaction}/settle',          [ReverseFactoringController::class, 'settle'])->name('reverse-factoring.settle')->middleware('module:banking,create,treasury_operations');
        Route::delete('/reverse-factoring/{transaction}/settle',        [ReverseFactoringController::class, 'undoSettle'])->name('reverse-factoring.settle.undo')->middleware('module:banking,edit,treasury_operations');
        Route::post('/reverse-factoring/{transaction}/set-to-draft',    [ReverseFactoringController::class, 'setToDraft'])->name('reverse-factoring.set-to-draft')->middleware('module:banking,reverse,treasury_operations');
        Route::delete('/reverse-factoring/{transaction}',               [ReverseFactoringController::class, 'destroy'])->name('reverse-factoring.destroy')->middleware('module:banking,edit,treasury_operations');

        // ── Treasury pages not built yet ─────────────────────────
        // ComingSoonController shows a clear "not available yet"
        // page instead of an empty dashboard (Audit M43). It is a real
        // controller, not a Closure, so route:cache stays safe.
        Route::get('/cash-position',  ComingSoonController::class)->name('cash-position.index')->middleware('module:banking,view,cash_position');
        Route::get('/cash-forecast',  ComingSoonController::class)->name('cash-forecast.index')->middleware('module:banking,view,cash_forecast');
        Route::get('/treasury',       ComingSoonController::class)->name('treasury.index')->middleware('module:banking,view,treasury_operations');

        // ── Treasury Settings ─────────────────────────────────────────
        Route::get('/borrowing-rate',  [BorrowingRateController::class, 'index'])->name('borrowing-rate.index')->middleware('module:banking,view,treasury_operations');
        Route::post('/borrowing-rate', [BorrowingRateController::class, 'store'])->name('borrowing-rate.store')->middleware('module:banking,create,treasury_operations'); // Audit H6: company-owned rate

        // ══════════════════════════════════════════════════════════
        //  STATEMENT SECTION (September 2026 — confirmed with Mahmoud)
        //
        //  Treasury-facing reports, distinct from formal accounting
        //  reports elsewhere — same source of truth (the GL) where a
        //  report is a true running-balance ledger, presented in the
        //  structure a treasurer needs (Beginning/Debit/Credit/End
        //  Balance), never a parallel table duplicating the GL. See
        //  LedgerStatementService header for the full reasoning.
        //
        //  Built in this order: Bank Statement (the reference pattern
        //  — built first), Safe Statement, Withdrawal Statement,
        //  LG & LC Statement / By Bank / By Beneficiary, Factoring
        //  Statement, Cash Cover Statement.
        // ══════════════════════════════════════════════════════════
        Route::get('/statements/bank',        [BankStatementController::class, 'index'])->name('statements.bank.index')->middleware('module:banking,view,cash_position');
        Route::get('/statements/bank/result', [BankStatementController::class, 'result'])->name('statements.bank.result')->middleware('module:banking,view,cash_position');
        Route::get('/statements/bank/export', [BankStatementController::class, 'exportExcel'])->name('statements.bank.export')->middleware('module:banking,view,cash_position');

        // ── Bank Reconciliation (Audit H-25, Oct 2026) ──────────────
        //  Proves the books agree with the bank's own statement, in the
        //  account's own currency. Static segments first, {reconciliation}
        //  routes after. Viewing needs Cash Position; changing anything
        //  needs Treasury Operations; reopening a completed one needs the
        //  "reverse" right (it undoes a signed-off control).
        Route::get('/bank-reconciliation',                                         [BankReconciliationController::class, 'index'])->name('bank-reconciliation.index')->middleware('module:banking,view,cash_position');
        Route::post('/bank-reconciliation',                                        [BankReconciliationController::class, 'store'])->name('bank-reconciliation.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/bank-reconciliation/{reconciliation}',                        [BankReconciliationController::class, 'show'])->name('bank-reconciliation.show')->middleware('module:banking,view,cash_position');
        Route::put('/bank-reconciliation/{reconciliation}',                        [BankReconciliationController::class, 'update'])->name('bank-reconciliation.update')->middleware('module:banking,edit,treasury_operations');
        Route::delete('/bank-reconciliation/{reconciliation}',                     [BankReconciliationController::class, 'destroy'])->name('bank-reconciliation.destroy')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/statement-lines',       [BankReconciliationController::class, 'storeStatementLine'])->name('bank-reconciliation.statement-lines.store')->middleware('module:banking,create,treasury_operations');
        Route::delete('/bank-reconciliation/{reconciliation}/statement-lines/{line}', [BankReconciliationController::class, 'destroyStatementLine'])->name('bank-reconciliation.statement-lines.destroy')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/import',                [BankReconciliationController::class, 'importStatement'])->name('bank-reconciliation.import')->middleware('module:banking,create,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/auto-match',            [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliation.auto-match')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/match',                 [BankReconciliationController::class, 'match'])->name('bank-reconciliation.match')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/clear',                 [BankReconciliationController::class, 'clear'])->name('bank-reconciliation.clear')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/unclear',               [BankReconciliationController::class, 'unclear'])->name('bank-reconciliation.unclear')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/unmatch',               [BankReconciliationController::class, 'unmatch'])->name('bank-reconciliation.unmatch')->middleware('module:banking,edit,treasury_operations');
        Route::post('/bank-reconciliation/{reconciliation}/complete',              [BankReconciliationController::class, 'complete'])->name('bank-reconciliation.complete')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/bank-reconciliation/{reconciliation}/reopen',               [BankReconciliationController::class, 'reopen'])->name('bank-reconciliation.reopen')->middleware('module:banking,reverse,treasury_operations');
        Route::get('/bank-reconciliation/{reconciliation}/export',                 [BankReconciliationController::class, 'exportExcel'])->name('bank-reconciliation.export')->middleware('module:banking,view,cash_position');

        // ── Safe Statement ──────────────────────────────────────────
        Route::get('/statements/safe',        [SafeStatementController::class, 'index'])->name('statements.safe.index')->middleware('module:banking,view,cash_position');
        Route::get('/statements/safe/result', [SafeStatementController::class, 'result'])->name('statements.safe.result')->middleware('module:banking,view,cash_position');
        Route::get('/statements/safe/export', [SafeStatementController::class, 'exportExcel'])->name('statements.safe.export')->middleware('module:banking,view,cash_position');

        // ── LG / Cash Cover Statement ──────────────────────────────
        Route::get('/statements/lg',        [LgStatementController::class, 'index'])->name('statements.lg.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/lg/result', [LgStatementController::class, 'result'])->name('statements.lg.result')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/lg/export', [LgStatementController::class, 'exportExcel'])->name('statements.lg.export')->middleware('module:banking,view,credit_facilities');

        // ── LC Statement ────────────────────────────────────────────
        Route::get('/statements/lc',        [LcStatementController::class, 'index'])->name('statements.lc.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/lc/result', [LcStatementController::class, 'result'])->name('statements.lc.result')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/lc/export', [LcStatementController::class, 'exportExcel'])->name('statements.lc.export')->middleware('module:banking,view,credit_facilities');

        // ── Withdrawal Statement ────────────────────────────────────
        Route::get('/statements/withdrawal',        [WithdrawalStatementController::class, 'index'])->name('statements.withdrawal.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/withdrawal/result', [WithdrawalStatementController::class, 'result'])->name('statements.withdrawal.result')->middleware('module:banking,view,credit_facilities');
        Route::get('/statements/withdrawal/export', [WithdrawalStatementController::class, 'exportExcel'])->name('statements.withdrawal.export')->middleware('module:banking,view,credit_facilities');

        // ── Letter of Guarantee Issuance ──────────────────────────────
        Route::get('/lg-issuance/{lgType?}',                        [LgIssuanceController::class, 'index'])->name('lg-issuance.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/lg-issuance/create/{issuanceType}',            [LgIssuanceController::class, 'create'])->name('lg-issuance.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/lg-issuance',                                 [LgIssuanceController::class, 'store'])->name('lg-issuance.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/lg-issuance/{issuance}/edit',                  [LgIssuanceController::class, 'edit'])->name('lg-issuance.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/lg-issuance/{issuance}',                       [LgIssuanceController::class, 'update'])->name('lg-issuance.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/comment',             [LgIssuanceController::class, 'updateComment'])->name('lg-issuance.update-comment')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/set-to-draft',        [LgIssuanceController::class, 'setToDraft'])->name('lg-issuance.set-to-draft')->middleware('module:banking,reverse,treasury_operations');
        Route::delete('/lg-issuance/{issuance}',                    [LgIssuanceController::class, 'destroy'])->name('lg-issuance.destroy')->middleware('module:banking,edit,treasury_operations');
        Route::post('/lg-issuance/{issuance}/renew',                [LgIssuanceController::class, 'renew'])->name('lg-issuance.renew')->middleware('module:banking,create,treasury_operations');
        Route::post('/lg-issuance/{issuance}/decrease',             [LgIssuanceController::class, 'decrease'])->name('lg-issuance.decrease')->middleware('module:banking,create,treasury_operations');
        Route::post('/lg-issuance/{issuance}/cancel',               [LgIssuanceController::class, 'cancel'])->name('lg-issuance.cancel')->middleware('module:banking,create,treasury_operations');
        Route::post('/lg-issuance/{issuance}/liquidate',            [LgIssuanceController::class, 'liquidate'])->name('lg-issuance.liquidate')->middleware('module:banking,create,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/undo-renewal',        [LgIssuanceController::class, 'undoRenewal'])->name('lg-issuance.undo-renewal')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/undo-decrease',       [LgIssuanceController::class, 'undoDecrease'])->name('lg-issuance.undo-decrease')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/undo-cancel',         [LgIssuanceController::class, 'undoCancel'])->name('lg-issuance.undo-cancel')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lg-issuance/{issuance}/undo-liquidate',      [LgIssuanceController::class, 'undoLiquidate'])->name('lg-issuance.undo-liquidate')->middleware('module:banking,edit,treasury_operations');

        // ── Letter of Credit Issuance ──────────────────────────────────
        Route::get('/lc-issuance/{lcType?}',                        [LcIssuanceController::class, 'index'])->name('lc-issuance.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/lc-issuance/create/{issuanceType}',            [LcIssuanceController::class, 'create'])->name('lc-issuance.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/lc-issuance',                                 [LcIssuanceController::class, 'store'])->name('lc-issuance.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/lc-issuance/{issuance}/edit',                  [LcIssuanceController::class, 'edit'])->name('lc-issuance.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/lc-issuance/{issuance}',                       [LcIssuanceController::class, 'update'])->name('lc-issuance.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/lc-issuance/{issuance}/set-to-draft',        [LcIssuanceController::class, 'setToDraft'])->name('lc-issuance.set-to-draft')->middleware('module:banking,reverse,treasury_operations');
        Route::delete('/lc-issuance/{issuance}',                    [LcIssuanceController::class, 'destroy'])->name('lc-issuance.destroy')->middleware('module:banking,edit,treasury_operations');
        Route::post('/lc-issuance/{issuance}/mark-as-paid',         [LcIssuanceController::class, 'markAsPaid'])->name('lc-issuance.mark-as-paid')->middleware('module:banking,create,treasury_operations');
        Route::patch('/lc-issuance/{issuance}/undo-mark-as-paid',   [LcIssuanceController::class, 'undoMarkAsPaid'])->name('lc-issuance.undo-mark-as-paid')->middleware('module:banking,edit,treasury_operations');
        Route::post('/lc-issuance/{issuance}/release-cash-cover',   [LcIssuanceController::class, 'releaseCashCover'])->name('lc-issuance.release-cash-cover')->middleware('module:banking,create,treasury_operations');
        Route::patch('/lc-issuance/{issuance}/undo-cash-cover-release', [LcIssuanceController::class, 'undoCashCoverRelease'])->name('lc-issuance.undo-cash-cover-release')->middleware('module:banking,edit,treasury_operations');
        Route::post('/lc-issuance/{issuance}/settle',               [LcIssuanceController::class, 'settle'])->name('lc-issuance.settle')->middleware('module:banking,create,treasury_operations');
        Route::patch('/lc-issuance/{issuance}/undo-settlement',     [LcIssuanceController::class, 'undoSettlement'])->name('lc-issuance.undo-settlement')->middleware('module:banking,edit,treasury_operations');
        Route::post('/lc-issuance/{issuance}/expenses',             [LcIssuanceController::class, 'storeExpense'])->name('lc-issuance.expenses.store')->middleware('module:banking,create,treasury_operations');
        Route::put('/lc-issuance-expenses/{expense}',               [LcIssuanceController::class, 'updateExpense'])->name('lc-issuance.expenses.update')->middleware('module:banking,edit,treasury_operations');
        Route::delete('/lc-issuance-expenses/{expense}',            [LcIssuanceController::class, 'destroyExpense'])->name('lc-issuance.expenses.destroy')->middleware('module:banking,edit,treasury_operations');
        Route::get('/lc-issuance-lookup/suppliers',                 [LcIssuanceController::class, 'eligibleSuppliers'])->name('lc-issuance.suppliers')->middleware('module:banking,view');
        Route::get('/lc-issuance-lookup/purchase-orders/{partnerId}', [LcIssuanceController::class, 'eligiblePurchaseOrders'])->name('lc-issuance.purchase-orders')->middleware('module:banking,view,treasury_operations');
        Route::get('/lc-issuance-lookup/{issuance}/supplier-bills', [LcIssuanceController::class, 'eligibleSupplierBills'])->name('lc-issuance.supplier-bills')->middleware('module:banking,view,treasury_operations');
        Route::get('/lc-issuance-lookup/facility-room/{facility}',  [LcIssuanceController::class, 'facilityRoomSummary'])->name('lc-issuance.facility-room')->middleware('module:banking,view,treasury_operations');
        Route::get('/lc-issuance-lookup/exchange-rate',             [LcIssuanceController::class, 'exchangeRateLookup'])->name('lc-issuance.exchange-rate')->middleware('module:banking,view,treasury_operations');
        Route::get('/lc-settlements',                               [LcIssuanceController::class, 'settlementsIndex'])->name('lc-settlements.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/lg-issuance-lookup/beneficiaries/{lgType}',    [LgIssuanceController::class, 'eligibleBeneficiaries'])->name('lg-issuance.beneficiaries')->middleware('module:banking,view,treasury_operations');
        Route::get('/lg-issuance-lookup/facility-room/{facility}',  [LgIssuanceController::class, 'facilityRoomSummary'])->name('lg-issuance.facility-room')->middleware('module:banking,view,treasury_operations');
        Route::get('/lg-issuance-lookup/contracts/{partnerId}',     [LgIssuanceController::class, 'eligibleContracts'])->name('lg-issuance.contracts')->middleware('module:banking,view,treasury_operations');

        // ── Internal Money Transfer ────────────────────────────────
        Route::get('/internal-money-transfers',                                 [InternalMoneyTransferController::class, 'index'])->name('internal-money-transfers.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/internal-money-transfers/create',                          [InternalMoneyTransferController::class, 'create'])->name('internal-money-transfers.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/internal-money-transfers',                                [InternalMoneyTransferController::class, 'store'])->name('internal-money-transfers.store')->middleware('module:banking,create,treasury_operations');
        Route::patch('/internal-money-transfers/{internalMoneyTransfer}/confirm', [InternalMoneyTransferController::class, 'confirmReceived'])->name('internal-money-transfers.confirm')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/internal-money-transfers/{internalMoneyTransfer}/cancel',  [InternalMoneyTransferController::class, 'cancel'])->name('internal-money-transfers.cancel')->middleware('module:banking,edit,treasury_operations');
        Route::get('/internal-money-transfers-lookup/safe-accounts',            [InternalMoneyTransferController::class, 'safeAccounts'])->name('internal-money-transfers.safe-accounts')->middleware('module:banking,view');

                // ── Buy/Sell Currency ────────────────────────────────────────
        Route::get('/buy-sell-currencies-lookup/safe-accounts',        [BuySellCurrencyController::class, 'safeAccounts'])->name('buy-sell-currencies.safe-accounts')->middleware('module:banking,view');
        Route::get('/buy-sell-currencies-lookup/reference-rate',       [BuySellCurrencyController::class, 'referenceRate'])->name('buy-sell-currencies.reference-rate')->middleware('module:banking,view,treasury_operations');
        Route::get('/buy-sell-currencies',                             [BuySellCurrencyController::class, 'index'])->name('buy-sell-currencies.index')->middleware('module:banking,view,treasury_operations');
        Route::get('/buy-sell-currencies/create',                      [BuySellCurrencyController::class, 'create'])->name('buy-sell-currencies.create')->middleware('module:banking,view,treasury_operations');
        Route::post('/buy-sell-currencies',                            [BuySellCurrencyController::class, 'store'])->name('buy-sell-currencies.store')->middleware('module:banking,create,treasury_operations');
        Route::get('/buy-sell-currencies/{buySellCurrency}/edit',      [BuySellCurrencyController::class, 'edit'])->name('buy-sell-currencies.edit')->middleware('module:banking,view,treasury_operations');
        Route::put('/buy-sell-currencies/{buySellCurrency}',           [BuySellCurrencyController::class, 'update'])->name('buy-sell-currencies.update')->middleware('module:banking,edit,treasury_operations');
        Route::patch('/buy-sell-currencies/{buySellCurrency}/post',    [BuySellCurrencyController::class, 'post'])->name('buy-sell-currencies.post')->middleware('module:banking,post,treasury_operations');
        Route::patch('/buy-sell-currencies/{buySellCurrency}/set-to-draft', [BuySellCurrencyController::class, 'setToDraft'])->name('buy-sell-currencies.set-to-draft')->middleware('module:banking,reverse,treasury_operations');
        Route::delete('/buy-sell-currencies/{buySellCurrency}',        [BuySellCurrencyController::class, 'destroy'])->name('buy-sell-currencies.destroy')->middleware('module:banking,edit,treasury_operations');
    
        // ── Letter of Credit (specific — BEFORE generic {facility}) ────
        Route::get('/facilities/lc',                   [LetterOfCreditController::class, 'index'])->name('facilities.lc.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/lc/create/{bank}',     [LetterOfCreditController::class, 'create'])->name('facilities.lc.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/lc',                  [LetterOfCreditController::class, 'store'])->name('facilities.lc.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/lc/{facility}/edit',   [LetterOfCreditController::class, 'edit'])->name('facilities.lc.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/lc/{facility}',        [LetterOfCreditController::class, 'update'])->name('facilities.lc.update')->middleware('module:banking,edit,credit_facilities');
        Route::post('/facilities/lc/{facility}/renew',   [LetterOfCreditController::class, 'renew'])->name('facilities.lc.renew')->middleware('module:banking,create,credit_facilities');
        Route::delete('/facilities/lc/{facility}/renew', [LetterOfCreditController::class, 'deleteRenewal'])->name('facilities.lc.delete-renewal')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/lc', [LetterOfCreditController::class, 'bankIndex'])->name('banks.lc.index')->middleware('module:banking,view,credit_facilities');

        // ── Medium Term Loan (specific — BEFORE generic {facility}) ────
        Route::get('/facilities/mtl',                   [MtlController::class, 'index'])->name('facilities.mtl.index')->middleware('module:banking,view,credit_facilities');
        Route::get('/facilities/mtl/create/{bank}',     [MtlController::class, 'create'])->name('facilities.mtl.create')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/mtl',                  [MtlController::class, 'store'])->name('facilities.mtl.store')->middleware('module:banking,create,credit_facilities');
        Route::get('/facilities/mtl/{facility}/edit',   [MtlController::class, 'edit'])->name('facilities.mtl.edit')->middleware('module:banking,view,credit_facilities');
        Route::put('/facilities/mtl/{facility}',        [MtlController::class, 'update'])->name('facilities.mtl.update')->middleware('module:banking,edit,credit_facilities');
        Route::get('/banks/{bank}/facilities/mtl', [MtlController::class, 'bankIndex'])->name('banks.mtl.index')->middleware('module:banking,view,credit_facilities');

        // ── MTL Loan Schedule ──────────────────────────────────────────
        Route::get('/facilities/mtl/{facility}/schedule',      [MtlController::class, 'schedule'])->name('facilities.mtl.schedule')->middleware('module:banking,view,credit_facilities');
        Route::post('/facilities/mtl/{facility}/schedule',     [MtlController::class, 'saveSchedule'])->name('facilities.mtl.schedule.save')->middleware('module:banking,create,credit_facilities');
        Route::post('/facilities/mtl/import-schedule',          [MtlController::class, 'importSchedule'])->name('facilities.mtl.schedule.import')->middleware('module:banking,create,credit_facilities');

        // ── MTL Settlement ───────────────────────────────────────────────
        Route::get('/mtl-schedule-lines/{scheduleLine}/settle',    [MtlController::class, 'settlementForm'])->name('mtl.settlement-form')->middleware('module:banking,view,treasury_operations');
        Route::post('/mtl-schedule-lines/{scheduleLine}/settle',   [MtlController::class, 'recordSettlement'])->name('mtl.settle')->middleware('module:banking,create,treasury_operations');
        Route::delete('/mtl-settlements/{settlement}',              [MtlController::class, 'deleteSettlement'])->name('mtl.settlement.delete')->middleware('module:banking,edit,treasury_operations');
    
    
    }); // end financial-institutions group

    // ── Accounting (stub) ─────────────────────────────────────────
    // AUDIT H3 (Sept 2026) — segregation of duties. Every "Post" route
    // (and recording / re-posting a payment or collection, which posts
    // immediately) now needs the separate POST right instead of EDIT or
    // CREATE; approvals need the separate APPROVE right. Both are set per
    // user on the Permissions screen (migration 000297).
    Route::prefix('accounting')->name('accounting.')->group(function () {
        // ── Dashboard (September 2026) — replaces the old Dashboard-
        // placeholder stub. See AccountingDashboardController header.
        Route::get('/dashboard',       [\App\Http\Controllers\Accounting\AccountingDashboardController::class, 'index'])->name('dashboard')->middleware('module:accounting,view');
        Route::get('/sales-orders',    ComingSoonController::class)->name('sales-orders.index')->middleware('module:accounting,view,sales_orders');
        Route::get('/invoices',        ComingSoonController::class)->name('invoices.index')->middleware('module:accounting,view,customer_invoices');
        Route::get('/ar',              ComingSoonController::class)->name('ar.index')->middleware('module:accounting,view,accounts_receivable');
        Route::get('/bills',           ComingSoonController::class)->name('bills.index')->middleware('module:accounting,view,supplier_bills');
        Route::get('/journal-entries',                [\App\Http\Controllers\Accounting\JournalEntryController::class, 'index'])->name('journal-entries.index')->middleware('module:accounting,view,journal_entries');
        // Static segments registered before {journalEntry} — same ordering rule as every other module in this file.
        Route::get('/journal-entries/create',          [\App\Http\Controllers\Accounting\JournalEntryController::class, 'create'])->name('journal-entries.create')->middleware('module:accounting,view,journal_entries');
        Route::post('/journal-entries',                [\App\Http\Controllers\Accounting\JournalEntryController::class, 'store'])->name('journal-entries.store')->middleware('module:accounting,create,journal_entries');
        Route::get('/journal-entries/{journalEntry}',  [\App\Http\Controllers\Accounting\JournalEntryController::class, 'show'])->name('journal-entries.show')->middleware('module:accounting,view,journal_entries');
        Route::patch('/journal-entries/{journalEntry}/set-to-draft', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'setToDraft'])->name('journal-entries.set-to-draft')->middleware('module:accounting,reverse,journal_entries');
        Route::get('/journal-entries/{journalEntry}/lines', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'lines'])->name('journal-entries.lines')->middleware('module:accounting,view');

        // ── Approvals (Audit H3 / H5, Sept 2026) — manual journals and
        //    reopen requests wait here for a SECOND person. The service
        //    layer refuses self-approval and re-checks the feature right
        //    for reopen requests (customer invoices vs supplier bills).
        Route::get('/approvals', [\App\Http\Controllers\Accounting\ApprovalController::class, 'index'])->name('approvals.index')->middleware('module:accounting,view');
        Route::post('/approvals/journals/{manualJournalRequest}/approve', [\App\Http\Controllers\Accounting\ApprovalController::class, 'approveJournal'])->name('approvals.journals.approve')->middleware('module:accounting,approve,journal_entries');
        Route::post('/approvals/journals/{manualJournalRequest}/reject',  [\App\Http\Controllers\Accounting\ApprovalController::class, 'rejectJournal'])->name('approvals.journals.reject')->middleware('module:accounting,approve,journal_entries');
        Route::post('/approvals/reopens/{documentReopenRequest}/approve', [\App\Http\Controllers\Accounting\ApprovalController::class, 'approveReopen'])->name('approvals.reopens.approve')->middleware('module:accounting,approve');
        Route::post('/approvals/reopens/{documentReopenRequest}/reject',  [\App\Http\Controllers\Accounting\ApprovalController::class, 'rejectReopen'])->name('approvals.reopens.reject')->middleware('module:accounting,approve');
        // ── General Ledger (real controller — replaces the old stub) ──
        // Static segments (result, export) registered before any
        // {account}-style route would be, same ordering rule as every
        // other module in this file — no such route exists here yet,
        // but kept consistent for when one might.
        Route::get('/general-ledger',         [\App\Http\Controllers\Accounting\GeneralLedgerController::class, 'index'])->name('general-ledger.index')->middleware('module:accounting,view,general_ledger');
        Route::get('/general-ledger/result',  [\App\Http\Controllers\Accounting\GeneralLedgerController::class, 'result'])->name('general-ledger.result')->middleware('module:accounting,view,general_ledger');
        Route::get('/general-ledger/export',  [\App\Http\Controllers\Accounting\GeneralLedgerController::class, 'exportExcel'])->name('general-ledger.export')->middleware('module:accounting,view,general_ledger');

        // ── Trial Balance (built alongside General Ledger, same shared
        // conventions — see TrialBalanceController header) ────────────
        Route::get('/trial-balance',         [\App\Http\Controllers\Accounting\TrialBalanceController::class, 'index'])->name('trial-balance.index')->middleware('module:accounting,view,trial_balance');
        Route::get('/trial-balance/result',  [\App\Http\Controllers\Accounting\TrialBalanceController::class, 'result'])->name('trial-balance.result')->middleware('module:accounting,view,trial_balance');
        Route::get('/trial-balance/export',  [\App\Http\Controllers\Accounting\TrialBalanceController::class, 'exportExcel'])->name('trial-balance.export')->middleware('module:accounting,view,trial_balance');

        // ── Income Statement / Balance Sheet / Cash Flow Statement
        // (September 2026) — Financial Reports Module Structure v1.0,
        // Sections 3.4–3.6. Replaces the three Dashboard-placeholder
        // stub routes that stood in for these since the routes file
        // was first laid out. Year-end closing follows Option A
        // (confirmed with Mahmoud): 314/315 are never posted, always
        // computed live — see BalanceSheetService header.
        Route::get('/pl',              [\App\Http\Controllers\Accounting\IncomeStatementController::class, 'index'])->name('pl.index')->middleware('module:accounting,view,profit_loss');
        Route::get('/balance-sheet',   [\App\Http\Controllers\Accounting\BalanceSheetController::class, 'index'])->name('balance-sheet.index')->middleware('module:accounting,view,balance_sheet');
        Route::get('/cash-flow',       [\App\Http\Controllers\Accounting\CashFlowStatementController::class, 'index'])->name('cash-flow.index')->middleware('module:accounting,view,cash_flow');

        // ── Inventory Engine — Stock Balance (read-only) ────────────
        Route::get('/inventory',                    [StockBalanceController::class, 'index'])->name('inventory.index')->middleware('module:accounting,view,stock_ledger');
        Route::get('/inventory/{product}/locations', [StockBalanceController::class, 'locationBreakdown'])->name('inventory.location-breakdown')->middleware('module:accounting,view,stock_ledger');

        // ── Purchase Orders (real controller — replaces the old stub) ──
        // Static segments (create) registered before {purchaseOrder}/edit,
        // same ordering rule as every other module in this file.
        Route::get('/purchase-orders',                              [PurchaseOrderController::class, 'index'])->name('purchase-orders.index')->middleware('module:accounting,view,purchase_orders');
        Route::get('/purchase-orders/create',                       [PurchaseOrderController::class, 'create'])->name('purchase-orders.create')->middleware('module:accounting,view,purchase_orders');
        Route::post('/purchase-orders',                             [PurchaseOrderController::class, 'store'])->name('purchase-orders.store')->middleware('module:accounting,create,purchase_orders');
        Route::get('/purchase-orders/{purchaseOrder}/edit',         [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit')->middleware('module:accounting,view,purchase_orders');
        Route::put('/purchase-orders/{purchaseOrder}',              [PurchaseOrderController::class, 'update'])->name('purchase-orders.update')->middleware('module:accounting,edit,purchase_orders');
        Route::patch('/purchase-orders/{purchaseOrder}/activate',   [PurchaseOrderController::class, 'activate'])->name('purchase-orders.activate')->middleware('module:accounting,edit,purchase_orders');
        Route::patch('/purchase-orders/{purchaseOrder}/close',      [PurchaseOrderController::class, 'close'])->name('purchase-orders.close')->middleware('module:accounting,edit,purchase_orders');
        Route::patch('/purchase-orders/{purchaseOrder}/force-close',[PurchaseOrderController::class, 'forceClose'])->name('purchase-orders.force-close')->middleware('module:accounting,edit,purchase_orders');

        // ── Goods Receipt Notes (Accounting Engine Phase 2) ──────────
        // Static segments (create, eligible-po-lines) registered before
        // {goodsReceiptNote}/edit, same ordering rule as everywhere else.
        Route::get('/goods-receipt-notes',                                   [GoodsReceiptNoteController::class, 'index'])->name('goods-receipt-notes.index')->middleware('module:accounting,view,goods_receipt');
        Route::get('/goods-receipt-notes/create',                            [GoodsReceiptNoteController::class, 'create'])->name('goods-receipt-notes.create')->middleware('module:accounting,view,goods_receipt');
        Route::get('/goods-receipt-notes/eligible-po-lines',                 [GoodsReceiptNoteController::class, 'eligiblePoLines'])->name('goods-receipt-notes.eligible-po-lines')->middleware('module:accounting,view,goods_receipt');
        Route::get('/goods-receipt-notes/eligible-landing-costs',            [GoodsReceiptNoteController::class, 'eligibleLandingCosts'])->name('goods-receipt-notes.eligible-landing-costs')->middleware('module:accounting,view,goods_receipt');
        Route::post('/goods-receipt-notes',                                  [GoodsReceiptNoteController::class, 'store'])->name('goods-receipt-notes.store')->middleware('module:accounting,create,goods_receipt');
        Route::get('/goods-receipt-notes/{goodsReceiptNote}/edit',           [GoodsReceiptNoteController::class, 'edit'])->name('goods-receipt-notes.edit')->middleware('module:accounting,view,goods_receipt');
        Route::put('/goods-receipt-notes/{goodsReceiptNote}',                [GoodsReceiptNoteController::class, 'update'])->name('goods-receipt-notes.update')->middleware('module:accounting,edit,goods_receipt');
        Route::delete('/goods-receipt-notes/{goodsReceiptNote}',             [GoodsReceiptNoteController::class, 'destroy'])->name('goods-receipt-notes.destroy')->middleware('module:accounting,edit,goods_receipt');
        Route::patch('/goods-receipt-notes/{goodsReceiptNote}/post',         [GoodsReceiptNoteController::class, 'post'])->name('goods-receipt-notes.post')->middleware('module:accounting,post,goods_receipt');
        Route::patch('/goods-receipt-notes/{goodsReceiptNote}/cancel',       [GoodsReceiptNoteController::class, 'cancel'])->name('goods-receipt-notes.cancel')->middleware('module:accounting,edit,goods_receipt');
        Route::patch('/goods-receipt-notes/{goodsReceiptNote}/set-to-draft', [GoodsReceiptNoteController::class, 'setToDraft'])->name('goods-receipt-notes.set-to-draft')->middleware('module:accounting,reverse,goods_receipt');

        // Audit H16 — Purchase Returns (goods sent back to a supplier against a posted GRN)
        Route::get('/purchase-returns',                           [\App\Http\Controllers\Accounting\PurchaseReturnController::class, 'index'])->name('purchase-returns.index')->middleware('module:accounting,view,goods_receipt');
        Route::post('/purchase-returns',                          [\App\Http\Controllers\Accounting\PurchaseReturnController::class, 'store'])->name('purchase-returns.store')->middleware('module:accounting,post,goods_receipt');
        Route::patch('/purchase-returns/{purchaseReturn}/cancel', [\App\Http\Controllers\Accounting\PurchaseReturnController::class, 'cancel'])->name('purchase-returns.cancel')->middleware('module:accounting,reverse,goods_receipt');

        // ── Landing Cost (see VeroCFO_Landing_Cost_Design_v1_0.md) ──
        // Never linked to a Purchase Order — always freely picked from
        // a list at Goods Receipt time (see eligible-landing-costs
        // above). Static segments (create, bank-accounts) registered
        // before {landingCost}/edit, same ordering rule as everywhere
        // else in this file.
        Route::get('/landing-costs',                                  [LandingCostController::class, 'index'])->name('landing-costs.index')->middleware('module:accounting,view,goods_receipt');
        Route::get('/landing-costs/create',                           [LandingCostController::class, 'create'])->name('landing-costs.create')->middleware('module:accounting,view,goods_receipt');
        Route::get('/landing-costs/bank-accounts',                    [LandingCostController::class, 'bankAccounts'])->name('landing-costs.bank-accounts')->middleware('module:accounting,view,goods_receipt');
        Route::post('/landing-costs',                                 [LandingCostController::class, 'store'])->name('landing-costs.store')->middleware('module:accounting,create,goods_receipt');
        Route::get('/landing-costs/{landingCost}/edit',               [LandingCostController::class, 'edit'])->name('landing-costs.edit')->middleware('module:accounting,view,goods_receipt');
        Route::put('/landing-costs/{landingCost}',                    [LandingCostController::class, 'update'])->name('landing-costs.update')->middleware('module:accounting,edit,goods_receipt');
        Route::delete('/landing-costs/{landingCost}',                 [LandingCostController::class, 'destroy'])->name('landing-costs.destroy')->middleware('module:accounting,edit,goods_receipt');
        Route::patch('/landing-costs/{landingCost}/post',             [LandingCostController::class, 'post'])->name('landing-costs.post')->middleware('module:accounting,post,goods_receipt');
        Route::patch('/landing-costs/{landingCost}/set-to-draft',     [LandingCostController::class, 'setToDraft'])->name('landing-costs.set-to-draft')->middleware('module:accounting,reverse,goods_receipt');
        Route::post('/landing-costs/{landingCost}/pay',               [LandingCostController::class, 'pay'])->name('landing-costs.pay')->middleware('module:accounting,create,goods_receipt');
        Route::patch('/landing-costs/{landingCost}/unpay',            [LandingCostController::class, 'unpay'])->name('landing-costs.unpay')->middleware('module:accounting,reverse,goods_receipt');

        // ── Fixed Asset Receipts (Fixed Assets module — dedicated,
        // simplified page, separate from the general Goods Receipt
        // Notes screen, confirmed with Mahmoud). Same underlying
        // GoodsReceiptNote engine — see FixedAssetReceiptController.
        // Static segments (create, eligible-po-lines) registered
        // before {goodsReceiptNote}/edit, same ordering rule as
        // everywhere else.
        Route::get('/fixed-asset-receipts',                              [FixedAssetReceiptController::class, 'index'])->name('fixed-asset-receipts.index')->middleware('module:accounting,view,fixed_assets');
        Route::get('/fixed-asset-receipts/create',                       [FixedAssetReceiptController::class, 'create'])->name('fixed-asset-receipts.create')->middleware('module:accounting,view,fixed_assets');
        Route::get('/fixed-asset-receipts/eligible-po-lines',             [FixedAssetReceiptController::class, 'eligiblePoLines'])->name('fixed-asset-receipts.eligible-po-lines')->middleware('module:accounting,view,fixed_assets');
        Route::post('/fixed-asset-receipts',                              [FixedAssetReceiptController::class, 'store'])->name('fixed-asset-receipts.store')->middleware('module:accounting,create,fixed_assets');
        Route::get('/fixed-asset-receipts/{goodsReceiptNote}/edit',       [FixedAssetReceiptController::class, 'edit'])->name('fixed-asset-receipts.edit')->middleware('module:accounting,view,fixed_assets');
        Route::put('/fixed-asset-receipts/{goodsReceiptNote}',            [FixedAssetReceiptController::class, 'update'])->name('fixed-asset-receipts.update')->middleware('module:accounting,edit,fixed_assets');
        Route::delete('/fixed-asset-receipts/{goodsReceiptNote}',         [FixedAssetReceiptController::class, 'destroy'])->name('fixed-asset-receipts.destroy')->middleware('module:accounting,edit,fixed_assets');
        Route::patch('/fixed-asset-receipts/{goodsReceiptNote}/post',     [FixedAssetReceiptController::class, 'post'])->name('fixed-asset-receipts.post')->middleware('module:accounting,post,fixed_assets');
        Route::patch('/fixed-asset-receipts/{goodsReceiptNote}/cancel',   [FixedAssetReceiptController::class, 'cancel'])->name('fixed-asset-receipts.cancel')->middleware('module:accounting,edit,fixed_assets');
        Route::patch('/fixed-asset-receipts/{goodsReceiptNote}/set-to-draft', [FixedAssetReceiptController::class, 'setToDraft'])->name('fixed-asset-receipts.set-to-draft')->middleware('module:accounting,reverse,fixed_assets');

        // ── Fixed Assets (Fixed Assets module, Phase 1) ──────────────
        // Static segments (run-depreciation) registered before
        // {fixedAsset}/edit, same ordering rule as everywhere else.
        Route::get('/fixed-assets',                              [FixedAssetController::class, 'index'])->name('fixed-assets.index')->middleware('module:accounting,view,fixed_assets');
        Route::post('/fixed-assets/run-depreciation',             [FixedAssetController::class, 'runDepreciation'])->name('fixed-assets.run-depreciation')->middleware('module:accounting,create,fixed_assets');
        // ── Audit H17 (Sept 2026) — sale / scrapping, impairment, transfer ──
        Route::patch('/fixed-asset-disposals/{fixedAssetDisposal}/cancel',     [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'cancelDisposal'])->name('fixed-assets.disposals.cancel')->middleware('module:accounting,reverse,fixed_assets');
        Route::patch('/fixed-asset-impairments/{fixedAssetImpairment}/cancel', [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'cancelImpairment'])->name('fixed-assets.impairments.cancel')->middleware('module:accounting,reverse,fixed_assets');
        Route::get('/fixed-assets/{fixedAsset}/manage',             [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'manage'])->name('fixed-assets.manage')->middleware('module:accounting,view,fixed_assets');
        Route::get('/fixed-assets/{fixedAsset}/disposal-preview',   [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'preview'])->name('fixed-assets.disposal-preview')->middleware('module:accounting,view,fixed_assets');
        Route::post('/fixed-assets/{fixedAsset}/dispose',           [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'dispose'])->name('fixed-assets.dispose')->middleware('module:accounting,post,fixed_assets');
        Route::post('/fixed-assets/{fixedAsset}/impair',            [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'impair'])->name('fixed-assets.impair')->middleware('module:accounting,post,fixed_assets');
        Route::post('/fixed-assets/{fixedAsset}/transfer',          [\App\Http\Controllers\Accounting\FixedAssetLifecycleController::class, 'transfer'])->name('fixed-assets.transfer')->middleware('module:accounting,edit,fixed_assets');
        Route::get('/fixed-assets/{fixedAsset}',                  [FixedAssetController::class, 'show'])->name('fixed-assets.show')->middleware('module:accounting,view,fixed_assets');
        Route::put('/fixed-assets/{fixedAsset}',                  [FixedAssetController::class, 'update'])->name('fixed-assets.update')->middleware('module:accounting,edit,fixed_assets');
        Route::patch('/fixed-assets/{fixedAsset}/mark-in-service',[FixedAssetController::class, 'markInService'])->name('fixed-assets.mark-in-service')->middleware('module:accounting,edit,fixed_assets');
        Route::patch('/fixed-assets/{fixedAsset}/post-depreciation',   [FixedAssetController::class, 'postDepreciation'])->name('fixed-assets.post-depreciation')->middleware('module:accounting,edit,fixed_assets');
        Route::delete('/fixed-asset-depreciation-entries/{depreciationEntry}', [FixedAssetController::class, 'reverseDepreciation'])->name('fixed-assets.reverse-depreciation')->middleware('module:accounting,edit,fixed_assets');

        // ── Inventory Statement (new report — July 2026) ───────────
        Route::get('/inventory-statement',     [\App\Http\Controllers\Accounting\InventoryStatementController::class, 'index'])->name('inventory-statement.index')->middleware('module:accounting,view,stock_ledger');
        Route::get('/inventory-statement/products/search', [\App\Http\Controllers\Accounting\InventoryStatementController::class, 'searchProducts'])->name('inventory-statement.products.search')->middleware('module:accounting,view,stock_ledger');
        Route::get('/inventory-statement/ledger', [\App\Http\Controllers\Accounting\InventoryStatementController::class, 'ledger'])->name('inventory-statement.ledger')->middleware('module:accounting,view,stock_ledger');

        // ── Month-End FX Revaluation (Audit H12, Sept 2026 — IAS 21) ──
        // A system journal, so it sits under the Journal Entries right:
        // VIEW to see and preview, POST to run it, REVERSE to cancel it.
        // Static segment (create) registered before {fxRevaluation}.
        Route::get('/fx-revaluations',                        [\App\Http\Controllers\Accounting\FxRevaluationController::class, 'index'])->name('fx-revaluations.index')->middleware('module:accounting,view,journal_entries');
        Route::get('/fx-revaluations/create',                 [\App\Http\Controllers\Accounting\FxRevaluationController::class, 'create'])->name('fx-revaluations.create')->middleware('module:accounting,view,journal_entries');
        Route::post('/fx-revaluations',                       [\App\Http\Controllers\Accounting\FxRevaluationController::class, 'store'])->name('fx-revaluations.store')->middleware('module:accounting,post,journal_entries');
        Route::get('/fx-revaluations/{fxRevaluation}',        [\App\Http\Controllers\Accounting\FxRevaluationController::class, 'show'])->name('fx-revaluations.show')->middleware('module:accounting,view,journal_entries');
        Route::patch('/fx-revaluations/{fxRevaluation}/cancel', [\App\Http\Controllers\Accounting\FxRevaluationController::class, 'cancel'])->name('fx-revaluations.cancel')->middleware('module:accounting,reverse,journal_entries');

        // ── Income Tax (Audit H19, Sept 2026 — IAS 12) ──────────────
        // Provision (Dr 591 / Cr 226003) and Debit WHT set-off (Dr 226003 / Cr 142).
        Route::get('/income-tax',                                  [\App\Http\Controllers\Accounting\IncomeTaxController::class, 'index'])->name('income-tax.index')->middleware('module:accounting,view,journal_entries');
        Route::post('/income-tax/provision',                       [\App\Http\Controllers\Accounting\IncomeTaxController::class, 'storeProvision'])->name('income-tax.provision')->middleware('module:accounting,post,journal_entries');
        Route::post('/income-tax/wht-offset',                      [\App\Http\Controllers\Accounting\IncomeTaxController::class, 'storeOffset'])->name('income-tax.wht-offset')->middleware('module:accounting,post,journal_entries');
        Route::patch('/income-tax/{incomeTaxEntry}/cancel',        [\App\Http\Controllers\Accounting\IncomeTaxController::class, 'cancel'])->name('income-tax.cancel')->middleware('module:accounting,reverse,journal_entries');

        // ── Revenue Schedules (Audit H18, Sept 2026 — IFRS 15 over time) ──
        Route::get('/revenue-schedules',          [\App\Http\Controllers\Accounting\RevenueScheduleController::class, 'index'])->name('revenue-schedules.index')->middleware('module:accounting,view,customer_invoices');
        Route::post('/revenue-schedules/release', [\App\Http\Controllers\Accounting\RevenueScheduleController::class, 'release'])->name('revenue-schedules.release')->middleware('module:accounting,post,customer_invoices');

        // ── Category Value Statement (new report — July 2026) ──────
        Route::get('/category-value-statement',     [\App\Http\Controllers\Accounting\InventoryStatementController::class, 'categoryValueIndex'])->name('category-value-statement.index')->middleware('module:accounting,view,stock_ledger');
        Route::get('/category-value-statement/run', [\App\Http\Controllers\Accounting\InventoryStatementController::class, 'categoryValueRun'])->name('category-value-statement.run')->middleware('module:accounting,view,stock_ledger');

        // ── Accruals (Accounting Engine Phase 2 — feeds Supplier Bill) ──
        // No edit/update route — an accrual posts its GL entry immediately
        // on creation (see AccrualService header), it is never revised.
        // AUDIT M4 FIX: accruals are month-end adjustment entries, so they
        // now need the Journal Entries right (was: any accounting right).
        Route::get('/accruals',                              [AccrualController::class, 'index'])->name('accruals.index')->middleware('module:accounting,view,journal_entries');
        Route::get('/accruals/create',                       [AccrualController::class, 'create'])->name('accruals.create')->middleware('module:accounting,view,journal_entries');
        Route::get('/accruals/eligible-for-partner',         [AccrualController::class, 'eligibleForPartner'])->name('accruals.eligible-for-partner')->middleware('module:accounting,view,journal_entries');
        Route::post('/accruals',                             [AccrualController::class, 'store'])->name('accruals.store')->middleware('module:accounting,create,journal_entries');
        Route::patch('/accruals/{accrual}/cancel',           [AccrualController::class, 'cancel'])->name('accruals.cancel')->middleware('module:accounting,edit,journal_entries');

        // ── Supplier Balances (per Mahmoud September 2026 — payable-side mirror of Customer Balances) ──
        Route::get('/supplier-balances',            [SupplierBalanceController::class, 'index'])->name('supplier-balances.index')->middleware('module:accounting,view,accounts_payable');
        Route::get('/supplier-balances/invoices',    [SupplierBalanceController::class, 'invoiceReport'])->name('supplier-balances.invoices')->middleware('module:accounting,view,accounts_payable');
        Route::get('/supplier-balances/statement',   [SupplierBalanceController::class, 'statement'])->name('supplier-balances.statement')->middleware('module:accounting,view,accounts_payable');

        // ── Supplier Bill (Accounting Engine Phase 2 — 3-way match: PO → GRN → Bill) ──
        // Static segments (create, eligible-grn-lines) registered before
        // {supplierBill}/edit, same ordering rule as everywhere else.
        Route::get('/supplier-bills',                                 [SupplierBillController::class, 'index'])->name('supplier-bills.index')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-bills/create',                          [SupplierBillController::class, 'create'])->name('supplier-bills.create')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-bills/eligible-grn-lines',              [SupplierBillController::class, 'eligibleGrnLines'])->name('supplier-bills.eligible-grn-lines')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-bills/latest-exchange-rate',            [SupplierBillController::class, 'latestExchangeRate'])->name('supplier-bills.latest-exchange-rate')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-bills/unapplied-payments',              [SupplierBillController::class, 'unappliedPayments'])->name('supplier-bills.unapplied-payments')->middleware('module:accounting,view,supplier_bills');
        Route::post('/supplier-bills',                                [SupplierBillController::class, 'store'])->name('supplier-bills.store')->middleware('module:accounting,create,supplier_bills');
        Route::get('/supplier-bills/{supplierBill}/edit',             [SupplierBillController::class, 'edit'])->name('supplier-bills.edit')->middleware('module:accounting,view,supplier_bills');
        Route::put('/supplier-bills/{supplierBill}',                  [SupplierBillController::class, 'update'])->name('supplier-bills.update')->middleware('module:accounting,edit,supplier_bills');
        Route::delete('/supplier-bills/{supplierBill}',               [SupplierBillController::class, 'destroy'])->name('supplier-bills.destroy')->middleware('module:accounting,edit,supplier_bills');
        Route::patch('/supplier-bills/{supplierBill}/post',           [SupplierBillController::class, 'post'])->name('supplier-bills.post')->middleware('module:accounting,post,supplier_bills');
        Route::patch('/supplier-bills/{supplierBill}/cancel',         [SupplierBillController::class, 'cancel'])->name('supplier-bills.cancel')->middleware('module:accounting,edit,supplier_bills');
        Route::patch('/supplier-bills/{supplierBill}/adjust-due-date', [SupplierBillController::class, 'adjustDueDate'])->name('supplier-bills.adjust-due-date')->middleware('module:accounting,edit,supplier_bills');
        Route::patch('/supplier-bills/{supplierBill}/set-to-draft',   [SupplierBillController::class, 'setToDraft'])->name('supplier-bills.set-to-draft')->middleware('module:accounting,reverse,supplier_bills');
        // Prepaid Expense Bill amortization — see PrepaidAmortizationService.
        // Own top-level resource (not nested under {supplierBill}) since
        // both actions operate one level below the bill (its lines, and
        // the posting-entries those lines generate).
        Route::post('/supplier-bill-lines/{supplierBillLine}/post-amortization', [SupplierBillController::class, 'postAmortization'])->name('supplier-bill-lines.post-amortization')->middleware('module:accounting,create,supplier_bills');
        Route::post('/prepaid-amortization-entries/{prepaidAmortizationEntry}/reverse', [SupplierBillController::class, 'reverseAmortization'])->name('prepaid-amortization-entries.reverse')->middleware('module:accounting,reverse');

        // ── Supplier Debit Note (Purchase Cycle — decreases AP on a posted Supplier Bill) ──
        // Fresh Expense / Accrual Settlement bill lines only — see
        // SupplierDebitNoteService header for the goods-line gap.
        Route::get('/supplier-debit-notes',                              [SupplierDebitNoteController::class, 'index'])->name('supplier-debit-notes.index')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-debit-notes/create',                       [SupplierDebitNoteController::class, 'create'])->name('supplier-debit-notes.create')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-debit-notes/eligible-bills',               [SupplierDebitNoteController::class, 'eligibleBills'])->name('supplier-debit-notes.eligible-bills')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-debit-notes/bill-lines',                   [SupplierDebitNoteController::class, 'billLines'])->name('supplier-debit-notes.bill-lines')->middleware('module:accounting,view,supplier_bills');
        Route::post('/supplier-debit-notes',                             [SupplierDebitNoteController::class, 'store'])->name('supplier-debit-notes.store')->middleware('module:accounting,create,supplier_bills');
        Route::get('/supplier-debit-notes/{supplierDebitNote}/edit',     [SupplierDebitNoteController::class, 'edit'])->name('supplier-debit-notes.edit')->middleware('module:accounting,view,supplier_bills');
        Route::put('/supplier-debit-notes/{supplierDebitNote}',          [SupplierDebitNoteController::class, 'update'])->name('supplier-debit-notes.update')->middleware('module:accounting,edit,supplier_bills');
        Route::delete('/supplier-debit-notes/{supplierDebitNote}',       [SupplierDebitNoteController::class, 'destroy'])->name('supplier-debit-notes.destroy')->middleware('module:accounting,edit,supplier_bills');
        Route::patch('/supplier-debit-notes/{supplierDebitNote}/post',   [SupplierDebitNoteController::class, 'post'])->name('supplier-debit-notes.post')->middleware('module:accounting,post,supplier_bills');
        Route::patch('/supplier-debit-notes/{supplierDebitNote}/cancel', [SupplierDebitNoteController::class, 'cancel'])->name('supplier-debit-notes.cancel')->middleware('module:accounting,edit,supplier_bills');

        // ── Supplier Credit Note (Purchase Cycle — increases AP on a posted Supplier Bill) ──
        // Same goods-line limitation as Supplier Debit Note above.
        Route::get('/supplier-credit-notes',                              [SupplierCreditNoteController::class, 'index'])->name('supplier-credit-notes.index')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-credit-notes/create',                       [SupplierCreditNoteController::class, 'create'])->name('supplier-credit-notes.create')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-credit-notes/eligible-bills',               [SupplierCreditNoteController::class, 'eligibleBills'])->name('supplier-credit-notes.eligible-bills')->middleware('module:accounting,view,supplier_bills');
        Route::get('/supplier-credit-notes/bill-lines',                   [SupplierCreditNoteController::class, 'billLines'])->name('supplier-credit-notes.bill-lines')->middleware('module:accounting,view,supplier_bills');
        Route::post('/supplier-credit-notes',                             [SupplierCreditNoteController::class, 'store'])->name('supplier-credit-notes.store')->middleware('module:accounting,create,supplier_bills');
        Route::get('/supplier-credit-notes/{supplierCreditNote}/edit',    [SupplierCreditNoteController::class, 'edit'])->name('supplier-credit-notes.edit')->middleware('module:accounting,view,supplier_bills');
        Route::put('/supplier-credit-notes/{supplierCreditNote}',         [SupplierCreditNoteController::class, 'update'])->name('supplier-credit-notes.update')->middleware('module:accounting,edit,supplier_bills');
        Route::delete('/supplier-credit-notes/{supplierCreditNote}',      [SupplierCreditNoteController::class, 'destroy'])->name('supplier-credit-notes.destroy')->middleware('module:accounting,edit,supplier_bills');
        Route::patch('/supplier-credit-notes/{supplierCreditNote}/post',  [SupplierCreditNoteController::class, 'post'])->name('supplier-credit-notes.post')->middleware('module:accounting,post,supplier_bills');
        Route::patch('/supplier-credit-notes/{supplierCreditNote}/cancel',[SupplierCreditNoteController::class, 'cancel'])->name('supplier-credit-notes.cancel')->middleware('module:accounting,edit,supplier_bills');

        // ── Customer Invoice (Sales Cycle, module 1 — mandatory SO link, Rule 28) ──
        Route::get('/customer-invoices',                                  [CustomerInvoiceController::class, 'index'])->name('customer-invoices.index')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-invoices/create',                           [CustomerInvoiceController::class, 'create'])->name('customer-invoices.create')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-invoices/eligible-so-lines',                [CustomerInvoiceController::class, 'eligibleSoLines'])->name('customer-invoices.eligible-so-lines')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-invoices/eligible-sales-orders',            [CustomerInvoiceController::class, 'eligibleSalesOrders'])->name('customer-invoices.eligible-sales-orders')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-invoices/unapplied-collections', [CustomerInvoiceController::class, 'unappliedCollections'])->name('customer-invoices.unapplied-collections')->middleware('module:accounting,view,customer_invoices');
        Route::post('/customer-invoices',                                 [CustomerInvoiceController::class, 'store'])->name('customer-invoices.store')->middleware('module:accounting,create,customer_invoices');
        Route::get('/customer-invoices/{customerInvoice}/edit',           [CustomerInvoiceController::class, 'edit'])->name('customer-invoices.edit')->middleware('module:accounting,view,customer_invoices');
        Route::put('/customer-invoices/{customerInvoice}',                [CustomerInvoiceController::class, 'update'])->name('customer-invoices.update')->middleware('module:accounting,edit,customer_invoices');
        Route::delete('/customer-invoices/{customerInvoice}',             [CustomerInvoiceController::class, 'destroy'])->name('customer-invoices.destroy')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/customer-invoices/{customerInvoice}/post',         [CustomerInvoiceController::class, 'post'])->name('customer-invoices.post')->middleware('module:accounting,post,customer_invoices');
        Route::patch('/customer-invoices/{customerInvoice}/cancel',       [CustomerInvoiceController::class, 'cancel'])->name('customer-invoices.cancel')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/customer-invoices/{customerInvoice}/adjust-due-date', [CustomerInvoiceController::class, 'adjustDueDate'])->name('customer-invoices.adjust-due-date')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/customer-invoices/{customerInvoice}/set-to-draft', [CustomerInvoiceController::class, 'setToDraft'])->name('customer-invoices.set-to-draft')->middleware('module:accounting,reverse,customer_invoices');

        // ── Delivery Note (Delivery & Revenue Recognition — outbound mirror of GRN) ──
        Route::get('/delivery-notes',                                [DeliveryNoteController::class, 'index'])->name('delivery-notes.index')->middleware('module:accounting,view,sales_orders');
        Route::get('/delivery-notes/create',                         [DeliveryNoteController::class, 'create'])->name('delivery-notes.create')->middleware('module:accounting,view,sales_orders');
        Route::get('/delivery-notes/eligible-so-lines',              [DeliveryNoteController::class, 'eligibleSoLines'])->name('delivery-notes.eligible-so-lines')->middleware('module:accounting,view,sales_orders');
        Route::get('/delivery-notes/eligible-sales-orders',          [DeliveryNoteController::class, 'eligibleSalesOrders'])->name('delivery-notes.eligible-sales-orders')->middleware('module:accounting,view,sales_orders');
        Route::get('/delivery-notes/location-stock',                 [DeliveryNoteController::class, 'locationStock'])->name('delivery-notes.location-stock')->middleware('module:accounting,view,sales_orders');
        Route::post('/delivery-notes',                               [DeliveryNoteController::class, 'store'])->name('delivery-notes.store')->middleware('module:accounting,create,sales_orders');
        Route::get('/delivery-notes/{deliveryNote}/edit',            [DeliveryNoteController::class, 'edit'])->name('delivery-notes.edit')->middleware('module:accounting,view,sales_orders');
        Route::put('/delivery-notes/{deliveryNote}',                 [DeliveryNoteController::class, 'update'])->name('delivery-notes.update')->middleware('module:accounting,edit,sales_orders');
        Route::delete('/delivery-notes/{deliveryNote}',              [DeliveryNoteController::class, 'destroy'])->name('delivery-notes.destroy')->middleware('module:accounting,edit,sales_orders');
        Route::patch('/delivery-notes/{deliveryNote}/post',          [DeliveryNoteController::class, 'post'])->name('delivery-notes.post')->middleware('module:accounting,post,sales_orders');
        Route::patch('/delivery-notes/{deliveryNote}/cancel',        [DeliveryNoteController::class, 'cancel'])->name('delivery-notes.cancel')->middleware('module:accounting,edit,sales_orders');
        Route::patch('/delivery-notes/{deliveryNote}/set-to-draft',  [DeliveryNoteController::class, 'setToDraft'])->name('delivery-notes.set-to-draft')->middleware('module:accounting,reverse,sales_orders');

        // Audit H16 — Sales Returns (goods back from a customer against a posted Delivery Note)
        Route::get('/sales-returns',                         [\App\Http\Controllers\Accounting\SalesReturnController::class, 'index'])->name('sales-returns.index')->middleware('module:accounting,view,sales_orders');
        Route::post('/sales-returns',                        [\App\Http\Controllers\Accounting\SalesReturnController::class, 'store'])->name('sales-returns.store')->middleware('module:accounting,post,sales_orders');
        Route::patch('/sales-returns/{salesReturn}/cancel',  [\App\Http\Controllers\Accounting\SalesReturnController::class, 'cancel'])->name('sales-returns.cancel')->middleware('module:accounting,reverse,sales_orders');

        // ── Credit Note (Sales Cycle, module 2 — always reverses a posted invoice) ──
        Route::get('/credit-notes',                            [CreditNoteController::class, 'index'])->name('credit-notes.index')->middleware('module:accounting,view,customer_invoices');
        Route::get('/credit-notes/create',                     [CreditNoteController::class, 'create'])->name('credit-notes.create')->middleware('module:accounting,view,customer_invoices');
        Route::get('/credit-notes/eligible-invoices',          [CreditNoteController::class, 'eligibleInvoices'])->name('credit-notes.eligible-invoices')->middleware('module:accounting,view,customer_invoices');
        Route::get('/credit-notes/invoice-lines',              [CreditNoteController::class, 'invoiceLines'])->name('credit-notes.invoice-lines')->middleware('module:accounting,view,customer_invoices');
        Route::post('/credit-notes',                           [CreditNoteController::class, 'store'])->name('credit-notes.store')->middleware('module:accounting,create,customer_invoices');
        Route::get('/credit-notes/{creditNote}/edit',          [CreditNoteController::class, 'edit'])->name('credit-notes.edit')->middleware('module:accounting,view,customer_invoices');
        Route::put('/credit-notes/{creditNote}',               [CreditNoteController::class, 'update'])->name('credit-notes.update')->middleware('module:accounting,edit,customer_invoices');
        Route::delete('/credit-notes/{creditNote}',            [CreditNoteController::class, 'destroy'])->name('credit-notes.destroy')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/credit-notes/{creditNote}/post',        [CreditNoteController::class, 'post'])->name('credit-notes.post')->middleware('module:accounting,post,customer_invoices');
        Route::patch('/credit-notes/{creditNote}/cancel',      [CreditNoteController::class, 'cancel'])->name('credit-notes.cancel')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/credit-notes/{creditNote}/reverse',     [CreditNoteController::class, 'reverse'])->name('credit-notes.reverse')->middleware('module:accounting,reverse,customer_invoices');

        // Customer Credits (Audit M9, Oct 2026) — surplus of a Credit Note on a paid invoice
        Route::get('/customer-credits',                                          [CustomerCreditController::class, 'index'])->name('customer-credits.index')->middleware('module:accounting,view,customer_receipts');
        Route::get('/customer-credits/{balance}/open-invoices',                  [CustomerCreditController::class, 'openInvoices'])->name('customer-credits.open-invoices')->middleware('module:accounting,view,customer_receipts');
        Route::post('/customer-credits/{balance}/apply',                         [CustomerCreditController::class, 'apply'])->name('customer-credits.apply')->middleware('module:accounting,create,customer_receipts');
        Route::post('/customer-credits/{balance}/refund',                        [CustomerCreditController::class, 'refund'])->name('customer-credits.refund')->middleware('module:accounting,post,customer_receipts');
        Route::patch('/customer-credit-applications/{application}/undo',         [CustomerCreditController::class, 'undoApplication'])->name('customer-credits.undo-application')->middleware('module:accounting,reverse,customer_receipts');
        Route::patch('/customer-refunds/{refund}/reverse',                       [CustomerCreditController::class, 'reverseRefund'])->name('customer-credits.reverse-refund')->middleware('module:accounting,reverse,customer_receipts');

        // ── Customer Balances (Customer Balances page, per Mahmoud September 2026) ──
        Route::get('/customer-balances',            [CustomerBalanceController::class, 'index'])->name('customer-balances.index')->middleware('module:accounting,view,accounts_receivable');
        Route::get('/customer-balances/invoices',    [CustomerBalanceController::class, 'invoiceReport'])->name('customer-balances.invoices')->middleware('module:accounting,view,accounts_receivable');
        Route::get('/customer-balances/statement',   [CustomerBalanceController::class, 'statement'])->name('customer-balances.statement')->middleware('module:accounting,view,accounts_receivable');

        // ── Internal Settlement (per Mahmoud September 2026 — nets a partner's AR against their own AP) ──
        Route::get('/internal-settlements',                                    [InternalSettlementController::class, 'index'])->name('internal-settlements.index')->middleware('module:accounting,view');
        Route::get('/internal-settlements/create',                             [InternalSettlementController::class, 'create'])->name('internal-settlements.create')->middleware('module:accounting,view');
        Route::get('/internal-settlements/eligible-partners',                  [InternalSettlementController::class, 'eligiblePartners'])->name('internal-settlements.eligible-partners')->middleware('module:accounting,view');
        Route::get('/internal-settlements/open-invoices-and-bills',            [InternalSettlementController::class, 'openInvoicesAndBills'])->name('internal-settlements.open-invoices-and-bills')->middleware('module:accounting,view');
        Route::post('/internal-settlements',                                   [InternalSettlementController::class, 'store'])->name('internal-settlements.store')->middleware('module:accounting,create');
        Route::get('/internal-settlements/{internalSettlement}/edit',          [InternalSettlementController::class, 'edit'])->name('internal-settlements.edit')->middleware('module:accounting,view');
        Route::put('/internal-settlements/{internalSettlement}',               [InternalSettlementController::class, 'update'])->name('internal-settlements.update')->middleware('module:accounting,edit');
        Route::delete('/internal-settlements/{internalSettlement}',            [InternalSettlementController::class, 'destroy'])->name('internal-settlements.destroy')->middleware('module:accounting,edit');
        Route::patch('/internal-settlements/{internalSettlement}/set-to-draft', [InternalSettlementController::class, 'setToDraft'])->name('internal-settlements.set-to-draft')->middleware('module:accounting,reverse');
        Route::patch('/internal-settlements/{internalSettlement}/cancel',      [InternalSettlementController::class, 'cancel'])->name('internal-settlements.cancel')->middleware('module:accounting,edit');

        // ── Customer Debit Note (Sales Cycle — increases AR on a posted invoice) ──
        Route::get('/customer-debit-notes',                              [CustomerDebitNoteController::class, 'index'])->name('customer-debit-notes.index')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-debit-notes/create',                       [CustomerDebitNoteController::class, 'create'])->name('customer-debit-notes.create')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-debit-notes/eligible-invoices',            [CustomerDebitNoteController::class, 'eligibleInvoices'])->name('customer-debit-notes.eligible-invoices')->middleware('module:accounting,view,customer_invoices');
        Route::get('/customer-debit-notes/invoice-lines',                [CustomerDebitNoteController::class, 'invoiceLines'])->name('customer-debit-notes.invoice-lines')->middleware('module:accounting,view,customer_invoices');
        Route::post('/customer-debit-notes',                             [CustomerDebitNoteController::class, 'store'])->name('customer-debit-notes.store')->middleware('module:accounting,create,customer_invoices');
        Route::get('/customer-debit-notes/{customerDebitNote}/edit',     [CustomerDebitNoteController::class, 'edit'])->name('customer-debit-notes.edit')->middleware('module:accounting,view,customer_invoices');
        Route::put('/customer-debit-notes/{customerDebitNote}',         [CustomerDebitNoteController::class, 'update'])->name('customer-debit-notes.update')->middleware('module:accounting,edit,customer_invoices');
        Route::delete('/customer-debit-notes/{customerDebitNote}',      [CustomerDebitNoteController::class, 'destroy'])->name('customer-debit-notes.destroy')->middleware('module:accounting,edit,customer_invoices');
        Route::patch('/customer-debit-notes/{customerDebitNote}/post',  [CustomerDebitNoteController::class, 'post'])->name('customer-debit-notes.post')->middleware('module:accounting,post,customer_invoices');
        Route::patch('/customer-debit-notes/{customerDebitNote}/cancel',[CustomerDebitNoteController::class, 'cancel'])->name('customer-debit-notes.cancel')->middleware('module:accounting,edit,customer_invoices');

        // ── Customer Collection (redesign, July 2026 — see
        //    VeroCFO_Customer_Collection_Design_v1_0.md). record()
        //    posts immediately — no draft stage, so no edit/update/
        //    destroy/post routes; cancel() is the only way to undo one,
        //    and only before anything's been allocated. ──
        Route::get('/customer-payments',                                    [CustomerPaymentController::class, 'index'])->name('customer-payments.index')->middleware('module:accounting,view,customer_receipts');
        Route::get('/customer-payments/create',                             [CustomerPaymentController::class, 'create'])->name('customer-payments.create')->middleware('module:accounting,view,customer_receipts');
        Route::get('/customer-payments/open-invoices',                      [CustomerPaymentController::class, 'openInvoices'])->name('customer-payments.open-invoices')->middleware('module:accounting,view,customer_receipts');
        Route::get('/customer-payments/destinations',                       [CustomerPaymentController::class, 'destinations'])->name('customer-payments.destinations')->middleware('module:accounting,view');
        Route::get('/customer-payments/safe-accounts',                      [CustomerPaymentController::class, 'safeAccounts'])->name('customer-payments.safe-accounts')->middleware('module:accounting,view,customer_receipts');
        Route::post('/customer-payments',                                   [CustomerPaymentController::class, 'store'])->name('customer-payments.store')->middleware('module:accounting,post,customer_receipts');
        Route::get('/customer-payments/{customerPayment}/edit',             [CustomerPaymentController::class, 'edit'])->name('customer-payments.edit')->middleware('module:accounting,view,customer_receipts');
        Route::put('/customer-payments/{customerPayment}',                  [CustomerPaymentController::class, 'update'])->name('customer-payments.update')->middleware('module:accounting,post,customer_receipts');
        Route::delete('/customer-payments/{customerPayment}',               [CustomerPaymentController::class, 'destroy'])->name('customer-payments.destroy')->middleware('module:accounting,edit,customer_receipts');
        Route::patch('/customer-payments/{customerPayment}/set-to-draft',   [CustomerPaymentController::class, 'setToDraft'])->name('customer-payments.set-to-draft')->middleware('module:accounting,reverse,customer_receipts');
        Route::patch('/customer-payments/{customerPayment}/cancel',         [CustomerPaymentController::class, 'cancel'])->name('customer-payments.cancel')->middleware('module:accounting,edit,customer_receipts');
        Route::post('/customer-payments/{customerPayment}/allocate',        [CustomerPaymentController::class, 'allocate'])->name('customer-payments.allocate')->middleware('module:accounting,create,customer_receipts');
        Route::delete('/customer-payment-allocations/{allocation}',         [CustomerPaymentController::class, 'unallocate'])->name('customer-payment-allocations.unallocate')->middleware('module:accounting,edit,customer_receipts');

        // ── Other Collection (August 2026 — see
        //    VeroCFO_Other_Collection_Design_v1_0.md). Money received
        //    from a Shareholder / Employee / Sister Company / Supplier
        //    (refund) / Other partner. Same posting-immediately + Set to
        //    Draft lifecycle as Customer Collection, but no allocate/
        //    unallocate — there is no invoice to match against. ──
        Route::get('/other-collections',                                    [OtherCollectionController::class, 'index'])->name('other-collections.index')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/create',                             [OtherCollectionController::class, 'create'])->name('other-collections.create')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/partners',                           [OtherCollectionController::class, 'partners'])->name('other-collections.partners')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/default-credit-account',             [OtherCollectionController::class, 'defaultCreditAccount'])->name('other-collections.default-credit-account')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/credit-accounts',                    [OtherCollectionController::class, 'creditAccounts'])->name('other-collections.credit-accounts')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/destinations',                       [OtherCollectionController::class, 'destinations'])->name('other-collections.destinations')->middleware('module:accounting,view,other_collections');
        Route::get('/other-collections/safe-accounts',                      [OtherCollectionController::class, 'safeAccounts'])->name('other-collections.safe-accounts')->middleware('module:accounting,view,other_collections');
        Route::post('/other-collections',                                   [OtherCollectionController::class, 'store'])->name('other-collections.store')->middleware('module:accounting,post,other_collections');
        Route::get('/other-collections/{otherCollection}/edit',             [OtherCollectionController::class, 'edit'])->name('other-collections.edit')->middleware('module:accounting,view,other_collections');
        Route::put('/other-collections/{otherCollection}',                  [OtherCollectionController::class, 'update'])->name('other-collections.update')->middleware('module:accounting,post,other_collections');
        Route::delete('/other-collections/{otherCollection}',               [OtherCollectionController::class, 'destroy'])->name('other-collections.destroy')->middleware('module:accounting,edit,other_collections');
        Route::patch('/other-collections/{otherCollection}/set-to-draft',   [OtherCollectionController::class, 'setToDraft'])->name('other-collections.set-to-draft')->middleware('module:accounting,reverse,other_collections');
        Route::patch('/other-collections/{otherCollection}/cancel',         [OtherCollectionController::class, 'cancel'])->name('other-collections.cancel')->middleware('module:accounting,edit,other_collections');

        // ── Supplier Payment ─────────────────────────────────────────────
        Route::get('/supplier-payments',                                    [SupplierPaymentController::class, 'index'])->name('supplier-payments.index')->middleware('module:accounting,view,supplier_payments');
        Route::get('/supplier-payments/create',                             [SupplierPaymentController::class, 'create'])->name('supplier-payments.create')->middleware('module:accounting,view,supplier_payments');
        Route::get('/supplier-payments/open-bills',                         [SupplierPaymentController::class, 'openBills'])->name('supplier-payments.open-bills')->middleware('module:accounting,view,supplier_payments');
        Route::get('/supplier-payments/sources',                            [SupplierPaymentController::class, 'sources'])->name('supplier-payments.sources')->middleware('module:accounting,view,supplier_payments');
        Route::get('/supplier-payments/safe-accounts',                      [SupplierPaymentController::class, 'safeAccounts'])->name('supplier-payments.safe-accounts')->middleware('module:accounting,view,supplier_payments');
        Route::post('/supplier-payments',                                   [SupplierPaymentController::class, 'store'])->name('supplier-payments.store')->middleware('module:accounting,post,supplier_payments');
        Route::get('/supplier-payments/{supplierPayment}/edit',             [SupplierPaymentController::class, 'edit'])->name('supplier-payments.edit')->middleware('module:accounting,view,supplier_payments');
        Route::put('/supplier-payments/{supplierPayment}',                  [SupplierPaymentController::class, 'update'])->name('supplier-payments.update')->middleware('module:accounting,post,supplier_payments');
        Route::delete('/supplier-payments/{supplierPayment}',               [SupplierPaymentController::class, 'destroy'])->name('supplier-payments.destroy')->middleware('module:accounting,edit,supplier_payments');
        Route::patch('/supplier-payments/{supplierPayment}/set-to-draft',   [SupplierPaymentController::class, 'setToDraft'])->name('supplier-payments.set-to-draft')->middleware('module:accounting,reverse,supplier_payments');
        Route::patch('/supplier-payments/{supplierPayment}/cancel',         [SupplierPaymentController::class, 'cancel'])->name('supplier-payments.cancel')->middleware('module:accounting,edit,supplier_payments');
        Route::post('/supplier-payments/{supplierPayment}/allocate',        [SupplierPaymentController::class, 'allocate'])->name('supplier-payments.allocate')->middleware('module:accounting,create,supplier_payments');
        Route::delete('/supplier-payment-allocations/{allocation}',         [SupplierPaymentController::class, 'unallocate'])->name('supplier-payment-allocations.unallocate')->middleware('module:accounting,edit,supplier_payments');

        // ── Other Payment (August 2026 — see
        //    VeroCFO_Other_Payment_Design_v1_0.md). Money paid to a
        //    Shareholder / Employee / Sister Company / Customer (refund)
        //    / Other partner. Same posting-immediately + Set to Draft
        //    lifecycle as Supplier Payment/Other Collection, but no
        //    allocate/unallocate — no bill to match against. ──
        Route::get('/other-payments',                                    [OtherPaymentController::class, 'index'])->name('other-payments.index')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/create',                             [OtherPaymentController::class, 'create'])->name('other-payments.create')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/partners',                           [OtherPaymentController::class, 'partners'])->name('other-payments.partners')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/default-debit-account',              [OtherPaymentController::class, 'defaultDebitAccount'])->name('other-payments.default-debit-account')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/debit-accounts',                     [OtherPaymentController::class, 'debitAccounts'])->name('other-payments.debit-accounts')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/sources',                            [OtherPaymentController::class, 'sources'])->name('other-payments.sources')->middleware('module:accounting,view,other_payments');
        Route::get('/other-payments/safe-accounts',                      [OtherPaymentController::class, 'safeAccounts'])->name('other-payments.safe-accounts')->middleware('module:accounting,view,other_payments');
        Route::post('/other-payments',                                   [OtherPaymentController::class, 'store'])->name('other-payments.store')->middleware('module:accounting,post,other_payments');
        Route::get('/other-payments/{otherPayment}/edit',                [OtherPaymentController::class, 'edit'])->name('other-payments.edit')->middleware('module:accounting,view,other_payments');
        Route::put('/other-payments/{otherPayment}',                     [OtherPaymentController::class, 'update'])->name('other-payments.update')->middleware('module:accounting,post,other_payments');
        Route::delete('/other-payments/{otherPayment}',                  [OtherPaymentController::class, 'destroy'])->name('other-payments.destroy')->middleware('module:accounting,edit,other_payments');
        Route::patch('/other-payments/{otherPayment}/set-to-draft',      [OtherPaymentController::class, 'setToDraft'])->name('other-payments.set-to-draft')->middleware('module:accounting,reverse,other_payments');
        Route::patch('/other-payments/{otherPayment}/cancel',            [OtherPaymentController::class, 'cancel'])->name('other-payments.cancel')->middleware('module:accounting,edit,other_payments');

        // ── Cash Expense Payment (August 2026) — a direct expense
        //    payment with no Supplier Bill behind it. Same posting-
        //    immediately + Set to Draft lifecycle as Other Payment, but
        //    the debit side is always an Expense account and VAT/WHT
        //    are supported directly — see migration 000177. ──
        Route::get('/cash-expense-payments',                                    [CashExpensePaymentController::class, 'index'])->name('cash-expense-payments.index')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/create',                             [CashExpensePaymentController::class, 'create'])->name('cash-expense-payments.create')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/partners',                           [CashExpensePaymentController::class, 'partners'])->name('cash-expense-payments.partners')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/sources',                            [CashExpensePaymentController::class, 'sources'])->name('cash-expense-payments.sources')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/safe-accounts',                      [CashExpensePaymentController::class, 'safeAccounts'])->name('cash-expense-payments.safe-accounts')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/default-vat-rate',                   [CashExpensePaymentController::class, 'defaultVatRate'])->name('cash-expense-payments.default-vat-rate')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/customers',                         [CashExpensePaymentController::class, 'customers'])->name('cash-expense-payments.customers')->middleware('module:accounting,view,cash_expenses');
        Route::get('/cash-expense-payments/projects-for-customer/{partnerId}', [CashExpensePaymentController::class, 'projectsForCustomer'])->name('cash-expense-payments.projects-for-customer')->middleware('module:accounting,view,cash_expenses');
        Route::post('/cash-expense-payments',                                   [CashExpensePaymentController::class, 'store'])->name('cash-expense-payments.store')->middleware('module:accounting,post,cash_expenses');
        Route::get('/cash-expense-payments/{cashExpensePayment}/edit',          [CashExpensePaymentController::class, 'edit'])->name('cash-expense-payments.edit')->middleware('module:accounting,view,cash_expenses');
        Route::put('/cash-expense-payments/{cashExpensePayment}',               [CashExpensePaymentController::class, 'update'])->name('cash-expense-payments.update')->middleware('module:accounting,post,cash_expenses');
        Route::delete('/cash-expense-payments/{cashExpensePayment}',            [CashExpensePaymentController::class, 'destroy'])->name('cash-expense-payments.destroy')->middleware('module:accounting,edit,cash_expenses');
        Route::patch('/cash-expense-payments/{cashExpensePayment}/set-to-draft',[CashExpensePaymentController::class, 'setToDraft'])->name('cash-expense-payments.set-to-draft')->middleware('module:accounting,reverse,cash_expenses');
        Route::patch('/cash-expense-payments/{cashExpensePayment}/cancel',      [CashExpensePaymentController::class, 'cancel'])->name('cash-expense-payments.cancel')->middleware('module:accounting,edit,cash_expenses');

        // ── Cheques Payable ───────────────────────────────────────────────
        // AUDIT M4 FIX: guarded by the Supplier Payments right (was: any
        // accounting right). Undoing a payment needs the "reverse" right.
        Route::get('/cheques-payable',                                    [ChequePayableController::class, 'index'])->name('cheques-payable.index')->middleware('module:accounting,view,supplier_payments');
        Route::post('/cheques-payable/{chequePayable}/mark-as-paid',      [ChequePayableController::class, 'markAsPaid'])->name('cheques-payable.mark-as-paid')->middleware('module:accounting,create,supplier_payments');
        Route::post('/cheques-payable/{chequePayable}/reset-payment',     [ChequePayableController::class, 'resetPayment'])->name('cheques-payable.reset-payment')->middleware('module:accounting,reverse,supplier_payments');
        Route::post('/cheques-payable/{chequePayable}/void',              [ChequePayableController::class, 'voidCheque'])->name('cheques-payable.void')->middleware('module:accounting,reverse,supplier_payments');

        // ── Cheques Receivable (the cheque's own lifecycle beyond
        //    receipt — design doc Section 6) ──
        // AUDIT M4 FIX: guarded by the Customer Receipts right (was: any
        // accounting right). Undoing a collection needs the "reverse" right.
        Route::get('/cheques-receivable',                                        [ChequeReceivableController::class, 'index'])->name('cheques-receivable.index')->middleware('module:accounting,view,customer_receipts');
        Route::post('/cheques-receivable/{chequeReceivable}/send-for-collection',[ChequeReceivableController::class, 'sendForCollection'])->name('cheques-receivable.send-for-collection')->middleware('module:accounting,create,customer_receipts');
        Route::post('/cheques-receivable/{chequeReceivable}/return-to-safe',     [ChequeReceivableController::class, 'returnToSafe'])->name('cheques-receivable.return-to-safe')->middleware('module:accounting,create,customer_receipts');
        Route::post('/cheques-receivable/{chequeReceivable}/collect',            [ChequeReceivableController::class, 'collect'])->name('cheques-receivable.collect')->middleware('module:accounting,create,customer_receipts');
        Route::post('/cheques-receivable/{chequeReceivable}/undo-collect', [ChequeReceivableController::class, 'undoCollect'])->name('cheques-receivable.undo-collect')->middleware('module:accounting,reverse,customer_receipts');
        Route::post('/cheques-receivable/{chequeReceivable}/reject',             [ChequeReceivableController::class, 'reject'])->name('cheques-receivable.reject')->middleware('module:accounting,create,customer_receipts');

        // ── Inventory Engine — Internal Transfers ───────────────────
        // Static segment (create) before none needed here — no {transfer} param on this controller (create-only).
        Route::get('/transfers',         [StockTransferController::class, 'index'])->name('transfers.index')->middleware('module:accounting,view,internal_transfer');
        Route::get('/transfers/create',  [StockTransferController::class, 'create'])->name('transfers.create')->middleware('module:accounting,view,internal_transfer');
        Route::get('/transfers/products/search', [StockTransferController::class, 'searchProducts'])->name('transfers.products.search')->middleware('module:accounting,view,internal_transfer');
        Route::get('/transfers/availability',    [StockTransferController::class, 'availability'])->name('transfers.availability')->middleware('module:accounting,view,internal_transfer');
        Route::post('/transfers',        [StockTransferController::class, 'store'])->name('transfers.store')->middleware('module:accounting,create,internal_transfer');

        // ── Inventory Engine — Stock Adjustments ────────────────────
        // Nav entry added to HandleInertiaRequests alongside Inventory/Transfers.
        Route::get('/stock-adjustments',        [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index')->middleware('module:accounting,view,inventory_adjustment');
        Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('stock-adjustments.create')->middleware('module:accounting,view,inventory_adjustment');
        Route::post('/stock-adjustments',       [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store')->middleware('module:accounting,create,inventory_adjustment');
        Route::post('/stock-adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('stock-adjustments.approve')->middleware('module:accounting,approve,inventory_adjustment');
        Route::post('/stock-adjustments/{adjustment}/reject',  [StockAdjustmentController::class, 'reject'])->name('stock-adjustments.reject')->middleware('module:accounting,approve,inventory_adjustment');

        // ── Stock Reservations (new — July 2026) ───────────────────
        Route::get('/stock-reservations',                  [\App\Http\Controllers\Accounting\StockReservationController::class, 'index'])->name('stock-reservations.index')->middleware('module:accounting,view,inventory_movement');
        Route::post('/stock-reservations',                  [\App\Http\Controllers\Accounting\StockReservationController::class, 'store'])->name('stock-reservations.store')->middleware('module:accounting,create,inventory_movement');
        Route::patch('/stock-reservations/{stockReservation}/release', [\App\Http\Controllers\Accounting\StockReservationController::class, 'release'])->name('stock-reservations.release')->middleware('module:accounting,edit,inventory_movement');

        // ── Assembly / Kits (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md,
        // Section 3) — lightweight component-assembly, available to any
        // company with inventory, not just Manufacturing. Deliberately
        // placed here (Inventory group), not under Mass Production below.
        Route::get('/kit-recipes',                    [KitRecipeController::class, 'index'])->name('kit-recipes.index')->middleware('module:accounting,view,inventory_items');
        Route::get('/kit-recipes/create',              [KitRecipeController::class, 'create'])->name('kit-recipes.create')->middleware('module:accounting,view,inventory_items');
        Route::post('/kit-recipes',                    [KitRecipeController::class, 'store'])->name('kit-recipes.store')->middleware('module:accounting,create,inventory_items');
        Route::get('/kit-recipes/{recipe}/edit',        [KitRecipeController::class, 'edit'])->name('kit-recipes.edit')->middleware('module:accounting,view,inventory_items');
        Route::put('/kit-recipes/{recipe}',             [KitRecipeController::class, 'update'])->name('kit-recipes.update')->middleware('module:accounting,edit,inventory_items');
        Route::patch('/kit-recipes/{recipe}/toggle-active', [KitRecipeController::class, 'toggleActive'])->name('kit-recipes.toggle-active')->middleware('module:accounting,edit,inventory_items');

        Route::get('/kit-assemblies',                   [KitAssemblyController::class, 'index'])->name('kit-assemblies.index')->middleware('module:accounting,view,inventory_movement');
        Route::get('/kit-assemblies/create',             [KitAssemblyController::class, 'create'])->name('kit-assemblies.create')->middleware('module:accounting,view,inventory_movement');
        Route::post('/kit-assemblies',                   [KitAssemblyController::class, 'store'])->name('kit-assemblies.store')->middleware('module:accounting,create,inventory_movement');
        Route::patch('/kit-assemblies/{assembly}/reverse', [KitAssemblyController::class, 'reverse'])->name('kit-assemblies.reverse')->middleware('module:accounting,reverse,inventory_movement');

        // ── Mass Production — Bill of Materials ─────────────────────
        Route::get('/bom',                    [BillOfMaterialController::class, 'index'])->name('bom.index')->middleware('module:accounting,view,bill_of_materials');
        Route::get('/bom/create',             [BillOfMaterialController::class, 'create'])->name('bom.create')->middleware('module:accounting,view,bill_of_materials');
        Route::post('/bom',                   [BillOfMaterialController::class, 'store'])->name('bom.store')->middleware('module:accounting,create,bill_of_materials');
        Route::get('/bom/{bom}/edit',         [BillOfMaterialController::class, 'edit'])->name('bom.edit')->middleware('module:accounting,view,bill_of_materials');
        Route::put('/bom/{bom}',              [BillOfMaterialController::class, 'update'])->name('bom.update')->middleware('module:accounting,edit,bill_of_materials');
        Route::patch('/bom/{bom}/activate',   [BillOfMaterialController::class, 'activate'])->name('bom.activate')->middleware('module:accounting,edit,bill_of_materials');

        // ── Mass Production — Overhead Pools ─────────────────────────
        Route::get('/overhead-pools',            [OverheadPoolController::class, 'index'])->name('overhead-pools.index')->middleware('module:accounting,view,production_orders');
        Route::get('/overhead-pools/create',     [OverheadPoolController::class, 'create'])->name('overhead-pools.create')->middleware('module:accounting,view,production_orders');
        Route::post('/overhead-pools',           [OverheadPoolController::class, 'store'])->name('overhead-pools.store')->middleware('module:accounting,create,production_orders');
        Route::get('/overhead-pools/{pool}/edit', [OverheadPoolController::class, 'edit'])->name('overhead-pools.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/overhead-pools/{pool}',      [OverheadPoolController::class, 'update'])->name('overhead-pools.update')->middleware('module:accounting,edit,production_orders');

        // Labor Categories (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
        // Section 3) — replaces the single company-wide blended labor rate.
        Route::get('/labor-categories',              [LaborCategoryController::class, 'index'])->name('labor-categories.index')->middleware('module:accounting,view,production_orders');
        Route::get('/labor-categories/create',       [LaborCategoryController::class, 'create'])->name('labor-categories.create')->middleware('module:accounting,view,production_orders');
        Route::post('/labor-categories',             [LaborCategoryController::class, 'store'])->name('labor-categories.store')->middleware('module:accounting,create,production_orders');
        Route::get('/labor-categories/{category}/edit', [LaborCategoryController::class, 'edit'])->name('labor-categories.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/labor-categories/{category}',      [LaborCategoryController::class, 'update'])->name('labor-categories.update')->middleware('module:accounting,edit,production_orders');

        // Process & Machine register (VeroCFO_Manufacturing_Costing_
        // Redesign_v1_0.md, Section 4) — standalone master data,
        // consumed by BOM Routing / Job Order Routing once Sections
        // 5/6 are built.
        Route::get('/processes',              [ProcessController::class, 'index'])->name('processes.index')->middleware('module:accounting,view,production_orders');
        Route::get('/processes/create',       [ProcessController::class, 'create'])->name('processes.create')->middleware('module:accounting,view,production_orders');
        Route::post('/processes',             [ProcessController::class, 'store'])->name('processes.store')->middleware('module:accounting,create,production_orders');
        Route::get('/processes/{process}/edit', [ProcessController::class, 'edit'])->name('processes.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/processes/{process}',      [ProcessController::class, 'update'])->name('processes.update')->middleware('module:accounting,edit,production_orders');

        Route::get('/machines',              [MachineController::class, 'index'])->name('machines.index')->middleware('module:accounting,view,production_orders');
        Route::get('/machines/create',       [MachineController::class, 'create'])->name('machines.create')->middleware('module:accounting,view,production_orders');
        Route::post('/machines',             [MachineController::class, 'store'])->name('machines.store')->middleware('module:accounting,create,production_orders');
        Route::get('/machines/{machine}/edit', [MachineController::class, 'edit'])->name('machines.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/machines/{machine}',      [MachineController::class, 'update'])->name('machines.update')->middleware('module:accounting,edit,production_orders');

        // ── Mass Production — Standard Rates ────────────────────────
        Route::get('/standard-rates',                       [StandardRateController::class, 'index'])->name('standard-rates.index')->middleware('module:accounting,view,production_orders');
        Route::get('/standard-rates/create',                [StandardRateController::class, 'create'])->name('standard-rates.create')->middleware('module:accounting,view,production_orders');
        Route::post('/standard-rates',                      [StandardRateController::class, 'store'])->name('standard-rates.store')->middleware('module:accounting,create,production_orders');
        Route::get('/standard-rates/{rate}/edit',           [StandardRateController::class, 'edit'])->name('standard-rates.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/standard-rates/{rate}',                [StandardRateController::class, 'update'])->name('standard-rates.update')->middleware('module:accounting,edit,production_orders');
        Route::patch('/standard-rates/{rate}/activate',     [StandardRateController::class, 'activate'])->name('standard-rates.activate')->middleware('module:accounting,edit,production_orders');
        Route::post('/standard-rates/{rate}/monthly-override', [StandardRateController::class, 'storeMonthlyOverride'])->name('standard-rates.monthly-override')->middleware('module:accounting,create,production_orders');

        // ── Mass Production — Production Orders ─────────────────────
        Route::get('/production',                            [ProductionOrderController::class, 'index'])->name('production.index')->middleware('module:accounting,view,production_orders');
        Route::get('/production/create',                     [ProductionOrderController::class, 'create'])->name('production.create')->middleware('module:accounting,view,production_orders');
        Route::post('/production',                           [ProductionOrderController::class, 'store'])->name('production.store')->middleware('module:accounting,create,production_orders');
        Route::get('/production/{order}',                    [ProductionOrderController::class, 'show'])->name('production.show')->middleware('module:accounting,view,production_orders');
        Route::patch('/production/{order}/start',            [ProductionOrderController::class, 'start'])->name('production.start')->middleware('module:accounting,edit,production_orders');
        Route::patch('/production/{order}/complete',         [ProductionOrderController::class, 'complete'])->name('production.complete')->middleware('module:accounting,edit,production_orders');
        Route::patch('/production/{order}/set-to-draft',      [ProductionOrderController::class, 'setToDraft'])->name('production.set-to-draft')->middleware('module:accounting,reverse,production_orders');
        Route::post('/production/{order}/consume-material',  [ProductionOrderController::class, 'consumeMaterial'])->name('production.consume-material')->middleware('module:accounting,create,production_orders');
        Route::post('/production/{order}/record-labor',      [ProductionOrderController::class, 'recordLabor'])->name('production.record-labor')->middleware('module:accounting,create,production_orders');
        Route::post('/production/{order}/record-overhead',   [ProductionOrderController::class, 'recordOverhead'])->name('production.record-overhead')->middleware('module:accounting,create,production_orders');

        // ── Job Order Production — templates (optional, unversioned) ──
        Route::get('/job-order-templates',            [JobOrderTemplateController::class, 'index'])->name('job-order-templates.index')->middleware('module:accounting,view,production_orders');
        Route::get('/job-order-templates/create',     [JobOrderTemplateController::class, 'create'])->name('job-order-templates.create')->middleware('module:accounting,view,production_orders');
        Route::post('/job-order-templates',           [JobOrderTemplateController::class, 'store'])->name('job-order-templates.store')->middleware('module:accounting,create,production_orders');
        Route::get('/job-order-templates/{template}/edit', [JobOrderTemplateController::class, 'edit'])->name('job-order-templates.edit')->middleware('module:accounting,view,production_orders');
        Route::put('/job-order-templates/{template}', [JobOrderTemplateController::class, 'update'])->name('job-order-templates.update')->middleware('module:accounting,edit,production_orders');

        // ── Job Order Production — the job itself ────────────────────
        Route::get('/job-orders',                            [JobOrderController::class, 'index'])->name('job-orders.index')->middleware('module:accounting,view,production_orders');
        Route::get('/job-orders/create',                     [JobOrderController::class, 'create'])->name('job-orders.create')->middleware('module:accounting,view,production_orders');
        Route::post('/job-orders',                           [JobOrderController::class, 'store'])->name('job-orders.store')->middleware('module:accounting,create,production_orders');
        Route::get('/job-orders/{job}',                      [JobOrderController::class, 'show'])->name('job-orders.show')->middleware('module:accounting,view,production_orders');
        Route::patch('/job-orders/{job}/start',              [JobOrderController::class, 'start'])->name('job-orders.start')->middleware('module:accounting,edit,production_orders');
        Route::patch('/job-orders/{job}/complete',           [JobOrderController::class, 'complete'])->name('job-orders.complete')->middleware('module:accounting,edit,production_orders');
        Route::patch('/job-orders/{job}/set-to-draft',        [JobOrderController::class, 'setToDraft'])->name('job-orders.set-to-draft')->middleware('module:accounting,reverse,production_orders');
        Route::post('/job-orders/{job}/consume-material',    [JobOrderController::class, 'consumeMaterial'])->name('job-orders.consume-material')->middleware('module:accounting,create,production_orders');
        Route::post('/job-orders/{job}/record-labor',        [JobOrderController::class, 'recordLabor'])->name('job-orders.record-labor')->middleware('module:accounting,create,production_orders');
        Route::post('/job-orders/{job}/record-overhead',     [JobOrderController::class, 'recordOverhead'])->name('job-orders.record-overhead')->middleware('module:accounting,create,production_orders');

        // Job Order Routing (Section 6) — optional Piece breakdown.
        Route::post('/job-orders/{job}/pieces',                                     [JobOrderController::class, 'storePiece'])->name('job-orders.pieces.store')->middleware('module:accounting,create,production_orders');
        Route::post('/job-orders/{job}/pieces/{piece}/operations',                  [JobOrderController::class, 'storeOperation'])->name('job-orders.pieces.operations.store')->middleware('module:accounting,create,production_orders');
        Route::post('/job-orders/{job}/pieces/{piece}/operations/{operation}/log',  [JobOrderController::class, 'logOperationCost'])->name('job-orders.pieces.operations.log')->middleware('module:accounting,create,production_orders');
        Route::patch('/job-orders/{job}/pieces/{piece}/complete',                   [JobOrderController::class, 'completePiece'])->name('job-orders.pieces.complete')->middleware('module:accounting,edit,production_orders');

        // ── Manufacturing Variance Closing ──────────────────────────
        // AUDIT M4 FIX (Sept 2026): these three routes had been placed
        // inside the HR group by mistake, so (a) closing a variance —
        // which posts to the General Ledger — was guarded by the HR
        // "create" right, and (b) their names came out as
        // hr.variance-closing.* while the menu, the screens and the
        // controller all call accounting.variance-closing.*. They now
        // live here, under Accounting → Production Orders rights.
        Route::get('/variance-closing',          [VarianceClosingController::class, 'index'])->name('variance-closing.index')->middleware('module:accounting,view,production_orders');
        Route::post('/variance-closing/preview', [VarianceClosingController::class, 'preview'])->name('variance-closing.preview')->middleware('module:accounting,create,production_orders');
        Route::post('/variance-closing',          [VarianceClosingController::class, 'store'])->name('variance-closing.store')->middleware('module:accounting,create,production_orders');
        Route::post('/variance-closing/{closing}/reverse', [VarianceClosingController::class, 'reverse'])->name('variance-closing.reverse')->middleware('module:accounting,reverse,production_orders');
    });

    // ── HR — Payroll (2026-09). Employees/Payroll are real now;
    // Advances/Leave/Expense Claims remain stubs — Advances already
    // works via Other Payment (147 Advances to Employees) without a
    // dedicated screen yet; Leave/Expense Claims are later slices.
    Route::prefix('hr')->name('hr.')->group(function () {
        // ── Dashboard (September 2026) — replaces the old Dashboard-
        // placeholder stub. See HrDashboardController header.
        Route::get('/dashboard',      [\App\Http\Controllers\Accounting\HrDashboardController::class, 'index'])->name('dashboard')->middleware('module:hr,view,payroll');

        Route::get('/employees',                          [EmployeePayrollProfileController::class, 'index'])->name('employees.index')->middleware('module:hr,view,employees');
        Route::get('/employees/create',                    [EmployeePayrollProfileController::class, 'create'])->name('employees.create')->middleware('module:hr,view,employees');
        Route::post('/employees',                          [EmployeePayrollProfileController::class, 'store'])->name('employees.store')->middleware('module:hr,create,employees');
        Route::get('/employees/{profile}/edit',             [EmployeePayrollProfileController::class, 'edit'])->name('employees.edit')->middleware('module:hr,view,employees');
        Route::put('/employees/{profile}',                  [EmployeePayrollProfileController::class, 'update'])->name('employees.update')->middleware('module:hr,edit,employees');
        Route::post('/employees/{profile}/salary-revisions', [EmployeePayrollProfileController::class, 'addSalaryRevision'])->name('employees.salary-revisions.store')->middleware('module:hr,create,employees');
        Route::post('/employees/{profile}/backdate-start', [EmployeePayrollProfileController::class, 'backdateStart'])->name('employees.backdate-start')->middleware('module:hr,edit,employees');
        Route::post('/employees/{profile}/classification-revisions', [EmployeePayrollProfileController::class, 'addClassificationRevision'])->name('employees.classification-revisions.store')->middleware('module:hr,create,employees');
        Route::post('/employees/{profile}/manufacturing-role-revisions', [EmployeePayrollProfileController::class, 'addManufacturingRoleRevision'])->name('employees.manufacturing-role-revisions.store')->middleware('module:hr,create,employees');
        Route::patch('/employees/{profile}/toggle-active',  [EmployeePayrollProfileController::class, 'toggleActive'])->name('employees.toggle-active')->middleware('module:hr,edit,employees');

        Route::get('/payroll',                              [PayrollRunController::class, 'index'])->name('payroll.index')->middleware('module:hr,view,payroll');
        Route::get('/payroll/create',                       [PayrollRunController::class, 'create'])->name('payroll.create')->middleware('module:hr,view,payroll');
        Route::post('/payroll',                              [PayrollRunController::class, 'store'])->name('payroll.store')->middleware('module:hr,create,payroll');
        Route::get('/payroll/{run}',                        [PayrollRunController::class, 'show'])->name('payroll.show')->middleware('module:hr,view,payroll');
        Route::patch('/payroll/{run}/post',                  [PayrollRunController::class, 'post'])->name('payroll.post')->middleware('module:hr,post,payroll');
        Route::patch('/payroll/{run}/set-to-draft',          [PayrollRunController::class, 'setToDraft'])->name('payroll.set-to-draft')->middleware('module:hr,reverse,payroll');
        Route::post('/payroll/{run}/pay',                    [PayrollRunController::class, 'pay'])->name('payroll.pay')->middleware('module:hr,post,payroll');
        Route::patch('/payroll/{run}/reverse-payment',       [PayrollRunController::class, 'reversePayment'])->name('payroll.reverse-payment')->middleware('module:hr,reverse,payroll');
        Route::get('/payroll-lines/{line}/payslip',          [PayrollRunController::class, 'payslip'])->name('payroll.lines.payslip')->middleware('module:hr,view,payroll');
        Route::post('/eosb-payouts',                         [\App\Http\Controllers\Accounting\EosbPayoutController::class, 'store'])->name('eosb-payouts.store')->middleware('module:hr,post,payroll');
        Route::patch('/eosb-payouts/{payout}/reverse',       [\App\Http\Controllers\Accounting\EosbPayoutController::class, 'reverse'])->name('eosb-payouts.reverse')->middleware('module:hr,reverse,payroll');
        Route::post('/payroll-lines/{line}/line-items',      [PayrollRunController::class, 'addLineItem'])->name('payroll.lines.add-item')->middleware('module:hr,create,payroll');
        Route::delete('/payroll-line-items/{item}',          [PayrollRunController::class, 'removeLineItem'])->name('payroll.line-items.remove')->middleware('module:hr,edit,payroll');
        Route::patch('/payroll-lines/{line}/manual-amounts',  [PayrollRunController::class, 'setManualAmounts'])->name('payroll.lines.manual-amounts')->middleware('module:hr,edit,payroll');
        Route::get('/payroll-lines/{line}/outstanding-advance', [PayrollRunController::class, 'outstandingAdvance'])->name('payroll.lines.outstanding-advance')->middleware('module:hr,view,payroll');

        Route::get('/deduction-reasons',                     [DeductionReasonController::class, 'index'])->name('deduction-reasons.index')->middleware('module:hr,view,payroll');
        Route::post('/deduction-reasons',                     [DeductionReasonController::class, 'store'])->name('deduction-reasons.store')->middleware('module:hr,create,payroll');
        Route::put('/deduction-reasons/{reason}',             [DeductionReasonController::class, 'update'])->name('deduction-reasons.update')->middleware('module:hr,edit,payroll');
        Route::patch('/deduction-reasons/{reason}/toggle-active', [DeductionReasonController::class, 'toggleActive'])->name('deduction-reasons.toggle-active')->middleware('module:hr,edit,payroll');
        Route::delete('/deduction-reasons/{reason}',          [DeductionReasonController::class, 'destroy'])->name('deduction-reasons.destroy')->middleware('module:hr,edit,payroll');

        // Advances & Settlements (2026-09) — replaces the earlier stub.
        Route::get('/advances',                   [AdvancesSettlementsController::class, 'index'])->name('advances.index')->middleware('module:hr,view,advances_settlements');
        Route::get('/advances/{partner}',          [AdvancesSettlementsController::class, 'show'])->name('advances.show')->middleware('module:hr,view,advances_settlements');

        Route::get('/government-remittance', [GovernmentRemittanceController::class, 'index'])->name('government-remittance.index')->middleware('module:hr,view,payroll');

        Route::get('/eosb-provision',                       [EosbProvisionRunController::class, 'index'])->name('eosb-provision.index')->middleware('module:hr,view,payroll');
        Route::get('/eosb-provision/create',                 [EosbProvisionRunController::class, 'create'])->name('eosb-provision.create')->middleware('module:hr,view,payroll');
        Route::post('/eosb-provision',                       [EosbProvisionRunController::class, 'store'])->name('eosb-provision.store')->middleware('module:hr,create,payroll');
        Route::get('/eosb-provision/{run}',                  [EosbProvisionRunController::class, 'show'])->name('eosb-provision.show')->middleware('module:hr,view,payroll');
        Route::patch('/eosb-provision/{run}/post',           [EosbProvisionRunController::class, 'post'])->name('eosb-provision.post')->middleware('module:hr,post,payroll');
        Route::patch('/eosb-provision/{run}/set-to-draft',   [EosbProvisionRunController::class, 'setToDraft'])->name('eosb-provision.set-to-draft')->middleware('module:hr,reverse,payroll');

        // ── Leave Management (2026-09) — replaces the earlier stub.
        // No accounting of its own: paid leave costs nothing extra and
        // unpaid leave reaches money through Payroll's existing
        // earned_reduction deduction. See LeaveRequestService's header.
        Route::get('/leave',                          [LeaveRequestController::class, 'index'])->name('leave.index')->middleware('module:hr,view,leave_management');
        Route::get('/leave/create',                    [LeaveRequestController::class, 'create'])->name('leave.create')->middleware('module:hr,create,leave_management');
        Route::post('/leave',                          [LeaveRequestController::class, 'store'])->name('leave.store')->middleware('module:hr,create,leave_management');
        Route::get('/leave/{leave}/edit',              [LeaveRequestController::class, 'edit'])->name('leave.edit')->middleware('module:hr,edit,leave_management');
        Route::put('/leave/{leave}',                   [LeaveRequestController::class, 'update'])->name('leave.update')->middleware('module:hr,edit,leave_management');
        Route::delete('/leave/{leave}',                [LeaveRequestController::class, 'destroy'])->name('leave.destroy')->middleware('module:hr,edit,leave_management');
        Route::patch('/leave/{leave}/submit',          [LeaveRequestController::class, 'submit'])->name('leave.submit')->middleware('module:hr,edit,leave_management');
        Route::patch('/leave/{leave}/approve',         [LeaveRequestController::class, 'approve'])->name('leave.approve')->middleware('module:hr,edit,leave_management');
        Route::patch('/leave/{leave}/reject',          [LeaveRequestController::class, 'reject'])->name('leave.reject')->middleware('module:hr,edit,leave_management');
        Route::patch('/leave/{leave}/cancel',          [LeaveRequestController::class, 'cancel'])->name('leave.cancel')->middleware('module:hr,edit,leave_management');
        Route::get('/leave-balances/{partner}',        [LeaveRequestController::class, 'balances'])->name('leave.balances')->middleware('module:hr,view,leave_management');

        Route::get('/leave-holidays',                  [LeaveHolidayController::class, 'index'])->name('leave-holidays.index')->middleware('module:hr,view,leave_management');
        Route::post('/leave-holidays',                 [LeaveHolidayController::class, 'store'])->name('leave-holidays.store')->middleware('module:hr,edit,leave_management');
        Route::put('/leave-holidays/weekend',          [LeaveHolidayController::class, 'updateWeekend'])->name('leave-holidays.weekend')->middleware('module:hr,edit,leave_management');
        Route::delete('/leave-holidays/{holiday}',     [LeaveHolidayController::class, 'destroy'])->name('leave-holidays.destroy')->middleware('module:hr,edit,leave_management');
        Route::get('/leave-types',                     [LeaveTypeController::class, 'index'])->name('leave-types.index')->middleware('module:hr,view,leave_management');
        Route::post('/leave-types',                    [LeaveTypeController::class, 'store'])->name('leave-types.store')->middleware('module:hr,create,leave_management');
        Route::put('/leave-types/{type}',              [LeaveTypeController::class, 'update'])->name('leave-types.update')->middleware('module:hr,edit,leave_management');
        Route::patch('/leave-types/{type}/toggle-active', [LeaveTypeController::class, 'toggleActive'])->name('leave-types.toggle-active')->middleware('module:hr,edit,leave_management');
        Route::delete('/leave-types/{type}',           [LeaveTypeController::class, 'destroy'])->name('leave-types.destroy')->middleware('module:hr,edit,leave_management');

        // ── Expense Claims (2026-09) — replaces the earlier stub.
        // The approval/liability half that Other Payment's existing
        // 'reimbursement' type never had. Posts on approve and again
        // on pay — see ExpenseClaimService's header.
        Route::get('/expense-claims',                    [ExpenseClaimController::class, 'index'])->name('expense-claims.index')->middleware('module:hr,view,expense_claims');
        Route::get('/expense-claims/create',             [ExpenseClaimController::class, 'create'])->name('expense-claims.create')->middleware('module:hr,create,expense_claims');
        Route::post('/expense-claims',                   [ExpenseClaimController::class, 'store'])->name('expense-claims.store')->middleware('module:hr,create,expense_claims');
        Route::get('/expense-claims/{claim}',            [ExpenseClaimController::class, 'show'])->name('expense-claims.show')->middleware('module:hr,view,expense_claims');
        Route::get('/expense-claims/{claim}/edit',       [ExpenseClaimController::class, 'edit'])->name('expense-claims.edit')->middleware('module:hr,edit,expense_claims');
        Route::put('/expense-claims/{claim}',            [ExpenseClaimController::class, 'update'])->name('expense-claims.update')->middleware('module:hr,edit,expense_claims');
        Route::delete('/expense-claims/{claim}',         [ExpenseClaimController::class, 'destroy'])->name('expense-claims.destroy')->middleware('module:hr,edit,expense_claims');
        Route::patch('/expense-claims/{claim}/submit',   [ExpenseClaimController::class, 'submit'])->name('expense-claims.submit')->middleware('module:hr,edit,expense_claims');
        Route::patch('/expense-claims/{claim}/approve',  [ExpenseClaimController::class, 'approve'])->name('expense-claims.approve')->middleware('module:hr,approve,expense_claims');
        Route::patch('/expense-claims/{claim}/reject',   [ExpenseClaimController::class, 'reject'])->name('expense-claims.reject')->middleware('module:hr,edit,expense_claims');
        Route::patch('/expense-claims/{claim}/cancel',   [ExpenseClaimController::class, 'cancel'])->name('expense-claims.cancel')->middleware('module:hr,edit,expense_claims');
        Route::patch('/expense-claims/{claim}/set-to-draft', [ExpenseClaimController::class, 'setToDraft'])->name('expense-claims.set-to-draft')->middleware('module:hr,reverse,expense_claims');
        Route::patch('/expense-claims/{claim}/mark-paid', [ExpenseClaimController::class, 'markPaid'])->name('expense-claims.mark-paid')->middleware('module:hr,edit,expense_claims');
    });

    // ── Analytics (stub) ─────────────────────────────────────────
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/dashboard',     ComingSoonController::class)->name('dashboard')->middleware('module:analytics,view,cfo_dashboard');
        Route::get('/sales',                  [SalesAnalysisController::class, 'dashboard'])->name('sales.index')->middleware('module:analytics,view,sales_analysis');
        Route::get('/sales/data',             [SalesAnalysisController::class, 'dashboardData'])->name('sales.data')->middleware('module:analytics,view,sales_analysis');
        Route::get('/sales/achiever-takeaway', [SalesAnalysisController::class, 'achieverTakeaway'])->name('sales.achiever-takeaway')->middleware('module:analytics,view,sales_analysis');
        Route::post('/sales/notes',            [SalesAnalysisController::class, 'storeNote'])->name('sales.notes.store')->middleware('module:analytics,create,sales_analysis');
        Route::put('/sales/notes/{note}',      [SalesAnalysisController::class, 'updateNote'])->name('sales.notes.update')->middleware('module:analytics,edit,sales_analysis');
        Route::delete('/sales/notes/{note}',   [SalesAnalysisController::class, 'destroyNote'])->name('sales.notes.destroy')->middleware('module:analytics,reverse,sales_analysis');
        Route::get('/sales/reports',          [SalesAnalysisController::class, 'reportsPage'])->name('sales.reports')->middleware('module:analytics,view,sales_analysis');
        Route::post('/sales/reports/run',     [SalesAnalysisController::class, 'runReport'])->name('sales.reports.run')->middleware('module:analytics,view,sales_analysis');
        Route::get('/sales/reports/export',   [SalesAnalysisController::class, 'exportReport'])->name('sales.reports.export')->middleware('module:analytics,view,sales_analysis');
        Route::get('/expenses',                        [ExpenseAnalysisController::class, 'dashboard'])->name('expenses.index')->middleware('module:analytics,view,expense_analysis');
        Route::get('/expenses/data',                    [ExpenseAnalysisController::class, 'dashboardData'])->name('expenses.data')->middleware('module:analytics,view,expense_analysis');
        Route::post('/expenses/notes',                  [ExpenseAnalysisController::class, 'storeNote'])->name('expenses.notes.store')->middleware('module:analytics,create,expense_analysis');
        Route::put('/expenses/notes/{note}',            [ExpenseAnalysisController::class, 'updateNote'])->name('expenses.notes.update')->middleware('module:analytics,edit,expense_analysis');
        Route::delete('/expenses/notes/{note}',         [ExpenseAnalysisController::class, 'destroyNote'])->name('expenses.notes.destroy')->middleware('module:analytics,reverse,expense_analysis');
        Route::get('/expenses/reports',                 [ExpenseAnalysisController::class, 'reportsPage'])->name('expenses.reports')->middleware('module:analytics,view,expense_analysis');
        Route::post('/expenses/reports/run',            [ExpenseAnalysisController::class, 'runReport'])->name('expenses.reports.run')->middleware('module:analytics,view,expense_analysis');
        Route::get('/expenses/reports/export',          [ExpenseAnalysisController::class, 'exportReport'])->name('expenses.reports.export')->middleware('module:analytics,view,expense_analysis');
        Route::get('/expenses/breakeven',                [ExpenseAnalysisController::class, 'breakevenPage'])->name('expenses.breakeven')->middleware('module:analytics,view,expense_analysis');
        Route::post('/expenses/breakeven/calculate',      [ExpenseAnalysisController::class, 'breakevenCalculate'])->name('expenses.breakeven.calculate')->middleware('module:analytics,view,expense_analysis');
        Route::get('/profitability', ComingSoonController::class)->name('profitability.index')->middleware('module:analytics,view,profitability');
        Route::get('/ratios',        ComingSoonController::class)->name('ratios.index')->middleware('module:analytics,view,financial_ratios');
    });

    // ── Annual Plan ───────────────────────────────────────────────
    Route::prefix('planning')->name('planning.')->group(function () {
        Route::get('/dashboard',    ComingSoonController::class)->name('dashboard')->middleware('module:planning,view');

        Route::prefix('annual-plan')->name('annual-plan.')->group(function () {
            Route::get('/',                                       [AnnualPlanController::class, 'index'])->name('index')->middleware('module:planning,view,annual_plan');
            Route::get('/create',                                 [AnnualPlanController::class, 'create'])->name('create')->middleware('module:planning,create,annual_plan');
            Route::post('/',                                      [AnnualPlanController::class, 'store'])->name('store')->middleware('module:planning,create,annual_plan');

            // Static segments before {plan} — same ordering rule as every other module in this file.
            Route::get('/{plan}/eligible-fixed-assets',            [AnnualPlanController::class, 'eligibleFixedAssets'])->name('eligible-fixed-assets')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/opening-balance',                  [AnnualPlanController::class, 'openingBalancePage'])->name('opening-balance')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/results',                          [AnnualPlanController::class, 'resultsPage'])->name('results')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/sales',                            [AnnualPlanController::class, 'salesPage'])->name('sales')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/cogs',                             [AnnualPlanController::class, 'cogsPage'])->name('cogs')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/manpower',                         [AnnualPlanController::class, 'manpowerPage'])->name('manpower')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/expenses',                         [AnnualPlanController::class, 'expensesPage'])->name('expenses')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/fixed-assets',                     [AnnualPlanController::class, 'fixedAssetsPage'])->name('fixed-assets')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::post('/{plan}/manpower/snapshot',               [AnnualPlanController::class, 'snapshotManpower'])->name('manpower.snapshot')->whereNumber('plan')->middleware('module:planning,create,annual_plan');
            Route::post('/{plan}/manpower/titles',                 [AnnualPlanController::class, 'storeManpowerTitle'])->name('manpower.titles.store')->whereNumber('plan')->middleware('module:planning,create,annual_plan');
            Route::post('/{plan}/products',                        [AnnualPlanController::class, 'storeProduct'])->name('products.store')->whereNumber('plan')->middleware('module:planning,create,annual_plan');
            Route::get('/{plan}/products/search',                  [AnnualPlanController::class, 'searchProducts'])->name('products.search')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::get('/{plan}/products/{product}/preview',       [AnnualPlanController::class, 'previewProduct'])->name('products.preview')->whereNumber('plan')->whereNumber('product')->middleware('module:planning,view,annual_plan');
            Route::post('/{plan}/expenses',                        [AnnualPlanController::class, 'storeExpenseLine'])->name('expenses.store')->whereNumber('plan')->middleware('module:planning,create,annual_plan');
            Route::post('/{plan}/fixed-assets/planned',            [AnnualPlanController::class, 'storePlannedFixedAsset'])->name('fixed-assets.planned.store')->whereNumber('plan')->middleware('module:planning,create,annual_plan');
            Route::post('/{plan}/fixed-assets/existing/{fixedAsset}', [AnnualPlanController::class, 'storeExistingFixedAsset'])->name('fixed-assets.existing.store')->whereNumber('plan')->middleware('module:planning,create,annual_plan');

            Route::get('/{plan}/edit',                            [AnnualPlanController::class, 'edit'])->name('edit')->whereNumber('plan')->middleware('module:planning,view,annual_plan');
            Route::put('/{plan}',                                 [AnnualPlanController::class, 'update'])->name('update')->whereNumber('plan')->middleware('module:planning,edit,annual_plan');
            Route::delete('/{plan}',                              [AnnualPlanController::class, 'destroy'])->name('destroy')->whereNumber('plan')->middleware('module:planning,reverse,annual_plan');

            Route::delete('/products/{planProduct}',              [AnnualPlanController::class, 'destroyProduct'])->name('products.destroy')->middleware('module:planning,reverse,annual_plan');
            Route::post('/products/{planProduct}/sales-lines',    [AnnualPlanController::class, 'storeSalesLines'])->name('products.sales-lines.store')->middleware('module:planning,create,annual_plan');
            Route::post('/products/{planProduct}/collection-tranches', [AnnualPlanController::class, 'storeCollectionTranches'])->name('products.collection-tranches.store')->middleware('module:planning,create,annual_plan');
            Route::post('/products/{planProduct}/cogs-assumption', [AnnualPlanController::class, 'storeCogsAssumption'])->name('products.cogs-assumption.store')->middleware('module:planning,create,annual_plan');

            Route::post('/manpower-titles/{planManpowerTitle}/hiring', [AnnualPlanController::class, 'storeManpowerHiring'])->name('manpower.hiring.store')->middleware('module:planning,create,annual_plan');
            Route::delete('/manpower-titles/{planManpowerTitle}', [AnnualPlanController::class, 'destroyManpowerTitle'])->name('manpower.titles.destroy')->middleware('module:planning,reverse,annual_plan');

            Route::put('/expenses/{planExpenseLine}',             [AnnualPlanController::class, 'updateExpenseLine'])->name('expenses.update')->middleware('module:planning,edit,annual_plan');
            Route::delete('/expenses/{planExpenseLine}',          [AnnualPlanController::class, 'destroyExpenseLine'])->name('expenses.destroy')->middleware('module:planning,reverse,annual_plan');

            Route::delete('/fixed-assets/{planFixedAsset}',       [AnnualPlanController::class, 'destroyFixedAsset'])->name('fixed-assets.destroy')->middleware('module:planning,reverse,annual_plan');
        });

        Route::get('/bva',          ComingSoonController::class)->name('bva.index')->middleware('module:planning,view,budget_vs_actual');
        Route::get('/scenarios',    ComingSoonController::class)->name('scenarios.index')->middleware('module:planning,view,scenario_modeling');
    });

    // ── Business Plan & Valuation ─────────────────────────────────
    Route::prefix('valuation')->name('valuation.')->group(function () {
        Route::get('/dashboard',     ComingSoonController::class)->name('dashboard')->middleware('module:valuation,view');

        Route::prefix('business-plan')->name('business-plan.')->group(function () {
            Route::get('/',                                       [BusinessPlanController::class, 'index'])->name('index')->middleware('module:valuation,view,business_plan');
            Route::get('/create',                                 [BusinessPlanController::class, 'create'])->name('create')->middleware('module:valuation,create,business_plan');
            Route::post('/',                                      [BusinessPlanController::class, 'store'])->name('store')->middleware('module:valuation,create,business_plan');

            // Static segments before {plan} — same ordering rule as every other module in this file.
            Route::get('/{plan}/eligible-fixed-assets',            [BusinessPlanController::class, 'eligibleFixedAssets'])->name('eligible-fixed-assets')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/opening-balance',                  [BusinessPlanController::class, 'openingBalancePage'])->name('opening-balance')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/results',                          [BusinessPlanController::class, 'resultsPage'])->name('results')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/sales',                            [BusinessPlanController::class, 'salesPage'])->name('sales')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/cogs',                             [BusinessPlanController::class, 'cogsPage'])->name('cogs')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/manpower',                         [BusinessPlanController::class, 'manpowerPage'])->name('manpower')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/expenses',                         [BusinessPlanController::class, 'expensesPage'])->name('expenses')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/fixed-assets',                     [BusinessPlanController::class, 'fixedAssetsPage'])->name('fixed-assets')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::post('/{plan}/manpower/snapshot',               [BusinessPlanController::class, 'snapshotManpower'])->name('manpower.snapshot')->whereNumber('plan')->middleware('module:valuation,create,business_plan');
            Route::post('/{plan}/manpower/titles',                 [BusinessPlanController::class, 'storeManpowerTitle'])->name('manpower.titles.store')->whereNumber('plan')->middleware('module:valuation,create,business_plan');
            Route::post('/{plan}/products',                        [BusinessPlanController::class, 'storeProduct'])->name('products.store')->whereNumber('plan')->middleware('module:valuation,create,business_plan');
            Route::get('/{plan}/products/search',                  [BusinessPlanController::class, 'searchProducts'])->name('products.search')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::get('/{plan}/products/{product}/preview',       [BusinessPlanController::class, 'previewProduct'])->name('products.preview')->whereNumber('plan')->whereNumber('product')->middleware('module:valuation,view,business_plan');
            Route::post('/{plan}/expenses',                        [BusinessPlanController::class, 'storeExpenseLine'])->name('expenses.store')->whereNumber('plan')->middleware('module:valuation,create,business_plan');
            Route::post('/{plan}/fixed-assets/planned',            [BusinessPlanController::class, 'storePlannedFixedAsset'])->name('fixed-assets.planned.store')->whereNumber('plan')->middleware('module:valuation,create,business_plan');
            Route::post('/{plan}/fixed-assets/existing/{fixedAsset}', [BusinessPlanController::class, 'storeExistingFixedAsset'])->name('fixed-assets.existing.store')->whereNumber('plan')->middleware('module:valuation,create,business_plan');

            Route::get('/{plan}/edit',                            [BusinessPlanController::class, 'edit'])->name('edit')->whereNumber('plan')->middleware('module:valuation,view,business_plan');
            Route::put('/{plan}',                                 [BusinessPlanController::class, 'update'])->name('update')->whereNumber('plan')->middleware('module:valuation,edit,business_plan');
            Route::delete('/{plan}',                              [BusinessPlanController::class, 'destroy'])->name('destroy')->whereNumber('plan')->middleware('module:valuation,reverse,business_plan');

            Route::delete('/products/{planProduct}',              [BusinessPlanController::class, 'destroyProduct'])->name('products.destroy')->middleware('module:valuation,reverse,business_plan');
            Route::post('/products/{planProduct}/sales-lines',    [BusinessPlanController::class, 'storeSalesLines'])->name('products.sales-lines.store')->middleware('module:valuation,create,business_plan');
            Route::post('/products/{planProduct}/collection-tranches', [BusinessPlanController::class, 'storeCollectionTranches'])->name('products.collection-tranches.store')->middleware('module:valuation,create,business_plan');
            Route::post('/products/{planProduct}/cogs-assumption', [BusinessPlanController::class, 'storeCogsAssumption'])->name('products.cogs-assumption.store')->middleware('module:valuation,create,business_plan');

            Route::post('/manpower-titles/{planManpowerTitle}/hiring', [BusinessPlanController::class, 'storeManpowerHiring'])->name('manpower.hiring.store')->middleware('module:valuation,create,business_plan');
            Route::delete('/manpower-titles/{planManpowerTitle}', [BusinessPlanController::class, 'destroyManpowerTitle'])->name('manpower.titles.destroy')->middleware('module:valuation,reverse,business_plan');

            Route::put('/expenses/{planExpenseLine}',             [BusinessPlanController::class, 'updateExpenseLine'])->name('expenses.update')->middleware('module:valuation,edit,business_plan');
            Route::delete('/expenses/{planExpenseLine}',          [BusinessPlanController::class, 'destroyExpenseLine'])->name('expenses.destroy')->middleware('module:valuation,reverse,business_plan');

            Route::delete('/fixed-assets/{planFixedAsset}',       [BusinessPlanController::class, 'destroyFixedAsset'])->name('fixed-assets.destroy')->middleware('module:valuation,reverse,business_plan');
        });

        // DCF / Multiples / Scenarios / Report — Phase 2, once
        // PlanCalculationService exists (spec Section 3.5). Left as
        // stubs deliberately, not built ahead of the engine they need.
        Route::get('/dcf',           ComingSoonController::class)->name('dcf.index')->middleware('module:valuation,view,dcf_valuation');
        Route::get('/multiples',     ComingSoonController::class)->name('multiples.index')->middleware('module:valuation,view,multiples');
        Route::get('/scenarios',     ComingSoonController::class)->name('scenarios.index')->middleware('module:valuation,view,scenario_valuation');
        Route::get('/report',        ComingSoonController::class)->name('report.index')->middleware('module:valuation,view,investor_report');
    });

}); // end auth + active.company group


// ══════════════════════════════════════════════════════════════════
//  AUTH ROUTES (login, logout, password reset)
// ══════════════════════════════════════════════════════════════════
require __DIR__.'/auth.php';