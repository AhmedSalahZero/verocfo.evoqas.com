<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockBalanceIndex.vue

  Read-only listing of every product's current company-wide WAC and
  quantity (ProductStockBalance — Inventory Engine Layer 1). Powers
  the "Inventory" sidebar item (accounting.inventory.index), gated
  by Company::hasInventory(). Design matches the ZAVERO premium
  standard — same shared components and --zv- token system as
  Clean Overdraft, so this reads as part of the same product.

  Sections:
    1. Header — eyebrow, title, subtitle
    2. KPI row — products with stock, total inventory value,
       zero-stock items. Total inventory value now includes Job
       Order segment value (MFG-003 fix below), so it reconciles
       with the Accounting Dashboard and the GL.
    3. Filter bar — product search
    4. Balances table — one row per product, expandable to show the
       per-location quantity split (product_location_stock) AND, when
       relevant, the Job Order segment split — without a full page
       navigation
    5. Pagination

  MFG-003 fix (confirmed with Mahmoud, 2026-09) — ProductStockBalance
  never includes a Job Order's own segment stock, by design (that
  stock belongs to one specific customer's sale). This used to make
  "Total Value" here disagree with the Accounting Dashboard, which
  does see it. Fixed by SHOWING the missing piece, not hiding it:
  a "Job Order Stock" column reveals how much (if any) of a product's
  physical stock is reserved this way, and "Total Value" now reflects
  the true combined figure so the two screens agree again.

  Expand behaviour: clicking a row's chevron fetches
  accounting.inventory.location-breakdown for that product once and
  caches the result client-side (now returning both the location
  split and the Job Order split together) — re-collapsing/
  re-expanding the same row doesn't re-fetch.

  @layout    AppLayout
  @route     accounting.inventory.index
  @props     balances (paginator), filters, reservedByProduct,
             jobOrderStockByProduct
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.stockBalanceIndex.inventory')"
      :subtitle="t('accounting.inventory.stockBalanceIndex.currentStockPositionForEveryProduct')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V4a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>'
    />

    <!-- ══════════════════════════════════════════════════════
         KPI ROW
    ══════════════════════════════════════════════════════ -->
    <div class="inv-kpi-grid">
      <KpiCard
        :label="t('accounting.inventory.stockBalanceIndex.productsWithStock')"
        :value="kpis.withStockCount"
        tone="green"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
      >
        <template #sub>{{ kpis.totalCount }} {{ t('accounting.inventory.stockBalanceIndex.totalProductsTracked') }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.inventory.stockBalanceIndex.totalInventoryValue')"
        :value="fmt(kpis.totalValue)"
        tone="navy"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>'
      >
        <template #sub>{{ t('accounting.inventory.stockBalanceIndex.atCurrentWACFunctionalCurrency') }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.inventory.stockBalanceIndex.zeroStockItems')"
        :value="kpis.zeroStockCount"
        tone="amber"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>'
      >
        <template #sub>
          <span v-if="kpis.zeroStockCount > 0" class="zv-kpi-delta zv-kpi-delta-warn">{{ t('accounting.inventory.stockBalanceIndex.mayNeedReordering') }}</span>
          <span v-else>{{ t('accounting.inventory.stockBalanceIndex.everythingTrackedHasStock') }}</span>
        </template>
      </KpiCard>
    </div>

    <!-- ══════════════════════════════════════════════════════
         MAIN CARD
    ══════════════════════════════════════════════════════ -->
    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="balances.total"
        results-label="product"
        results-label-plural="products"
        @clear="clearFilters"
      >
        <input
          v-model="search"
          type="text"
          class="zv-input inv-search"
          :placeholder="t('accounting.inventory.stockBalanceIndex.searchByProductCodeOrName')"
          @input="debouncedApplyFilters"
        />
      </FilterBar>

      <EmptyState
        v-if="balances.data.length === 0"
        :title="hasActiveFilters ? t('accounting.inventory.stockBalanceIndex.noProductsMatchThisSearch') : t('accounting.inventory.stockBalanceIndex.noStockMovementsYet')"
        :message="hasActiveFilters ? t('accounting.inventory.stockBalanceIndex.tryADifferentSearchTermOr') : t('accounting.inventory.stockBalanceIndex.onceGoodsAreReceivedOrA')"
        icon-path="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0l-2 6a2 2 0 01-2 2H8a2 2 0 01-2-2l-2-6m16 0H4"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('accounting.inventory.stockBalanceIndex.clearFilters') }}
          </button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th class="inv-th-expand"></th>
              <th>{{ t('accounting.inventory.stockBalanceIndex.product') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockBalanceIndex.quantityOnHand') }}</th>
              <th class="zv-table-num">
                {{ t('accounting.inventory.stockBalanceIndex.reserved') }}
                <span class="inv-th-help" :title="t('accounting.inventory.stockBalanceIndex.heldAgainstAnActivatedSalesOrder')">?</span>
              </th>
              <th class="zv-table-num">{{ t('common.available') }}</th>
              <th class="zv-table-num">
                {{ t('accounting.inventory.stockBalanceIndex.jobOrderStock') }}
                <span class="inv-th-help" :title="t('accounting.inventory.stockBalanceIndex.jobOrderStockHelp')">?</span>
              </th>
              <th class="zv-table-num">{{ t('accounting.inventory.stockBalanceIndex.wACUnitCost') }}</th>
              <th class="zv-table-num">
                {{ t('accounting.inventory.stockBalanceIndex.totalValue') }}
                <span class="inv-th-help" :title="t('accounting.inventory.stockBalanceIndex.totalValueHelp')">?</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="b in balances.data" :key="b.id">
              <tr class="inv-row" @click="toggleRow(b)">
                <td class="inv-th-expand">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                       class="w-3.5 h-3.5 inv-chevron" :class="{ 'inv-chevron-open': isExpanded(b) }">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                  </svg>
                </td>
                <td>
                  <div class="inv-product-cell">
                    <span class="zv-code">{{ b.product?.code }}</span>
                    <span>{{ b.product?.name_en }}</span>
                  </div>
                </td>
                <td class="zv-table-num" :class="{ 'zv-text-amber': Number(b.quantity_on_hand) <= 0 }">
                  {{ fmtQty(b.quantity_on_hand) }}
                </td>
                <td class="zv-table-num">{{ reservedFor(b.product_id) > 0 ? fmtQty(reservedFor(b.product_id)) : '—' }}</td>
                <td class="zv-table-num font-semibold">{{ fmtQty(availableFor(b)) }}</td>
                <td class="zv-table-num">{{ jobOrderQtyFor(b.product_id) > 0 ? fmtQty(jobOrderQtyFor(b.product_id)) : '—' }}</td>
                <td class="zv-table-num">{{ fmt(b.wac_unit_cost) }}</td>
                <td class="zv-table-num font-semibold">{{ fmt(totalValueFor(b)) }}</td>
              </tr>

              <tr v-if="isExpanded(b)" class="inv-breakdown-row">
                <td :colspan="8">
                  <div v-if="loadingBreakdown === b.product_id" class="inv-breakdown-loading">{{ t('accounting.inventory.stockBalanceIndex.loadingLocations') }}</div>
                  <template v-else>
                    <div v-if="!locationBreakdownFor(b.product_id).length" class="inv-breakdown-empty">
                      {{ t('accounting.inventory.stockBalanceIndex.noLocationHoldsStockOfThis') }}
                    </div>
                    <div v-else class="inv-breakdown-grid">
                      <div v-for="row in locationBreakdownFor(b.product_id)" :key="row.id" class="inv-breakdown-item">
                        <span class="inv-breakdown-location">{{ row.inventory_location?.name_en }}</span>
                        <span class="zv-num">{{ fmtQty(row.quantity_on_hand) }}</span>
                      </div>
                    </div>

                    <div v-if="jobOrderBreakdownFor(b.product_id).length" class="inv-breakdown-jobs">
                      <div class="inv-breakdown-jobs-label">{{ t('accounting.inventory.stockBalanceIndex.reservedForJobOrders') }}</div>
                      <div class="inv-breakdown-grid">
                        <div v-for="row in jobOrderBreakdownFor(b.product_id)" :key="row.job_order_id" class="inv-breakdown-item">
                          <span class="inv-breakdown-location">{{ row.job_code }}</span>
                          <span class="zv-num">{{ fmtQty(row.quantity_on_hand) }} ({{ fmt(row.total_value) }})</span>
                        </div>
                      </div>
                    </div>
                  </template>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="balances" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  balances: { type: Object, required: true },
  filters:  { type: Object, default: () => ({}) },
  reservedByProduct: { type: Object, default: () => ({}) },
  jobOrderStockByProduct: { type: Object, default: () => ({}) },
})

