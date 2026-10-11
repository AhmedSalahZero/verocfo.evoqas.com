<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockAdjustmentIndex.vue

  Listing page for manual stock adjustments (accounting.stock-
  adjustments.index), gated by Company::hasInventory(). Design
  matches the ZAVERO premium standard — same shared components as
  Clean Overdraft's Index page. New sidebar entry added alongside
  Inventory/Transfers (see HandleInertiaRequests).

  Notes is shown (truncated) as its own column, not hidden behind a
  detail view — StockAdjustmentRequest requires a note on every
  adjustment specifically so the reason is visible at a glance here,
  not just recorded and buried.

  @layout    AppLayout
  @route     accounting.stock-adjustments.index
  @props     movements (paginator), filters, products, locations, pending, canApprove

  Audit M-18: adjustments wait here for a second person (Approve /
  Reject) before anything reaches stock or the ledger.
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.stockAdjustmentIndex.stockAdjustments')"
      :subtitle="t('accounting.inventory.stockAdjustmentIndex.manualCorrectionsToStockPositionFound')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M4 21v-7m0 0V3m0 11h4m10 0v7m0-11V3m0 8h4M10 21v-3m0 0v-8m0 8H6m4-8V3m4 12v-3m0 0V3m0 12h4"/>'
    >
      <template #actions>
        <Link :href="route('accounting.stock-adjustments.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.inventory.stockAdjustmentIndex.newAdjustment') }}
        </Link>
      </template>
    </PageHeader>

    <div v-if="pending.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.inventory.stockAdjustmentIndex.waitingForApproval') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.product') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.direction') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.location') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockAdjustmentIndex.quantity') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.reasonCategory') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.reason') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.recordedBy') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in pending" :key="r.id">
              <td>{{ r.product }}</td>
              <td>{{ r.direction === 'in' ? 'Stock In' : 'Stock Out' }}</td>
              <td>{{ r.location }}</td>
              <td class="zv-table-num">{{ fmtQty(r.quantity) }}</td>
              <td>{{ t('accounting.inventory.stockAdjustmentForm.reasons.' + r.reason_code) }}<span v-if="r.count_reference"> · {{ r.count_reference }}</span></td>
              <td><div class="zv-cell-clamp" :title="r.notes">{{ r.notes }}</div></td>
              <td>{{ r.requested_by }}</td>
              <td class="text-end whitespace-nowrap">
                <template v-if="canApprove">
                  <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="r.is_mine && !isSuperAdmin" :title="r.is_mine ? t('accounting.inventory.stockAdjustmentIndex.cannotApproveOwn') : ''" @click="approve(r)">{{ t('accounting.inventory.stockAdjustmentIndex.approve') }}</button>
                  <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :disabled="r.is_mine && !isSuperAdmin" @click="reject(r)">{{ t('accounting.inventory.stockAdjustmentIndex.reject') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="movements.total"
        results-label="adjustment"
        @clear="clearFilters"
      >
        <SelectBox v-model="productFilter" :options="products" value-key="id" :label-fn="p => p.name_en" :placeholder="t('accounting.inventory.stockAdjustmentIndex.allProducts')" @change="applyFilters" />

        <SelectBox v-model="locationFilter" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.inventory.stockAdjustmentIndex.allLocations')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="movements.data.length === 0"
        :title="hasActiveFilters ? t('accounting.inventory.stockAdjustmentIndex.noAdjustmentsMatchTheseFilters') : t('accounting.inventory.stockAdjustmentIndex.noAdjustmentsRecordedYet')"
        :message="hasActiveFilters ? t('accounting.inventory.stockAdjustmentIndex.tryADifferentProductOrLocation') : t('accounting.inventory.stockAdjustmentIndex.useTheButtonAboveToRecord')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('accounting.inventory.stockAdjustmentIndex.clearFilters') }}
          </button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.date') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.product') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.direction') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.location') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockAdjustmentIndex.quantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockAdjustmentIndex.value') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.reason') }}</th>
              <th>{{ t('accounting.inventory.stockAdjustmentIndex.recordedBy') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in movements.data" :key="m.id">
              <td>{{ fmtDate(m.movement_date) }}</td>
              <td>
                <div class="inv-product-cell">
                  <span class="zv-code">{{ m.product?.code }}</span>
                  <span>{{ m.product?.name_en }}</span>
                </div>
              </td>
              <td class="text-center"><StatusBadge :status="m.movement_type" :overrides="MOVEMENT_TYPE_BADGES" /></td>
              <td>{{ m.inventory_location?.name_en }}</td>
              <td class="zv-table-num">{{ fmtQty(m.quantity) }}</td>
              <td class="zv-table-num">{{ fmt(m.wac_total_value) }}</td>
              <td><div class="zv-cell-clamp" :title="m.notes"><span v-if="m.reason_code" class="font-semibold">{{ t('accounting.inventory.stockAdjustmentForm.reasons.' + m.reason_code) }}<span v-if="m.count_reference"> ({{ m.count_reference }})</span>: </span>{{ m.notes || '—' }}</div></td>
              <td>{{ m.created_by?.name ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="movements" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  movements: { type: Object, required: true },
  filters:   { type: Object, default: () => ({}) },
  products:  { type: Array,  default: () => [] },
  locations: { type: Array,  default: () => [] },
  pending:   { type: Array,  default: () => [] },
  canApprove: { type: Boolean, default: false },
})

const isSuperAdmin = computed(() => usePage().props.auth?.user?.role === 'super_admin')

function approve(r) {
  router.post(route('accounting.stock-adjustments.approve', r.id), {}, { preserveScroll: true })
}

function reject(r) {
  const reason = window.prompt(t('accounting.inventory.stockAdjustmentIndex.rejectPrompt'))
  if (reason && reason.trim()) {
    router.post(route('accounting.stock-adjustments.reject', r.id), { reason }, { preserveScroll: true })
  }
}

const MOVEMENT_TYPE_BADGES = {
  adjustment_in:  { label: 'Stock In',  badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },
  adjustment_out: { label: 'Stock Out', badge: 'zv-badge-danger', dot: 'zv-status-dot-danger' },
}

const productFilter  = ref(props.filters?.product_id ?? '')
const locationFilter = ref(props.filters?.inventory_location_id ?? '')

const hasActiveFilters = computed(() => !!productFilter.value || !!locationFilter.value)

function applyFilters() {
  router.get(route('accounting.stock-adjustments.index'), {
    product_id: productFilter.value || undefined,
    inventory_location_id: locationFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  productFilter.value = ''
  locationFilter.value = ''
  applyFilters()
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }
</style>
