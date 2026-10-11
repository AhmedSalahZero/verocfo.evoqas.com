<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockTransferIndex.vue

  Listing page for internal stock transfers (accounting.transfers.
  index), gated by Company::hasInventory(). Design matches the
  ZAVERO premium standard — same shared components as Clean
  Overdraft's Index page.

  A transfer writes TWO ledger rows (transfer_out at the source
  location, transfer_in at the destination) — see StockLedgerService
  ::transfer(). This page shows both legs as their own row rather
  than pairing them into one "From A → To B" line, because
  stock_movements has no link between the two rows yet (flagged in
  StockTransferController's header, not hidden). Each row still
  shows enough on its own (product, direction, location, quantity,
  date, who recorded it) to be a usable ledger view today.

  Sections:
    1. Header — eyebrow, title, "+ New Transfer"
    2. Filter bar — product, location
    3. Movements table
    4. Pagination

  @layout    AppLayout
  @route     accounting.transfers.index
  @props     movements (paginator), filters, products, locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.stockTransferIndex.internalTransfers')"
      :subtitle="t('accounting.inventory.stockTransferIndex.stockMovedBetweenYourInventoryLocations')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>'
    >
      <template #actions>
        <Link :href="route('accounting.transfers.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.inventory.stockTransferIndex.newTransfer') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="movements.total"
        results-label="movement"
        results-label-plural="movements"
        @clear="clearFilters"
      >
        <SelectBox v-model="productFilter" :options="products" value-key="id" :label-fn="p => p.name_en" :placeholder="t('accounting.inventory.stockTransferIndex.allProducts')" @change="applyFilters" />

        <SelectBox v-model="locationFilter" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.inventory.stockTransferIndex.allLocations')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="movements.data.length === 0"
        :title="hasActiveFilters ? t('accounting.inventory.stockTransferIndex.noTransfersMatchTheseFilters') : t('accounting.inventory.stockTransferIndex.noTransfersRecordedYet')"
        :message="hasActiveFilters ? t('accounting.inventory.stockTransferIndex.tryADifferentProductOrLocation') : t('accounting.inventory.stockTransferIndex.useTheButtonAboveToMove')"
        icon-path="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('accounting.inventory.stockTransferIndex.clearFilters') }}
          </button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.stockTransferIndex.date') }}</th>
              <th>{{ t('accounting.inventory.stockTransferIndex.product') }}</th>
              <th>{{ t('accounting.inventory.stockTransferIndex.direction') }}</th>
              <th>{{ t('accounting.inventory.stockTransferIndex.location') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockTransferIndex.quantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockTransferIndex.wACValue') }}</th>
              <th>{{ t('accounting.inventory.stockTransferIndex.recordedBy') }}</th>
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
import { Link, router } from '@inertiajs/vue3'
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
})

// StatusBadge's default map is for lifecycle statuses (active/draft/
// etc.), not movement types — extending it here for this page only,
// same pattern the component's own docs describe for a page with
// status words not in the defaults.
const MOVEMENT_TYPE_BADGES = {
  transfer_out: { label: 'Transfer Out', badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
  transfer_in:  { label: 'Transfer In',  badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const productFilter  = ref(props.filters?.product_id ?? '')
const locationFilter = ref(props.filters?.inventory_location_id ?? '')

const hasActiveFilters = computed(() => !!productFilter.value || !!locationFilter.value)

function applyFilters() {
  router.get(route('accounting.transfers.index'), {
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