// Reserved (July 2026) — general, company-wide reservations only
// (see Stock Reservations screen). Available = on hand − reserved,
// floored at 0 for display (a reservation can never exceed on-hand
// stock in practice, but never show a negative to a user regardless).
function reservedFor(productId) {
  return props.reservedByProduct?.[productId] ?? '0'
}
function availableFor(balance) {
  const available = Number(balance.quantity_on_hand || 0) - Number(reservedFor(balance.product_id))
  return Math.max(available, 0)
}

// MFG-003 fix (2026-09) — Job Order segment stock is physically real
// but deliberately excluded from quantity_on_hand above (it belongs
// to one specific customer's sale, not general stock). Shown here
// separately rather than blended into Available, since it is NOT
// available for ordinary sale.
function jobOrderQtyFor(productId) {
  return props.jobOrderStockByProduct?.[productId]?.quantity ?? '0'
}
function jobOrderValueFor(productId) {
  return props.jobOrderStockByProduct?.[productId]?.value ?? '0'
}
// The true total value of everything the company owns of this
// product — pooled WAC value plus whatever sits in Job Order
// segments — so this reconciles with the Accounting Dashboard / GL,
// which already counts both.
function totalValueFor(balance) {
  return Number(balance.wac_total_value || 0) + Number(jobOrderValueFor(balance.product_id))
}

