<!--
══════════════════════════════════════════════════════════════════
  Accounting/GeneralLedger/Result.vue

  The ledger itself — every posted journal entry line on this
  account's own Chart of Accounts row, running balance, same layout
  as Bank Statement (Beginning Balance / Debit / Credit / End
  Balance / Comment). One addition Bank Statement doesn't need: a
  row can be expanded to see the OTHER side(s) of its journal entry
  too — reuses the same JSON endpoint the Journal Entries screen
  already uses (accounting.journal-entries.lines), so this is a
  real drill-down, not a placeholder.

  @layout    AppLayout
  @route     accounting.general-ledger.result
  @props     account, rows (paginator), totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[
        { label: t('accounting.generalLedger.result.backToGeneralLedger'), href: route('accounting.general-ledger.index') },
        { label: account.code },
      ]"
      :title="t('accounting.generalLedger.result.generalLedger') + ' — ' + account.code + ' · ' + account.name_en"
      :subtitle="totals.transaction_count + ' ' + t('accounting.generalLedger.result.transactionsInThisDateRange') + (costCenterLabel ? ' · ' + t('accounting.generalLedger.result.costCenter') + ': ' + costCenterLabel : '')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <a :href="exportUrl" class="zv-btn zv-btn-secondary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
          </svg>
          {{ t('accounting.generalLedger.result.exportToExcel') }}
        </a>
      </template>
    </PageHeader>

    <div v-if="filters.cost_center_id" class="zv-alert zv-alert-info mb-3">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span class="text-sm">{{ t('accounting.generalLedger.result.costCenterOpeningBalanceNote') }}</span>
    </div>

    <!-- The four figures that frame the ledger: what the account held
         before the range, what moved through it, and where it landed.
         Icons here are directional, not judgmental — a debit is not
         "good" and a credit is not "bad" — so the tones are the two
         neutral brand colors for the movement pair, navy for the
         opening figure and blue for the closing one. -->
    <div class="zv-kpi-row-4 gl-kpi-grid">
      <KpiCard
        :label="t('accounting.generalLedger.result.beginningBalance')"
        :value="fmt(totals.beginning_balance)"
        tone="navy"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      />
      <KpiCard
        :label="t('accounting.generalLedger.result.totalDebit')"
        :value="fmt(totals.total_debit)"
        tone="green"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4"/>'
      />
      <KpiCard
        :label="t('accounting.generalLedger.result.totalCredit')"
        :value="fmt(totals.total_credit)"
        tone="gold"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 20V8m0 0l-4 4m4-4l4 4"/>'
      />
      <KpiCard
        :label="t('accounting.generalLedger.result.endingBalance')"
        :value="fmt(totals.ending_balance)"
        tone="blue"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>'
      />
    </div>

    <div class="zv-card">
      <div v-if="rows.data.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('accounting.generalLedger.result.noTransactionsInThisDateRange') }}</div>
      </div>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('common.date') }}</th>
              <th>{{ t('accounting.generalLedger.result.entryNumber') }}</th>
              <th class="zv-table-num">{{ t('accounting.generalLedger.result.beginningBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.generalLedger.result.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.generalLedger.result.credit') }}</th>
              <th class="zv-table-num">{{ t('accounting.generalLedger.result.endBalance') }}</th>
              <th>{{ t('accounting.generalLedger.result.comment') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(row, idx) in rows.data" :key="row.id">
              <tr class="gl-row" @click="toggleExpand(row)">
                <td class="gl-muted">{{ rows.from + idx }}</td>
                <td class="gl-muted">{{ fmtDate(row.entry_date) }}</td>
                <td><span class="zv-code">{{ row.entry_number }}</span></td>
                <td class="zv-table-num">{{ fmt(row.beginning_balance) }}</td>
                <td class="zv-table-num">{{ fmt(row.debit) }}</td>
                <td class="zv-table-num">{{ fmt(row.credit) }}</td>
                <td class="zv-table-num gl-end-balance">{{ fmt(row.end_balance) }}</td>
                <td><div class="zv-cell-clamp">{{ row.description || row.narration }}</div></td>
                <td class="gl-expand-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5" :class="{ 'gl-rotated': expandedId === row.journal_entry_id }">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                  </svg>
                </td>
              </tr>
              <tr v-if="expandedId === row.journal_entry_id" class="zv-subrow gl-expand-row">
                <td colspan="9">
                  <div v-if="expandedLoading" class="gl-expand-loading">{{ t('common.loading') }}…</div>
                  <table v-else class="zv-table gl-sub-table">
                    <thead>
                      <tr>
                        <th>{{ t('accounting.generalLedger.result.account') }}</th>
                        <th>{{ t('accounting.generalLedger.result.description') }}</th>
                        <th class="zv-table-num">{{ t('accounting.generalLedger.result.debit') }}</th>
                        <th class="zv-table-num">{{ t('accounting.generalLedger.result.credit') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(line, i) in expandedLines" :key="i">
                        <td><span class="zv-code">{{ line.account_code }}</span><span class="zv-account-name">{{ line.account_name }}</span></td>
                        <td>{{ line.description }}</td>
                        <td class="zv-table-num">{{ Number(line.debit) > 0 ? fmt(line.debit) : '—' }}</td>
                        <td class="zv-table-num">{{ Number(line.credit) > 0 ? fmt(line.credit) : '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div v-if="rows.data.length > 0" class="zv-pagination">
        <span>{{ t('common.showing') }} {{ rows.from }}–{{ rows.to }} {{ t('common.of') }} {{ rows.total }}</span>
        <div class="zv-pagination-pages">
          <template v-for="(link, idx) in rows.links" :key="idx">
            <component
              :is="link.url ? Link : 'span'"
              :href="link.url || undefined"
              class="zv-page-btn"
              :class="{ active: link.active, 'gl-page-btn-disabled': !link.url }"
            >
              <svg v-if="link.label.includes('Previous')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
              <svg v-else-if="link.label.includes('Next')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
              <span v-else>{{ link.label }}</span>
            </component>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'

const { t } = useI18n()

const props = defineProps({
  account:     { type: Object, required: true },
  costCenter:  { type: Object, default: null },
  rows:        { type: Object, required: true },
  totals:      { type: Object, required: true },
  filters:     { type: Object, default: () => ({}) },
})

const costCenterLabel = computed(() => {
  return props.costCenter ? `${props.costCenter.code} — ${props.costCenter.name_en}` : null
})

const expandedId = ref(null)
const expandedLines = ref([])
const expandedLoading = ref(false)

async function toggleExpand(row) {
  if (expandedId.value === row.journal_entry_id) {
    expandedId.value = null
    return
  }
  expandedId.value = row.journal_entry_id
  expandedLoading.value = true
  expandedLines.value = []
  try {
    const res = await fetch(route('accounting.journal-entries.lines', row.journal_entry_id), {
      headers: { Accept: 'application/json' },
    })
    const data = await res.json()
    expandedLines.value = data.lines
  } finally {
    expandedLoading.value = false
  }
}

const exportUrl = computed(() => {
  const params = new URLSearchParams({
    account_id: props.filters.account_id,
    ...(props.filters.cost_center_id ? { cost_center_id: props.filters.cost_center_id } : {}),
    ...(props.filters.start_date ? { start_date: props.filters.start_date } : {}),
    ...(props.filters.end_date ? { end_date: props.filters.end_date } : {}),
  })
  return route('accounting.general-ledger.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
/* Columns and breakpoints now come from the shared .zv-kpi-row-4;
   only the vertical rhythm around the row is specific to this page. */
.gl-kpi-grid { margin: 1rem 0 1.5rem; }

.gl-end-balance { color: #f59e0b; font-weight: 600; }
.gl-muted { color: var(--zv-text-muted); }
.gl-row { cursor: pointer; }
.gl-row:hover { background: var(--zv-bg-surface-alt); }
.gl-expand-icon { color: var(--zv-text-muted); }
.gl-expand-icon svg { transition: transform 0.15s ease; }
.gl-expand-icon svg.gl-rotated { transform: rotate(90deg); }
/* Background now comes from the shared .zv-subrow tint. */
.gl-expand-row td { padding: 0.75rem 1rem; }
.gl-expand-loading { color: var(--zv-text-muted); font-size: 13px; padding: 0.5rem 0; }
.gl-sub-table { margin: 0; }
.gl-page-btn-disabled { opacity: 0.35; pointer-events: none; }
.zv-pagination-pages { display: flex; gap: 4px; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
