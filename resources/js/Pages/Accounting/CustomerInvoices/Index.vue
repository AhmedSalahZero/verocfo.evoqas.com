<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerInvoices/Index.vue

  Lists all Customer Invoices for the active company. Same shared
  PageHeader / FilterBar / EmptyState / zv-table-wrap pattern as
  SupplierBills/Index.vue. Shows the linked Sales Order, value,
  how much is still outstanding, and status. Only a draft invoice
  can be edited or cancelled — posting is the one irreversible
  action (see CustomerInvoiceService header); a posted invoice is
  corrected via a Credit Note instead.

  @layout    AppLayout
  @route     accounting.customer-invoices.index
  @props     customerInvoices (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerInvoices.index.customerInvoices')"
      :subtitle="t('accounting.customerInvoices.index.turnsASalesOrderIntoA')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m4 6a9 9 0 11-18 0 9 9 0 0118 0zM9 9h.01M15 15h.01"/>'
    >
      <template #actions>
        <Link :href="route('accounting.customer-invoices.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.customerInvoices.index.newCustomerInvoice') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="customerInvoices.total"
        results-label="Invoice"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.customerInvoices.index.searchByInvoiceCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.customerInvoices.index.draft') }, { value: 'posted', label: t('accounting.customerInvoices.index.posted') }, { value: 'cancelled', label: t('accounting.customerInvoices.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="customerInvoices.data.length === 0"
        :title="hasActiveFilters ? t('accounting.customerInvoices.index.noCustomerInvoicesMatchThisFilter') : t('accounting.customerInvoices.index.noCustomerInvoicesYet')"
        :message="hasActiveFilters ? t('accounting.customerInvoices.index.tryADifferentStatusOrClear') : t('accounting.customerInvoices.index.useTheButtonAboveToRaise')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.customerInvoices.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.customerInvoices.index.invoiceCode') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('accounting.customerInvoices.index.salesOrder') }}</th>
              <th>{{ t('accounting.customerInvoices.index.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerInvoices.index.value') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerInvoices.index.outstanding') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="text-center">{{ t('accounting.customerInvoices.index.collection') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in customerInvoices.data" :key="inv.id">
              <td><span class="zv-code">{{ inv.invoice_code }}</span></td>
              <td><PartnerCell :name="inv.partner?.name_en" /></td>
              <td>
                <span v-if="inv.sales_order" class="zv-tag zv-tag-gold">{{ inv.sales_order.so_code }}</span>
                <span v-else-if="inv.is_opening_balance" class="zv-tag zv-tag-gray">{{ t('accounting.customerInvoices.index.openingBalance') }}</span>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td>{{ inv.invoice_date }}</td>
              <td class="zv-table-num">
                {{ fmt(inv.invoice_value) }} {{ inv.currency?.code }}
                <span v-if="inv.exceeds_so_value" class="zv-badge zv-badge-amber ci-exceeds-badge" :title="t('accounting.customerInvoices.index.thisInvoiceWasPostedAboveIts')">
                  {{ t('accounting.customerInvoices.index.exceedsSO') }}
                </span>
              </td>
              <td class="zv-table-num">
                <span :class="{ 'ci-outstanding-zero': Number(inv.amount_outstanding) <= 0 }">{{ fmt(inv.amount_outstanding) }}</span>
              </td>
              <td class="text-center"><StatusBadge :status="inv.status" :overrides="INVOICE_STATUS_BADGES" /></td>
              <td class="text-center">
                <ProgressBadge
                  v-if="inv.status === 'posted'"
                  :label="t(collectionMeta(inv).label)"
                  :badge="collectionMeta(inv).badge"
                  :value="inv.invoice_value"
                  :outstanding="inv.amount_outstanding"
                  :tone="collectionMeta(inv).tone"
                />
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.customer-invoices.edit', inv.id)" class="zv-action-btn" :title="inv.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="inv.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <Link
                    v-if="inv.status === 'posted' && Number(inv.amount_outstanding) > 0"
                    :href="route('accounting.customer-payments.create', { invoice_id: inv.id })"
                    class="zv-btn zv-btn-primary zv-btn-sm"
                  >{{ t('accounting.customerInvoices.index.collect') }}</Link>
                  <button v-if="inv.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(inv)">{{ t('accounting.customerInvoices.index.post') }}</button>
                  <button v-if="inv.status === 'draft'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(inv)">
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

      <Pagination :paginator="customerInvoices" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, usePage } from '@inertiajs/vue3'
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
  customerInvoices: { type: Object, required: true },
  filters:          { type: Object, default: () => ({}) },
})

const INVOICE_STATUS_BADGES = {
  posted: { label: 'Posted', badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  // 'draft' now genuinely reachable two ways — a brand-new never-posted
  // invoice, or one reopened via Set to Draft. Same label either way;
  // the invoice's own page explains which if it matters.
  draft: { label: 'Draft', badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
}

// Collection status is derived, not stored — only meaningful once an
// invoice is posted (a draft has no real AR position yet). "Partial"
// means at least something has been paid but not all of it; matches
// amount_paid/amount_outstanding, both already maintained by
// CustomerPaymentService on every allocate()/unallocate() and by
// setToDraft() when a collection is reopened.
function collectionMeta(inv) {
  const paid = Number(inv.amount_paid || 0)
  const outstanding = Number(inv.amount_outstanding || 0)

  if (outstanding <= 0) return { label: 'Collected', badge: 'zv-badge-green', tone: 'green' }
  if (paid > 0) return { label: 'Partially Collected', badge: 'zv-badge-amber', tone: 'amber' }
  return { label: 'Not Collected', badge: 'zv-badge-gray', tone: 'blue' }
}

const searchTerm   = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!statusFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.customer-invoices.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

const page = usePage()

// ── Post: warn-and-override flow (confirmed with Mahmoud, August 2026) ──
//
// First click posts normally. If the SO value would be exceeded, the
// backend doesn't just error — it flashes a distinct 'so_value_exceeded'
// message (see HandleInertiaRequests + CustomerInvoiceController::post()).
// pendingSoExceedInvoice remembers which invoice that was for, so when the
// watcher below sees that flash come through, it can show a second confirm
// ("proceed anyway?") and, if accepted, resubmit the SAME invoice with
// override_so_limit=true. The Sales Order's own value is never touched
// either way — see CustomerInvoiceService::post()'s own comment.
const pendingSoExceedInvoice = ref(null)

function confirmPost(inv) {
  if (! confirm(t('accounting.customerInvoices.index.postThisCustomerInvoiceThisRecognizes'))) return
  pendingSoExceedInvoice.value = inv
  router.patch(route('accounting.customer-invoices.post', inv.id))
}

watch(() => page.props.flash?.so_value_exceeded, (msg) => {
  if (! msg || ! pendingSoExceedInvoice.value) return
  const inv = pendingSoExceedInvoice.value
  pendingSoExceedInvoice.value = null

  if (confirm(msg + '\n\n' + t('accounting.customerInvoices.index.proceedAnywayAnExceedsSOBadge'))) {
    router.patch(route('accounting.customer-invoices.post', inv.id), { override_so_limit: true })
  }
})

function confirmCancel(inv) {
  if (! confirm(t('accounting.customerInvoices.index.cancelThisDraftCustomerInvoice'))) return
  router.patch(route('accounting.customer-invoices.cancel', inv.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.ci-outstanding-zero { color: var(--zv-text-secondary); }
.ci-exceeds-badge { margin-inline-start: 6px; vertical-align: middle; font-size: 10px; }
</style>
