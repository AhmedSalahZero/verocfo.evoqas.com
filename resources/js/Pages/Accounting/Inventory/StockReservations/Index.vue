<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockReservations/Index.vue

  New screen (July 2026) — warehouse-facing, deliberately separate
  from the Sales Order form itself. Two sections:

  1. Reserve — activated Sales Orders that still have at least one
     line with no reservation yet. Each eligible line has its own
     "Reserve" button (full ordered quantity, no partial amounts).
  2. Active Reservations — everything currently reserved, with a
     "Release" action per row (confirmed: intended once the line is
     fully delivered — a manual judgment call today since there's no
     Delivery Note yet to trigger it automatically).

  Scope, confirmed simple on purpose: General reservation only —
  company-wide against the product, never tied to one Inventory
  Location. An Internal Transfer between locations is completely
  unaffected by a reservation existing.

  @layout    AppLayout
  @route     accounting.stock-reservations.index
  @props     eligibleSalesOrders, activeReservations (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.stockReservations.index.stockReservations')"
      :subtitle="t('accounting.inventory.stockReservations.index.holdStockAgainstAnActivatedSales')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>'
    />

    <!-- ── Reserve ─────────────────────────────────────────────── -->
    <div class="zv-card mb-5">
      <div class="zv-card-header"><h2 class="zv-card-title">{{ t('accounting.inventory.stockReservations.index.reserveStock') }}</h2></div>

      <EmptyState
        v-if="eligibleSalesOrders.length === 0"
        :title="t('accounting.inventory.stockReservations.index.nothingWaitingToBeReserved')"
        :message="t('accounting.inventory.stockReservations.index.everyLineOnEveryActivatedSales')"
        icon-path="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.stockReservations.index.salesOrder') }}</th>
              <th>{{ t('accounting.inventory.stockReservations.index.product') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockReservations.index.quantity') }}</th>
              <th></th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="so in eligibleSalesOrders" :key="so.id">
              <tr v-for="line in so.lines" :key="line.id">
                <td><span class="zv-tag zv-tag-gold">{{ so.so_code }}</span></td>
                <td>
                  <span class="zv-code">{{ line.product?.code }}</span>
                  <span class="zv-account-name">{{ line.product?.name_en }}</span>
                </td>
                <td class="zv-table-num">{{ fmtQty(line.quantity) }}</td>
                <td>{{ line.uom?.code }}</td>
                <td class="zv-col-actions">
                  <div class="zv-row-actions">
                    <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="reservingId === line.id" @click="reserveLine(line)">
                      {{ reservingId === line.id ? t('accounting.inventory.stockReservations.index.reserving') : t('accounting.inventory.stockReservations.index.reserve') }}
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Active Reservations ─────────────────────────────────── -->
    <div class="zv-card">
      <div class="zv-card-header"><h2 class="zv-card-title">{{ t('accounting.inventory.stockReservations.index.activeReservations') }}</h2></div>

      <FilterBar
        :has-active-filters="!!searchTerm"
        :results-count="activeReservations.total"
        results-label="reservation"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.inventory.stockReservations.index.searchByProductOrSOCode')" @input="debouncedSearch" />
      </FilterBar>

      <EmptyState
        v-if="activeReservations.data.length === 0"
        :title="t('accounting.inventory.stockReservations.index.noActiveReservations')"
        :message="t('accounting.inventory.stockReservations.index.reservedLinesWillShowUpHere')"
        icon-path="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.stockReservations.index.salesOrder') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('accounting.inventory.stockReservations.index.product') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockReservations.index.quantity') }}</th>
              <th></th>
              <th>{{ t('accounting.inventory.stockReservations.index.reservedBy') }}</th>
              <th>{{ t('accounting.inventory.stockReservations.index.reservedAt') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in activeReservations.data" :key="r.id">
              <td><span class="zv-tag zv-tag-gold">{{ r.sales_order?.so_code }}</span></td>
              <td><PartnerCell :name="r.sales_order?.partner?.name_en" /></td>
              <td>
                <span class="zv-code">{{ r.product?.code }}</span>
                <span class="zv-account-name">{{ r.product?.name_en }}</span>
              </td>
              <td class="zv-table-num">{{ fmtQty(r.quantity) }}</td>
              <td>{{ r.sales_order_line?.uom?.code }}</td>
              <td>{{ r.reserved_by?.name }}</td>
              <td>{{ r.reserved_at }}</td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="releasingId === r.id" @click="confirmRelease(r)">
                    {{ releasingId === r.id ? t('accounting.inventory.stockReservations.index.releasing') : t('accounting.inventory.stockReservations.index.release') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <Pagination :paginator="activeReservations" />
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  eligibleSalesOrders: { type: Array, default: () => [] },
  activeReservations:  { type: Object, required: true },
  filters:             { type: Object, default: () => ({}) },
})

const reservingId = ref(null)
const releasingId = ref(null)

function reserveLine(line) {
  reservingId.value = line.id
  router.post(route('accounting.stock-reservations.store'), { sales_order_line_id: line.id }, {
    preserveScroll: true,
    onFinish: () => { reservingId.value = null },
  })
}

function confirmRelease(reservation) {
  if (! confirm(t('accounting.inventory.stockReservations.index.releaseThisReservationOnlyDoThis'))) return
  releasingId.value = reservation.id
  router.patch(route('accounting.stock-reservations.release', reservation.id), {}, {
    preserveScroll: true,
    onFinish: () => { releasingId.value = null },
  })
}

const searchTerm = ref(props.filters?.search ?? '')
let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}
function applyFilters() {
  router.get(route('accounting.stock-reservations.index'), { search: searchTerm.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })
}
function clearFilters() {
  searchTerm.value = ''
  applyFilters()
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}
</script>

