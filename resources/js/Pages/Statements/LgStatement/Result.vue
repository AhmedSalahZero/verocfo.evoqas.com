<!--
══════════════════════════════════════════════════════════════════
  Statements/LgStatement/Result.vue

  The LG / Cash Cover Statement itself — every LG movement (issuance,
  renewal, decrease, cancellation, liquidation) for the filtered set,
  each with a plain-English Comment. Grouped by Beneficiary or Bank
  client-side per filters.group_by ('none' shows one flat list).

  @layout    AppLayout
  @route     fi.statements.lg.result
  @props     rows, totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.statements.lg.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('financialInstitutions.statements.lgStatementResult.backToLgStatement') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">{{ t('financialInstitutions.statements.lgStatementResult.lgStatement') }}</h1>
        <p class="zv-page-subtitle">{{ totals.movement_count }} {{ t('financialInstitutions.statements.lgStatementResult.movementsInThisSelection') }}</p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('financialInstitutions.statements.lgStatementResult.exportToExcel') }}
      </a>
    </div>

    <div class="lgs-kpi-grid">
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lgStatementResult.movements') }}</div>
        <div class="zv-kpi-value">{{ totals.movement_count }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lgStatementResult.totalIssued') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_issued) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lgStatementResult.cancellations') }}</div>
        <div class="zv-kpi-value">{{ totals.cancelled_count }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.lgStatementResult.liquidations') }}</div>
        <div class="zv-kpi-value">{{ totals.liquidated_count }}</div>
      </div>
    </div>

    <div v-if="rows.length === 0" class="zv-card">
      <div class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.lgStatementResult.noMovementsInThisSelection') }}</div>
      </div>
    </div>

    <div v-else>
      <div v-for="group in groupedRows" :key="group.label" class="zv-card lgs-group-card">
        <div v-if="filters.group_by !== 'none'" class="lgs-group-header">
          {{ group.label }}
          <span class="lgs-group-count">{{ group.rows.length }} {{ t('financialInstitutions.statements.lgStatementResult.movements') }}</span>
        </div>

        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('common.date') }}</th>
                <th>{{ t('financialInstitutions.statements.lgStatementResult.lgCode') }}</th>
                <th v-if="filters.group_by !== 'beneficiary'">{{ t('financialInstitutions.statements.lgStatementResult.beneficiary') }}</th>
                <th>{{ t('financialInstitutions.statements.lgStatementResult.lgType') }}</th>
                <th v-if="filters.group_by !== 'bank'">{{ t('financialInstitutions.statements.lgStatementResult.bank') }}</th>
                <th>{{ t('financialInstitutions.statements.lgStatementResult.event') }}</th>
                <th class="lgs-th-num">{{ t('financialInstitutions.statements.lgStatementResult.amount') }}</th>
                <th class="lgs-th-num">{{ t('financialInstitutions.statements.lgStatementResult.cashCover') }}</th>
                <th class="lgs-th-num">{{ t('financialInstitutions.statements.lgStatementResult.commissionRate') }}</th>
                <th>{{ t('financialInstitutions.statements.lgStatementResult.renewalDate') }}</th>
                <th>{{ t('financialInstitutions.statements.lgStatementResult.comment') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in group.rows" :key="idx" class="lgs-row" :class="{ 'lgs-row-reversed': row.is_reversed }">
                <td class="lgs-muted">{{ fmtDate(row.date) }}</td>
                <td>{{ row.lg_code || '—' }}</td>
                <td v-if="filters.group_by !== 'beneficiary'">{{ row.beneficiary_name }}</td>
                <td>{{ row.lg_type }}</td>
                <td v-if="filters.group_by !== 'bank'">{{ row.bank_name }}</td>
                <td><span class="lgs-badge" :class="`lgs-badge-${row.event_type}`">{{ eventLabel(row.event_type) }}</span></td>
                <td class="lgs-num">{{ fmt(row.amount) }}</td>
                <td class="lgs-num">{{ fmt(row.cash_cover) }}</td>
                <td class="lgs-num">{{ Number(row.commission_rate).toFixed(2) }}%</td>
                <td class="lgs-muted">{{ fmtDate(row.renewal_date) }}</td>
                <td class="lgs-comment">{{ row.comment }}</td>
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
  const key = props.filters.group_by === 'beneficiary' ? 'beneficiary_name' : 'bank_name'
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
    issuance: t('financialInstitutions.statements.lgStatementResult.eventIssuance'),
    renewal: t('financialInstitutions.statements.lgStatementResult.eventRenewal'),
    decrease: t('financialInstitutions.statements.lgStatementResult.eventDecrease'),
    cancel: t('financialInstitutions.statements.lgStatementResult.eventCancel'),
    liquidate: t('financialInstitutions.statements.lgStatementResult.eventLiquidate'),
  }[type] ?? type
}

const exportUrl = computed(() => {
  const params = new URLSearchParams()
  Object.entries(props.filters).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') params.set(k, v) })
  return route('fi.statements.lg.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.lgs-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1rem 0 1.5rem; }
@media (max-width: 900px) { .lgs-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lgs-kpi-grid { grid-template-columns: 1fr; } }

.lgs-group-card { margin-bottom: 1rem; }
.lgs-group-header { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 14px; color: var(--zv-navy, #0F2554); padding: 0.5rem 0 0.75rem; border-bottom: 1px solid var(--zv-border, #e5e7eb); margin-bottom: 0.5rem; }
.lgs-group-count { font-weight: 400; font-size: 12px; color: var(--zv-text-muted); }

.lgs-th-num { text-align: end; }
.lgs-num { text-align: end; font-variant-numeric: tabular-nums; }
.lgs-muted { color: var(--zv-text-muted); white-space: nowrap; }
.lgs-comment { color: var(--zv-text-secondary); font-size: 12.5px; max-width: 380px; }
.lgs-row:hover { background: var(--zv-bg-surface-alt); }
.lgs-row-reversed { opacity: 0.55; text-decoration: line-through; }

.lgs-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.lgs-badge-issuance { background: rgba(20,144,168,0.12); color: #1490A8; }
.lgs-badge-renewal { background: rgba(34,197,94,0.12); color: #16a34a; }
.lgs-badge-decrease { background: rgba(245,158,11,0.12); color: #b45309; }
.lgs-badge-cancel { background: rgba(107,114,128,0.15); color: #4b5563; }
.lgs-badge-liquidate { background: rgba(239,68,68,0.12); color: #dc2626; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
