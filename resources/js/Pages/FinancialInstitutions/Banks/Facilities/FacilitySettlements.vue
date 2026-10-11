<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/FacilitySettlements.vue

  ONE shared page across all four OD-family facility types (Clean OD,
  Commercial Paper OD, Contract-Assignment OD, Fully Secured OD) —
  same reasoning as the shared FacilitySettlementEngine itself
  (Building Bible Rule 34.1). Reached via a "Settlements" link from
  each facility type's own Index page, not a top-level menu item.

  Three sections:
    1. Post a Charge — interest / HDB fee / admin fee. The one action
       here that posts a real new journal entry.
    2. Open Items — charges and withdrawals not yet fully settled,
       i.e. exactly what the NEXT settlement will apply against, in
       Rule 34.2 priority order (interest → HDB → admin → oldest
       withdrawal first).
    3. Settlement History — every settlement recorded against this
       facility (from Supplier/Customer Payment or Internal Money
       Transfer), each reversible.

  @layout    AppLayout
  @route     fi.facilities.settlements.index
  @props     facility, openCharges, openWithdrawals, settlements (paginator), bankAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="zv-page-header">
      <div>
        <div class="zv-breadcrumb">
          <Link :href="facilityIndexRoute" class="zv-text-blue">{{ facilityTypeLabel }}</Link>
          <span class="zv-breadcrumb-sep">/</span>
          <span>{{ t('financialInstitutions.banks.facilities.facilitySettlements.settlements') }}</span>
        </div>
        <h1 class="zv-page-title">{{ facility.bank?.name_en }} — {{ facility.facility_reference || facilityTypeLabel }}</h1>
        <p class="zv-page-subtitle">{{ t('financialInstitutions.banks.facilities.facilitySettlements.everyChargeAndRepaymentAppliedIn') }}</p>
      </div>
    </div>

    <div class="fset-kpi-grid">
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.facilityLimit') }}</div>
        <div class="zv-kpi-value">{{ facility.currency?.code }} {{ fmt(facility.facility_limit) }}</div>
      </div>
      <div class="zv-kpi-card" :class="{ 'fset-kpi-warn': utilizationPct >= 90 }">
        <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.outstandingBalance') }}</div>
        <div class="zv-kpi-value" :class="{ 'fset-kpi-warn-value': utilizationPct >= 90 }">{{ facility.currency?.code }} {{ fmt(facility.outstanding_balance) }}</div>
        <div class="zv-kpi-sub">{{ utilizationPct }}% {{ t('financialInstitutions.banks.facilities.facilitySettlements.utilized') }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.openCharges') }}</div>
        <div class="zv-kpi-value">{{ fmt(totalOpenCharges) }}</div>
        <div class="zv-kpi-sub">{{ openCharges.length }} {{ t('financialInstitutions.banks.facilities.facilitySettlements.unpaidChargeRows') }}</div>
      </div>
      <div class="zv-kpi-card">
        <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.openWithdrawals') }}</div>
        <div class="zv-kpi-value">{{ fmt(totalOpenWithdrawals) }}</div>
        <div class="zv-kpi-sub">{{ openWithdrawals.length }} {{ t('financialInstitutions.banks.facilities.facilitySettlements.unsettledDrawdowns') }}</div>
      </div>
    </div>

    <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">{{ $page.props.flash.success }}</div>
    <div v-if="$page.props.flash?.error" class="zv-alert zv-alert-danger mb-4">{{ $page.props.flash.error }}</div>

    <!-- ══════════════════ Post a Charge ══════════════════ -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#0F2554">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <div>
          <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.facilitySettlements.postACharge') }}</div>
          <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.facilitySettlements.interestHdbFeeOrAdminFeeThe') }}</div>
        </div>
      </div>
      <div class="form-section-body">
        <OptionTiles
          :model-value="chargeForm.charge_type" variant="pill" class="mb-4"
          :options="[
            { value: 'interest', label: t('financialInstitutions.banks.facilities.facilitySettlements.interest') },
            { value: 'highest_debit_balance', label: t('financialInstitutions.banks.facilities.facilitySettlements.hdbFee') },
            { value: 'admin_fee', label: t('financialInstitutions.banks.facilities.facilitySettlements.adminFee') },
          ]"
          @update:model-value="(v) => chargeForm.charge_type = v"
        />

        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.facilitySettlements.period') }}</label>
            <input v-model="chargeForm.period_label" type="text" class="zv-input" placeholder="2026-03" :class="{ 'zv-input-error': chargeForm.errors.period_label }"/>
            <p v-if="chargeForm.errors.period_label" class="zv-field-error">{{ chargeForm.errors.period_label }}</p>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.facilitySettlements.chargeDate') }}</label>
            <input v-model="chargeForm.charge_date" type="date" class="zv-input" :class="{ 'zv-input-error': chargeForm.errors.charge_date }"/>
            <p v-if="chargeForm.errors.charge_date" class="zv-field-error">{{ chargeForm.errors.charge_date }}</p>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.theoreticalAmount') }}</label>
            <input v-model="chargeForm.theoretical_amount" type="number" step="0.01" min="0" class="zv-input text-end" placeholder="0.00"/>
            <p class="field-hint">{{ t('financialInstitutions.banks.facilities.facilitySettlements.systemCalculatedOptionalLeaveBlank') }}</p>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.facilitySettlements.actualAmount') }}</label>
            <input v-model="chargeForm.actual_amount" type="number" step="0.01" min="0.01" class="zv-input text-end" placeholder="0.00" :class="{ 'zv-input-error': chargeForm.errors.actual_amount }"/>
            <p class="field-hint">{{ t('financialInstitutions.banks.facilities.facilitySettlements.fromTheBankSStatement') }}</p>
            <p v-if="chargeForm.errors.actual_amount" class="zv-field-error">{{ chargeForm.errors.actual_amount }}</p>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.facilitySettlements.bankAccount') }}</label>
            <select v-model="chargeForm.bank_account_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.banks.facilities.facilitySettlements.useTheFacilitySOwnAccount') }}</option>
              <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.account_number }}</option>
            </select>
          </div>
        </div>

        <div v-if="differenceNote" class="field-hint mt-2">{{ differenceNote }}</div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="chargeForm.processing" @click="submitCharge">
            {{ chargeForm.processing ? t('common.saving') : t('financialInstitutions.banks.facilities.facilitySettlements.postCharge') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ══════════════════ Open Items ══════════════════ -->
    <div class="zv-card mb-5">
      <div class="lgi-existing-header">{{ t('financialInstitutions.banks.facilities.facilitySettlements.openItemsSettlementOrder') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.banks.facilities.facilitySettlements.order') }}</th>
              <th>{{ t('common.type') }}</th>
              <th>{{ t('financialInstitutions.banks.facilities.facilitySettlements.dateDue') }}</th>
              <th class="lgi-th-num">{{ t('common.amount') }}</th>
              <th class="lgi-th-num">{{ t('financialInstitutions.banks.facilities.facilitySettlements.remaining') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in orderedOpenItems" :key="`${row.kind}-${row.id}`" class="lgi-row">
              <td class="lgi-muted">{{ idx + 1 }}</td>
              <td><span class="fset-type-badge" :class="`fset-type-${row.kind}`">{{ row.typeLabel }}</span></td>
              <td class="lgi-muted">{{ fmtDate(row.date) }}</td>
              <td class="zv-table-num">{{ fmt(row.amount) }}</td>
              <td class="zv-table-num">{{ fmt(row.remaining_amount) }}</td>
            </tr>
            <tr v-if="orderedOpenItems.length === 0">
              <td colspan="5" class="lgi-muted text-center py-4">{{ t('financialInstitutions.banks.facilities.facilitySettlements.nothingOutstanding') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════ Settlement History ══════════════════ -->
    <div class="zv-card">
      <div class="lgi-existing-header">{{ t('financialInstitutions.banks.facilities.facilitySettlements.settlementHistory') }}</div>

      <div v-if="settlements.data.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.banks.facilities.facilitySettlements.noSettlementsYet') }}</div>
      </div>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.banks.facilities.facilitySettlements.date') }}</th>
              <th>{{ t('financialInstitutions.banks.facilities.facilitySettlements.source') }}</th>
              <th>{{ t('financialInstitutions.banks.facilities.facilitySettlements.bankAccount') }}</th>
              <th class="lgi-th-num">{{ t('common.amount') }}</th>
              <th>{{ t('common.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in settlements.data" :key="row.id" class="lgi-row">
              <td class="lgi-muted">{{ fmtDate(row.settlement_date) }}</td>
              <td>{{ sourceLabel(row.source_type) }}</td>
              <td class="lgi-muted">{{ row.bank_account?.account_number }}</td>
              <td class="zv-table-num">{{ fmt(row.amount) }}</td>
              <td>
                <span class="fset-status-badge" :class="row.status === 'reversed' ? 'fset-status-reversed' : 'fset-status-posted'">
                  {{ row.status === 'reversed' ? t('financialInstitutions.banks.facilities.facilitySettlements.reversed') : t('financialInstitutions.banks.facilities.facilitySettlements.posted') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="fset-reverse-hint">{{ t('financialInstitutions.banks.facilities.facilitySettlements.toUndoASettlementReopenOrCancel') }}</p>

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
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
  facility:        { type: Object, required: true },
  openCharges:     { type: Array, default: () => [] },
  openWithdrawals: { type: Array, default: () => [] },
  settlements:     { type: Object, required: true },
  bankAccounts:    { type: Array, default: () => [] },
})

const facilityTypeLabel = computed(() => ({
  clean_od: t('financialInstitutions.banks.facilities.facilitySettlements.cleanOverdraft'),
  commercial_paper: t('financialInstitutions.banks.facilities.facilitySettlements.commercialPaperOverdraft'),
  contract_so: t('financialInstitutions.banks.facilities.facilitySettlements.contractAssignmentOverdraft'),
  fully_secured: t('financialInstitutions.banks.facilities.facilitySettlements.fullySecuredOverdraft'),
}[props.facility.facility_type] ?? props.facility.facility_type))

const facilityIndexRoute = computed(() => {
  const routeName = {
    clean_od: 'fi.facilities.clean-od.index',
    commercial_paper: 'fi.facilities.commercial-paper-od.index',
    contract_so: 'fi.facilities.contract-od.index',
    fully_secured: 'fi.facilities.fully-secured-od.index',
  }[props.facility.facility_type]
  return routeName ? route(routeName) : '#'
})

const utilizationPct = computed(() => {
  const limit = Number(props.facility.facility_limit || 0)
  if (limit <= 0) return 0
  return Math.round((Number(props.facility.outstanding_balance || 0) / limit) * 100)
})

const totalOpenCharges = computed(() => props.openCharges.reduce((sum, c) => sum + Number(c.remaining_amount || 0), 0))
const totalOpenWithdrawals = computed(() => props.openWithdrawals.reduce((sum, w) => sum + Number(w.remaining_amount || 0), 0))

// Same Rule 34.2 order the engine itself applies: interest → HDB →
// admin fee (each oldest first), then withdrawals oldest due date
// first — so this table shows exactly what the NEXT settlement will
// hit, in order.
const orderedOpenItems = computed(() => {
  const chargeLabel = { interest: t('financialInstitutions.banks.facilities.facilitySettlements.interest'), highest_debit_balance: t('financialInstitutions.banks.facilities.facilitySettlements.hdbFee'), admin_fee: t('financialInstitutions.banks.facilities.facilitySettlements.adminFee') }
  const priority = { interest: 1, highest_debit_balance: 2, admin_fee: 3 }

  const charges = [...props.openCharges]
    .sort((a, b) => (priority[a.charge_type] - priority[b.charge_type]) || new Date(a.charge_date) - new Date(b.charge_date))
    .map(c => ({ kind: 'charge', id: c.id, typeLabel: chargeLabel[c.charge_type] ?? c.charge_type, date: c.charge_date, amount: c.actual_amount, remaining_amount: c.remaining_amount }))

  const withdrawals = [...props.openWithdrawals]
    .sort((a, b) => new Date(a.due_date) - new Date(b.due_date))
    .map(w => ({ kind: 'withdrawal', id: w.id, typeLabel: t('financialInstitutions.banks.facilities.facilitySettlements.withdrawal'), date: w.due_date, amount: w.amount, remaining_amount: w.remaining_amount }))

  return [...charges, ...withdrawals]
})

const chargeForm = useForm({
  charge_type: 'interest',
  period_label: '',
  charge_date: todayLocal(),
  theoretical_amount: '',
  actual_amount: '',
  bank_account_id: '',
  override_reason: '',
})

const differenceNote = computed(() => {
  const theo = Number(chargeForm.theoretical_amount || 0)
  const actual = Number(chargeForm.actual_amount || 0)
  if (!theo || !actual) return ''
  const diff = actual - theo
  if (Math.abs(diff) < 0.01) return ''
  return t('financialInstitutions.banks.facilities.facilitySettlements.differsFromTheoreticalBy') + ' ' + fmt(Math.abs(diff)) + (diff > 0 ? ' (+)' : ' (-)')
})

function submitCharge() {
  chargeForm.post(route('fi.facilities.settlements.post-charge', props.facility.id), {
    preserveScroll: true,
    // Keep the typed figures when the server refuses (for example, no
    // exchange rate on file) — a refusal arrives as a normal page with an
    // error message, so onSuccess still runs.
    onSuccess: (page) => { if (!page.props.flash?.error) chargeForm.reset('period_label', 'theoretical_amount', 'actual_amount', 'override_reason') },
  })
}

function sourceLabel(sourceType) {
  return {
    customer_payment: t('financialInstitutions.banks.facilities.facilitySettlements.customerPayment'),
    internal_money_transfer: t('financialInstitutions.banks.facilities.facilitySettlements.internalTransfer'),
  }[sourceType] ?? sourceType
}

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
</script>

<style>
.fset-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .fset-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fset-kpi-grid { grid-template-columns: 1fr; } }
.fset-kpi-warn { border-color: rgba(239,68,68,0.35); }
.fset-kpi-warn-value { color: #ef4444; }

.fset-type-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.fset-type-charge { background: rgba(245,158,11,0.1); color: #f59e0b; }
.fset-type-withdrawal { background: rgba(20,144,168,0.1); color: #1490A8; }

.fset-status-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.fset-status-posted { background: rgba(16,185,129,0.1); color: #10b981; }
.fset-status-reversed { background: rgba(100,116,139,0.1); color: #64748b; }
.fset-reverse-hint { padding: 12px 18px; font-size: 12.5px; color: var(--zv-text-muted); border-top: 1px dashed var(--zv-border); }

.lgi-muted { color: var(--zv-text-muted); }
.lgi-th-num { text-align: end; }
.lgi-th-actions { width: 60px; }
.zv-table-num { text-align: end; font-variant-numeric: tabular-nums; }
.lgi-row:hover { background: var(--zv-bg-surface-alt); }
.lgi-existing-header { padding: 14px 18px; font-size: 14px; font-weight: 700; color: var(--zv-text-primary); border-bottom: 1px solid var(--zv-border); }
.lgi-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--zv-radius); color: var(--zv-text-muted); transition: all 0.15s; }
.lgi-icon-btn:hover { background: var(--zv-bg-surface-alt); color: #ef4444; }
.lgi-page-btn-disabled { opacity: 0.35; pointer-events: none; }
.zv-pagination-pages { display: flex; gap: 4px; }
.text-end { text-align: end; }
.text-center { text-align: center; }
.py-4 { padding-top: 1rem; padding-bottom: 1rem; }
</style>
