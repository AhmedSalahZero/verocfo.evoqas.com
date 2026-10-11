<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/LcIssuanceIndex.vue

  Individual LC issuances — tabbed by LC Type (Sight LC / Deferred /
  Cash Against Document), same shape and visual language as
  LgIssuanceIndex.vue. Only 2 create sources (confirmed with Mahmoud
  — Against CD/TD turned out to be facility-level collateral, not a
  separate issuance-level source — see LetterOfCreditIssuanceService
  class header).

  Row actions: Edit always; Mark as Paid while running + posted;
  Settle while paid + bank-financed + still owing the bank. Both open
  an inline modal, same pattern as LG's lifecycle actions.

  @layout    AppLayout
  @route     fi.lc-issuance.index
  @props     issuances (paginator), stats, activeLcType, lcTypes,
             filters, bankAccounts, expenseAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.treasury.lcIssuanceIndex.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.treasury.lcIssuanceIndex.letterOfCreditIssuance') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.lcIssuanceIndex.letterOfCreditIssuance') }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.treasury.lcIssuanceIndex.individualLCsIssuedAgainstYourFacilities') }}</p>
        </div>

        <div class="lgi-create-group">
          <Link :href="route('fi.lc-issuance.create', 'facility')" class="lgi-create-btn lgi-create-navy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            {{ t('financialInstitutions.treasury.lcIssuanceIndex.fromLCFacility') }}
          </Link>
          <Link :href="route('fi.lc-issuance.create', 'cash_cover')" class="lgi-create-btn lgi-create-gold">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.treasury.lcIssuanceIndex.lC100CashCover') }}
          </Link>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           LC TYPE TABS
      ══════════════════════════════════════════════════════ -->
      <div class="lgi-tabs">
        <Link
          v-for="type in lcTypes"
          :key="type"
          :href="route('fi.lc-issuance.index', type)"
          class="lgi-tab"
          :class="{ 'lgi-tab-active': activeLcType === type }"
        >
          {{ t(type) }}
        </Link>
      </div>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW
      ══════════════════════════════════════════════════════ -->
      <div class="lgi-kpi-grid">

        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t(activeLcType) }} {{ t('financialInstitutions.treasury.lcIssuanceIndex.issuances') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.treasury.lcIssuanceIndex.noneIssuedYet') }}</template>
            <template v-else>{{ stats.running_count }} {{ t('financialInstitutions.treasury.lcIssuanceIndex.running') }}</template>
          </div>
        </div>

        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.outstandingAmount') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value lgi-kpi-dash">—</div>
          <div v-else class="lgi-currency-stack" :class="{ 'lgi-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'out-' + c.currency_code" class="lgi-currency-line">
              <span class="lgi-currency-code">{{ c.currency_code }}</span>
              <span class="lgi-currency-value zv-num">{{ fmt(c.total_outstanding) }}</span>
            </div>
          </div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lcIssuanceIndex.runningIssuancesOnly') }}</div>
        </div>

        <div class="zv-kpi-card" :class="{ 'lgi-kpi-warn-card': Number(stats.bank_debt_outstanding) > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.owedToBank') }}</div>
          <div class="zv-kpi-value" :class="{ 'lgi-kpi-warn-value': Number(stats.bank_debt_outstanding) > 0 }">
            {{ fmt(stats.bank_debt_outstanding) }}
          </div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lcIssuanceIndex.bankFinancedStillUnsettled') }}</div>
        </div>

        <div class="zv-kpi-card" :class="{ 'lgi-kpi-warn-card': stats.near_due_count > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.nearDue') }}</div>
          <div class="zv-kpi-value" :class="{ 'lgi-kpi-warn-value': stats.near_due_count > 0 }">
            {{ stats.near_due_count }}
          </div>
          <div class="zv-kpi-sub">
            <span v-if="stats.near_due_count > 0" class="zv-kpi-delta zv-kpi-delta-warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
              </svg>
              {{ t('financialInstitutions.treasury.lcIssuanceIndex.within30Days') }}
            </span>
            <template v-else>{{ t('financialInstitutions.treasury.lcIssuanceIndex.noneDueSoon') }}</template>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card lgi-main-card">

        <div class="zv-filter-bar">
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
          <button v-if="startDate || endDate" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="clearFilters">
            {{ t('financialInstitutions.treasury.lcIssuanceIndex.clearFilters') }}
          </button>
        </div>

        <!-- Empty state -->
        <div v-if="issuances.data.length === 0" class="zv-empty">
          <div class="zv-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-10 h-10">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <div class="zv-empty-title">{{ t('common.no') }} {{ t(activeLcType) }} {{ t('financialInstitutions.treasury.lcIssuanceIndex.issuancesFound') }}</div>
          <div class="zv-empty-sub">{{ t('financialInstitutions.treasury.lcIssuanceIndex.useOneOfTheButtonsAbove') }}</div>
        </div>

        <!-- Table -->
        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.transactionName') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.supplier') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.source') }}</th>
                <th>{{ t('common.status') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.lCCode') }}</th>
                <th class="lgi-th-num">{{ t('financialInstitutions.treasury.lcIssuanceIndex.lCAmount') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.issuanceDate') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceIndex.dueDate') }}</th>
                <th class="lgi-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in issuances.data" :key="row.id" class="lgi-row">
                <td class="lgi-muted">{{ issuances.from + idx }}</td>
                <td class="lgi-name-cell">{{ row.transaction_name }}</td>
                <td>{{ row.supplier?.name_en }}</td>
                <td><span class="lgi-source-badge" :class="sourceBadgeClass(row.issuance_type)">{{ t(sourceLabel(row.issuance_type)) }}</span></td>
                <td>
                  <span class="zv-badge" :class="statusMeta(row).badge">
                    <span class="zv-status-dot" :class="statusMeta(row).dot"></span>
                    {{ t(statusMeta(row).label) }}
                  </span>
                  <span v-if="row.posting_status === 'draft'" class="zv-badge zv-badge-warning" :title="t('financialInstitutions.treasury.lcIssuanceIndex.journalEntryReversedReopenedForEditing')">
                    {{ t('financialInstitutions.treasury.lcIssuanceIndex.draft') }}
                  </span>
                  <span v-if="row.status === 'paid'" class="lci-financed-tag" :class="row.financed_by === 'bank' ? 'lci-financed-bank' : 'lci-financed-self'">
                    {{ row.financed_by === 'bank' ? t('financialInstitutions.treasury.lcIssuanceIndex.bankFinanced') : t('financialInstitutions.treasury.lcIssuanceIndex.selfFinanced') }}
                  </span>
                </td>
                <td class="lgi-muted">{{ row.bank?.name_en }}</td>
                <td class="lgi-muted">{{ row.lc_code || '—' }}</td>
                <td class="zv-table-num">{{ row.currency?.code }} {{ fmt(row.lc_amount) }}</td>
                <td class="lgi-muted">{{ fmtDate(row.issuance_date) }}</td>
                <td>
                  <span class="lgi-renewal" :class="dueClass(row)">
                    <span class="zv-status-dot" :class="dueDotClass(row)"></span>
                    {{ fmtDate(row.due_date) }}
                  </span>
                </td>
                <td class="lgi-th-actions">
                  <div class="lgi-row-actions">
                    <Link :href="route('fi.lc-issuance.edit', row.id)" class="lgi-icon-btn" :title="t('common.edit')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </Link>
                    <button v-if="row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lcIssuanceIndex.markAsPaid')" @click="openMarkAsPaidModal(row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </button>
                    <button v-if="row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lcIssuanceIndex.closeUnusedReleaseCover')" @click="openReleaseCoverModal(row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                      </svg>
                    </button>
                    <button v-if="row.status === 'paid' && row.financed_by === 'bank' && Number(row.bank_debt_outstanding) > 0" type="button" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lcIssuanceIndex.settle')" @click="openSettleModal(row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a4 4 0 00-8 0v2m-2 0h12l-1 11H8L7 9z"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="issuances.data.length > 0" class="zv-pagination">
          <span>{{ t('common.showing') }} {{ issuances.from }}–{{ issuances.to }} {{ t('common.of') }} {{ issuances.total }}</span>
          <div class="zv-pagination-pages">
            <template v-for="(link, idx) in issuances.links" :key="idx">
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

    <!-- ══════════════════════════ Action Modals ══════════════════════════ -->
    <Teleport to="body">
      <div v-if="modal.type" class="lgi-modal-overlay" @click.self="closeModal">
        <div class="lgi-modal">

          <template v-if="modal.type === 'mark_paid'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lcIssuanceIndex.markAsPaid') }} — {{ modal.row.lc_code }}</h3>
            <p class="lgi-modal-hint">
              {{ modal.row.financed_by === 'bank'
                ? t('financialInstitutions.treasury.lcIssuanceIndex.theBankPaysTheSupplierNow')
                : t('financialInstitutions.treasury.lcIssuanceIndex.theCompanyPaysTheSupplierDirectly') }}
            </p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.paymentDate') }}</label>
              <input v-model="modalForm.paid_at" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.supplierBill') }}</label>
              <select v-model="modalForm.supplier_bill_id" class="zv-select" @change="onBillChange">
                <option value="">{{ eligibleBills.length ? t('financialInstitutions.treasury.lcIssuanceIndex.select') : t('common.loading') }}</option>
                <option v-for="b in eligibleBills" :key="b.id" :value="b.id">{{ b.bill_code }} — {{ fmt(b.amount_outstanding) }} {{ t('financialInstitutions.treasury.lcIssuanceIndex.outstanding') }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.amountPaidToSupplier') }}</label>
              <input v-model="modalForm.amount_paid_to_supplier" type="number" step="0.01" class="zv-input"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.lcIssuanceIndex.defaultsToTheBillSFullOutstanding') }}</p>
            </div>
            <div v-if="modal.row.financed_by === 'self'" class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.paymentAccount') }}</label>
              <select v-model="modalForm.payment_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lcIssuanceIndex.select') }}</option>
                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'release_cover'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lcIssuanceIndex.closeUnusedReleaseCover') }} — {{ modal.row.lc_code }}</h3>
            <p class="lgi-modal-hint">
              {{ t('financialInstitutions.treasury.lcIssuanceIndex.releaseCoverHint', { amount: fmt(modal.row.cash_cover_amount || 0) }) }}
            </p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.releaseDate') }}</label>
              <input v-model="modalForm.release_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'settle'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lcIssuanceIndex.settleWithBank') }} — {{ modal.row.lc_code }}</h3>
            <p class="lgi-modal-hint">{{ t('financialInstitutions.treasury.lcIssuanceIndex.owedToBank2') }}: {{ modal.row.currency?.code }} {{ fmt(modal.row.bank_debt_outstanding) }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.settlementDate') }}</label>
              <input v-model="modalForm.settlement_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.principalAmount') }}</label>
              <input v-model="modalForm.principal_amount" type="number" step="0.01" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.interestAmount') }}</label>
              <input v-model="modalForm.interest_amount" type="number" step="0.01" class="zv-input"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.lcIssuanceIndex.onlyKnownAtSettlementTimeEnter') }}</p>
            </div>
            <div v-if="Number(modalForm.interest_amount) > 0" class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.interestExpenseAccount') }}</label>
              <select v-model="modalForm.interest_expense_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lcIssuanceIndex.select') }}</option>
                <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_en }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceIndex.fromAccount') }}</label>
              <select v-model="modalForm.settlement_from_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lcIssuanceIndex.select') }}</option>
                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <div class="lgi-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModal">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="modalSubmitting" @click="submitModal">
              {{ modalSubmitting ? t('common.saving') : t('common.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

const props = defineProps({
  issuances:    { type: Object, required: true },
  stats: {
    type: Object,
    default: () => ({ total_count: 0, running_count: 0, by_currency: [], bank_debt_outstanding: 0, near_due_count: 0 }),
  },
  activeLcType: { type: String, required: true },
  lcTypes:      { type: Array, default: () => [] },
  filters:      { type: Object, default: () => ({}) },
  bankAccounts: { type: Array, default: () => [] },
  expenseAccounts: { type: Array, default: () => [] },
})

// ── Filters ───────────────────────────────────────────────────────

const startDate = ref(props.filters?.start_date ?? '')
const endDate   = ref(props.filters?.end_date ?? '')

function applyFilters() {
  router.get(route('fi.lc-issuance.index', props.activeLcType), {
    start_date: startDate.value || undefined,
    end_date: endDate.value || undefined,
  }, { preserveState: true, preserveScroll: true })
}

function clearFilters() {
  startDate.value = ''
  endDate.value = ''
  applyFilters()
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
function sourceLabel(issuanceType) {
  return { facility: 'LC Facility', cash_cover: '100% Cash Cover' }[issuanceType] ?? issuanceType
}
function sourceBadgeClass(issuanceType) {
  return { facility: 'lgi-source-navy', cash_cover: 'lgi-source-gold' }[issuanceType] ?? ''
}
function statusMeta(row) {
  if (row.status === 'paid') return { label: 'Paid', badge: 'zv-badge-success', dot: 'zv-dot-ok' }
  if (row.status === 'expired') return { label: 'Expired', badge: 'zv-badge-warning', dot: 'zv-dot-warn' }
  return { label: 'Running', badge: 'zv-badge-muted', dot: 'zv-dot-muted' }
}
function daysUntil(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}
function dueClass(row) {
  const d = daysUntil(row.due_date)
  if (row.status !== 'running' || d === null) return ''
  if (d < 0) return 'lgi-expiry-overdue'
  if (d <= 30) return 'lgi-expiry-warn'
  return ''
}
function dueDotClass(row) {
  const d = daysUntil(row.due_date)
  if (row.status !== 'running' || d === null) return 'zv-dot-muted'
  if (d < 0) return 'zv-dot-danger'
  if (d <= 30) return 'zv-dot-warn'
  return 'zv-dot-ok'
}

// ── Action modals ────────────────────────────────────────────────

const modal = ref({ type: null, row: null })
const modalForm = ref({})
const modalSubmitting = ref(false)
const eligibleBills = ref([])

async function openMarkAsPaidModal(row) {
  modal.value = { type: 'mark_paid', row }
  modalForm.value = {
    paid_at: todayLocal(),
    supplier_bill_id: '', amount_paid_to_supplier: '', payment_account_id: '', notes: '',
  }
  eligibleBills.value = []
  const { data } = await axios.get(route('fi.lc-issuance.supplier-bills', row.id))
  eligibleBills.value = data
}

function onBillChange() {
  const bill = eligibleBills.value.find(b => b.id == modalForm.value.supplier_bill_id)
  modalForm.value.amount_paid_to_supplier = bill ? bill.amount_outstanding : ''
}

function openReleaseCoverModal(row) {
  modal.value = { type: 'release_cover', row }
  modalForm.value = { release_date: todayLocal(), notes: '' }
}

function openSettleModal(row) {
  modal.value = { type: 'settle', row }
  modalForm.value = {
    settlement_date: todayLocal(),
    principal_amount: row.bank_debt_outstanding, interest_amount: '',
    interest_expense_account_id: '', settlement_from_account_id: '', notes: '',
  }
}

function closeModal() {
  modal.value = { type: null, row: null }
  eligibleBills.value = []
}

function submitModal() {
  modalSubmitting.value = true
  const routeName = { mark_paid: 'fi.lc-issuance.mark-as-paid', settle: 'fi.lc-issuance.settle', release_cover: 'fi.lc-issuance.release-cash-cover' }[modal.value.type]

  router.post(route(routeName, modal.value.row.id), modalForm.value, {
    preserveScroll: true,
    onFinish: () => { modalSubmitting.value = false; closeModal() },
  })
}
</script>

<style>
/* Reuses zv-* / lgi-* tokens already established by LgIssuanceIndex.vue
   (create buttons, tabs, KPI grid, filter bar, table, modal, pagination)
   — safety copy here too, same reasoning as every other Treasury page:
   a page can render before the global app.css is guaranteed loaded. */
.lgi-create-group { display: flex; gap: 8px; flex-wrap: wrap; }
.lgi-create-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border-radius: var(--zv-radius); font-size: 12.5px; font-weight: 600;
  border: 1px solid transparent; transition: all 0.15s; white-space: nowrap;
}
.lgi-create-navy { background: rgba(15,37,84,0.07); border-color: rgba(15,37,84,0.18); color: #0F2554; }
.lgi-create-navy:hover { background: rgba(15,37,84,0.13); }
[data-theme="dark"] .lgi-create-navy { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); color: #90a4c4; }
.lgi-create-gold { background: #FFBF00; border-color: #FFBF00; color: #0F2554; }
.lgi-create-gold:hover { background: #e6ac00; }

.lgi-tabs { display: flex; gap: 4px; margin-bottom: 1.25rem; border-bottom: 1px solid var(--zv-border); }
.lgi-tab {
  padding: 10px 18px; font-size: 13px; font-weight: 600; color: var(--zv-text-muted);
  border-bottom: 2px solid transparent; transition: all 0.15s;
}
.lgi-tab:hover { color: var(--zv-text-primary); }
.lgi-tab-active { color: #0F2554; border-bottom-color: #FFBF00; }
[data-theme="dark"] .lgi-tab-active { color: #90a4c4; }

.lgi-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .lgi-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lgi-kpi-grid { grid-template-columns: 1fr; } }

.zv-kpi-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 16px 18px; box-shadow: var(--zv-shadow-sm); }
.zv-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 8px; }
.zv-kpi-value { font-size: 24px; font-weight: 800; color: var(--zv-text-primary); font-variant-numeric: tabular-nums; }
.zv-kpi-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 6px; }
.zv-kpi-delta { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.zv-kpi-delta-warn { color: #f59e0b; }
.lgi-kpi-dash { color: var(--zv-text-muted); }
.lgi-kpi-warn-card { border-color: rgba(245,158,11,0.35); }
.lgi-kpi-warn-value { color: #f59e0b; }

.lgi-currency-stack { display: flex; flex-direction: column; gap: 4px; }
.lgi-currency-line { display: flex; align-items: baseline; gap: 8px; }
.lgi-currency-code { font-size: 11px; font-weight: 700; color: var(--zv-text-muted); min-width: 32px; }
.lgi-currency-value { font-size: 18px; font-weight: 800; color: var(--zv-text-primary); }
.lgi-currency-stack-multi .lgi-currency-value { font-size: 15px; }

.zv-filter-bar { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); flex-wrap: wrap; }
.lgi-filter-label { font-size: 12px; font-weight: 600; color: var(--zv-text-muted); }
.lgi-date-input { max-width: 160px; }

.lgi-muted { color: var(--zv-text-muted); }
.lgi-name-cell { font-weight: 600; color: var(--zv-text-primary); }
.lgi-th-num { text-align: end; }
.lgi-th-actions { width: 130px; }
.zv-table-num { text-align: end; font-variant-numeric: tabular-nums; }
.lgi-row:hover { background: var(--zv-bg-surface-alt); }

.lgi-source-badge { display: inline-flex; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.lgi-source-navy { background: rgba(15,37,84,0.08); color: #0F2554; }
[data-theme="dark"] .lgi-source-navy { background: rgba(255,255,255,0.08); color: #90a4c4; }
.lgi-source-gold { background: rgba(255,191,0,0.15); color: #b8860b; }

.zv-status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.zv-dot-ok { background: #10b981; } .zv-dot-warn { background: #f59e0b; } .zv-dot-danger { background: #ef4444; } .zv-dot-muted { background: var(--zv-text-muted); }

.zv-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.zv-badge-success { background: rgba(16,185,129,0.1); color: #10b981; }
.zv-badge-danger  { background: rgba(239,68,68,0.1);  color: #ef4444; }
.zv-badge-muted   { background: rgba(100,116,139,0.1); color: #64748b; }
.zv-badge-warning { background: rgba(245,158,11,0.12); color: #b45309; margin-inline-start: 6px; }

.lci-financed-tag { display: inline-flex; margin-inline-start: 6px; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.lci-financed-bank { background: rgba(20,144,168,0.12); color: #1490A8; }
.lci-financed-self { background: rgba(100,116,139,0.1); color: #64748b; }

.lgi-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.lgi-expiry-warn { color: #f59e0b; font-weight: 600; }
.lgi-expiry-overdue { color: #ef4444; font-weight: 700; }

.lgi-row-actions { display: flex; gap: 4px; }
.lgi-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px;
  border-radius: var(--zv-radius); color: var(--zv-text-muted); transition: all 0.15s;
}
.lgi-icon-btn:hover { background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); }
.lgi-icon-btn-warn:hover { color: #f59e0b; }
.lgi-icon-btn-danger:hover { color: #ef4444; }

.zv-pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; font-size: 12.5px; color: var(--zv-text-muted); }
.zv-pagination-pages { display: flex; gap: 4px; }
.zv-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 8px; border-radius: var(--zv-radius); font-size: 12.5px; }
.zv-page-btn.active { background: #0F2554; color: #fff; font-weight: 700; }
.lgi-page-btn-disabled { opacity: 0.35; pointer-events: none; }

.lgi-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.5); display: flex; align-items: center; justify-content: center; z-index: 60; padding: 20px; }
.lgi-modal { background: var(--zv-bg-card); border-radius: var(--zv-radius-lg); padding: 24px; width: 100%; max-width: 460px; max-height: 90vh; overflow-y: auto; box-shadow: var(--zv-shadow-lg); }
.lgi-modal-title { font-size: 16px; font-weight: 800; color: var(--zv-text-primary); margin-bottom: 6px; }
.lgi-modal-hint { font-size: 12.5px; color: var(--zv-text-muted); margin-bottom: 14px; }
.lgi-modal-hint-inline { font-weight: 400; text-transform: none; }
.lgi-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.zv-empty { padding: 60px 20px; text-align: center; }
.zv-empty-icon { color: var(--zv-text-muted); display: flex; justify-content: center; margin-bottom: 12px; }
.zv-empty-title { font-size: 15px; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 4px; }
.zv-empty-sub { font-size: 13px; color: var(--zv-text-muted); }

.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field-hint { font-size: 11px; color: var(--zv-text-muted); margin-top: 2px; }
</style>