// ── KPIs — computed client-side from the current page's data plus
// balances.total; a dedicated stats() endpoint isn't needed yet for
// a single-page-at-a-time read view like this one. ──────────────
const kpis = computed(() => {
  const rows = balances.value.data
  return {
    totalCount:     props.balances.total,
    withStockCount: rows.filter(r => Number(r.quantity_on_hand) > 0).length,
    zeroStockCount: rows.filter(r => Number(r.quantity_on_hand) <= 0).length,
    totalValue:     rows.reduce((sum, r) => sum + totalValueFor(r), 0),
  }
})
// Round 8: this used to be `const balances = props.balances`, a one-time copy
// of the first page. After a search (the page keeps its state) the table and
// the cards kept showing the old rows. A computed always follows the props.
const balances = computed(() => props.balances)

// ── Filters ───────────────────────────────────────────────────────

const search = ref(props.filters?.search ?? '')
const hasActiveFilters = computed(() => !!search.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.inventory.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}

// ── Row expand / location + Job Order breakdown ──────────────────

const expandedIds = ref(new Set())
const breakdownCache = ref({})
const loadingBreakdown = ref(null)

function isExpanded(b) {
  return expandedIds.value.has(b.id)
}

function locationBreakdownFor(productId) {
  return breakdownCache.value[productId]?.locations || []
}
function jobOrderBreakdownFor(productId) {
  return breakdownCache.value[productId]?.jobOrders || []
}

async function toggleRow(b) {
  if (expandedIds.value.has(b.id)) {
    expandedIds.value.delete(b.id)
    expandedIds.value = new Set(expandedIds.value)
    return
  }

  expandedIds.value = new Set(expandedIds.value).add(b.id)

  if (breakdownCache.value[b.product_id]) return

  loadingBreakdown.value = b.product_id
  try {
    const res = await fetch(route('accounting.inventory.location-breakdown', b.product_id), {
      headers: { Accept: 'application/json' },
    })
    breakdownCache.value = { ...breakdownCache.value, [b.product_id]: await res.json() }
  } finally {
    loadingBreakdown.value = null
  }
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}
</script>

<style>
.inv-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .inv-kpi-grid { grid-template-columns: 1fr; } }

.inv-search { max-width: 20rem; }

.inv-th-help {
  display: inline-flex; align-items: center; justify-content: center;
  width: 1rem; height: 1rem; border-radius: 50%;
  background: var(--zv-bg-surface); color: var(--zv-text-muted);
  font-size: 0.625rem; font-weight: 700; cursor: help; margin-inline-start: 0.25rem;
}

.inv-th-expand { width: 2.5rem; }

.inv-row { cursor: pointer; }
.inv-row:hover { background: var(--zv-row-hover, rgba(15, 37, 84, 0.03)); }

.inv-chevron { transition: transform 0.15s ease; color: var(--zv-text-muted, #94a3b8); }
.inv-chevron-open { transform: rotate(90deg); }

.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }

.inv-breakdown-row td { padding: 0; background: var(--zv-subtle-bg, #f8fafc); }
.inv-breakdown-loading, .inv-breakdown-empty { padding: 0.75rem 1.25rem; font-size: 0.85rem; color: var(--zv-text-muted, #94a3b8); }
.inv-breakdown-grid { display: flex; flex-wrap: wrap; gap: 0.5rem 2rem; padding: 0.75rem 1.25rem; }
.inv-breakdown-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
.inv-breakdown-location { color: var(--zv-text-secondary, #475569); }

.inv-breakdown-jobs { border-top: 1px solid var(--zv-border, #e2e8f0); }
.inv-breakdown-jobs-label { padding: 0.5rem 1.25rem 0; font-size: 0.75rem; font-weight: 600; color: var(--zv-text-muted, #94a3b8); text-transform: uppercase; }
</style>
