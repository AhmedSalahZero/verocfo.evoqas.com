<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierBills/Index.vue

  Lists all Supplier Bills for the active company. Same shared
  PageHeader / FilterBar / EmptyState / zv-table-wrap pattern as
  GoodsReceiptNotes/Index.vue. Shows bill type (Goods/Expense),
  linked PO (if any), value in bill currency, and status. Only a
  draft Bill can be edited or cancelled — posting is the one
  irreversible action (see SupplierBillService header).

  @layout    AppLayout
  @route     accounting.supplier-bills.index
  @props     supplierBills (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.supplierBills.index.supplierBills')"
      :subtitle="t('accounting.supplierBills.index.theRealInvoiceFromASupplier')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>'
    >
      <template #actions>
        <Link :href="route('accounting.supplier-bills.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.supplierBills.index.newSupplierBill') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="supplierBills.total"
        results-label="Bill"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.supplierBills.index.searchByBillCodeOrSupplier')" @input="debouncedSearch" />
        <SelectBox
          v-model="billTypeFilter"
          :options="billTypeOptions"
          :placeholder="t('accounting.supplierBills.index.allTypes')"
          class="zv-filter-select"
          @change="applyFilters"
        />
        <SelectBox
          v-model="statusFilter"
          :options="statusOptions"
          :placeholder="t('common.allStatuses')"
          class="zv-filter-select"
          @change="applyFilters"
        />
      </FilterBar>

      <EmptyState
        v-if="supplierBills.data.length === 0"
        :title="hasActiveFilters ? t('accounting.supplierBills.index.noSupplierBillsMatchThisFilter') : t('accounting.supplierBills.index.noSupplierBillsYet')"
        :message="hasActiveFilters ? t('accounting.supplierBills.index.tryADifferentStatusOrClear') : t('accounting.supplierBills.index.useTheButtonAboveToRecord')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.supplierBills.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.supplierBills.index.billCode') }}</th>
              <th>{{ t('common.type') }}</th>
              <th>{{ t('accounting.supplierBills.index.supplier') }}</th>
              <th>{{ t('accounting.supplierBills.index.supplierInvoice') }}</th>
              <th>{{ t('accounting.supplierBills.index.purchaseOrder') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBills.index.value') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBills.index.outstanding') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="text-center">{{ t('accounting.supplierBills.index.collection') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bill in supplierBills.data" :key="bill.id">
              <td><span class="zv-code">{{ bill.bill_code }}</span></td>
              <td>
                <span class="zv-tag" :class="bill.bill_type === 'goods' ? '' : bill.bill_type === 'prepaid' ? 'zv-tag-amber' : 'zv-tag-gray'">
                  {{ bill.bill_type === 'goods' ? t('accounting.supplierBills.index.goods') : bill.bill_type === 'prepaid' ? t('accounting.supplierBills.index.prepaid') : t('accounting.supplierBills.index.expense') }}
                </span>
              </td>
              <td><PartnerCell :name="bill.partner?.name_en" /></td>
              <td>{{ bill.supplier_invoice_number || '—' }}</td>
              <td>
                <span v-if="bill.purchase_order" class="zv-tag zv-tag-gold">{{ bill.purchase_order.po_code }}</span>
                <span v-else-if="bill.is_opening_balance" class="zv-tag zv-tag-gray">{{ t('accounting.supplierBills.index.openingBalance') }}</span>
                <span v-else class="zv-tag zv-tag-gray">{{ t('accounting.supplierBills.index.text') }}</span>
              </td>
              <td class="zv-table-num">{{ fmt(bill.bill_value) }} {{ bill.currency?.code }}</td>
              <td class="zv-table-num">
                <span :class="{ 'sb-outstanding-zero': Number(bill.amount_outstanding) <= 0 }">{{ fmt(bill.amount_outstanding) }}</span>
              </td>
              <td class="text-center"><StatusBadge :status="bill.status" :overrides="BILL_STATUS_BADGES" /></td>
              <td class="text-center">
                <ProgressBadge
                  v-if="bill.status === 'posted'"
                  :label="t(collectionMeta(bill).label)"
                  :badge="collectionMeta(bill).badge"
                  :value="bill.bill_value"
                  :outstanding="bill.amount_outstanding"
                  :tone="collectionMeta(bill).tone"
                />
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.supplier-bills.edit', bill.id)" class="zv-action-btn" :title="bill.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="bill.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <Link
                    v-if="bill.status === 'posted' && Number(bill.amount_outstanding) > 0"
                    :href="route('accounting.supplier-payments.create', { bill_id: bill.id })"
                    class="zv-btn zv-btn-primary zv-btn-sm"
                  >{{ t('accounting.supplierBills.index.pay') }}</Link>
                  <button v-if="bill.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(bill)">{{ t('accounting.supplierBills.index.post') }}</button>
                  <button v-if="bill.status === 'draft'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(bill)">
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

      <Pagination :paginator="supplierBills" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'
import ProgressBadge from '@/Components/ProgressBadge.vue'

const { t } = useI18n()

const props = defineProps({
  supplierBills: { type: Object, required: true },
  filters:       { type: Object, default: () => ({}) },
})

const BILL_STATUS_BADGES = {
  posted: { label: 'Posted', badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

// Collection status is derived, not stored — only meaningful once a
// bill is posted (a draft has no real AP position yet). "Partial"
// means at least something has been paid but not all of it. Matches
// amount_paid/amount_outstanding, both maintained by
// SupplierPaymentService on every allocate()/unallocate() and by
// setToDraft() when a payment is reopened — same logic already
// proven on the Customer Invoice side.
function collectionMeta(bill) {
  const paid = Number(bill.amount_paid || 0)
  const outstanding = Number(bill.amount_outstanding || 0)

  if (outstanding <= 0) return { label: 'Paid', badge: 'zv-badge-green', tone: 'green' }
  if (paid > 0) return { label: 'Partially Paid', badge: 'zv-badge-amber', tone: 'amber' }
  return { label: 'Not Paid', badge: 'zv-badge-gray', tone: 'blue' }
}

// Filter dropdowns are short, fixed vocabularies — no search box (the
// component hides it under 8 options) and clearable, since "all" is a
// legal state and is what the placeholder stands for.
const billTypeOptions = computed(() => ([
  { value: 'goods',   label: t('accounting.supplierBills.index.goods') },
  { value: 'expense', label: t('accounting.supplierBills.index.expense') },
  { value: 'prepaid', label: t('accounting.supplierBills.index.prepaid') },
]))
const statusOptions = computed(() => ([
  { value: 'draft',     label: t('accounting.supplierBills.index.draft') },
  { value: 'posted',    label: t('accounting.supplierBills.index.posted') },
  { value: 'cancelled', label: t('accounting.supplierBills.index.cancelled') },
]))

const searchTerm     = ref(props.filters?.search ?? '')
const statusFilter   = ref(props.filters?.status ?? '')
const billTypeFilter = ref(props.filters?.bill_type ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!statusFilter.value || !!billTypeFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.supplier-bills.index'), {
    search:    searchTerm.value || undefined,
    status:    statusFilter.value || undefined,
    bill_type: billTypeFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  billTypeFilter.value = ''
  applyFilters()
}

function confirmPost(bill) {
  if (! confirm(t('accounting.supplierBills.index.postThisSupplierBillThisCreates'))) return
  router.patch(route('accounting.supplier-bills.post', bill.id))
}

function confirmCancel(bill) {
  if (! confirm(t('accounting.supplierBills.index.cancelThisDraftSupplierBill'))) return
  router.patch(route('accounting.supplier-bills.cancel', bill.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.sb-outstanding-zero { color: var(--zv-text-secondary); }
</style>
