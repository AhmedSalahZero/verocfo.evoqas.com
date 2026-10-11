<!--
══════════════════════════════════════════════════════════════════
  Statements/WithdrawalStatement/Result.vue

  The Withdrawal Statement itself — every OD facility withdrawal in
  the filtered set: due date, amount, settled, remaining, status,
  traceable by bank. Click a row to expand its own settlement
  history (which FacilitySettlement paid it down, and when).
  Grouped by Bank or Facility client-side per filters.group_by.

  @layout    AppLayout
  @route     fi.statements.withdrawal.result
  @props     rows, totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.statements.withdrawal.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('financialInstitutions.statements.withdrawalStatementResult.backToWithdrawalStatement') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">{{ t('financialInstitutions.statements.withdrawalStatementResult.withdrawalStatement') }}</h1>
        <p class="zv-page-subtitle">{{ totals.withdrawal_count }} {{ t('financialInstitutions.statements.withdrawalStatementResult.withdrawalsInThisSelection') }}</p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('financialInstitutions.statements.withdrawalStatementResult.exportToExcel') }}
      </a>
    </div>

    <div class="ws-kpi-grid">
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.withdrawalStatementResult.withdrawals') }}</div>
        <div class="zv-kpi-value">{{ totals.withdrawal_count }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.withdrawalStatementResult.totalAmount') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_amount) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.withdrawalStatementResult.totalRemaining') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_remaining) }}</div>
      </div>
      <div class="zv-kpi-card" :class="{ 'ws-kpi-warn': totals.overdue_count > 0 }">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.withdrawalStatementResult.overdue') }}</div>
        <div class="zv-kpi-value" :class="{ 'ws-kpi-warn-value': totals.overdue_count > 0 }">{{ totals.overdue_count }}</div>
      </div>
    </div>

    <div v-if="rows.length === 0" class="zv-card">
      <div class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.withdrawalStatementResult.noWithdrawalsInThisSelection') }}</div>
      </div>
    </div>

    <div v-else>
      <div v-for="group in groupedRows" :key="group.label" class="zv-card ws-group-card">
        <div v-if="filters.group_by !== 'none'" class="ws-group-header">
          {{ group.label }}
          <span class="ws-group-count">{{ group.rows.length }} {{ t('financialInstitutions.statements.withdrawalStatementResult.withdrawals') }}</span>
        </div>

        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th></th>
                <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.withdrawalDate') }}</th>
                <th v-if="filters.group_by !== 'bank'">{{ t('financialInstitutions.statements.withdrawalStatementResult.bank') }}</th>
                <th v-if="filters.group_by !== 'facility'">{{ t('financialInstitutions.statements.withdrawalStatementResult.facility') }}</th>
                <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.dueDate') }}</th>
                <th class="ws-th-num">{{ t('financialInstitutions.statements.withdrawalStatementResult.amount') }}</th>
                <th class="ws-th-num">{{ t('financialInstitutions.statements.withdrawalStatementResult.settled') }}</th>
                <th class="ws-th-num">{{ t('financialInstitutions.statements.withdrawalStatementResult.remaining') }}</th>
                <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.status') }}</th>
                <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.description') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in group.rows" :key="row.id">
                <tr class="ws-row" :class="{ 'ws-row-overdue': row.is_overdue }" @click="toggle(row.id)">
                  <td class="ws-expand">
                    <svg v-if="row.settlements.length" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5" :class="{ 'ws-expand-open': expanded.has(row.id) }">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                  </td>
                  <td class="ws-muted">{{ fmtDate(row.withdrawal_date) }}</td>
                  <td v-if="filters.group_by !== 'bank'">{{ row.bank_name }}</td>
                  <td v-if="filters.group_by !== 'facility'">{{ row.facility_reference || row.facility_type }}</td>
                  <td class="ws-muted">{{ fmtDate(row.due_date) }}</td>
                  <td class="ws-num">{{ fmt(row.amount) }}</td>
                  <td class="ws-num">{{ fmt(row.settled_amount) }}</td>
                  <td class="ws-num" :class="{ 'ws-remaining-warn': row.is_overdue }">{{ fmt(row.remaining_amount) }}</td>
                  <td><span class="ws-badge" :class="`ws-badge-${row.is_overdue ? 'overdue' : row.status}`">{{ statusLabel(row) }}</span></td>
                  <td class="ws-comment">{{ row.description }}</td>
                </tr>
                <tr v-if="expanded.has(row.id) && row.settlements.length" class="ws-detail-row">
                  <td :colspan="9 - (filters.group_by !== 'none' ? 1 : 0)">
                    <table class="ws-sub-table">
                      <thead>
                        <tr>
                          <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.settlementDate') }}</th>
                          <th class="ws-th-num">{{ t('financialInstitutions.statements.withdrawalStatementResult.amount') }}</th>
                          <th>{{ t('financialInstitutions.statements.withdrawalStatementResult.status') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(s, i) in row.settlements" :key="i">
                          <td class="ws-muted">{{ fmtDate(s.settlement_date) }}</td>
                          <td class="ws-num">{{ fmt(s.amount) }}</td>
                          <td>{{ s.status === 'reversed' ? t('financialInstitutions.statements.withdrawalStatementResult.reversed') : t('financialInstitutions.statements.withdrawalStatementResult.posted') }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  rows:    { type: Array, default: () => [] },
  totals:  { type: Object, required: true },
  filters: { type: Object, default: () => ({ group_by: 'none' }) },
})

const expanded = reactive(new Set())
function toggle(id) {
  if (expanded.has(id)) expanded.delete(id)
  else expanded.add(id)
}

const groupedRows = computed(() => {
  if (props.filters.group_by === 'none') {
    return [{ label: '', rows: props.rows }]
  }
  const key = props.filters.group_by === 'bank' ? 'bank_name' : 'facility_reference'
  const groups = {}
  for (const row of props.rows) {
    const label = row[key] || row.facility_type || '—'
    if (!groups[label]) groups[label] = []
    groups[label].push(row)
  }
  return Object.keys(groups).sort().map(label => ({ label, rows: groups[label] }))
})

function statusLabel(row) {
  if (row.is_overdue) return t('financialInstitutions.statements.withdrawalStatementResult.statusOverdue')
  return {
    open: t('financialInstitutions.statements.withdrawalStatementResult.statusOpen'),
    partial: t('financialInstitutions.statements.withdrawalStatementResult.statusPartial'),
    settled: t('financialInstitutions.statements.withdrawalStatementResult.statusSettled'),
  }[row.status] ?? row.status
}

const exportUrl = computed(() => {
  const params = new URLSearchParams()
  Object.entries(props.filters).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') params.set(k, v) })
  return route('fi.statements.withdrawal.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.ws-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1rem 0 1.5rem; }
@media (max-width: 900px) { .ws-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .ws-kpi-grid { grid-template-columns: 1fr; } }
.ws-kpi-warn { border-color: rgba(239,68,68,0.35); }
.ws-kpi-warn-value { color: #ef4444; }

.ws-group-card { margin-bottom: 1rem; }
.ws-group-header { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 14px; color: var(--zv-navy, #0F2554); padding: 0.5rem 0 0.75rem; border-bottom: 1px solid var(--zv-border, #e5e7eb); margin-bottom: 0.5rem; }
.ws-group-count { font-weight: 400; font-size: 12px; color: var(--zv-text-muted); }

.ws-th-num { text-align: end; }
.ws-num { text-align: end; font-variant-numeric: tabular-nums; }
.ws-muted { color: var(--zv-text-muted); white-space: nowrap; }
.ws-comment { color: var(--zv-text-secondary); font-size: 12.5px; max-width: 300px; }
.ws-row { cursor: pointer; }
.ws-row:hover { background: var(--zv-bg-surface-alt); }
.ws-row-overdue { background: rgba(239,68,68,0.04); }
.ws-remaining-warn { color: #dc2626; font-weight: 600; }
.ws-expand { width: 24px; }
.ws-expand svg { transition: transform 0.15s; color: var(--zv-text-muted); }
.ws-expand-open { transform: rotate(90deg); }

.ws-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.ws-badge-open { background: rgba(20,144,168,0.12); color: #1490A8; }
.ws-badge-partial { background: rgba(245,158,11,0.12); color: #b45309; }
.ws-badge-settled { background: rgba(34,197,94,0.12); color: #16a34a; }
.ws-badge-overdue { background: rgba(239,68,68,0.12); color: #dc2626; }

.ws-detail-row td { padding: 0 !important; background: var(--zv-bg-surface-alt); }
.ws-sub-table { width: 100%; margin: 0.25rem 0 0.75rem; }
.ws-sub-table th, .ws-sub-table td { padding: 4px 12px; font-size: 12px; }
.ws-sub-table th { color: var(--zv-text-muted); font-weight: 500; text-align: start; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
