<!--
══════════════════════════════════════════════════════════════════
  Statements/SafeStatement/Result.vue

  The Safe Statement itself — every posted journal entry line
  against this safe's own Chart of Accounts row, running balance,
  same layout as Bank Statement (Beginning Balance / Debit / Credit /
  End Balance / Comment). No Limit/Room columns — those only exist
  for an Overdraft-family Credit Facility, never a safe.

  @layout    AppLayout
  @route     fi.statements.safe.result
  @props     account, rows (paginator), totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.statements.safe.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('financialInstitutions.statements.safeStatementResult.backToSafeStatement') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">
          {{ t('financialInstitutions.statements.safeStatementResult.safeStatement') }} — {{ account.code }} · {{ account.name }}
        </h1>
        <p class="zv-page-subtitle">{{ totals.transaction_count }} {{ t('financialInstitutions.statements.safeStatementResult.transactionsInThisDateRange') }}</p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('financialInstitutions.statements.safeStatementResult.exportToExcel') }}
      </a>
    </div>

    <div class="sfs-kpi-grid">
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.safeStatementResult.beginningBalance') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.beginning_balance) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.safeStatementResult.totalDebit') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_debit) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.safeStatementResult.totalCredit') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_credit) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.safeStatementResult.endingBalance') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.ending_balance) }}</div>
      </div>
    </div>

    <div class="zv-card">
      <div v-if="rows.data.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.safeStatementResult.noTransactionsInThisDateRange') }}</div>
      </div>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('common.date') }}</th>
              <th class="sfs-th-num">{{ t('financialInstitutions.statements.safeStatementResult.beginningBalance') }}</th>
              <th class="sfs-th-num">{{ t('financialInstitutions.statements.safeStatementResult.debit') }}</th>
              <th class="sfs-th-num">{{ t('financialInstitutions.statements.safeStatementResult.credit') }}</th>
              <th class="sfs-th-num">{{ t('financialInstitutions.statements.safeStatementResult.endBalance') }}</th>
              <th>{{ t('financialInstitutions.statements.safeStatementResult.comment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in rows.data" :key="row.id" class="sfs-row">
              <td class="sfs-muted">{{ rows.from + idx }}</td>
              <td class="sfs-muted">{{ fmtDate(row.entry_date) }}</td>
              <td class="sfs-num">{{ fmt(row.beginning_balance) }}</td>
              <td class="sfs-num">{{ fmt(row.debit) }}</td>
              <td class="sfs-num">{{ fmt(row.credit) }}</td>
              <td class="sfs-num sfs-end-balance">{{ fmt(row.end_balance) }}</td>
              <td class="sfs-comment">{{ row.description || row.narration }}</td>
            </tr>
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
              :class="{ active: link.active, 'sfs-page-btn-disabled': !link.url }"
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
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  account:  { type: Object, required: true },
  rows:     { type: Object, required: true },
  totals:   { type: Object, required: true },
  filters:  { type: Object, default: () => ({}) },
})

const exportUrl = computed(() => {
  const params = new URLSearchParams({
    safe_account_id: props.filters.safe_account_id,
    ...(props.filters.start_date ? { start_date: props.filters.start_date } : {}),
    ...(props.filters.end_date ? { end_date: props.filters.end_date } : {}),
  })
  return route('fi.statements.safe.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.sfs-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1rem 0 1.5rem; }
@media (max-width: 900px) { .sfs-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .sfs-kpi-grid { grid-template-columns: 1fr; } }

.sfs-th-num { text-align: end; }
.sfs-num { text-align: end; font-variant-numeric: tabular-nums; }
.sfs-end-balance { color: #f59e0b; font-weight: 600; }
.sfs-muted { color: var(--zv-text-muted); }
.sfs-comment { color: var(--zv-text-secondary); font-size: 12.5px; max-width: 420px; }
.sfs-row:hover { background: var(--zv-bg-surface-alt); }
.sfs-page-btn-disabled { opacity: 0.35; pointer-events: none; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
