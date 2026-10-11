<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/Dashboard.vue

  HR Dashboard (September 2026, per Mahmoud — "same pattern" as the
  Accounting and Sales Orders dashboards). Single scrolling page:
  payroll cost overview and category mix (period-scoped: This Month
  / Year to Date, by the PayrollRun's own period_year/period_month),
  Top Employees by gross pay, and a separate "as of today" balances
  section (EOSB provision, tax/social-insurance remittance,
  outstanding advances & custody) that deliberately does NOT move
  with the period toggle — same point-in-time-balance treatment as
  Accounting Dashboard's AR/AP, kept in its own clearly-labeled
  section specifically so it doesn't look broken the way Sales
  Orders' unscoped sections did before that fix.

  Same `fid-` classes as the other two dashboards — byte-for-byte
  the same visual language.

  Top Employees / Outstanding Advances rows are a real drill-down:
  each links straight to the existing Employees / Advances &
  Settlements pages rather than re-implementing that detail here.

  @layout    AppLayout
  @route     hr.dashboard
  @props     data, urls — see HrDashboardController::index()
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">
    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.dashboard.hrDashboard')"
      :subtitle="t('accounting.hr.dashboard.subtitle')"
    />

    <!-- ── Period toggle ─────────────────────────────────────── -->
    <div class="fid-pill-row">
      <button class="fid-pill" :class="{ 'fid-pill-active': period === 'month' }" @click="period = 'month'">{{ data.periods.month }}</button>
      <button class="fid-pill" :class="{ 'fid-pill-active': period === 'ytd' }" @click="period = 'ytd'">{{ t('accounting.hr.dashboard.yearToDate') }}</button>
    </div>

    <!-- ── Overview KPI row ──────────────────────────────────── -->
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-blue">👥</div>
        <div>
          <p class="fid-kpi-label">{{ t('accounting.hr.dashboard.activeEmployees') }}</p>
          <p class="fid-kpi-value fid-num-blue">{{ data.headcount.active }}</p>
          <p class="fid-kpi-note">{{ data.headcount.total }} {{ t('accounting.hr.dashboard.totalOnFile') }}</p>
        </div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-green">💰</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.grossPayrollCost') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(current.overview.gross_pay) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-blue">🏦</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.netPayDisbursed') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(current.overview.net_pay) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-amber">📊</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.totalCompanyCost') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(current.overview.total_cost) }}</p></div>
      </div>
    </div>

    <!-- ── Payroll Breakdown (period-scoped) ──────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.hr.dashboard.payrollBreakdown') }}</div>
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">📈</div><div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.grossPay') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(current.overview.gross_pay) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">➖</div><div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.employeeDeductions') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(current.overview.employee_deductions) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">✅</div><div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.netPay') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(current.overview.net_pay) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">🏛</div><div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.employerSocialInsurance') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(current.overview.employer_social_insurance) }}</p></div></div>
    </div>
    <div class="fid-chart-grid-2">
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.hr.dashboard.costByCategory') }}</h4>
        <DonutChart3D :key="period" :data="current.category_mix" :colors="categoryColors" />
      </div>
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.hr.dashboard.categoryBreakdown') }}</h4>
        <table class="zv-table">
          <thead><tr><th>{{ t('accounting.hr.dashboard.category') }}</th><th class="fid-th-num">{{ t('common.amount') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in current.category_mix" :key="row.category">
              <td>{{ row.category }}</td><td class="fid-num">{{ fmt(row.value) }}</td>
            </tr>
            <tr v-if="!current.category_mix.length"><td colspan="2" class="fid-muted" style="text-align:center;">{{ t('accounting.hr.dashboard.noPayrollActivity') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Top Employees ──────────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.hr.dashboard.topEmployees') }}</div>
    <div class="zv-card fid-chart-card">
      <div class="fid-chart-header">
        <h4 class="fid-chart-title">{{ t('accounting.hr.dashboard.byGrossPay') }}</h4>
        <Link :href="urls.employeesIndex" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('accounting.hr.dashboard.viewAll') }}</Link>
      </div>
      <table class="zv-table">
        <thead>
          <tr>
            <th>{{ t('accounting.hr.dashboard.employee') }}</th>
            <th class="fid-th-num">{{ t('accounting.hr.dashboard.grossPay') }}</th>
            <th class="fid-th-num">{{ t('accounting.hr.dashboard.netPay') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in current.top_employees" :key="row.partner_id" class="fid-row" @click="goToEmployee(row.employee_payroll_profile_id)">
            <td>{{ row.name }} <span class="fid-muted">({{ row.code }})</span></td>
            <td class="fid-num fid-num-green">{{ fmt(row.gross_pay) }}</td>
            <td class="fid-num fid-num-blue">{{ fmt(row.net_pay) }}</td>
          </tr>
          <tr v-if="!current.top_employees.length"><td colspan="3" class="fid-muted" style="text-align:center;">{{ t('accounting.hr.dashboard.noPayrollActivity') }}</td></tr>
        </tbody>
      </table>
    </div>

    <!-- ── Balances (as of today — not period-scoped) ─────────── -->
    <div class="fid-section-heading">{{ t('accounting.hr.dashboard.balancesAsOfToday') }}</div>
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.eosbIndex)">
        <div class="fid-kpi-icon fid-icon-amber">🏦</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.eosbProvision') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.balances.eosb_provision) }}</p></div>
      </div>
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.remittanceIndex)">
        <div class="fid-kpi-icon fid-icon-red">📋</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.taxRemittanceDue') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.balances.tax_remittance_due) }}</p></div>
      </div>
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.remittanceIndex)">
        <div class="fid-kpi-icon fid-icon-red">🏛</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.socialInsuranceDue') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.balances.social_insurance_due) }}</p></div>
      </div>
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.advancesIndex)">
        <div class="fid-kpi-icon fid-icon-amber">💳</div>
        <div><p class="fid-kpi-label">{{ t('accounting.hr.dashboard.advancesOutstanding') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.balances.advances_outstanding) }}</p></div>
      </div>
    </div>

    <div class="zv-card fid-chart-card">
      <div class="fid-chart-header">
        <h4 class="fid-chart-title">{{ t('accounting.hr.dashboard.employeesWithOutstandingBalances') }}</h4>
        <Link :href="urls.advancesIndex" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('accounting.hr.dashboard.viewAll') }}</Link>
      </div>
      <table class="zv-table">
        <thead>
          <tr>
            <th>{{ t('accounting.hr.dashboard.employee') }}</th>
            <th class="fid-th-num">{{ t('accounting.hr.dashboard.advance') }}</th>
            <th class="fid-th-num">{{ t('accounting.hr.dashboard.custody') }}</th>
            <th class="fid-th-num">{{ t('common.total') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in data.balances.top_advances" :key="row.partner_id" class="fid-row" @click="goToAdvance(row.partner_id)">
            <td>{{ row.name }} <span class="fid-muted">({{ row.code }})</span></td>
            <td class="fid-num fid-num-amber">{{ fmt(row.advance) }}</td>
            <td class="fid-num fid-num-amber">{{ fmt(row.custody) }}</td>
            <td class="fid-num fid-num-red">{{ fmt(row.total) }}</td>
          </tr>
          <tr v-if="!data.balances.top_advances.length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('accounting.hr.dashboard.noOutstandingBalances') }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'

const { t } = useI18n()

const props = defineProps({
  data: { type: Object, required: true },
  urls: { type: Object, default: () => ({}) },
})

const period = ref('month')

// Payroll cost / category mix / top employees all switch together
// with the pill — data.balances stays outside this, deliberately
// (see the file header on point-in-time balances vs. period activity).
const current = computed(() => props.data.data[period.value])

const categoryColors = ['#0F2554', '#1490A8', '#7c3aed', '#b45309', '#16a34a']

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }

function goTo(url) { if (url) router.get(url) }

function goToEmployee(profileId) {
  if (!profileId) return
  router.get(route('hr.employees.edit', profileId))
}

function goToAdvance(partnerId) {
  router.get(route('hr.advances.show', partnerId))
}
</script>

<!--
  Styles copied from Accounting/Dashboard/Index.vue and
  Projects/SalesOrders/Dashboard.vue (per Mahmoud: "same pattern"
  across all three dashboards). Kept as the same `fid-` class names
  deliberately — see Accounting/Dashboard/Index.vue's own style-block
  comment for why. Adds `fid-icon-red`, the one variant neither of
  the other two dashboards needed — HR's remittance-due balances are
  liabilities worth flagging red, not just amber.
-->
<style>
.fid-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.fid-pill { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-secondary, #4b5563); }
.fid-pill-active { background: var(--zv-navy, #0F2554); color: #fff; }

.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; }

.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-kpi-card-clickable { cursor: pointer; }
.fid-kpi-card-clickable:hover { border-color: var(--zv-blue, #1490A8); }
.fid-kpi-icon { font-size: 18px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0; }
.fid-icon-blue { background: rgba(20,144,168,0.12); }
.fid-icon-amber { background: rgba(180,83,9,0.12); }
.fid-icon-green { background: rgba(22,163,74,0.12); }
.fid-icon-red { background: rgba(220,38,38,0.12); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-kpi-note { font-size: 10.5px; color: var(--zv-text-muted, #6b7280); margin-top: 2px; }
.fid-num-blue { color: #1490A8; }
.fid-num-amber { color: #b45309; }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }

.fid-chart-card { padding: 14px; }
.fid-chart-title { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); margin-bottom: 8px; }
.fid-chart-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }

.fid-chart-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 900px) { .fid-chart-grid-2 { grid-template-columns: 1fr; } }

.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; padding: 8px; }
.fid-row { cursor: pointer; }
.fid-row:hover { background: var(--zv-bg-surface-alt, #f9fafb); }
</style>
