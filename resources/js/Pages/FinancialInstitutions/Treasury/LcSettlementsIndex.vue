<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/LcSettlementsIndex.vue

  Standalone, cross-LC view of every Settlement — Mahmoud's explicit
  request, separate from the per-LC History table on LcIssuanceForm.
  Reads the same lc_issuance_history rows (event_type = 'settlement')
  through LetterOfCreditIssuanceService::paginateSettlements() — a
  different lens on the same data, not a second copy of it.

  @layout    AppLayout
  @route     fi.lc-settlements.index
  @props     settlements (paginator), stats, filters, banks
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.lc-issuance.index')" class="zv-text-blue">{{ t('financialInstitutions.treasury.lcSettlementsIndex.letterOfCreditIssuance') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.treasury.lcSettlementsIndex.lCSettlements') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.lcSettlementsIndex.lCSettlements') }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.treasury.lcSettlementsIndex.everyRepaymentToTheBankAcross') }}</p>
        </div>
      </div>

      <div class="lgi-kpi-grid">
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcSettlementsIndex.settlements') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lcSettlementsIndex.matchingCurrentFilters') }}</div>
        </div>
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcSettlementsIndex.totalPrincipalRepaid') }}</div>
          <div class="zv-kpi-value">{{ fmt(stats.total_principal) }}</div>
        </div>
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcSettlementsIndex.totalInterestPaid') }}</div>
          <div class="zv-kpi-value">{{ fmt(stats.total_interest) }}</div>
        </div>
        <div class="zv-kpi-card" :class="{ 'lgi-kpi-warn-card': Number(stats.still_owed_to_bank) > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcSettlementsIndex.stillOwedToBank') }}</div>
          <div class="zv-kpi-value" :class="{ 'lgi-kpi-warn-value': Number(stats.still_owed_to_bank) > 0 }">{{ fmt(stats.still_owed_to_bank) }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lcSettlementsIndex.acrossAllBankFinancedLCs') }}</div>
        </div>
      </div>

      <div class="zv-card lgi-main-card">

        <div class="zv-filter-bar">
          <label class="lgi-filter-label">{{ t('financialInstitutions.treasury.lcSettlementsIndex.bank') }}</label>
          <select v-model="bankId" class="zv-select lgi-date-input">
            <option value="">{{ t('financialInstitutions.treasury.lcSettlementsIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>
          <label class="lgi-filter-label">{{ t('common.startDate') }}</label>
          <input v-model="startDate" type="date" class="zv-input lgi-date-input"/>
          <label class="lgi-filter-label">{{ t('common.endDate') }}</label>
          <input v-model="endDate" type="date" class="zv-input lgi-date-input"/>
          <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" @click="applyFilters">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
            </svg>
            {{ t('common.search') }}
          </button>
          <button v-if="startDate || endDate || bankId" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="clearFilters">
            {{ t('financialInstitutions.treasury.lcSettlementsIndex.clearFilters') }}
          </button>
        </div>

        <div v-if="settlements.data.length === 0" class="zv-empty">
          <div class="zv-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-10 h-10">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a4 4 0 00-8 0v2m-2 0h12l-1 11H8L7 9z"/>
            </svg>
          </div>
          <div class="zv-empty-title">{{ t('financialInstitutions.treasury.lcSettlementsIndex.noSettlementsFound') }}</div>
          <div class="zv-empty-sub">{{ t('financialInstitutions.treasury.lcSettlementsIndex.settlementsRecordedFromAnyLCSSettle') }}</div>
        </div>

        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('financialInstitutions.treasury.lcSettlementsIndex.date') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcSettlementsIndex.lCCode') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcSettlementsIndex.transactionName') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcSettlementsIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcSettlementsIndex.fromAccount') }}</th>
                <th class="lgi-th-num">{{ t('financialInstitutions.treasury.lcSettlementsIndex.principal') }}</th>
                <th class="lgi-th-num">{{ t('financialInstitutions.treasury.lcSettlementsIndex.interest') }}</th>
                <th class="lgi-th-num">{{ t('financialInstitutions.treasury.lcSettlementsIndex.remainingBankDebt') }}</th>
                <th></th>
                <th class="lgi-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in settlements.data" :key="row.id" class="lgi-row">
                <td class="lgi-muted">{{ fmtDate(row.event_date) }}</td>
                <td class="lgi-name-cell">{{ row.lc_issuance?.lc_code || '—' }}</td>
                <td>{{ row.lc_issuance?.transaction_name }}</td>
                <td class="lgi-muted">{{ row.lc_issuance?.bank?.name_en }}</td>
                <td class="lgi-muted">{{ row.settlement_from_account?.bank?.name_en }} — {{ row.settlement_from_account?.account_number }}</td>
                <td class="zv-table-num">{{ fmt(row.principal_amount) }}</td>
                <td class="zv-table-num">{{ fmt(row.interest_amount) }}</td>
                <td class="zv-table-num">{{ row.lc_issuance?.currency?.code }} {{ fmt(row.bank_debt_after_event) }}</td>
                <td><span v-if="row.undone_at" class="lgi-undone-tag">{{ t('financialInstitutions.treasury.lcSettlementsIndex.undone') }}</span></td>
                <td class="lgi-th-actions">
                  <Link :href="route('fi.lc-issuance.edit', row.lc_issuance_id)" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lcSettlementsIndex.openLC')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                    </svg>
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="settlements.data.length > 0" class="zv-pagination">
          <span>{{ t('common.showing') }} {{ settlements.from }}–{{ settlements.to }} {{ t('common.of') }} {{ settlements.total }}</span>
          <div class="zv-pagination-pages">
            <template v-for="(link, idx) in settlements.links" :key="idx">
              <component
                :is="link.url ? Link : 'span'"
                :href="link.url || undefined"
                class="zv-page-btn"
                :class="{ active: link.active, 'lgi-page-btn-disabled': !link.url }"
              >
                <svg v-if="link.label.includes('Previous')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                <svg v-else-if="link.label.includes('Next')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span v-else>{{ link.label }}</span>
              </component>
            </template>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  settlements: { type: Object, required: true },
  stats: {
    type: Object,
    default: () => ({ total_count: 0, total_principal: 0, total_interest: 0, still_owed_to_bank: 0 }),
  },
  filters: { type: Object, default: () => ({}) },
  banks:   { type: Array, default: () => [] },
})

const startDate = ref(props.filters?.start_date ?? '')
const endDate   = ref(props.filters?.end_date ?? '')
const bankId    = ref(props.filters?.bank_id ?? '')

function applyFilters() {
  router.get(route('fi.lc-settlements.index'), {
    start_date: startDate.value || undefined,
    end_date: endDate.value || undefined,
    bank_id: bankId.value || undefined,
  }, { preserveState: true, preserveScroll: true })
}

function clearFilters() {
  startDate.value = ''
  endDate.value = ''
  bankId.value = ''
  applyFilters()
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
/* Local safety copy of the lgi-* tokens (zv-* ones are global in
   app.css) — needed because a Vue SFC's <style> block only ships
   with THAT component; a person opening this page directly (not
   navigating here from LcIssuanceIndex.vue) would otherwise get no
   styling at all for these classes. Same reasoning documented in
   LcIssuanceIndex.vue's own style block. */
.lgi-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .lgi-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lgi-kpi-grid { grid-template-columns: 1fr; } }
.lgi-kpi-warn-card { border-color: rgba(245,158,11,0.35); }
.lgi-kpi-warn-value { color: #f59e0b; }

.lgi-filter-label { font-size: 12px; font-weight: 600; color: var(--zv-text-muted); }
.lgi-date-input { max-width: 160px; }

.lgi-muted { color: var(--zv-text-muted); }
.lgi-name-cell { font-weight: 600; color: var(--zv-text-primary); }
.lgi-th-num { text-align: end; }
.lgi-th-actions { width: 60px; }
.zv-table-num { text-align: end; font-variant-numeric: tabular-nums; }
.lgi-row:hover { background: var(--zv-bg-surface-alt); }

.lgi-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px;
  border-radius: var(--zv-radius); color: var(--zv-text-muted); transition: all 0.15s;
}
.lgi-icon-btn:hover { background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); }

.lgi-undone-tag {
  display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: var(--zv-bg-surface-alt); color: var(--zv-text-muted);
}

.lgi-page-btn-disabled { opacity: 0.35; pointer-events: none; }
.zv-pagination-pages { display: flex; gap: 4px; }
</style>
