<!--
══════════════════════════════════════════════════════════════════
  Accounting/PurchaseOrders/Index.vue

  Lists all Purchase Orders for the active company. Mirrors
  Projects/SalesOrders/Index.vue's structure, adapted for:
    - Supplier column instead of Customer
    - Receipt + Billing status badges alongside the main status
      (see PurchaseOrder migration header for why these are kept
      independent) — both currently always show their "not started"
      state since GRN/Supplier Bill don't exist yet; the columns are
      here and correct, just waiting for those modules to update them
    - Force-Close action (with mandatory reason) alongside the plain
      Close action Sales Order already has

  Fix (July 2026): header and filter bar migrated from this page's
  original one-off markup onto the shared PageHeader/FilterBar
  components — same visual result (this page was the reference the
  shared components' styling was brought up to match), but now built
  the same way as every other Accounting index page instead of being
  the one bespoke outlier. The table below is untouched.

  @layout    AppLayout
  @route     accounting.purchase-orders.index
  @props     purchaseOrders: PaginatedCollection, filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.purchaseOrders.index.purchaseOrders')"
      :subtitle="t('accounting.purchaseOrders.index.procurementCommitmentsFeedsGoodsReceiptAnd')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.purchase-orders.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.purchaseOrders.index.newPurchaseOrder') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasFilters"
        :results-count="purchaseOrders.total"
        results-label="Purchase Order"
        @clear="clearFilters"
      >
        <input v-model="form.search" type="text" class="zv-input" :placeholder="t('accounting.purchaseOrders.index.searchByPOCodeOrSubject')" @input="debouncedSearch"/>
        <SelectBox v-model="form.status" :options="[{ value: 'draft', label: t('accounting.purchaseOrders.index.draft') }, { value: 'active', label: t('common.active') }, { value: 'on_hold', label: t('accounting.purchaseOrders.index.onHold') }, { value: 'closed', label: t('common.closed') }, { value: 'cancelled', label: t('accounting.purchaseOrders.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="purchaseOrders.data.length === 0"
        :title="t('accounting.purchaseOrders.index.noPurchaseOrdersFound')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      >
        <template v-if="hasFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('components.filterBar.clearFilters') }}
          </button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.purchaseOrders.index.pOCode') }}</th>
              <th>{{ t('accounting.purchaseOrders.index.subject') }}</th>
              <th>{{ t('accounting.purchaseOrders.index.supplier') }}</th>
              <th>{{ t('accounting.purchaseOrders.index.project') }}</th>
              <th class="zv-table-num">{{ t('accounting.purchaseOrders.index.pOValue') }}</th>
              <th class="text-center">{{ t('accounting.purchaseOrders.index.receipt') }}</th>
              <th class="text-center">{{ t('accounting.purchaseOrders.index.billing') }}</th>
              <th>{{ t('common.endDate') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="po in purchaseOrders.data" :key="po.id">
              <td><span class="zv-code">{{ po.po_code }}</span></td>
              <td><div class="zv-cell-clamp">{{ po.subject || '—' }}</div></td>
              <td><PartnerCell :name="po.partner?.name_en" /></td>
              <td>
                <Link v-if="po.project" :href="route('projects.edit', po.project.id)" class="zv-tag zv-tag-gold">
                  {{ po.project.contract_code }}
                </Link>
                <span v-else class="zv-text-muted">{{ t('accounting.purchaseOrders.index.notLinked') }}</span>
              </td>
              <td class="zv-table-num">
                {{ fmt(po.po_value) }}
                <span class="po-ccy">{{ po.currency?.code }}</span>
              </td>
              <td class="text-center">
                <ProgressBadge
                  :label="t(receiptLabel(po.receipt_status))"
                  :badge="trackBadge(po.receipt_status)"
                  :percent="trackPercent(po.receipt_status)"
                  :tone="trackTone(po.receipt_status)"
                />
              </td>
              <td class="text-center">
                <ProgressBadge
                  :label="t(billingLabel(po.billing_status))"
                  :badge="trackBadge(po.billing_status)"
                  :percent="trackPercent(po.billing_status)"
                  :tone="trackTone(po.billing_status)"
                />
              </td>
              <td class="po-date">{{ fmtDate(po.end_date) }}</td>
              <td class="text-center"><StatusBadge :status="po.status" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.purchase-orders.edit', po.id)" class="zv-action-btn" :title="t('common.edit')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>
                  <button v-if="po.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="activate(po)">{{ t('common.activate') }}</button>
                  <button v-if="['draft','active','on_hold'].includes(po.status)" class="zv-action-btn" :title="t('accounting.purchaseOrders.index.close')" @click="closePo(po)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                  <button v-if="['draft','active','on_hold'].includes(po.status) && po.receipt_status !== 'fully_received'" class="zv-action-btn zv-action-btn-danger" :title="t('accounting.purchaseOrders.index.forceCloseSupplierStoppedSupplying')" @click="openForceClose(po)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="purchaseOrders" />
    </div>

    <!-- Force-Close modal — mandatory reason -->
    <Teleport to="body">
      <div v-if="forceCloseTarget" class="zv-modal-backdrop zv-acc" @click.self="forceCloseTarget = null">
        <div class="zv-modal zv-modal-sm">
          <div class="zv-modal-header">
            <div>
              <div class="zv-modal-title">{{ t('accounting.purchaseOrders.index.forceClose') }} — {{ forceCloseTarget.po_code }}</div>
              <p class="zv-page-subtitle">{{ t('accounting.purchaseOrders.index.thisPOIsNotFullyReceived') }}</p>
            </div>
            <button type="button" class="zv-modal-close" @click="forceCloseTarget = null">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <div class="zv-modal-body">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.purchaseOrders.index.reason') }}</label>
              <textarea v-model="forceCloseReason" class="zv-textarea" rows="3" :placeholder="t('accounting.purchaseOrders.index.eGSupplierDiscontinuedThisMaterial')"></textarea>
            </div>
          </div>
          <div class="zv-modal-footer">
            <button type="button" class="zv-btn zv-btn-secondary" @click="forceCloseTarget = null">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="!forceCloseReason.trim()" @click="submitForceClose">{{ t('accounting.purchaseOrders.index.confirmClose') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
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
    purchaseOrders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const form = ref({ search: props.filters.search || '', status: props.filters.status || '' })
const hasFilters = computed(() => form.value.search || form.value.status)

let searchTimer = null
function debouncedSearch() { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 350) }

function applyFilters() {
    router.get(route('accounting.purchase-orders.index'), {
        search: form.value.search || undefined,
        status: form.value.status || undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    form.value = { search: '', status: '' }
    router.get(route('accounting.purchase-orders.index'))
}

function activate(po) { router.patch(route('accounting.purchase-orders.activate', po.id)) }

function closePo(po) {
    if (confirm(t('accounting.purchaseOrders.index.closeThisPurchaseOrder'))) {
        router.patch(route('accounting.purchase-orders.close', po.id))
    }
}

// ── Force close ──────────────────────────────────────────────────
const forceCloseTarget = ref(null)
const forceCloseReason = ref('')
function openForceClose(po) { forceCloseTarget.value = po; forceCloseReason.value = '' }
function submitForceClose() {
    router.patch(route('accounting.purchase-orders.force-close', forceCloseTarget.value.id), {
        closure_reason: forceCloseReason.value,
    }, { onFinish: () => { forceCloseTarget.value = null } })
}

function fmt(val) { if (!val && val !== 0) return '—'; return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(val) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
function receiptLabel(s) { return { not_received: 'Not Received', partially_received: 'Partial', fully_received: 'Received' }[s] ?? s }
function billingLabel(s) { return { not_billed: 'Not Billed', partially_billed: 'Partial', fully_billed: 'Billed' }[s] ?? s }

// Receipt and billing progress share one three-step shape — nothing
// yet / some of it / all of it — so they share one badge mapping
// instead of the two near-identical color blocks this page used to
// carry in its own stylesheet. Colors come from the shared badge
// set, so these now match the status badge beside them.
function trackBadge(s) {
    if (s === 'fully_received' || s === 'fully_billed') return 'zv-badge-green'
    if (s === 'partially_received' || s === 'partially_billed') return 'zv-badge-amber'
    return 'zv-badge-gray'
}
function trackPercent(s) {
    if (s === 'fully_received' || s === 'fully_billed') return 100
    if (s === 'partially_received' || s === 'partially_billed') return 50
    return 0
}
function trackTone(s) {
    if (s === 'fully_received' || s === 'fully_billed') return 'green'
    if (s === 'partially_received' || s === 'partially_billed') return 'amber'
    return 'blue'
}
</script>
<style scoped>
/* Sept 2026 (Accounting design pass): this page's entire bespoke
   stylesheet — .po-card / .po-table / .po-row / .po-badge / .po-
   action-btn / .po-pagination / .po-modal and friends — is gone.
   It was a near-copy of the shared zv- components with slightly
   different paddings and its own status colors, and it was styled
   against a `--vero-*` token family that no longer exists (see the
   alias note in app.css), so most of it was rendering unstyled
   anyway. The page now uses the same PageHeader / FilterBar /
   EmptyState / zv-table-wrap / StatusBadge / Pagination stack as
   every other Accounting index page.

   What is left is only what is genuinely specific to this table. */

/* Currency code trailing the amount — same size relationship the
   rest of the module uses for a unit next to a number. */
.po-ccy {
  font-size: 0.6875rem;
  font-weight: 400;
  color: var(--zv-text-muted);
  margin-inline-start: 3px;
}

.po-date { white-space: nowrap; }
</style>
