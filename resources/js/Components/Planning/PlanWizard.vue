<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/PlanWizard.vue

  The shared wizard body for both Annual Plan and Business Plan &
  Valuation (Decision 1: one shared flow, two products). Tabs:
  Products, Sales, COGS, Manpower, Expenses, Fixed Assets, Opening
  Balance, Results — plus a Valuation tab when planType is
  'business_plan'. Every write goes to a routePrefix-relative route
  (e.g. planning.annual-plan.products.store /
  valuation.business-plan.products.store) — same trait, same routes,
  different prefix per product.

  @layout    (plain component — hosted inside Show.vue, which has
              AppLayout applied automatically)
  @props     plan: Object, planType: 'annual'|'business_plan',
             routePrefix: String (e.g. 'planning.annual-plan')
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="pw-page">
        <div class="pw-header">
            <Link :href="route(routePrefix + '.index')" class="pw-back">← {{ planType === 'annual' ? t('planning.wizard.annualPlanLabel') : t('planning.wizard.businessPlanLabel') }}</Link>
            <h1 class="pw-title">{{ plan.name }}</h1>
            <p class="pw-subtitle">{{ plan.plan_code }} · {{ formatDate(plan.start_date) }} → {{ formatDate(plan.end_date) }} · {{ plan.currency?.code }}</p>
        </div>

        <div class="pw-tabs">
            <button v-for="tab in tabs" :key="tab.key" class="pw-tab" :class="{ active: activeTab === tab.key }" @click="activeTab = tab.key">{{ tab.label }}</button>
        </div>

        <!-- ── Products ─────────────────────────────────────────── -->
        <div v-if="activeTab === 'products'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.products') }}</h2>
            <table class="pw-table">
                <thead><tr><th>{{ t('planning.wizard.name') }}</th><th>{{ t('planning.wizard.nature') }}</th><th>{{ t('planning.wizard.vatPct') }}</th><th>{{ t('planning.wizard.whtPct') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in plan.products" :key="p.id">
                        <td>{{ p.name }} <span v-if="!p.product_id" class="pw-tag">{{ t('planning.wizard.planOnly') }}</span></td>
                        <td class="pw-muted">{{ p.nature }}</td>
                        <td class="pw-muted">{{ p.vat_rate }}%</td>
                        <td class="pw-muted">{{ p.wht_rate }}%</td>
                        <td class="text-end">
                            <button class="pw-link" @click="selectedProductId = p.id; activeTab = 'sales'">{{ t('planning.wizard.configure') }}</button>
                            <button class="pw-link pw-link-danger" @click="removeProduct(p)">{{ t('planning.wizard.remove') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3 class="pw-subsection-title">{{ t('planning.wizard.addAProduct') }}</h3>
            <div class="pw-field-grid">
                <div class="pw-field">
                    <label>{{ t('planning.wizard.name') }}</label>
                    <input v-model="productForm.name" type="text" :placeholder="t('planning.wizard.productNamePlaceholder')" />
                </div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.nature') }}</label>
                    <select v-model="productForm.nature">
                        <option value="trading">{{ t('planning.wizard.trading') }}</option>
                        <option value="manufacturing">{{ t('planning.wizard.manufacturing') }}</option>
                        <option value="service">{{ t('planning.wizard.service') }}</option>
                    </select>
                </div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.vatPct') }}</label>
                    <input v-model="productForm.vat_rate" type="number" step="0.01" min="0" max="100" />
                </div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.whtPct') }}</label>
                    <input v-model="productForm.wht_rate" type="number" step="0.01" min="0" max="100" />
                </div>
            </div>
            <button class="pw-btn-primary" style="margin-top:14px" @click="addProduct">{{ t('planning.wizard.addProduct') }}</button>
        </div>

        <!-- ── Sales ────────────────────────────────────────────── -->
        <div v-if="activeTab === 'sales'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.salesProjection') }}</h2>
            <div class="pw-field-grid">
                <div class="pw-field">
                    <label>{{ t('planning.wizard.product') }}</label>
                    <select v-model.number="selectedProductId">
                        <option v-for="p in plan.products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.year') }}</label>
                    <select v-model.number="selectedYear">
                        <option v-for="y in plan.duration_years" :key="y" :value="y">{{ t('planning.wizard.yearN', { n: y }) }}</option>
                    </select>
                </div>
            </div>

            <table v-if="currentProduct" class="pw-table" style="margin-top:16px">
                <thead><tr><th>{{ t('planning.wizard.month') }}</th><th>{{ t('planning.wizard.price') }}</th><th>{{ t('planning.wizard.volume') }}</th><th>{{ t('planning.wizard.localPct') }}</th><th>{{ t('planning.wizard.exportPct') }}</th></tr></thead>
                <tbody>
                    <tr v-for="row in salesRowsForYear" :key="row.period_month">
                        <td class="pw-muted">{{ monthLabel(row.period_month) }}</td>
                        <td><input v-model="row.price" type="number" step="0.01" min="0" class="pw-cell-input" /></td>
                        <td><input v-model="row.volume" type="number" step="0.0001" min="0" class="pw-cell-input" /></td>
                        <td><input v-model="row.local_pct" type="number" step="0.01" min="0" max="100" class="pw-cell-input" /></td>
                        <td><input v-model="row.export_pct" type="number" step="0.01" min="0" max="100" class="pw-cell-input" /></td>
                    </tr>
                </tbody>
            </table>
            <button v-if="currentProduct" class="pw-btn-primary" style="margin-top:14px" @click="saveSalesLines">{{ t('planning.wizard.saveSalesProjection') }}</button>

            <template v-if="currentProduct">
                <h3 class="pw-subsection-title">{{ t('planning.wizard.collectionPolicy') }}</h3>
                <div class="pw-tranche-grid">
                    <div v-for="market in ['local', 'export']" :key="market" class="pw-tranche-box">
                        <h4 class="pw-tranche-title">{{ market === 'local' ? t('planning.wizard.localSales') : t('planning.wizard.exportSales') }}</h4>
                        <div v-for="(tranche, idx) in tranches[market]" :key="idx" class="pw-tranche-row">
                            <input v-model="tranche.pct" type="number" step="0.01" min="0" max="100" class="pw-cell-input" placeholder="%" />
                            <span class="pw-muted">{{ t('planning.wizard.pctCollected') }}</span>
                            <input v-model="tranche.days_after_sale" type="number" step="1" min="0" class="pw-cell-input" placeholder="days" />
                            <span class="pw-muted">{{ t('planning.wizard.daysAfterSale') }}</span>
                            <button class="pw-link pw-link-danger" @click="tranches[market].splice(idx, 1)">×</button>
                        </div>
                        <button class="pw-link" @click="tranches[market].push({ pct: 0, days_after_sale: 0 })">{{ t('planning.wizard.addTranche') }}</button>
                        <button class="pw-btn-secondary" style="margin-top:8px" @click="saveTranches(market)">{{ t('planning.wizard.savePolicyFor', { market }) }}</button>
                    </div>
                </div>
            </template>
        </div>

        <!-- ── COGS ─────────────────────────────────────────────── -->
        <div v-if="activeTab === 'cogs'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.costOfGoodsSold') }}</h2>
            <div class="pw-field">
                <label>{{ t('planning.wizard.product') }}</label>
                <select v-model.number="selectedProductId">
                    <option v-for="p in plan.products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.nature }})</option>
                </select>
            </div>

            <div v-if="currentProduct" class="pw-field-grid" style="margin-top:16px">
                <template v-if="currentProduct.nature === 'manufacturing'">
                    <div class="pw-field"><label>{{ t('planning.wizard.unitMaterialCost') }}</label><input v-model="cogsForm.unit_material_cost" type="number" step="0.0001" /></div>
                    <div class="pw-field"><label>{{ t('planning.wizard.unitLaborCost') }}</label><input v-model="cogsForm.unit_labor_cost" type="number" step="0.0001" /></div>
                    <div class="pw-field"><label>{{ t('planning.wizard.overheadPctOfLabor') }}</label><input v-model="cogsForm.overhead_pct_of_labor" type="number" step="0.01" /></div>
                </template>
                <template v-else-if="currentProduct.nature === 'trading'">
                    <div class="pw-field"><label>{{ t('planning.wizard.unitPurchaseCost') }}</label><input v-model="cogsForm.unit_purchase_cost" type="number" step="0.0001" /></div>
                    <div class="pw-field"><label>{{ t('planning.wizard.inventoryCoverageDays') }}</label><input v-model="cogsForm.inventory_coverage_days" type="number" step="1" /></div>
                </template>
                <template v-else>
                    <div class="pw-field"><label>{{ t('planning.wizard.directCostPct') }}</label><input v-model="cogsForm.direct_cost_pct" type="number" step="0.01" /></div>
                </template>
                <div class="pw-field"><label>{{ t('planning.wizard.annualCostIncreasePct') }}</label><input v-model="cogsForm.annual_cost_increase_pct" type="number" step="0.01" /></div>
            </div>
            <button v-if="currentProduct" class="pw-btn-primary" style="margin-top:14px" @click="saveCogs">{{ t('planning.wizard.saveCogsAssumption') }}</button>
        </div>

        <!-- ── Manpower ─────────────────────────────────────────── -->
        <div v-if="activeTab === 'manpower'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.manpower') }}</h2>
            <button class="pw-btn-secondary" @click="snapshotManpower">{{ t('planning.wizard.pullExistingTeam') }}</button>

            <table class="pw-table" style="margin-top:16px">
                <thead><tr><th>{{ t('planning.wizard.title') }}</th><th>{{ t('planning.wizard.category') }}</th><th>{{ t('planning.wizard.existingHeadcount') }}</th><th>{{ t('planning.wizard.avgSalary') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="title in plan.manpower_titles" :key="title.id">
                        <td>{{ title.job_title }} <span v-if="!title.is_existing" class="pw-tag">{{ t('planning.wizard.planned') }}</span></td>
                        <td class="pw-muted">{{ title.expense_category }}</td>
                        <td class="pw-muted">{{ title.existing_headcount }}</td>
                        <td class="pw-muted">{{ title.avg_current_salary }}</td>
                        <td class="text-end">
                            <button class="pw-link" @click="selectedTitleId = title.id">{{ t('planning.wizard.setHiring') }}</button>
                            <button class="pw-link pw-link-danger" @click="removeTitle(title)">{{ t('planning.wizard.remove') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template v-if="selectedTitle">
                <h3 class="pw-subsection-title">{{ t('planning.wizard.plannedNewHiresFor', { title: selectedTitle.job_title }) }}</h3>
                <div class="pw-tranche-row" v-for="y in plan.duration_years" :key="y" style="margin-bottom:8px">
                    <span class="pw-muted" style="width:60px">{{ t('planning.wizard.yearN', { n: y }) }}</span>
                    <input v-model.number="hiringByYear[y]" type="number" step="1" min="0" class="pw-cell-input" :placeholder="t('planning.wizard.newHiresPlaceholder')" />
                    <button class="pw-link" @click="saveHiring(y)">{{ t('planning.wizard.save') }}</button>
                </div>
            </template>

            <h3 class="pw-subsection-title">{{ t('planning.wizard.addAPlannedTitle') }}</h3>
            <div class="pw-field-grid">
                <div class="pw-field"><label>{{ t('planning.wizard.jobTitle') }}</label><input v-model="titleForm.job_title" type="text" /></div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.category') }}</label>
                    <select v-model="titleForm.expense_category">
                        <option value="sales">{{ t('planning.wizard.sales') }}</option>
                        <option value="marketing">{{ t('planning.wizard.marketing') }}</option>
                        <option value="general_admin">{{ t('planning.wizard.generalAdmin') }}</option>
                        <option value="cost_of_service">{{ t('planning.wizard.costOfService') }}</option>
                        <option value="manufacturing">{{ t('planning.wizard.manufacturing') }}</option>
                    </select>
                </div>
                <div class="pw-field"><label>{{ t('planning.wizard.estimatedSalary') }}</label><input v-model="titleForm.avg_current_salary" type="number" step="0.01" /></div>
            </div>
            <button class="pw-btn-primary" style="margin-top:14px" @click="addTitle">{{ t('planning.wizard.addTitle') }}</button>
        </div>

        <!-- ── Expenses ─────────────────────────────────────────── -->
        <div v-if="activeTab === 'expenses'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.operatingExpenses') }}</h2>
            <table class="pw-table">
                <thead><tr><th>{{ t('planning.wizard.name') }}</th><th>{{ t('planning.wizard.category') }}</th><th>{{ t('planning.wizard.type') }}</th><th>{{ t('planning.wizard.amount') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="e in plan.expense_lines" :key="e.id">
                        <td>{{ e.expense_name }}</td>
                        <td class="pw-muted">{{ e.category }}</td>
                        <td class="pw-muted">{{ expenseTypeLabel(e.expense_type) }}</td>
                        <td class="pw-muted">{{ e.amount }}</td>
                        <td class="text-end"><button class="pw-link pw-link-danger" @click="removeExpense(e)">{{ t('planning.wizard.remove') }}</button></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="pw-subsection-title">{{ t('planning.wizard.addExpense') }}</h3>
            <div class="pw-field-grid">
                <div class="pw-field"><label>{{ t('planning.wizard.name') }}</label><input v-model="expenseForm.expense_name" type="text" /></div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.category') }}</label>
                    <select v-model="expenseForm.category">
                        <option value="sales">{{ t('planning.wizard.sales') }}</option>
                        <option value="marketing">{{ t('planning.wizard.marketing') }}</option>
                        <option value="general_admin">{{ t('planning.wizard.generalAdmin') }}</option>
                        <option value="finance">{{ t('planning.wizard.finance') }}</option>
                    </select>
                </div>
                <div class="pw-field">
                    <label>{{ t('planning.wizard.type') }}</label>
                    <select v-model="expenseForm.expense_type">
                        <option value="pct_revenue">{{ t('planning.wizard.pctOfRevenue') }}</option>
                        <option value="fixed_recurring">{{ t('planning.wizard.fixedMonthly') }}</option>
                        <option value="one_time">{{ t('planning.wizard.oneTimeAmortized') }}</option>
                    </select>
                </div>
                <div class="pw-field"><label>{{ t('planning.wizard.amount') }} {{ expenseForm.expense_type === 'pct_revenue' ? '(%)' : '' }}</label><input v-model="expenseForm.amount" type="number" step="0.01" /></div>
                <div class="pw-field" v-if="expenseForm.expense_type === 'one_time'"><label>{{ t('planning.wizard.amortizeOverMonths') }}</label><input v-model="expenseForm.amortization_months" type="number" step="1" min="1" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.annualIncreasePct') }}</label><input v-model="expenseForm.annual_increase_pct" type="number" step="0.01" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.startMonthZeroBased') }}</label><input v-model.number="expenseForm.start_month" type="number" step="1" min="0" /></div>
            </div>
            <button class="pw-btn-primary" style="margin-top:14px" @click="addExpense">{{ t('planning.wizard.addExpense') }}</button>
        </div>

        <!-- ── Fixed Assets ─────────────────────────────────────── -->
        <div v-if="activeTab === 'fixed-assets'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.fixedAssets') }}</h2>
            <table class="pw-table">
                <thead><tr><th>{{ t('planning.wizard.name') }}</th><th>{{ t('planning.wizard.cost') }}</th><th>{{ t('planning.wizard.usefulLife') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="a in plan.fixed_assets" :key="a.id">
                        <td>{{ a.name }} <span v-if="!a.fixed_asset_id" class="pw-tag">{{ t('planning.wizard.planned') }}</span></td>
                        <td class="pw-muted">{{ a.cost_basis }}</td>
                        <td class="pw-muted">{{ a.useful_life_months }} mo</td>
                        <td class="text-end"><button class="pw-link pw-link-danger" @click="removeAsset(a)">{{ t('planning.wizard.remove') }}</button></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="pw-subsection-title">{{ t('planning.wizard.addAnExistingAsset') }}</h3>
            <button class="pw-btn-secondary" @click="loadEligibleAssets">{{ t('planning.wizard.loadFixedAssetsRegister') }}</button>
            <table v-if="eligibleAssets.length" class="pw-table" style="margin-top:12px">
                <thead><tr><th>{{ t('planning.wizard.code') }}</th><th>{{ t('planning.wizard.name') }}</th><th>{{ t('planning.wizard.cost') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="a in eligibleAssets" :key="a.id">
                        <td class="pw-muted">{{ a.asset_code }}</td>
                        <td>{{ a.name }}</td>
                        <td class="pw-muted">{{ a.cost_basis }}</td>
                        <td class="text-end"><button class="pw-link" @click="addExistingAsset(a)">{{ t('planning.wizard.add') }}</button></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="pw-subsection-title">{{ t('planning.wizard.addAPlannedFuturePurchase') }}</h3>
            <div class="pw-field-grid">
                <div class="pw-field"><label>{{ t('planning.wizard.name') }}</label><input v-model="assetForm.name" type="text" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.cost') }}</label><input v-model="assetForm.cost_basis" type="number" step="0.01" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.residualValue') }}</label><input v-model="assetForm.residual_value" type="number" step="0.01" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.usefulLifeMonths') }}</label><input v-model="assetForm.useful_life_months" type="number" step="1" /></div>
                <div class="pw-field"><label>{{ t('planning.wizard.purchaseMonthZeroBased') }}</label><input v-model.number="assetForm.purchase_month" type="number" step="1" min="0" /></div>
            </div>
            <button class="pw-btn-primary" style="margin-top:14px" @click="addPlannedAsset">{{ t('planning.wizard.addPlannedAsset') }}</button>
        </div>

        <!-- ── Opening Balance ──────────────────────────────────── -->
        <div v-if="activeTab === 'opening-balance'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.openingBalance') }}</h2>
            <button class="pw-btn-secondary" @click="loadOpeningBalance">{{ t('planning.wizard.load') }}</button>
            <div v-if="openingBalance" class="pw-kpi-grid" style="margin-top:16px">
                <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.cashAndBank') }}</span><span class="pw-kpi-value">{{ openingBalance.figures?.cash_and_bank }}</span></div>
                <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.accountsReceivable') }}</span><span class="pw-kpi-value">{{ openingBalance.figures?.accounts_receivable }}</span></div>
                <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.inventory') }}</span><span class="pw-kpi-value">{{ openingBalance.figures?.inventory }}</span></div>
                <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.accountsPayable') }}</span><span class="pw-kpi-value">{{ openingBalance.figures?.accounts_payable }}</span></div>
                <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.totalEquity') }}</span><span class="pw-kpi-value">{{ openingBalance.figures?.total_equity }}</span></div>
            </div>
        </div>

        <!-- ── Results ──────────────────────────────────────────── -->
        <div v-if="activeTab === 'results'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.results') }}</h2>
            <button class="pw-btn-secondary" @click="loadResults">{{ t('planning.wizard.runCalculation') }}</button>

            <template v-if="results">
                <table class="pw-table" style="margin-top:16px">
                    <thead>
                        <tr><th>{{ t('planning.wizard.year') }}</th><th>{{ t('planning.wizard.revenue') }}</th><th>{{ t('planning.wizard.grossProfit') }}</th><th>{{ t('planning.wizard.ebitda') }}</th><th>{{ t('planning.wizard.ebit') }}</th><th>{{ t('planning.wizard.netProfit') }}</th><th>{{ t('planning.wizard.cashEnd') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, y) in results.annual.pl" :key="y">
                            <td>{{ t('planning.wizard.yearN', { n: Number(y) + 1 }) }}</td>
                            <td class="pw-muted">{{ row.revenue }}</td>
                            <td class="pw-muted">{{ row.grossProfit }}</td>
                            <td class="pw-muted">{{ row.ebitda }}</td>
                            <td class="pw-muted">{{ row.ebit }}</td>
                            <td class="pw-muted">{{ row.netProfit }}</td>
                            <td class="pw-muted">{{ results.annual.cf[y]?.cash_balance_end }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="pw-kpi-grid" style="margin-top:16px">
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.requiredEquityInjection') }}</span><span class="pw-kpi-value">{{ results.required_equity_injection }}</span></div>
                </div>

                <p v-if="!allBalanced" class="pw-error" style="margin-top:12px">
                    {{ t('planning.wizard.balanceSheetMismatchWarning') }}
                </p>
            </template>
        </div>

        <!-- ── Valuation (Business Plan only) ──────────────────── -->
        <div v-if="activeTab === 'valuation' && planType === 'business_plan'" class="pw-card">
            <h2 class="pw-section-title">{{ t('planning.wizard.valuation') }}</h2>
            <button class="pw-btn-secondary" @click="loadResults">{{ t('planning.wizard.runCalculation') }}</button>

            <template v-if="results?.valuation">
                <h3 class="pw-subsection-title">{{ t('planning.wizard.dcf') }}</h3>
                <div class="pw-kpi-grid">
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.npv') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.npv }}</span></div>
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.irr') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.irr_pct }}%</span></div>
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.terminalValue') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.terminal_value }}</span></div>
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.moic') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.moic }}x</span></div>
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.paybackMonths') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.payback_months ?? '—' }}</span></div>
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.breakEvenMonth') }}</span><span class="pw-kpi-value">{{ results.valuation.dcf.break_even_months ?? '—' }}</span></div>
                </div>

                <h3 class="pw-subsection-title">{{ t('planning.wizard.multiplierValuation') }}</h3>
                <div class="pw-kpi-grid">
                    <div class="pw-kpi"><span class="pw-kpi-label">{{ t('planning.wizard.year1BasisTimesMultiplier', { basis: basisLabel(results.valuation.valuation_basis), multiplier: results.valuation.valuation_multiplier }) }}</span><span class="pw-kpi-value">{{ results.valuation.multiplier_value }}</span></div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps({
    plan: { type: Object, required: true },
    planType: { type: String, required: true },
    routePrefix: { type: String, required: true },
})

const baseTabs = computed(() => [
    { key: 'products', label: t('planning.wizard.tabProducts') },
    { key: 'sales', label: t('planning.wizard.tabSales') },
    { key: 'cogs', label: t('planning.wizard.tabCogs') },
    { key: 'manpower', label: t('planning.wizard.tabManpower') },
    { key: 'expenses', label: t('planning.wizard.tabExpenses') },
    { key: 'fixed-assets', label: t('planning.wizard.tabFixedAssets') },
    { key: 'opening-balance', label: t('planning.wizard.tabOpeningBalance') },
    { key: 'results', label: t('planning.wizard.tabResults') },
])
const tabs = computed(() => props.planType === 'business_plan'
    ? [...baseTabs.value, { key: 'valuation', label: t('planning.wizard.tabValuation') }]
    : baseTabs.value)

const activeTab = ref('products')
const selectedProductId = ref(props.plan.products?.[0]?.id ?? null)
const selectedYear = ref(1)
const selectedTitleId = ref(null)
const eligibleAssets = ref([])
const openingBalance = ref(null)
const results = ref(null)

const currentProduct = computed(() => props.plan.products?.find(p => p.id === selectedProductId.value) ?? null)
const selectedTitle = computed(() => props.plan.manpower_titles?.find(t => t.id === selectedTitleId.value) ?? null)

const salesRowsForYear = computed(() => {
    if (! currentProduct.value) return []
    const start = (selectedYear.value - 1) * 12
    return (currentProduct.value.sales_lines ?? [])
        .filter(r => r.period_month >= start && r.period_month < start + 12)
        .sort((a, b) => a.period_month - b.period_month)
})

// Declared BEFORE the immediate watcher below: that watcher runs the moment the
// component is set up and writes into cogsForm. Declared after it (as before),
// the page crashed on load with "Cannot access 'cogsForm' before initialization".
const cogsForm = reactive({
    unit_material_cost: 0, unit_labor_cost: 0, overhead_pct_of_labor: 0,
    unit_purchase_cost: 0, inventory_coverage_days: 30,
    direct_cost_pct: 0, annual_cost_increase_pct: 0,
})

const tranches = reactive({ local: [], export: [] })
watch(currentProduct, (p) => {
    tranches.local = (p?.collection_tranches ?? []).filter(t => t.market === 'local').map(t => ({ pct: t.pct, days_after_sale: t.days_after_sale }))
    tranches.export = (p?.collection_tranches ?? []).filter(t => t.market === 'export').map(t => ({ pct: t.pct, days_after_sale: t.days_after_sale }))
    cogsForm.unit_material_cost = p?.cogs_assumption?.unit_material_cost ?? 0
    cogsForm.unit_labor_cost = p?.cogs_assumption?.unit_labor_cost ?? 0
    cogsForm.overhead_pct_of_labor = p?.cogs_assumption?.overhead_pct_of_labor ?? 0
    cogsForm.unit_purchase_cost = p?.cogs_assumption?.unit_purchase_cost ?? 0
    cogsForm.inventory_coverage_days = p?.cogs_assumption?.inventory_coverage_days ?? 30
    cogsForm.direct_cost_pct = p?.cogs_assumption?.direct_cost_pct ?? 0
    cogsForm.annual_cost_increase_pct = p?.cogs_assumption?.annual_cost_increase_pct ?? 0
}, { immediate: true })

const hiringByYear = reactive({})
watch(selectedTitle, (title) => {
    for (let y = 1; y <= props.plan.duration_years; y++) {
        hiringByYear[y] = title?.hiring_lines?.find(h => h.plan_year === y)?.new_hires_count ?? 0
    }
}, { immediate: true })

const allBalanced = computed(() => {
    if (! results.value) return true
    // PLN-002 fix (2026-09) — same verdict the engine computes, so the
    // wizard and the results screen can never disagree about it.
    if (results.value.balance_check) return results.value.balance_check.all_balanced
    return Object.values(results.value.bs ?? {}).every(row => row.is_balanced)
})

function formatDate(d) { return d ? new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—' }
function monthLabel(m) { return t('planning.wizard.monthN', { n: m + 1 }) }
function expenseTypeLabel(expenseType) {
    return {
        pct_revenue: t('planning.wizard.pctOfRevenue'),
        fixed_recurring: t('planning.wizard.fixedMonthly'),
        one_time: t('planning.wizard.oneTimeAmortized'),
    }[expenseType] ?? expenseType
}
function basisLabel(b) {
    return {
        revenue: t('planning.wizard.revenue'),
        ebitda: t('planning.wizard.ebitda'),
        net_profit: t('planning.wizard.netProfit'),
    }[b] ?? b
}

const routeName = (suffix) => route(`${props.routePrefix}.${suffix}`, { plan: props.plan.id })

// ── Products ──────────────────────────────────────────────────
const productForm = reactive({ name: '', nature: 'trading', vat_rate: 0, wht_rate: 0 })
function addProduct() {
    router.post(routeName('products.store'), { ...productForm }, {
        preserveScroll: true,
        onSuccess: () => { productForm.name = ''; productForm.vat_rate = 0; productForm.wht_rate = 0 },
    })
}
function removeProduct(p) {
    if (! confirm(t('planning.wizard.removeProductConfirm', { name: p.name }))) return
    router.delete(route(`${props.routePrefix}.products.destroy`, { planProduct: p.id }), { preserveScroll: true })
}

// ── Sales ─────────────────────────────────────────────────────
function saveSalesLines() {
    router.post(route(`${props.routePrefix}.products.sales-lines.store`, { planProduct: selectedProductId.value }), {
        lines: salesRowsForYear.value.map(r => ({ period_month: r.period_month, price: r.price, volume: r.volume, local_pct: r.local_pct, export_pct: r.export_pct })),
    }, { preserveScroll: true })
}
function saveTranches(market) {
    router.post(route(`${props.routePrefix}.products.collection-tranches.store`, { planProduct: selectedProductId.value }), {
        market, tranches: tranches[market],
    }, { preserveScroll: true })
}

// ── COGS ──────────────────────────────────────────────────────
function saveCogs() {
    router.post(route(`${props.routePrefix}.products.cogs-assumption.store`, { planProduct: selectedProductId.value }), { ...cogsForm }, { preserveScroll: true })
}

// ── Manpower ──────────────────────────────────────────────────
function snapshotManpower() {
    router.post(routeName('manpower.snapshot'), {}, { preserveScroll: true })
}
const titleForm = reactive({ job_title: '', expense_category: 'sales', avg_current_salary: 0 })
function addTitle() {
    router.post(routeName('manpower.titles.store'), { ...titleForm }, {
        preserveScroll: true,
        onSuccess: () => { titleForm.job_title = ''; titleForm.avg_current_salary = 0 },
    })
}
function removeTitle(title) {
    if (! confirm(t('planning.wizard.removeTitleConfirm', { name: title.job_title }))) return
    router.delete(route(`${props.routePrefix}.manpower.titles.destroy`, { planManpowerTitle: title.id }), { preserveScroll: true })
}
function saveHiring(year) {
    router.post(route(`${props.routePrefix}.manpower.hiring.store`, { planManpowerTitle: selectedTitleId.value }), {
        plan_year: year, new_hires_count: hiringByYear[year] ?? 0,
    }, { preserveScroll: true })
}

// ── Expenses ──────────────────────────────────────────────────
const expenseForm = reactive({ expense_name: '', category: 'general_admin', expense_type: 'fixed_recurring', amount: 0, amortization_months: 1, annual_increase_pct: 0, start_month: 0 })
function addExpense() {
    router.post(routeName('expenses.store'), { ...expenseForm }, {
        preserveScroll: true,
        onSuccess: () => { expenseForm.expense_name = ''; expenseForm.amount = 0 },
    })
}
function removeExpense(e) {
    if (! confirm(t('planning.wizard.removeExpenseConfirm', { name: e.expense_name }))) return
    router.delete(route(`${props.routePrefix}.expenses.destroy`, { planExpenseLine: e.id }), { preserveScroll: true })
}

// ── Fixed Assets ──────────────────────────────────────────────
async function loadEligibleAssets() {
    const { data } = await window.axios.get(routeName('eligible-fixed-assets'))
    eligibleAssets.value = data
}
function addExistingAsset(asset) {
    router.post(route(`${props.routePrefix}.fixed-assets.existing.store`, { plan: props.plan.id, fixedAsset: asset.id }), {}, { preserveScroll: true })
}
const assetForm = reactive({ name: '', cost_basis: 0, residual_value: 0, useful_life_months: 12, purchase_month: 0 })
function addPlannedAsset() {
    router.post(routeName('fixed-assets.planned.store'), { ...assetForm }, {
        preserveScroll: true,
        onSuccess: () => { assetForm.name = ''; assetForm.cost_basis = 0 },
    })
}
function removeAsset(a) {
    if (! confirm(t('planning.wizard.removeAssetConfirm', { name: a.name }))) return
    router.delete(route(`${props.routePrefix}.fixed-assets.destroy`, { planFixedAsset: a.id }), { preserveScroll: true })
}

// ── Opening Balance / Results ─────────────────────────────────
async function loadOpeningBalance() {
    const { data } = await window.axios.get(routeName('opening-balance'))
    openingBalance.value = data
}
async function loadResults() {
    const { data } = await window.axios.get(routeName('results'))
    results.value = data
}
</script>

<style scoped>
.pw-page { padding: 28px 32px; max-width: 1200px; margin: 0 auto; }
.pw-back { font-size: 13px; color: #1490A8; text-decoration: none; }
.pw-header { margin-bottom: 16px; }
.pw-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 8px 0 2px; }
.pw-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 0; }
.pw-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--vero-border); margin-bottom: 18px; overflow-x: auto; }
.pw-tab { padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--vero-text-muted); background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.pw-tab.active { color: #0F2554; border-bottom-color: #FFBF00; }
.pw-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); padding: 24px; }
.pw-section-title { font-size: 15px; font-weight: 700; color: #0F2554; margin: 0 0 14px; }
.pw-subsection-title { font-size: 13px; font-weight: 700; color: #0F2554; text-transform: uppercase; letter-spacing: 0.04em; margin: 24px 0 12px; padding-top: 20px; border-top: 1px solid var(--vero-border); }
.pw-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.pw-field { display: flex; flex-direction: column; gap: 6px; }
.pw-field label { font-size: 12px; font-weight: 600; color: var(--vero-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.pw-field input, .pw-field select { padding: 8px 11px; border: 1px solid var(--vero-border); border-radius: 7px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); }
.pw-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.pw-table th { text-align: left; padding: 8px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--vero-text-muted); border-bottom: 1px solid var(--vero-border); }
.pw-table td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid var(--vero-border); vertical-align: middle; }
.pw-muted { color: var(--vero-text-muted); }
.pw-tag { font-size: 10px; background: rgba(20,144,168,0.12); color: #1490A8; padding: 2px 6px; border-radius: 10px; margin-left: 6px; }
.pw-cell-input { width: 90px; padding: 5px 7px; border: 1px solid var(--vero-border); border-radius: 5px; font-size: 12px; background: var(--vero-card); color: var(--vero-text); }
.pw-link { font-size: 12px; font-weight: 600; color: #1490A8; background: none; border: none; cursor: pointer; padding: 0 6px; }
.pw-link-danger { color: #ef4444; }
.pw-btn-primary { padding: 9px 18px; background: #0F2554; color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.pw-btn-secondary { padding: 8px 16px; background: transparent; color: #0F2554; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
.pw-tranche-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.pw-tranche-box { border: 1px solid var(--vero-border); border-radius: 8px; padding: 14px; }
.pw-tranche-title { font-size: 13px; font-weight: 700; margin: 0 0 10px; }
.pw-tranche-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; }
.pw-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.pw-kpi { background: rgba(15,37,84,0.04); border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.pw-kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--vero-text-muted); }
.pw-kpi-value { font-size: 16px; font-weight: 700; color: #0F2554; }
.pw-error { font-size: 12px; color: #ef4444; }
</style>
