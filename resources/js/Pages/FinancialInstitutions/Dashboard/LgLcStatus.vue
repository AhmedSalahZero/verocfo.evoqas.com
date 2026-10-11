<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Dashboard/LgLcStatus.vue

  Served by DashboardController@lglcStatus. The "LG & LC Status" tab
  of the Financial Institutions Dashboard — for each of Letters of
  Guarantee and Letters of Credit: Limit / Outstanding / Room / Cash
  Cover KPIs, "Outstanding per Type" and "Outstanding per Financial
  Institution" 3D donuts, and a per-bank/type/status detail table
  with a "Limit Details by Bank" drill-down.

  Structure ported from CashVero's Dashboard/LGLCStatus.vue — see
  LgLcStatusDashboardService's header for the documented data-shape
  adaptation (VeroCFO's Agreement → many Issuances design vs.
  CashVero's single-instrument LG/LC contract).

  @layout    AppLayout
  @route     fi.dashboard.lglc
  @props     see LgLcStatusDashboardService::build()
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">
      <PageHeader
        :title="t('financialInstitutions.dashboard.lgLcStatus.dashboard')"
        :subtitle="t('financialInstitutions.dashboard.lgLcStatus.subtitle')"
      />

      <DashboardTabs active="lglc" :urls="dashboardTabUrls" />

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

      <template v-for="section in facilitySections" :key="section.key">
        <template v-if="section.data.canShow?.[activeCurrency]">
          <div class="fid-section-heading">{{ t(section.labelKey) }} [{{ activeCurrency }}]</div>

          <div class="fid-kpi-row-4">
            <div class="fid-kpi-card" @click="openLimitDetails(section.key)">
              <div class="fid-kpi-icon fid-icon-blue">🎯</div>
              <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.lgLcStatus.limit') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(reportFor(section.key).limit) }}</p></div>
            </div>
            <div class="fid-kpi-card fid-mini-card">
              <div class="fid-kpi-icon fid-icon-amber">📉</div>
              <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.lgLcStatus.outstandingBalance') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(reportFor(section.key).outstanding_balance) }}</p></div>
            </div>
            <div class="fid-kpi-card fid-mini-card">
              <div class="fid-kpi-icon fid-icon-green">✅</div>
              <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.lgLcStatus.availableRoom') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(reportFor(section.key).room) }}</p></div>
            </div>
            <div class="fid-kpi-card fid-mini-card">
              <div class="fid-kpi-icon fid-icon-amber">🛡</div>
              <div><p class="fid-kpi-label">{{ t('financialInstitutions.dashboard.lgLcStatus.cashCover') }}</p><p class="fid-kpi-value">{{ fmt(reportFor(section.key).cash_cover) }}</p></div>
            </div>
          </div>

          <div class="fid-chart-grid-2">
            <div class="zv-card fid-chart-card">
              <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.lgLcStatus.outstandingPerType') }}</h4>
              <DonutChart3D :data="charts.type?.[section.key]?.[activeCurrency] || []" />
            </div>
            <div class="zv-card fid-chart-card">
              <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.lgLcStatus.outstandingPerInstitution') }}</h4>
              <DonutChart3D :data="charts.institution?.[section.key]?.[activeCurrency] || []" :colors="['#b45309', '#1490A8', '#16a34a', '#0F2554', '#dc2626']" />
            </div>
          </div>

          <div class="zv-card fid-table-card">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>{{ t('financialInstitutions.dashboard.lgLcStatus.institution') }}</th>
                  <th>{{ t('financialInstitutions.dashboard.lgLcStatus.type') }}</th>
                  <th>{{ t('financialInstitutions.dashboard.lgLcStatus.source') }}</th>
                  <th>{{ t('financialInstitutions.dashboard.lgLcStatus.status') }}</th>
                  <th v-if="section.key === 'lg'" class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.count') }}</th>
                  <th v-if="section.key === 'lc'" class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.limit') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.outstanding') }}</th>
                  <th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.cashCover') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in tableRows(section.key)" :key="i">
                  <td class="fid-muted">{{ i + 1 }}</td>
                  <td>{{ row.financial_institution_name }}</td>
                  <td><span class="fid-badge">{{ row.type }}</span></td>
                  <td class="fid-muted">{{ row.source }}</td>
                  <td><span class="fid-badge" :class="row.status === 'Expired' ? 'fid-badge-red' : 'fid-badge-green'">{{ row.status }}</span></td>
                  <td v-if="section.key === 'lg'" class="fid-num">{{ row.count }}</td>
                  <td v-if="section.key === 'lc'" class="fid-num fid-num-blue">{{ fmt(row.limit) }}</td>
                  <td class="fid-num fid-num-amber">{{ fmt(row.outstanding) }}</td>
                  <td class="fid-num">{{ fmt(row.cash_cover) }}</td>
                </tr>
                <tr v-if="!tableRows(section.key).length"><td colspan="8" class="fid-muted" style="text-align:center; padding: 24px;">{{ t('financialInstitutions.dashboard.lgLcStatus.noneOutstanding') }} {{ activeCurrency }}.</td></tr>
              </tbody>
            </table>
          </div>
        </template>
      </template>

      <!-- Limit Details modal -->
      <div v-if="limitDetailsModal" class="fid-modal-backdrop" @click.self="limitDetailsModal = null">
        <div class="zv-card fid-modal">
          <h2 class="fid-chart-title" style="font-size:15px; margin-bottom: 12px;">{{ t('financialInstitutions.dashboard.lgLcStatus.limitDetails') }}</h2>
          <table class="zv-table">
            <thead><tr><th>{{ t('financialInstitutions.dashboard.lgLcStatus.institution') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.limit') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.outstanding') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.room') }}</th><th class="fid-th-num">{{ t('financialInstitutions.dashboard.lgLcStatus.cashCover') }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, i) in limitDetailsModal.rows" :key="i">
                <td>{{ row.financial_institution_name }}</td>
                <td class="fid-num fid-num-blue">{{ fmt(row.limit) }}</td>
                <td class="fid-num fid-num-amber">{{ fmt(row.outstanding) }}</td>
                <td class="fid-num fid-num-green">{{ fmt(row.room) }}</td>
                <td class="fid-num">{{ fmt(row.cash_cover) }}</td>
              </tr>
              <tr v-if="!limitDetailsModal.rows.length"><td colspan="5" class="fid-muted" style="text-align:center;">{{ t('financialInstitutions.dashboard.lgLcStatus.noFacilitiesFound') }}</td></tr>
              <tr v-if="limitDetailsModal.rows.length" class="fid-summary-row">
                <td>{{ t('common.total') }}</td>
                <td class="fid-num fid-num-blue">{{ fmt(limitDetailsModal.totals.limit) }}</td>
                <td class="fid-num fid-num-amber">{{ fmt(limitDetailsModal.totals.outstanding) }}</td>
                <td class="fid-num fid-num-green">{{ fmt(limitDetailsModal.totals.room) }}</td>
                <td class="fid-num">{{ fmt(limitDetailsModal.totals.cash_cover) }}</td>
              </tr>
            </tbody>
          </table>
          <div style="display:flex; justify-content:flex-end; margin-top:12px;">
            <button class="zv-btn zv-btn-secondary" @click="limitDetailsModal = null">{{ t('common.close') }}</button>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import DashboardTabs from '@/Components/FinancialInstitutions/DashboardTabs.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'

const { t } = useI18n()

const props = defineProps({
  selectedCurrencies: { type: Array, default: () => [] },
  lg: { type: Object, default: () => ({}) },
  lc: { type: Object, default: () => ({}) },
  dashboardTabUrls: { type: Object, default: () => ({}) },
})

const activeCurrency = ref(props.selectedCurrencies[0] || '')

const facilitySections = computed(() => [
  { key: 'lg', labelKey: 'financialInstitutions.dashboard.lgLcStatus.lettersOfGuarantee', data: props.lg },
  { key: 'lc', labelKey: 'financialInstitutions.dashboard.lgLcStatus.lettersOfCredit', data: props.lc },
])

function reportFor(key) { return props[key]?.reports?.[activeCurrency.value] || {} }
function tableRows(key) { return props[key]?.tableRows?.[activeCurrency.value] || [] }

const charts = computed(() => ({
  type: { lg: props.lg?.charts?.type, lc: props.lc?.charts?.type },
  institution: { lg: props.lg?.charts?.institution, lc: props.lc?.charts?.institution },
}))

const limitDetailsModal = ref(null)
function openLimitDetails(key) {
  const rows = props[key]?.reports?.[activeCurrency.value]?.limitDetailsByBank || []
  const totals = rows.reduce((acc, r) => ({
    limit: acc.limit + Number(r.limit || 0),
    outstanding: acc.outstanding + Number(r.outstanding || 0),
    room: acc.room + Number(r.room || 0),
    cash_cover: acc.cash_cover + Number(r.cash_cover || 0),
  }), { limit: 0, outstanding: 0, room: 0, cash_cover: 0 })
  limitDetailsModal.value = { rows, totals }
}

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }
</script>

<style>
.fid-chart-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 900px) { .fid-chart-grid-2 { grid-template-columns: 1fr; } }

.fid-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: rgba(20,144,168,0.12); color: #1490A8; }
.fid-badge-green { background: rgba(22,163,74,0.12); color: #16a34a; }
.fid-badge-red { background: rgba(239,68,68,0.12); color: #dc2626; }

.fid-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
.fid-modal { width: 100%; max-width: 720px; max-height: 85vh; overflow: auto; padding: 20px; }
.fid-summary-row { font-weight: 700; background: var(--zv-bg-surface-alt, #f9fafb); }
</style>
