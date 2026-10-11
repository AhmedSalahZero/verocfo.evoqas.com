<!--
══════════════════════════════════════════════════════════════════
  Statements/LcStatement/Result.vue

  The LC Statement itself — every LC movement (issuance, marked
  paid, settlement) for the filtered set, each with a plain-English
  Comment. Grouped by Supplier or Bank client-side per
  filters.group_by ('none' shows one flat list). Same pattern as
  Statements/LgStatement/Result.vue.

  @layout    AppLayout
  @route     fi.statements.lc.result
  @props     rows, totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.statements.lc.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('financialInstitutions.statements.lcStatementResult.backToLcStatement') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">{{ t('financialInstitutions.statements.lcStatementResult.lcStatement') }}</h1>
        <p class="zv-page-subtitle">{{ totals.movement_count }} {{ t('financialInstitutions.statements.lcStatementResult.movementsInThisSelection') }}</p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('financialInstitutions.statements.lcStatementResult.exportToExcel') }}
      </a>
    </div>

    <div class="lcs-kpi-grid">
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lcStatementResult.movements') }}</div>
        <div class="zv-kpi-value">{{ totals.movement_count }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lcStatementResult.totalIssued') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_issued) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lcStatementResult.markedPaid') }}</div>
        <div class="zv-kpi-value">{{ totals.paid_count }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lcStatementResult.settlements') }}</div>
        <div class="zv-kpi-value">{{ totals.settled_count }}</div>
      </div>
    </div>

    <div v-if="rows.length === 0" class="zv-card">
      <div class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.lcStatementResult.noMovementsInThisSelection') }}</div>
      </div>
    </div>

    <div v-else>
      <div v-for="group in groupedRows" :key="group.label" class="zv-card lcs-group-card">
        <div v-if="filters.group_by !== 'none'" class="lcs-group-header">
          {{ group.label }}
          <span class="lcs-group-count">{{ group.rows.length }} {{ t('financialInstitutions.statements.lcStatementResult.movements') }}</span>
        </div>

        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('common.date') }}</th>
                <th>{{ t('financialInstitutions.statements.lcStatementResult.lcCode') }}</th>
                <th v-if="filters.group_by !== 'supplier'">{{ t('financialInstitutions.statements.lcStatementResult.supplier') }}</th>
                <th>{{ t('financialInstitutions.statements.lcStatementResult.lcType') }}</th>
                <th v-if="filters.group_by !== 'bank'">{{ t('financialInstitutions.statements.lcStatementResult.bank') }}</th>
                <th>{{ t('financialInstitutions.statements.lcStatementResult.event') }}</th>
                <th class="lcs-th-num">{{ t('financialInstitutions.statements.lcStatementResult.amount') }}</th>
                <th class="lcs-th-num">{{ t('financialInstitutions.statements.lcStatementResult.cashCover') }}</th>
                <th class="lcs-th-num">{{ t('financialInstitutions.statements.lcStatementResult.commissionRate') }}</th>
                <th>{{ t('financialInstitutions.statements.lcStatementResult.dueDate') }}</th>
                <th>{{ t('financialInstitutions.statements.lcStatementResult.comment') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in group.rows" :key="idx" class="lcs-row" :class="{ 'lcs-row-reversed': row.is_reversed }">
                <td class="lcs-muted">{{ fmtDate(row.date) }}</td>
                <td>{{ row.lc_code || '—' }}</td>
                <td v-if="filters.group_by !== 'supplier'">{{ row.supplier_name }}</td>
                <td>{{ row.lc_type }}</td>
                <td v-if="filters.group_by !== 'bank'">{{ row.bank_name }}</td>
                <td><span class="lcs-badge" :class="`lcs-badge-${row.event_type}`">{{ eventLabel(row.event_type) }}</span></td>
                <td class="lcs-num">{{ fmt(row.amount) }}</td>
                <td class="lcs-num">{{ fmt(row.cash_cover) }}</td>
                <td class="lcs-num">{{ Number(row.commission_rate).toFixed(2) }}%</td>
                <td class="lcs-muted">{{ fmtDate(row.due_date) }}</td>
                <td class="lcs-comment">{{ row.comment }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  rows:    { type: Array, default: () => [] },
  totals:  { type: Object, required: true },
  filters: { type: Object, default: () => ({ group_by: 'none' }) },
})

const groupedRows = computed(() => {
  if (props.filters.group_by === 'none') {
    return [{ label: '', rows: props.rows }]
  }
  const key = props.filters.group_by === 'supplier' ? 'supplier_name' : 'bank_name'
  const groups = {}
  for (const row of props.rows) {
    const label = row[key] || '—'
    if (!groups[label]) groups[label] = []
    groups[label].push(row)
  }
  return Object.keys(groups).sort().map(label => ({ label, rows: groups[label] }))
})

function eventLabel(type) {
  return {
    issuance: t('financialInstitutions.statements.lcStatementResult.eventIssuance'),
    mark_paid: t('financialInstitutions.statements.lcStatementResult.eventMarkPaid'),
    settlement: t('financialInstitutions.statements.lcStatementResult.eventSettlement'),
  }[type] ?? type
}

const exportUrl = computed(() => {
  const params = new URLSearchParams()
  Object.entries(props.filters).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') params.set(k, v) })
  return route('fi.statements.lc.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.lcs-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1rem 0 1.5rem; }
@media (max-width: 900px) { .lcs-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lcs-kpi-grid { grid-template-columns: 1fr; } }

.lcs-group-card { margin-bottom: 1rem; }
.lcs-group-header { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 14px; color: var(--zv-navy, #0F2554); padding: 0.5rem 0 0.75rem; border-bottom: 1px solid var(--zv-border, #e5e7eb); margin-bottom: 0.5rem; }
.lcs-group-count { font-weight: 400; font-size: 12px; color: var(--zv-text-muted); }

.lcs-th-num { text-align: end; }
.lcs-num { text-align: end; font-variant-numeric: tabular-nums; }
.lcs-muted { color: var(--zv-text-muted); white-space: nowrap; }
.lcs-comment { color: var(--zv-text-secondary); font-size: 12.5px; max-width: 380px; }
.lcs-row:hover { background: var(--zv-bg-surface-alt); }
.lcs-row-reversed { opacity: 0.55; text-decoration: line-through; }

.lcs-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.lcs-badge-issuance { background: rgba(20,144,168,0.12); color: #1490A8; }
.lcs-badge-mark_paid { background: rgba(34,197,94,0.12); color: #16a34a; }
.lcs-badge-settlement { background: rgba(107,114,128,0.15); color: #4b5563; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
