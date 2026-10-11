<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Leasing/AmortizationSchedule.vue

  Shows a single lease contract's full payment schedule (interest /
  principal / balance per period) and its asset wear-down history,
  side by side. Built alongside the Lease Amortization Engine
  (Piece 1-5, July 2026).

  Actions available from here:
    - Record Payment — one button per unpaid schedule line. Never
      automatic (confirmed with Mahmoud): the user deliberately says
      "this payment happened," whenever it actually did.
    - Reverse — one button per posted wear-down entry, in case it
      ever needs correcting (early termination, a mistake, etc.).
      Never a silent edit — this posts the mirror-image reversal,
      same pattern JournalEntryService uses everywhere else.
    - Final Purchase — only shown for an active Operational Lease
      Treatment B contract. Capital Lease never shows this (its
      asset was already recognized as owned from day one).

  Audit M5 (Sept 2026):
    - Record Payment and Reverse called route names without the
      'fi.' prefix, so both buttons failed — fixed.
    - The Final Purchase modal now shows the server's validation
      message under the amount (e.g. "must be greater than zero").

  @layout    AppLayout
  @route     fi.leasing-contracts.schedule
  @props     contract: LeasingContract (with amortizationLines, wearDownEntries, leasingCompany)
             liabilitySplit: { as_of_date, current, long_term, total } | null
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.leasing.amortizationSchedule.leasingContracts'), href: route('fi.leasing-contracts.index') }, { label: contract.asset_description }]"
      :title="contract.asset_description"
    >
      <template #subtitle>
        <span class="font-semibold">{{ contract.leasing_company?.name_en }}</span>
        <span class="mx-2" style="color:var(--zv-border-md)">·</span>
        <span>{{ contract.contract_type === 'capital' ? t('financialInstitutions.leasing.amortizationSchedule.capitalLease') : t('financialInstitutions.leasing.amortizationSchedule.operationalLeaseTreatmentB') }}</span>
      </template>
      <template #actions>
        <button v-if="canFinalPurchase" type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openFinalPurchase">
          {{ t('financialInstitutions.leasing.amortizationSchedule.finalPurchase') }}
        </button>
        <StatusBadge :status="contract.status" />
      </template>
    </PageHeader>

    <!-- KPI row -->
    <div class="las-kpi-grid mb-6">
      <KpiCard :label="t('financialInstitutions.leasing.amortizationSchedule.initialValue')" :value="formatMoney(contract.initial_lease_liability)" />
      <KpiCard :label="t('financialInstitutions.leasing.amortizationSchedule.remainingBalance')" :value="formatMoney(remainingBalance)" />
      <KpiCard v-if="liabilitySplit" :label="t('financialInstitutions.leasing.amortizationSchedule.dueWithin12Months')" :value="formatMoney(liabilitySplit.current)" />
      <KpiCard v-if="liabilitySplit" :label="t('financialInstitutions.leasing.amortizationSchedule.dueBeyond12Months')" :value="formatMoney(liabilitySplit.long_term)" />
    </div>

    <!-- Payment schedule -->
    <div class="zv-card mb-6">
      <div class="zv-card-header">{{ t('financialInstitutions.leasing.amortizationSchedule.paymentSchedule') }}</div>

      <EmptyState
        v-if="!contract.amortization_lines?.length"
        :title="t('financialInstitutions.leasing.amortizationSchedule.noScheduleYet')"
        :message="t('financialInstitutions.leasing.amortizationSchedule.activateThisLeaseFirstToGenerate')"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.text') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.date') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.payment') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.interest') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.principal') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.balanceAfter') }}</th>
              <th>{{ t('common.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in contract.amortization_lines" :key="line.id">
              <td>{{ line.period_number }}</td>
              <td>{{ line.payment_date }}</td>
              <td>{{ formatMoney(line.payment_amount) }}</td>
              <td>{{ formatMoney(line.interest_amount) }}</td>
              <td>{{ formatMoney(line.principal_amount) }}</td>
              <td>{{ formatMoney(line.balance_after) }}</td>
              <td><StatusBadge :status="line.is_paid ? 'paid' : 'unpaid'" :overrides="PAYMENT_STATUS_BADGES" /></td>
              <td>
                <button v-if="!line.is_paid" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="recordPayment(line)">
                  {{ t('financialInstitutions.leasing.amortizationSchedule.recordPayment') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Asset wear-down history -->
    <div class="zv-card">
      <div class="zv-card-header">{{ t('financialInstitutions.leasing.amortizationSchedule.assetWearDownHistory') }}</div>

      <EmptyState
        v-if="!contract.wear_down_entries?.length"
        :title="t('financialInstitutions.leasing.amortizationSchedule.noWearDownEntries')"
        :message="t('financialInstitutions.leasing.amortizationSchedule.nothingHasBeenPostedForThis')"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.text') }}</th>
              <th>{{ t('financialInstitutions.leasing.amortizationSchedule.date') }}</th>
              <th>{{ t('common.amount') }}</th>
              <th>{{ t('common.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in contract.wear_down_entries" :key="entry.id">
              <td>{{ entry.period_number }}</td>
              <td>{{ entry.entry_date }}</td>
              <td>{{ formatMoney(entry.amount) }}</td>
              <td><StatusBadge :status="entry.status" :overrides="WEAR_DOWN_STATUS_BADGES" /></td>
              <td>
                <button v-if="entry.status === 'posted'" type="button" class="zv-btn zv-btn-danger zv-btn-sm" @click="reverseWearDown(entry)">
                  {{ t('financialInstitutions.leasing.amortizationSchedule.reverse') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Final purchase modal -->
    <Teleport to="body">
      <div v-if="finalPurchaseModal" class="las-modal-overlay" @click.self="finalPurchaseModal = false">
        <div class="las-modal">
          <h3 class="las-modal-title">{{ t('financialInstitutions.leasing.amortizationSchedule.finalPurchase') }}</h3>
          <p class="las-modal-hint">{{ t('financialInstitutions.leasing.amortizationSchedule.moveThisAssetFromRightOf') }}</p>
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.amortizationSchedule.purchaseValue') }}</label>
            <input v-model="purchaseValue" type="number" step="0.01" min="0.01" class="zv-input"/>
            <p v-if="purchaseError" class="zv-field-error">{{ purchaseError }}</p>
          </div>
          <div class="las-modal-actions">
            <button type="button" class="zv-btn zv-btn-secondary" @click="finalPurchaseModal = false">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" @click="submitFinalPurchase">{{ t('common.confirm') }}</button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  contract: Object,
  liabilitySplit: Object,
})

const PAYMENT_STATUS_BADGES = {
  paid:   { label: 'Paid',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  unpaid: { label: 'Unpaid', badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
}
const WEAR_DOWN_STATUS_BADGES = {
  posted:   { label: 'Posted',   badge: 'zv-badge-blue', dot: 'zv-status-dot-blue' },
  reversed: { label: 'Reversed', badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' },
}

const remainingBalance = computed(() => {
  const unpaid = (props.contract.amortization_lines || []).filter(l => !l.is_paid)
  return unpaid.length ? unpaid[unpaid.length - 1].balance_after : '0.00'
})

const canFinalPurchase = computed(() =>
  props.contract.status === 'active' && props.contract.contract_type === 'operational' && props.contract.accounting_treatment === 'rou_b'
)

function formatMoney(value) {
  return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function recordPayment(line) {
  if (!confirm(t('financialInstitutions.leasing.amortizationSchedule.confirmThisPaymentHasActuallyBeen'))) return
  router.post(route('fi.lease-amortization-lines.record-payment', line.id), {}, { preserveScroll: true })
}

function reverseWearDown(entry) {
  if (!confirm(t('financialInstitutions.leasing.amortizationSchedule.reverseThisWearDownEntryYou'))) return
  router.post(route('fi.lease-wear-down-entries.reverse', entry.id), {}, { preserveScroll: true })
}

const finalPurchaseModal = ref(false)
const purchaseValue = ref('')
const purchaseError = ref('')

function openFinalPurchase() {
  purchaseValue.value = ''
  purchaseError.value = ''
  finalPurchaseModal.value = true
}

function submitFinalPurchase() {
  purchaseError.value = ''
  router.post(route('fi.leasing-contracts.final-purchase', props.contract.id), { purchase_value: purchaseValue.value }, {
    onSuccess: () => { finalPurchaseModal.value = false },
    onError: (errors) => { purchaseError.value = errors.purchase_value || errors.purchase_date || '' },
  })
}
</script>

<style scoped>
.las-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
@media (max-width: 1100px) { .las-kpi-grid { grid-template-columns: repeat(2, 1fr); } }

.las-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; display: flex; align-items: center; justify-content: center; }
.las-modal { width: 420px; max-width: 92vw; background: var(--zv-bg-card); border-radius: var(--zv-radius-lg); border: 1px solid var(--zv-border); box-shadow: var(--zv-shadow-lg); padding: 20px; }
.las-modal-title { font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--zv-text-primary); }
.las-modal-hint { font-size: 12.5px; color: var(--zv-text-muted); margin-bottom: 14px; }
.las-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
</style>