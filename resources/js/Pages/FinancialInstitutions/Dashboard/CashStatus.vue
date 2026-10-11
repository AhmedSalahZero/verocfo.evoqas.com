<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Dashboard/CashStatus.vue

  Served by DashboardController@cashStatus. The "Cash Status" tab of
  the Financial Institutions Dashboard — per-currency cash position
  (Cash & Banks / Time Deposit / Certificate of Deposit / Total), the
  combined "Total Cash Facilities" card, one section per OD facility
  type the company has (mini KPI row, "Available Room by Bank" 3D
  donut, and a live "Bank Movement" chart with bank/facility
  pickers), then Medium/Long Term Loans and Leasing Contracts tables.

  Structure ported from CashVero's Dashboard/CashStatus.vue — see
  CashStatusDashboardService's header for the documented data-shape
  adaptations (no Certificate of Deposit concept in VeroCFO; Bank
  Movement picks a Facility rather than an Account Number).

  Audit H15 (Sept 2026): every figure under a currency tab is in that
  currency's own units (a USD account shows USD), with its EGP (main
  currency) equivalent underneath. Safes sit under their own currency,
  or the main currency — no longer under the first tab. When an
  account's foreign balance is not fully known the page says so.

  @layout    AppLayout
  @route     fi.dashboard
  @props     see CashStatusDashboardService::build()
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">
      <PageHeader
        :title="t('financialInstitutions.dashboard.cashStatus.dashboard')"
        :subtitle="t('financialInstitutions.dashboard.cashStatus.subtitle')"
      />

      <DashboardTabs active="cash" :urls="dashboardTabUrls" />

      <div class="zv-card fid-filter-bar">
        <div class="field-group">
          <label class="zv-label">{{ t('financialInstitutions.dashboard.cashStatus.asOfDate') }}</label>
          <input v-model="filterDate" type="date" class="zv-input"/>
        </div>
        <button class="zv-btn zv-btn-primary" @click="applyDateFilter">{{ t('common.apply') }}</button>
      </div>

      <div class="fid-pill-row">
        <button
          v-for="currency in selectedCurrencies"
          :key="currency"
          class="fid-pill"
          :class="{ 'fid-pill-active': activeCurrency === currency }"
          @click="activeCurrency = currency"
        >
          {{ currency }}
        </button>
      </div>

      <template v-if="activeCurrency">
        <!-- Current Cash Position -->
        <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.cashStatus.currentCashPosition') }} [{{ activeCurrency }}]</div>
        <div v-if="(incompleteNative?.[activeCurrency] || 0) > 0" class="zv-alert zv-alert-warning fid-native-warning">
          <span class="zv-alert-icon">⚠</span> {{ t('financialInstitutions.dashboard.cashStatus.nativeIncomplete', { count: incompleteNative[activeCurrency] }) }}
        </div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleDetail('cash')">
            <div class="fid-kpi-icon fid-icon-blue">🏦</div>
            <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.cashAndBanks') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(reports?.cash_and_banks?.[activeCurrency]) }}</p><p v-if="isForeign" class="fid-kpi-note">≈ {{ fmt(reportsFunctional?.cash_and_banks?.[activeCurrency]) }} {{ mainCurrency }}</p></div>
          </div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleDetail('td')">
            <div class="fid-kpi-icon fid-icon-amber">⏱</div>
            <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.timeDeposit') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(reports?.time_deposits?.[activeCurrency]) }}</p><p v-if="isForeign" class="fid-kpi-note">≈ {{ fmt(reportsFunctional?.time_deposits?.[activeCurrency]) }} {{ mainCurrency }}</p></div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-amber">📄</div>
            <div>
              <p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.certificateOfDeposit') }}</p>
              <p class="fid-kpi-value fid-num-amber">{{ fmt(reports?.certificate_of_deposits?.[activeCurrency]) }}</p>
              <p class="fid-kpi-note">{{ t('financialInstitutions.dashboard.cashStatus.cdNotModeledYet') }}</p>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-green">Σ</div>
            <div><p class="fid-kpi-label">{{ t('common.total') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(reports?.total?.[activeCurrency]) }}</p><p v-if="isForeign" class="fid-kpi-note">≈ {{ fmt(reportsFunctional?.total?.[activeCurrency]) }} {{ mainCurrency }}</p></div>
          </div>
        </div>

        <div v-if="openDetail === 'cash'" class="zv-card fid-detail-card">
          <table class="zv-table">
            <thead><tr><th>{{ t('financialInstitutions.dashboard.cashStatus.source') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.institution') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.accountNumber') }}</th><th class="fid-th-num">{{ t('common.amount') }} ({{ activeCurrency }})</th><th v-if="isForeign" class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.equivalentIn', { currency: mainCurrency }) }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, i) in (details?.[activeCurrency]?.cash_and_banks || [])" :key="i">
                <td>{{ row.source }}</td><td>{{ row.financial_institution_name }}</td><td>{{ row.account_number }}</td>
                <td class="fid-num">{{ row.amount === null ? '—' : fmt(row.amount) }}<span v-if="row.native_complete === false" class="fid-num-amber" :title="t('financialInstitutions.dashboard.cashStatus.nativeIncompleteRow')"> ⚠</span></td>
                <td v-if="isForeign" class="fid-num fid-muted">{{ fmt(row.amount_functional) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="openDetail === 'td'" class="zv-card fid-detail-card">
          <table class="zv-table">
            <thead><tr><th>{{ t('financialInstitutions.dashboard.cashStatus.institution') }}</th><th class="fid-th-num">{{ t('common.amount') }} ({{ activeCurrency }})</th><th v-if="isForeign" class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.equivalentIn', { currency: mainCurrency }) }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, i) in (details?.[activeCurrency]?.time_of_deposits || [])" :key="i">
                <td>{{ row.financial_institution_name }}</td><td class="fid-num">{{ fmt(row.amount) }}</td>
                <td v-if="isForeign" class="fid-num fid-muted">{{ row.amount_functional === null ? '—' : fmt(row.amount_functional) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Total Cash Facilities -->
        <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.cashStatus.totalCashFacilities') }} [{{ activeCurrency }}]</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">🎯</div><div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.limit') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(totalCard?.[activeCurrency]?.limit) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">📉</div><div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.outstanding') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(totalCard?.[activeCurrency]?.outstanding) }}</p><p v-if="isForeign" class="fid-kpi-note">≈ {{ fmt(totalCard?.[activeCurrency]?.outstanding_functional) }} {{ mainCurrency }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">✅</div><div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.available') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(totalCard?.[activeCurrency]?.room) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">%</div><div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.interest') }}</p><p class="fid-kpi-value">{{ fmt(totalCard?.[activeCurrency]?.interest_amount) }}</p></div></div>
        </div>

        <!-- Per OD-type sections -->
        <template v-for="section in visibleOdSections" :key="section.type">
          <div class="fid-section-heading">{{ t(odLabelKey(section.type)) }} [{{ activeCurrency }}]</div>

          <div class="fid-kpi-row-4 fid-mini">
            <div
              class="fid-kpi-card fid-mini-card fid-kpi-card-clickable"
              :class="{ 'fid-mini-card-active': openLimitDetail === section.type }"
              @click="toggleLimitDetail(section.type)"
            >
              <p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.limit') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(section.cardData?.[activeCurrency]?.limit) }}</p>
            </div>
            <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.outstanding') }}<span v-if="(section.cardData?.[activeCurrency]?.incomplete || 0) > 0" class="fid-num-amber" :title="t('financialInstitutions.dashboard.cashStatus.nativeIncompleteRow')"> ⚠</span></p><p class="fid-kpi-value fid-num-amber">{{ fmt(section.cardData?.[activeCurrency]?.outstanding) }}</p><p v-if="isForeign" class="fid-kpi-note">≈ {{ fmt(section.cardData?.[activeCurrency]?.outstanding_functional) }} {{ mainCurrency }}</p></div>
            <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.availableRoom') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(section.cardData?.[activeCurrency]?.room) }}</p></div>
            <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.cashStatus.interest') }}</p><p class="fid-kpi-value">{{ fmt(section.cardData?.[activeCurrency]?.interest_amount) }}</p></div>
          </div>

          <div v-if="openLimitDetail === section.type" class="zv-card fid-detail-card">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('financialInstitutions.dashboard.cashStatus.bank') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.limit') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.outstanding') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.availableRoom') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.interest') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in limitDetailRows(section)" :key="i">
                  <td>{{ row.item || '—' }}</td>
                  <td class="fid-num fid-num-blue">{{ fmt(row.limit) }}</td>
                  <td class="fid-num fid-num-amber">{{ fmt(row.end_balance) }}</td>
                  <td class="fid-num fid-num-green">{{ fmt(row.available_room) }}</td>
                  <td class="fid-num">{{ fmt(row.interest) }}</td>
                </tr>
                <tr v-if="!limitDetailRows(section).length"><td colspan="5" class="fid-muted" style="text-align:center;">{{ t('financialInstitutions.dashboard.cashStatus.noFacilityRows') }}</td></tr>
                <tr v-else class="fid-summary-row">
                  <td>{{ t('common.total') }}</td>
                  <td class="fid-num fid-num-blue">{{ fmt(sumLimitDetail(section, 'limit')) }}</td>
                  <td class="fid-num fid-num-amber">{{ fmt(sumLimitDetail(section, 'end_balance')) }}</td>
                  <td class="fid-num fid-num-green">{{ fmt(sumLimitDetail(section, 'available_room')) }}</td>
                  <td class="fid-num">{{ fmt(sumLimitDetail(section, 'interest')) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="fid-chart-grid-3">
            <div class="zv-card fid-chart-card">
              <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.cashStatus.availableRoomByBank') }}</h4>
              <DonutChart3D :key="`${section.type}-${activeCurrency}`" :data="roomDonutData(section)" />
            </div>
            <div class="zv-card fid-chart-card fid-chart-card-wide">
              <div class="fid-chart-header">
                <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.cashStatus.bankMovement') }}</h4>
                <div class="flex gap-2">
                  <select v-model="stateFor(section.type).bankId" class="zv-select fid-select-sm" @change="onBankChange(section)">
                    <option v-for="bank in section.banks?.[activeCurrency]" :key="bank.id" :value="String(bank.id)">{{ bank.name }}</option>
                  </select>
                  <select v-model="stateFor(section.type).facilityId" class="zv-select fid-select-sm" @change="onFacilityChange(section)">
                    <option value="">{{ t('financialInstitutions.dashboard.cashStatus.selectFacility') }}</option>
                    <option v-for="opt in stateFor(section.type).facilityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                  </select>
                </div>
              </div>
              <MultiLineChart :data="stateFor(section.type).chartData" :series-def="movementSeries"/>
            </div>
          </div>
        </template>

        <!-- Medium/Long Term Loans -->
        <template v-if="(mediumTermLoans?.[activeCurrency] || []).length">
          <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.cashStatus.mediumTermLoans') }} [{{ activeCurrency }}]</div>
          <div class="zv-card fid-table-card">
            <table class="zv-table">
              <thead><tr><th>{{ t('financialInstitutions.dashboard.cashStatus.bank') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.loan') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.limit') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.paid') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.outstanding') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.nextInstallment') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.pastDue') }}</th></tr></thead>
              <tbody>
                <template v-for="loan in mediumTermLoans[activeCurrency]" :key="loan.id">
                  <tr class="fid-row" @click="expandedId = expandedId === 'ms-'+loan.id ? null : 'ms-'+loan.id">
                    <td>{{ loan.institution_name }}</td><td>{{ loan.name }}</td>
                    <td class="fid-num fid-num-blue">{{ loan.limit_formatted }}</td>
                    <td class="fid-num fid-num-green">{{ loan.paid_formatted }}</td>
                    <td class="fid-num fid-num-amber">{{ loan.outstanding_formatted }}</td>
                    <td>{{ loan.next_installment_date || '—' }} <span v-if="loan.next_installment_amount">({{ loan.next_installment_amount }})</span></td>
                    <td class="fid-num fid-num-red">{{ loan.total_past_due_remaining_formatted }}</td>
                  </tr>
                  <tr v-if="expandedId === 'ms-'+loan.id"><td colspan="7">
                    <div v-if="(loan.past_dues || []).length" class="fid-pastdue-list">
                      <span v-for="(pd, i) in loan.past_dues" :key="i">{{ pd.date }}: <span class="fid-num-red">{{ fmt(pd.remaining) }}</span></span>
                    </div>
                    <div v-else class="fid-muted">{{ t('financialInstitutions.dashboard.cashStatus.noPastDueInstallments') }}</div>
                  </td></tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Leasing Contracts -->
        <template v-if="(leasingContracts?.[activeCurrency] || []).length">
          <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.cashStatus.leasingContracts') }} [{{ activeCurrency }}]</div>
          <div class="zv-card fid-table-card">
            <table class="zv-table">
              <thead><tr><th>{{ t('financialInstitutions.dashboard.cashStatus.leasingCompany') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.contract') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.limit') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.paid') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.outstanding') }}</th><th>{{ t('financialInstitutions.dashboard.cashStatus.nextInstallment') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.cashStatus.pastDue') }}</th></tr></thead>
              <tbody>
                <template v-for="lease in leasingContracts[activeCurrency]" :key="lease.id">
                  <tr class="fid-row" @click="expandedId = expandedId === 'lc-'+lease.id ? null : 'lc-'+lease.id">
                    <td>{{ lease.institution_name }}</td><td>{{ lease.name }}</td>
                    <td class="fid-num fid-num-blue">{{ lease.limit_formatted }}</td>
                    <td class="fid-num fid-num-green">{{ lease.paid_formatted }}</td>
                    <td class="fid-num fid-num-amber">{{ lease.outstanding_formatted }}</td>
                    <td>{{ lease.next_installment_date || '—' }} <span v-if="lease.next_installment_amount">({{ lease.next_installment_amount }})</span></td>
                    <td class="fid-num fid-num-red">{{ lease.total_past_due_remaining_formatted }}</td>
                  </tr>
                  <tr v-if="expandedId === 'lc-'+lease.id"><td colspan="7">
                    <div v-if="(lease.past_dues || []).length" class="fid-pastdue-list">
                      <span v-for="(pd, i) in lease.past_dues" :key="i">{{ pd.date }}: <span class="fid-num-red">{{ fmt(pd.remaining) }}</span></span>
                    </div>
                    <div v-else class="fid-muted">{{ t('financialInstitutions.dashboard.cashStatus.noPastDueInstallments') }}</div>
                  </td></tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>
      </template>
    </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import DashboardTabs from '@/Components/FinancialInstitutions/DashboardTabs.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'
import MultiLineChart from '@/Components/Charts/MultiLineChart.vue'

const { t } = useI18n()

const props = defineProps({
  selectedCurrencies: { type: Array, default: () => [] },
  reports: { type: Object, default: () => ({}) },
  reportsFunctional: { type: Object, default: () => ({}) },
  incompleteNative: { type: Object, default: () => ({}) },
  mainCurrency: { type: String, default: 'EGP' },
  details: { type: Object, default: () => ({}) },
  totalCard: { type: Object, default: () => ({}) },
  odSections: { type: Object, default: () => ({}) },
  odTypeLabels: { type: Object, default: () => ({}) },
  mediumTermLoans: { type: Object, default: () => ({}) },
  leasingContracts: { type: Object, default: () => ({}) },
  date: { type: String, default: '' },
  filterUrl: { type: String, default: '' },
  dashboardTabUrls: { type: Object, default: () => ({}) },
})

const activeCurrency = ref(props.selectedCurrencies[0] || '')
// H15: show the main-currency equivalent only on a foreign-currency tab.
const isForeign = computed(() => !!activeCurrency.value && activeCurrency.value !== props.mainCurrency)
const filterDate = ref(props.date)
const openDetail = ref(null)
const openLimitDetail = ref(null)
const expandedId = ref(null)

function toggleDetail(key) { openDetail.value = openDetail.value === key ? null : key }
function toggleLimitDetail(sectionType) { openLimitDetail.value = openLimitDetail.value === sectionType ? null : sectionType }
function limitDetailRows(section) { return section.roomRows?.[activeCurrency.value] || [] }
function sumLimitDetail(section, key) {
  return limitDetailRows(section).reduce((s, r) => s + Number(r[key] || 0), 0)
}

function applyDateFilter() {
  router.get(props.filterUrl, { date: filterDate.value }, { preserveScroll: true, preserveState: true })
}

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }

const odLabelKeys = {
  fully_secured: 'financialInstitutions.dashboard.cashStatus.odFullySecured',
  clean_od: 'financialInstitutions.dashboard.cashStatus.odClean',
  commercial_paper: 'financialInstitutions.dashboard.cashStatus.odCommercialPaper',
  contract_so: 'financialInstitutions.dashboard.cashStatus.odContractSo',
}
function odLabelKey(type) { return odLabelKeys[type] || type }

const visibleOdSections = computed(() =>
  Object.values(props.odSections || {}).filter(s => s.has?.[activeCurrency.value])
)

function roomDonutData(section) {
  const rows = section.roomRows?.[activeCurrency.value] || []
  const byBank = {}
  for (const row of rows) {
    const name = row.item || '—'
    byBank[name] = (byBank[name] || 0) + Number(row.available_room || 0)
  }
  return Object.entries(byBank).filter(([, v]) => v !== 0).map(([category, value]) => ({ category, value }))
}

const movementSeries = [
  { field: 'debit', name: t('financialInstitutions.dashboard.cashStatus.cashIn'), color: '#16a34a' },
  { field: 'credit', name: t('financialInstitutions.dashboard.cashStatus.cashOut'), color: '#dc2626' },
  { field: 'end_balance', name: t('financialInstitutions.dashboard.cashStatus.endBalance'), color: '#1490A8' },
]

const movementState = reactive({})
function stateFor(type) {
  if (!movementState[type]) {
    movementState[type] = { bankId: '', facilityId: '', facilityOptions: [], chartData: [] }
  }
  return movementState[type]
}

async function onBankChange(section) {
  const state = stateFor(section.type)
  state.facilityOptions = []
  state.facilityId = ''
  state.chartData = []
  if (!state.bankId) return
  try {
    const { data } = await window.axios.get(route('fi.dashboard.cash-status.facilities'), {
      params: { facility_type: section.type, currency: activeCurrency.value, bank_id: state.bankId },
    })
    state.facilityOptions = data?.data || []
    if (state.facilityOptions.length) {
      state.facilityId = state.facilityOptions[0].value
      await onFacilityChange(section)
    }
  } catch (e) { console.warn('Failed to load facilities', e) }
}

async function onFacilityChange(section) {
  const state = stateFor(section.type)
  if (!state.facilityId) { state.chartData = []; return }
  try {
    const { data } = await window.axios.get(route('fi.dashboard.cash-status.movement'), {
      params: { facility_id: state.facilityId },
    })
    state.chartData = data?.chart_data || []
  } catch (e) { console.warn('Failed to load movement chart', e) }
}

// Prime the first bank for every visible section on load.
for (const section of Object.values(props.odSections || {})) {
  const banks = section.banks?.[activeCurrency.value] || []
  if (banks.length) {
    stateFor(section.type).bankId = String(banks[0].id)
    onBankChange(section)
  }
}
</script>

<style>
.fid-filter-bar { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 1.5rem; padding: 12px; }
.fid-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.fid-pill { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-secondary, #4b5563); }
.fid-pill-active { background: var(--zv-navy, #0F2554); color: #fff; }

.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; }

.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
.fid-mini { margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-kpi-card-clickable { cursor: pointer; }
.fid-kpi-card-clickable:hover { border-color: var(--zv-blue, #1490A8); }
.fid-mini-card { padding: 10px 14px; }
.fid-mini-card-active { border-color: var(--zv-blue, #1490A8); box-shadow: 0 0 0 1px var(--zv-blue, #1490A8); }
.fid-kpi-icon { font-size: 18px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0; }
.fid-icon-blue { background: rgba(20,144,168,0.12); }
.fid-icon-amber { background: rgba(180,83,9,0.12); }
.fid-icon-green { background: rgba(22,163,74,0.12); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-kpi-note { font-size: 10.5px; color: var(--zv-text-muted, #6b7280); margin-top: 2px; }
.fid-num-blue { color: #1490A8; }
.fid-num-amber { color: #b45309; }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }

.fid-detail-card, .fid-table-card { overflow-x: auto; margin-bottom: 1rem; }
.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; padding: 8px; }
.fid-row { cursor: pointer; }
.fid-row:hover { background: var(--zv-bg-surface-alt, #f9fafb); }
.fid-pastdue-list { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 12px; padding: 6px 8px; }

.fid-chart-grid-3 { display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-chart-grid-3 { grid-template-columns: 1fr; } }
.fid-chart-card { padding: 14px; }
.fid-chart-title { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); margin-bottom: 8px; }
.fid-chart-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.fid-select-sm { font-size: 12px; padding: 4px 8px; }
.fid-native-warning { margin: 0 0 12px; font-size: 12px; }
</style>
