<!--
══════════════════════════════════════════════════════════════════
  Statements/BankStatement/Result.vue

  The statement itself — every posted journal entry line on this
  account's own Chart of Accounts row, running balance, exactly the
  layout Mahmoud specified (Beginning Balance / Debit / Credit / End
  Balance / Comment). No Reviewed column (reconciliation postponed)
  and no Actions/edit column (CashVero-only inline edit, deliberately
  not ported — see LedgerStatementService header).

  v1.1 — the account can now be either a real BankAccount or an
  Overdraft-family CreditFacility; account.source_type tells us which,
  purely for the account-type label lookup below.

  v1.2 (2026-09, Mahmoud) — a Credit Facility gets two extra columns,
  Limit and Room, and two extra KPI cards, shown even when there is
  zero activity in the selected date range (totals.facility_limit /
  totals.available_room are always present for a facility — see
  BankStatementController). A bank account never shows these.

  @layout    AppLayout
  @route     fi.statements.bank.result
  @props     account, rows (paginator), totals, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.statements.bank.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('financialInstitutions.statements.bankStatementResult.backToBankStatement') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">
          {{ t('financialInstitutions.statements.bankStatementResult.bankStatement') }} — {{ account.bank_name }} · {{ accountTypeLabel(account.account_type) }} · {{ account.account_number }} · {{ account.currency_code }}
        </h1>
        <p class="zv-page-subtitle">{{ totals.transaction_count }} {{ t('financialInstitutions.statements.bankStatementResult.transactionsInThisDateRange') }}</p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('financialInstitutions.statements.bankStatementResult.exportToExcel') }}
      </a>
    </div>

    <!-- H-25: a foreign-currency account is reported in its OWN currency so it can be ticked against the bank's statement -->
    <div v-if="account.is_native" class="zv-alert zv-alert-info mb-3">
      {{ t('financialInstitutions.statements.bankStatementResult.shownInOwnCurrency', { currency: account.currency_code }) }}
    </div>
    <div v-if="totals.unknown_foreign_count > 0" class="zv-alert zv-alert-warning mb-3">
      {{ t('financialInstitutions.statements.bankStatementResult.unknownForeignAmounts', { count: totals.unknown_foreign_count }) }}
    </div>

    <div class="bst-kpi-grid" :class="{ 'bst-kpi-grid-6': isFacility }">
      <div v-if="isFacility" class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.limit') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.facility_limit) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.beginningBalance') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.beginning_balance) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.totalDebit') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_debit) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.totalCredit') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.total_credit) }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.endingBalance') }}</div>
        <div class="zv-kpi-value">{{ fmt(totals.ending_balance) }}</div>
      </div>
      <div v-if="isFacility" class="zv-kpi-card" :class="{ 'bst-kpi-warn': Number(totals.available_room) <= 0 }">
        <div class="zv-kpi-label">{{ t('financialInstitutions.statements.bankStatementResult.room') }}</div>
        <div class="zv-kpi-value" :class="{ 'bst-kpi-warn-value': Number(totals.available_room) <= 0 }">{{ fmt(totals.available_room) }}</div>
      </div>
    </div>

    <div class="zv-card">
      <!-- Mahmoud: a facility always shows its Limit/Room even with
           zero transactions this period — so no "empty state" blocks
           the KPI cards above for a facility the way it would for a
           bank account with genuinely nothing to show at all. -->
      <div v-if="rows.data.length === 0 && !isFacility" class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.bankStatementResult.noTransactionsInThisDateRange') }}</div>
      </div>
      <div v-else-if="rows.data.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.statements.bankStatementResult.noTransactionsInThisDateRangeFacility') }}</div>
      </div>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('common.date') }}</th>
              <th v-if="isFacility" class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.limit') }}</th>
              <th class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.beginningBalance') }}</th>
              <th class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.debit') }}</th>
              <th class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.credit') }}</th>
              <th class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.endBalance') }}</th>
              <th v-if="isFacility" class="bst-th-num">{{ t('financialInstitutions.statements.bankStatementResult.room') }}</th>
              <th>{{ t('financialInstitutions.statements.bankStatementResult.comment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in rows.data" :key="row.id" class="bst-row">
              <td class="bst-muted">{{ rows.from + idx }}</td>
              <td class="bst-muted">{{ fmtDate(row.entry_date) }}</td>
              <td v-if="isFacility" class="bst-num">{{ fmt(totals.facility_limit) }}</td>
              <td class="bst-num">{{ fmt(row.beginning_balance) }}</td>
              <td class="bst-num">{{ fmt(row.debit) }}</td>
              <td class="bst-num">{{ fmt(row.credit) }}</td>
              <td class="bst-num bst-end-balance">{{ fmt(row.end_balance) }}</td>
              <td v-if="isFacility" class="bst-num">{{ fmt(roomFor(row.end_balance)) }}</td>
              <td class="bst-comment">
                {{ row.description || row.narration }}
              </td>
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
              :class="{ active: link.active, 'bst-page-btn-disabled': !link.url }"
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

const account = props.account
const isFacility = computed(() => account.source_type === 'credit_facility')

function accountTypeLabel(type) {
  return {
    current: t('financialInstitutions.statements.bankStatementResult.currentAccount'),
    time_deposit: t('financialInstitutions.statements.bankStatementResult.timeDeposit'),
    escrow: t('financialInstitutions.statements.bankStatementResult.escrow'),
    lg_cash_cover: t('financialInstitutions.statements.bankStatementResult.lgCashCover'),
    lc_cash_cover: t('financialInstitutions.statements.bankStatementResult.lcCashCover'),
    clean_od: t('financialInstitutions.statements.bankStatementResult.cleanOverdraft'),
    commercial_paper: t('financialInstitutions.statements.bankStatementResult.commercialPaperOverdraft'),
    contract_so: t('financialInstitutions.statements.bankStatementResult.contractAssignmentOverdraft'),
    fully_secured: t('financialInstitutions.statements.bankStatementResult.fullySecuredOverdraft'),
  }[type] ?? type
}

function roomFor(endBalance) {
  return Number(props.totals.facility_limit || 0) - Number(endBalance || 0)
}

const exportUrl = computed(() => {
  const params = new URLSearchParams({
    source_type: props.filters.source_type,
    source_id: props.filters.source_id,
    ...(props.filters.start_date ? { start_date: props.filters.start_date } : {}),
    ...(props.filters.end_date ? { end_date: props.filters.end_date } : {}),
  })
  return route('fi.statements.bank.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.bst-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1rem 0 1.5rem; }
.bst-kpi-grid-6 { grid-template-columns: repeat(6, 1fr); }
@media (max-width: 1100px) { .bst-kpi-grid-6 { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 900px) { .bst-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .bst-kpi-grid, .bst-kpi-grid-6 { grid-template-columns: 1fr; } }

.bst-kpi-warn { border-color: rgba(239,68,68,0.35); }
.bst-kpi-warn-value { color: #ef4444; }

.bst-th-num { text-align: end; }
.bst-num { text-align: end; font-variant-numeric: tabular-nums; }
.bst-end-balance { color: #f59e0b; font-weight: 600; }
.bst-muted { color: var(--zv-text-muted); }
.bst-comment { color: var(--zv-text-secondary); font-size: 12.5px; max-width: 420px; }
.bst-row:hover { background: var(--zv-bg-surface-alt); }
.bst-page-btn-disabled { opacity: 0.35; pointer-events: none; }
.zv-pagination-pages { display: flex; gap: 4px; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
