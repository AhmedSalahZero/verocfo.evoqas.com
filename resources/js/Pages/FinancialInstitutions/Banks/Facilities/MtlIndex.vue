<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/MtlIndex.vue

  Listing page for Medium Term Loan facilities — same KPI-card /
  dual-mode standard as LetterOfCreditIndex.vue. Each row's primary
  action is "Loan Schedule" (not Edit-first), since that's the page
  the reference images treat as the main working surface.

  @layout    AppLayout
  @route     fi.facilities.mtl.index (portfolio) / fi.banks.mtl.index (bank-scoped)
  @props     facilities (paginator), stats, filters, banks, bank
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.mtlIndex.banks') }}</Link>
            <template v-if="isScoped"><span class="zv-breadcrumb-sep">/</span><span>{{ bank.name_en }}</span></template>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.mtlIndex.mediumTermLoan') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.mtlIndex.mediumTermLoans') }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.banks.facilities.mtlIndex.termLoansWithAManuallyEntered') }}</p>
        </div>

        <Link v-if="isScoped" :href="route('fi.facilities.mtl.create', bank.id)" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          {{ t('financialInstitutions.banks.facilities.mtlIndex.addLoan') }}
        </Link>
        <div v-else class="mtl-new-wrap" ref="newWrapRef">
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="showBankPicker = !showBankPicker">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('financialInstitutions.banks.facilities.mtlIndex.addLoan') }}
          </button>
          <Transition name="mtl-drop">
            <div v-if="showBankPicker" class="zv-dropdown">
              <div class="mtl-bank-picker-label">{{ t('financialInstitutions.banks.facilities.mtlIndex.chooseABankToBegin') }}</div>
              <Link v-for="b in banks" :key="b.id" :href="route('fi.facilities.mtl.create', b.id)" class="zv-dropdown-item">{{ b.name_en }}</Link>
            </div>
          </Transition>
        </div>
      </div>

      <div class="mtl-kpi-grid">
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.mtlIndex.loans') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">{{ stats.total_count === 0 ? t('financialInstitutions.banks.facilities.mtlIndex.noLoansYet') : stats.active_count + ' ' + t('financialInstitutions.banks.facilities.mtlIndex.active') }}</div>
        </div>
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.mtlIndex.totalLimit') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value mtl-kpi-dash">—</div>
          <div v-else class="mtl-currency-stack" :class="{ 'mtl-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="c.currency_code" class="mtl-currency-line">
              <span class="mtl-currency-code">{{ c.currency_code }}</span>
              <span class="mtl-currency-value">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </div>
        <div class="zv-kpi-card" :class="{ 'mtl-kpi-warn-card': stats.past_due_count > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.mtlIndex.pastDueInstallments') }}</div>
          <div class="zv-kpi-value" :class="{ 'mtl-kpi-warn-value': stats.past_due_count > 0 }">{{ stats.past_due_count }}</div>
          <div class="zv-kpi-sub">{{ stats.past_due_count > 0 ? t('financialInstitutions.banks.facilities.mtlIndex.needsAttention') : t('financialInstitutions.banks.facilities.mtlIndex.allCurrent') }}</div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-filter-bar">
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.mtlIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>
          <select v-model="statusFilter" class="zv-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
          </select>
          <div class="mtl-filter-count">{{ facilities.total }} {{ t('financialInstitutions.banks.facilities.mtlIndex.loans2') }}</div>
        </div>

        <div v-if="facilities.data.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('financialInstitutions.banks.facilities.mtlIndex.noMediumTermLoansYet') }}</div>
        </div>

        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.mtlIndex.bank') }}</th>
                <th>{{ t('common.name') }}</th>
                <th>{{ t('common.type') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.mtlIndex.currency') }}</th>
                <th class="mtl-th-num">{{ t('financialInstitutions.banks.facilities.mtlIndex.limit') }}</th>
                <!--
                  TRS-002 — a loan is a payment source now: the bank
                  pays suppliers out of it. How much has been drawn,
                  and how much is left to draw, is the thing a user
                  needs from this row and had no way to see.
                  'Available' is shown only for a NEW loan, since an
                  existing one was drawn before this system and has
                  nothing left to draw.
                -->
                <th class="mtl-th-num">{{ t('financialInstitutions.banks.facilities.mtlIndex.drawn') }}</th>
                <th class="mtl-th-num">{{ t('financialInstitutions.banks.facilities.mtlIndex.available') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.mtlIndex.interestRate') }}</th>
                <th>{{ t('common.endDate') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="mtl-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">
                <td v-if="!isScoped">{{ f.bank?.name_en }}</td>
                <td class="mtl-name-cell">{{ f.name || '—' }}</td>
                <td><span class="mtl-type-badge" :class="f.entry_type === 'new' ? 'mtl-type-new' : 'mtl-type-existing'">{{ t(f.entry_type === 'new' ? 'New' : 'Existing') }}</span></td>
                <td>{{ f.currency?.code }}</td>
                <td class="zv-table-num">{{ fmt(f.facility_limit) }}</td>
                <td class="zv-table-num">{{ fmt(f.outstanding_balance) }}</td>
                <td class="zv-table-num">
                  <span v-if="f.entry_type === 'new'">{{ fmt(availableRoom(f)) }}</span>
                  <span v-else class="zv-text-muted">—</span>
                </td>
                <td>{{ fmt(f.interest_rate) }}%</td>
                <td>{{ fmtDate(f.review_date) }}</td>
                <td><span class="zv-badge" :class="f.status === 'active' ? 'zv-badge-success' : 'zv-badge-muted'">{{ t(f.status) }}</span></td>
                <td class="mtl-th-actions">
                  <div class="mtl-row-actions">
                    <Link :href="route('fi.facilities.mtl.schedule', f.id)" class="zv-btn zv-btn-secondary zv-btn-sm">
                      {{ t('financialInstitutions.banks.facilities.mtlIndex.loanSchedule') }}
                    </Link>
                    <Link :href="route('fi.facilities.mtl.edit', f.id)" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('common.edit')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </Link>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps({
  facilities: { type: Object, required: true },
  stats: { type: Object, default: () => ({ total_count: 0, active_count: 0, by_currency: [], past_due_count: 0 }) },
  banks: { type: Array, default: () => [] },
  bank: { type: Object, default: null },
  filters: { type: Object, default: () => ({}) },
})

const isScoped = computed(() => !!props.bank)
const bankFilter = ref(props.filters?.bank_id ?? '')
const statusFilter = ref(props.filters?.status ?? '')
const showBankPicker = ref(false)
const newWrapRef = ref(null)

function applyFilters() {
  const params = isScoped.value ? { status: statusFilter.value || undefined } : { bank_id: bankFilter.value || undefined, status: statusFilter.value || undefined }
  const url = isScoped.value ? route('fi.banks.mtl.index', props.bank.id) : route('fi.facilities.mtl.index')
  router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })
}

function handleClickAway(e) { if (newWrapRef.value && !newWrapRef.value.contains(e.target)) showBankPicker.value = false }
onMounted(() => document.addEventListener('click', handleClickAway))
onUnmounted(() => document.removeEventListener('click', handleClickAway))

// limit − drawn. What the company can still pay suppliers out of.
function availableRoom(f) {
  return Number(f.facility_limit || 0) - Number(f.outstanding_balance || 0)
}

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
</script>

<style>
.mtl-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .mtl-kpi-grid { grid-template-columns: 1fr; } }
.zv-kpi-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 16px 18px; box-shadow: var(--zv-shadow-sm); }
.zv-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 8px; }
.zv-kpi-value { font-size: 24px; font-weight: 800; color: var(--zv-text-primary); }
.zv-kpi-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 6px; }
.mtl-kpi-dash { color: var(--zv-text-muted); }
.mtl-kpi-warn-card { border-color: rgba(245,158,11,0.35); }
.mtl-kpi-warn-value { color: #f59e0b; }
.mtl-currency-stack { display: flex; flex-direction: column; gap: 4px; }
.mtl-currency-line { display: flex; align-items: baseline; gap: 8px; }
.mtl-currency-code { font-size: 11px; font-weight: 700; color: var(--zv-text-muted); min-width: 32px; }
.mtl-currency-value { font-size: 18px; font-weight: 800; color: var(--zv-text-primary); }
.mtl-currency-stack-multi .mtl-currency-value { font-size: 15px; }

.zv-dropdown { position: absolute; top: calc(100% + 8px); right: 0; z-index: 30; min-width: 240px; background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-lg); padding: 8px; }
.mtl-new-wrap { position: relative; }
.mtl-bank-picker-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--zv-text-muted); padding: 6px 10px 8px; }
.zv-dropdown-item { display: block; padding: 8px 10px; border-radius: var(--zv-radius); font-size: 13.5px; color: var(--zv-text-primary); }
.zv-dropdown-item:hover { background: var(--zv-bg-surface-alt); }
.mtl-drop-enter-active, .mtl-drop-leave-active { transition: all 0.15s ease; }
.mtl-drop-enter-from, .mtl-drop-leave-to { opacity: 0; transform: translateY(-6px); }

.zv-filter-bar { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); }
.mtl-filter-count { margin-inline-start: auto; font-size: 12.5px; color: var(--zv-text-muted); font-weight: 600; }
.mtl-th-num { text-align: end; }
.mtl-th-actions { width: 150px; }
.zv-table-num { text-align: end; }
.mtl-name-cell { font-weight: 600; color: var(--zv-text-primary); }
.mtl-type-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.mtl-type-new { background: rgba(20,144,168,0.1); color: #1490A8; }
.mtl-type-existing { background: rgba(100,116,139,0.1); color: #64748b; }
.zv-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.zv-badge-success { background: rgba(16,185,129,0.1); color: #10b981; }
.zv-badge-muted { background: rgba(100,116,139,0.1); color: #64748b; }
.mtl-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
.zv-empty { padding: 60px 24px; text-align: center; color: var(--zv-text-muted); }
</style>
