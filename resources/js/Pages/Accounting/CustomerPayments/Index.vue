<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerPayments/Index.vue

  Lists all Customer Collections. Every posted collection with cash
  still unallocated (whether it's a Settlement that had leftover, or
  a genuine Down Payment) shows an "Apply" action opening a modal to
  match it against open invoices — the same allocate() mechanism
  either way, per the design doc's core insight (Section 1).

  A Cheque-type collection shows a small status chip linking through
  to Cheques Receivable, where its own lifecycle (sent for
  collection / collected / rejected) is managed.

  @layout    AppLayout
  @route     accounting.customer-payments.index
  @props     customerPayments (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerPayments.index.customerCollection')"
      :subtitle="t('accounting.customerPayments.index.moneyReceivedFromCustomersSettledAgainst')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.customer-payments.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.customerPayments.index.newCollection') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="customerPayments.total"
        results-label="Collection"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.customerPayments.index.searchByCollectionCode')" @input="debouncedSearch" />
        <SelectBox v-model="collectionTypeFilter" :options="[{ value: 'settlement', label: t('accounting.customerPayments.index.settlement') }, { value: 'down_payment', label: t('accounting.customerPayments.index.downPayment') }]" :placeholder="t('accounting.customerPayments.index.allTypes')" @change="applyFilters" />
        <SelectBox v-model="moneyTypeFilter" :options="[{ value: 'bank_deposit', label: t('accounting.customerPayments.index.bankDeposit') }, { value: 'incoming_transfer', label: t('accounting.customerPayments.index.incomingTransfer') }, { value: 'cash_in_safe', label: t('accounting.customerPayments.index.cashInSafe') }, { value: 'cheque', label: t('accounting.customerPayments.index.cheque') }]" :placeholder="t('accounting.customerPayments.index.allMoneyTypes')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="customerPayments.data.length === 0"
        :title="hasActiveFilters ? t('accounting.customerPayments.index.noCollectionsMatchThisFilter') : t('accounting.customerPayments.index.noCustomerCollectionsYet')"
        :message="hasActiveFilters ? t('accounting.customerPayments.index.tryADifferentTypeOrClear') : t('accounting.customerPayments.index.useTheButtonAboveToRecord')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.customerPayments.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.customerPayments.index.collectionCode') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('common.type') }}</th>
              <th>{{ t('accounting.customerPayments.index.moneyType') }}</th>
              <th>{{ t('accounting.customerPayments.index.date') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerPayments.index.unapplied') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pmt in customerPayments.data" :key="pmt.id">
              <td><span class="zv-code">{{ pmt.payment_code }}</span></td>
              <td><PartnerCell :name="pmt.partner?.name_en" /></td>
              <td>
                <span class="zv-tag" :class="pmt.collection_type === 'down_payment' ? '' : 'zv-tag-gray'">
                  {{ pmt.collection_type === 'down_payment' ? t('accounting.customerPayments.index.downPayment') : t('accounting.customerPayments.index.settlement') }}
                </span>
              </td>
              <td>
                {{ moneyTypeLabel(pmt.money_type) }}
                <Link v-if="pmt.money_type === 'cheque' && pmt.cheque" :href="route('accounting.cheques-receivable.index')" class="cp-cheque-chip" :class="`cp-cheque-${pmt.cheque.status}`">
                  {{ chequeStatusLabel(pmt.cheque.status) }}
                </Link>
              </td>
              <td>{{ pmt.payment_date }}</td>
              <td class="zv-table-num">{{ fmt(pmt.amount) }} {{ pmt.receive_currency?.code }}</td>
              <td class="zv-table-num">
                <span :class="{ 'cp-unallocated-zero': Number(pmt.unallocated_amount) <= 0 }">{{ fmt(pmt.unallocated_amount) }}</span>
              </td>
              <td class="text-center">
                <span class="zv-badge" :class="statusMeta(pmt.status).badge">{{ t(statusMeta(pmt.status).label) }}</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.customer-payments.edit', pmt.id)" class="zv-action-btn" :title="pmt.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="pmt.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="pmt.status === 'posted' && Number(pmt.unallocated_amount) > 0" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openAllocate(pmt)">{{ t('accounting.customerPayments.index.apply') }}</button>
                  <button v-if="pmt.status === 'posted' && pmt.allocations?.length === 0 && (!pmt.cheque || pmt.cheque.status === 'received')" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(pmt)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="customerPayments" />
    </div>

    <!-- ══════════════════════════════════════════════════════
         APPLY MODAL — used for both leftover Settlement credit and Down Payment
    ══════════════════════════════════════════════════════ -->
    <Teleport to="body">
      <div v-if="allocateModal.payment" class="cp-modal-overlay zv-acc" @click.self="closeAllocate">
        <div class="cp-modal">
          <h3 class="cp-modal-title">{{ t('accounting.customerPayments.index.applyCollection') }} — {{ allocateModal.payment.payment_code }}</h3>
          <p class="cp-modal-hint">
            {{ t('accounting.customerPayments.index.unapplied') }}: <strong>{{ fmt(allocateModal.payment.unallocated_amount) }}</strong>
          </p>
          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.customerPayments.index.applicationDate') }}</label>
            <input v-model="applicationDate" type="date" class="zv-input" />
          </div>

          <div v-if="allocateError" class="zv-alert zv-alert-danger mb-3">{{ allocateError }}</div>
          <div v-if="allocateLoading" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('accounting.customerPayments.index.loadingOpenInvoices') }}</div>

          <div v-else-if="!openInvoices.length" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.customerPayments.index.thisCustomerHasNoOpenInvoices') }}
          </div>

          <div v-else class="cp-invoice-list">
            <div v-for="inv in openInvoices" :key="inv.id" class="cp-invoice-row">
              <div class="cp-invoice-info">
                <div><span class="zv-code">{{ inv.invoice_code }}</span></div>
                <div class="text-sm opacity-60">{{ inv.invoice_date }} — {{ t('accounting.customerPayments.index.outstanding') }}: {{ fmt(inv.amount_outstanding) }}</div>
              </div>
              <input
                v-model="allocationAmounts[inv.id]"
                type="number" step="0.01" min="0"
                class="zv-input text-end" style="max-width: 140px;"
                :placeholder="t('common.amount')"
              />
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="submitAllocation(inv)">{{ t('accounting.customerPayments.index.apply') }}</button>
            </div>
          </div>

          <div class="cp-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeAllocate">{{ t('accounting.customerPayments.index.close') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  customerPayments: { type: Object, required: true },
  filters:          { type: Object, default: () => ({}) },
})

const searchTerm         = ref(props.filters?.search ?? '')
const collectionTypeFilter = ref(props.filters?.collection_type ?? '')
const moneyTypeFilter      = ref(props.filters?.money_type ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!collectionTypeFilter.value || !!moneyTypeFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.customer-payments.index'), {
    search: searchTerm.value || undefined,
    collection_type: collectionTypeFilter.value || undefined,
    money_type: moneyTypeFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  collectionTypeFilter.value = ''
  moneyTypeFilter.value = ''
  applyFilters()
}

function confirmCancel(pmt) {
  if (! confirm(t('accounting.customerPayments.index.cancelThisCustomerCollectionThisCan'))) return
  router.patch(route('accounting.customer-payments.cancel', pmt.id))
}

function moneyTypeLabel(type) {
  const map = { bank_deposit: 'Bank Deposit', incoming_transfer: 'Incoming Transfer', cash_in_safe: 'Cash in Safe', cheque: 'Cheque' }
  return t(map[type] ?? type)
}

// Draft is a real, reachable state now (v1.2 — Reopen-to-Draft): only
// ever reached via "Set to Draft" on a posted collection, never on a
// brand-new one (those still post immediately, unchanged).
const STATUS_META = {
  draft:     { label: 'Draft',     badge: 'zv-badge-amber' },
  posted:    { label: 'Posted',    badge: 'zv-badge-green' },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-gray'  },
}
function statusMeta(status) {
  return STATUS_META[status] ?? { label: status, badge: 'zv-badge-gray' }
}

function chequeStatusLabel(status) {
  const map = { received: 'In Safe', sent_for_collection: 'Sent for Collection', collected: 'Collected', rejected: 'Rejected' }
  return t(map[status] ?? status)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Apply modal ───────────────────────────────────────────────────

const allocateModal   = ref({ payment: null })
const applicationDate = ref(todayLocal()) // Audit M-12
const openInvoices    = ref([])
const allocationAmounts = ref({})
const allocateLoading = ref(false)
const allocateError   = ref('')

function openAllocate(payment) {
  allocateModal.value = { payment }
  allocateError.value = ''
  allocationAmounts.value = {}
  applicationDate.value = todayLocal()
  allocateLoading.value = true
  openInvoices.value = []

  fetch(route('accounting.customer-payments.open-invoices', { partner_id: payment.partner_id }), {
    headers: { 'Accept': 'application/json' },
  })
    .then(res => res.json())
    .then(data => { openInvoices.value = data.invoices ?? [] })
    .finally(() => { allocateLoading.value = false })
}

function closeAllocate() {
  allocateModal.value = { payment: null }
}

function submitAllocation(invoice) {
  const amount = allocationAmounts.value[invoice.id]
  if (! amount || Number(amount) <= 0) return

  allocateError.value = ''

  fetch(route('accounting.customer-payments.allocate', allocateModal.value.payment.id), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ customer_invoice_id: invoice.id, amount, application_date: applicationDate.value }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { allocateError.value = data.message || t('accounting.customerPayments.index.somethingWentWrongPleaseTryAgain'); return }
      closeAllocate()
      router.reload({ preserveScroll: true })
    })
    .catch(() => { allocateError.value = t('accounting.customerPayments.index.somethingWentWrongPleaseTryAgain') })
}
</script>

