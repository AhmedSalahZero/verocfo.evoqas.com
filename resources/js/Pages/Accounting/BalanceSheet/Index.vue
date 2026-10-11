<!--
══════════════════════════════════════════════════════════════════
  Accounting/BalanceSheet/Index.vue

  Accounting > Financial Reports > Balance Sheet (Financial Reports
  Module Structure v1.0, Section 3.5). Same three view modes as
  Trial Balance (September 2026, per Mahmoud):
    - Single date — one as-of snapshot (default)
    - Monthly    — one fiscal year's real accounting periods, each
                   column is that period's END-DATE snapshot
    - Quarterly  — those same periods grouped into 4 quarters

  Year-end closing — Option A (confirmed with Mahmoud): 314/315 are
  never posted to, always computed live, PER COLUMN — this page just
  displays whatever BalanceSheetService returns for each period.

  @layout    AppLayout
  @route     accounting.balance-sheet.index
  @props     report, filters, costCenters, fiscalYears
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.balanceSheet.index.balanceSheet') }]"
      :title="t('accounting.balanceSheet.index.balanceSheet')"
      :subtitle="t('accounting.balanceSheet.index.whatTheCompanyOwnsOwesAndIsWorth')"
    />

    <!-- ── Filters ────────────────────────────────────────────── -->
    <div class="zv-card mb-5">
      <div class="form-section-body">
        <label class="zv-label mb-2 d-block">{{ t('accounting.balanceSheet.index.viewMode') }}</label>
        <div class="zv-tile-group fr-mode-group">
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'single' }" @click="form.mode = 'single'">
            <div class="zv-tile-label">{{ t('accounting.balanceSheet.index.singleDate') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'monthly' }" @click="form.mode = 'monthly'">
            <div class="zv-tile-label">{{ t('accounting.balanceSheet.index.monthly') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'quarterly' }" @click="form.mode = 'quarterly'">
            <div class="zv-tile-label">{{ t('accounting.balanceSheet.index.quarterly') }}</div>
          </button>
        </div>

        <div class="field-divider"></div>

        <div v-if="form.mode === 'single'" class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.balanceSheet.index.asOfDate') }}</label>
            <input v-model="form.as_of_date" type="date" class="zv-input" :max="today"/>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.balanceSheet.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.balanceSheet.index.allCostCenters')" />
          </div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.balanceSheet.index.fiscalYear') }}</label>
            <SelectBox v-model="form.fiscal_year_id" :options="fiscalYears" value-key="id" :label-fn="fy => `${fy.name} (${fy.status})`" :placeholder="t('accounting.balanceSheet.index.selectFiscalYear')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.balanceSheet.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.balanceSheet.index.allCostCenters')" />
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit" @click="viewReport">
            {{ t('accounting.balanceSheet.index.viewReport') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="!report" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('accounting.balanceSheet.index.pickAFiscalYearToSeeMonthlyQuarterly') }}</div></div></div>

    <template v-else>
      <div v-if="anyUnbalanced" class="zv-alert zv-alert-warning mb-3">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span class="text-sm">{{ t('accounting.balanceSheet.index.doesNotBalanceWarning') }}</span>
      </div>

      <!-- ── Assets ─────────────────────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.balanceSheet.index.assets') }}</div></div>
        <div class="zv-table-wrap fr-table-wrap">
          <table class="zv-table fr-table">
            <thead>
              <tr><th class="fr-sticky-col"></th><th v-for="p in report.periods" :key="p.key" class="zv-table-num">{{ p.label }}</th></tr>
            </thead>
            <tbody>
              <template v-for="section in report.assets.sections" :key="section.account_type">
                <tr class="is-section-row"><td class="fr-sticky-col">{{ accountTypeLabel(section.account_type) }}</td><td v-for="p in report.periods" :key="section.account_type+'-h-'+p.key"></td></tr>
                <tr v-for="(row, idx) in section.rows" :key="section.account_type + '-' + idx">
                  <td class="fr-sticky-col"><span v-if="row.code" class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                  <td v-for="p in report.periods" :key="row.id+'-'+idx+'-'+p.key" class="zv-table-num" :class="Number(row.periods[p.key]) < 0 ? 'zv-num-danger' : ''">{{ fmt(row.periods[p.key]) }}</td>
                </tr>
                <tr class="zv-summary-row">
                  <td class="fr-sticky-col">{{ t('accounting.balanceSheet.index.total') }} {{ accountTypeLabel(section.account_type) }}</td>
                  <td v-for="p in report.periods" :key="section.account_type+'-t-'+p.key" class="zv-table-num">{{ fmt(section.totals[p.key]) }}</td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr class="zv-summary-row is-grand-total-row">
                <td class="fr-sticky-col">{{ t('accounting.balanceSheet.index.totalAssets') }}</td>
                <td v-for="p in report.periods" :key="'ta-'+p.key" class="zv-table-num">{{ fmt(report.assets.totals[p.key]) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ── Liabilities ────────────────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.balanceSheet.index.liabilities') }}</div></div>
        <div class="zv-table-wrap fr-table-wrap">
          <table class="zv-table fr-table">
            <thead>
              <tr><th class="fr-sticky-col"></th><th v-for="p in report.periods" :key="p.key" class="zv-table-num">{{ p.label }}</th></tr>
            </thead>
            <tbody>
              <template v-for="section in report.liabilities.sections" :key="section.account_type">
                <tr class="is-section-row"><td class="fr-sticky-col">{{ accountTypeLabel(section.account_type) }}</td><td v-for="p in report.periods" :key="section.account_type+'-h-'+p.key"></td></tr>
                <tr v-for="row in section.rows" :key="section.account_type + '-' + row.id">
                  <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                  <td v-for="p in report.periods" :key="row.id+'-'+p.key" class="zv-table-num">{{ fmt(row.periods[p.key]) }}</td>
                </tr>
                <tr class="zv-summary-row">
                  <td class="fr-sticky-col">{{ t('accounting.balanceSheet.index.total') }} {{ accountTypeLabel(section.account_type) }}</td>
                  <td v-for="p in report.periods" :key="section.account_type+'-t-'+p.key" class="zv-table-num">{{ fmt(section.totals[p.key]) }}</td>
                </tr>
              </template>
              <tr v-if="report.liabilities.sections.length === 0"><td class="fr-sticky-col text-sm opacity-50">{{ t('accounting.balanceSheet.index.noLiabilities') }}</td><td v-for="p in report.periods" :key="'nl-'+p.key"></td></tr>
            </tbody>
            <tfoot>
              <tr class="zv-summary-row is-grand-total-row">
                <td class="fr-sticky-col">{{ t('accounting.balanceSheet.index.totalLiabilities') }}</td>
                <td v-for="p in report.periods" :key="'tl-'+p.key" class="zv-table-num">{{ fmt(report.liabilities.totals[p.key]) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ── Equity ─────────────────────────────────────────────── -->
      <div class="zv-card">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.balanceSheet.index.equity') }}</div></div>
        <div class="zv-table-wrap fr-table-wrap">
          <table class="zv-table fr-table">
            <thead>
              <tr><th class="fr-sticky-col"></th><th v-for="p in report.periods" :key="p.key" class="zv-table-num">{{ p.label }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in report.equity.sections[0]?.rows ?? []" :key="row.id">
                <td class="fr-sticky-col">
                  <span class="zv-code">{{ row.code }}</span> {{ row.name_en }}
                  <span v-if="row.code === '315' || row.code === '314'" class="zv-tag ml-2">{{ t('accounting.balanceSheet.index.computed') }}</span>
                </td>
                <td v-for="p in report.periods" :key="row.id+'-'+p.key" class="zv-table-num">{{ fmt(row.periods[p.key]) }}</td>
              </tr>
              <tr v-if="!report.equity.sections[0]?.rows?.length"><td class="fr-sticky-col text-sm opacity-50">{{ t('accounting.balanceSheet.index.noEquityActivity') }}</td><td v-for="p in report.periods" :key="'ne-'+p.key"></td></tr>
            </tbody>
            <tfoot>
              <tr class="zv-summary-row is-grand-total-row">
                <td class="fr-sticky-col">{{ t('accounting.balanceSheet.index.totalLiabilitiesAndEquity') }}</td>
                <td v-for="p in report.periods" :key="'tle-'+p.key" class="zv-table-num">{{ fmt(report.total_liabilities_and_equity[p.key]) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  report:      { type: Object, default: null },
  filters:     { type: Object, default: () => ({}) },
  costCenters: { type: Array, default: () => [] },
  fiscalYears: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  mode: props.filters.mode || 'single',
  as_of_date: props.filters.as_of_date || '',
  fiscal_year_id: props.filters.fiscal_year_id || '',
  cost_center_id: props.filters.cost_center_id || '',
})

const canSubmit = computed(() => form.mode === 'single' || !!form.fiscal_year_id)

function viewReport() {
  if (!canSubmit.value) return
  router.get(route('accounting.balance-sheet.index'), {
    mode: form.mode,
    fiscal_year_id: form.mode !== 'single' ? form.fiscal_year_id : undefined,
    cost_center_id: form.cost_center_id || undefined,
    as_of_date: form.mode === 'single' ? (form.as_of_date || undefined) : undefined,
  }, { preserveState: true, preserveScroll: true })
}

const anyUnbalanced = computed(() => {
  if (!props.report) return false
  return Object.values(props.report.is_balanced || {}).some(b => !b)
})

const TYPE_LABELS = {
  'Current Assets': 'index.currentAssets',
  'Fixed Assets': 'index.fixedAssets',
  'Long-Term Assets': 'index.longTermAssets',
  'Current Liabilities': 'index.currentLiabilities',
  'Long-term Liabilities': 'index.longTermLiabilities',
}
function accountTypeLabel(type) {
  const key = TYPE_LABELS[type]
  return key ? t('accounting.balanceSheet.' + key) : type
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.fr-mode-group { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 700px) { .fr-mode-group { grid-template-columns: 1fr; } }
.d-block { display: block; }

.fr-table-wrap { overflow-x: auto; }
.fr-table { border-collapse: collapse; }
.fr-sticky-col { position: sticky; left: 0; background: var(--zv-bg-surface); z-index: 2; min-width: 260px; text-align: left; }

.is-section-row td {
  background: var(--zv-bg-surface-alt);
  font-weight: 700;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--zv-text-muted);
}
.is-section-row .fr-sticky-col { background: var(--zv-bg-surface-alt); }
.is-grand-total-row td { font-size: 1.0625rem; border-top: 2px solid var(--zv-border); }
.zv-summary-row .fr-sticky-col, .is-grand-total-row .fr-sticky-col { background: var(--zv-bg-surface); }
</style>