<style>
.cp-unallocated-zero { color: var(--zv-text-secondary); }

.cp-cheque-chip {
  display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 20px;
  font-size: 10.5px; font-weight: 600; text-decoration: none;
}
.cp-cheque-received             { background: rgba(148,163,184,0.15); color: #64748b; }
.cp-cheque-sent_for_collection  { background: rgba(245,158,11,0.12); color: #f59e0b; }
.cp-cheque-collected            { background: rgba(16,185,129,0.12); color: #10b981; }
.cp-cheque-rejected             { background: rgba(239,68,68,0.12); color: #ef4444; }

.cp-modal-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100;
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.cp-modal {
  background: var(--vero-card); border-radius: 12px; padding: 24px;
  width: 100%; max-width: 520px; max-height: 88vh; overflow-y: auto;
}
.cp-modal-title { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.cp-modal-hint { font-size: 12.5px; color: var(--zv-text-secondary); margin: 0 0 16px; }
.cp-modal-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
.cp-invoice-list { display: flex; flex-direction: column; gap: 8px; max-height: 340px; overflow-y: auto; }
.cp-invoice-row { display: flex; align-items: center; gap: 10px; padding: 8px; border: 1px solid var(--vero-border); border-radius: 8px; }
.cp-invoice-info { flex: 1; }
</style>
