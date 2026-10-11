<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/ProductionOrderIndex.vue

  Listing page for Production Orders. Status badges use the same
  lifecycle colors as ProductionOrderShow's stepper (blue = in
  progress, green = completed) so a reader carries the same visual
  vocabulary from the list into the detail page.

  KPI row is computed from the current page's rows only, same
  documented limitation as StockBalanceIndex — a full-portfolio
  stats() endpoint isn't built yet, so the sub-label says "this page"
  rather than implying a portfolio total.

  @layout    AppLayout
  @route     accounting.production.index
  @props     orders (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.productionOrderIndex.productionOrders')"
      :subtitle="t('accounting.manufacturing.productionOrderIndex.massProductionJobsFromOpeningThrough')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
    >
      <template #actions>
        <Link :href="route('accounting.production.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.productionOrderIndex.newProductionOrder') }}
        </Link>
      </template>
    </PageHeader>

    <div class="poi-kpi-grid">
      <KpiCard
        :label="t('accounting.manufacturing.productionOrderIndex.draft')"
        :value="kpis.draft"
        tone="amber"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
      ><template #sub>{{ t('accounting.manufacturing.productionOrderIndex.thisPage') }}</template></KpiCard>
      <KpiCard
        :label="t('accounting.manufacturing.productionOrderIndex.inProgress')"
        :value="kpis.inProgress"
        tone="blue"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      ><template #sub>{{ t('accounting.manufacturing.productionOrderIndex.thisPage') }}</template></KpiCard>
      <KpiCard
        :label="t('accounting.manufacturing.productionOrderIndex.completed')"
        :value="kpis.completed"
        tone="green"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      ><template #sub>{{ t('accounting.manufacturing.productionOrderIndex.thisPage') }}</template></KpiCard>
    </div>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="orders.total"
        results-label="order"
        @clear="clearFilters"
      >
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.manufacturing.productionOrderIndex.draft') }, { value: 'in_progress', label: t('accounting.manufacturing.productionOrderIndex.inProgress') }, { value: 'completed', label: t('accounting.manufacturing.productionOrderIndex.completed') }, { value: 'closed', label: t('common.closed') }, { value: 'cancelled', label: t('accounting.manufacturing.productionOrderIndex.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="orders.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.productionOrderIndex.noOrdersMatchThisFilter') : t('accounting.manufacturing.productionOrderIndex.noProductionOrdersYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.productionOrderIndex.tryADifferentStatusOrClear') : t('accounting.manufacturing.productionOrderIndex.useTheButtonAboveToOpen')"
        icon-path="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.productionOrderIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.productionOrderIndex.orderCode') }}</th>
              <th>{{ t('accounting.manufacturing.productionOrderIndex.product') }}</th>
              <th>{{ t('accounting.manufacturing.productionOrderIndex.bOM') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderIndex.quantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderIndex.stdUnitCost') }}</th>
              <th>{{ t('accounting.manufacturing.productionOrderIndex.outputLocation') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in orders.data" :key="o.id">
              <td><span class="zv-code">{{ o.order_code }}</span></td>
              <td><div class="zv-cell-clamp">{{ o.product?.name_en }}</div></td>
              <td class="bom-version-chip">{{ t('accounting.manufacturing.productionOrderIndex.v') }}{{ o.bill_of_material?.version }}</td>
              <td class="zv-table-num">{{ fmtQty(o.quantity_to_produce) }}</td>
              <td class="zv-table-num">{{ fmt(standardUnitCost(o)) }}</td>
              <td>{{ o.output_location?.name_en }}</td>
              <td class="text-center"><StatusBadge :status="o.status" :overrides="ORDER_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.production.show', o.id)" class="zv-action-btn" :title="t('accounting.manufacturing.productionOrderIndex.open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="orders" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  orders:  { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

// StatusBadge's default map covers active/draft/closed/cancelled but
// not in_progress/completed — Production Order's own status words —
// so both are defined here rather than falling back to raw, underscored text.
const ORDER_STATUS_BADGES = {
  in_progress: { label: 'In Progress', badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue'  },
  completed:   { label: 'Completed',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const kpis = computed(() => {
  const rows = props.orders.data
  return {
    draft:      rows.filter(o => o.status === 'draft').length,
    inProgress: rows.filter(o => o.status === 'in_progress').length,
    completed:  rows.filter(o => o.status === 'completed').length,
  }
})

const statusFilter = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!statusFilter.value)

function applyFilters() {
  router.get(route('accounting.production.index'), { status: statusFilter.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  statusFilter.value = ''
  applyFilters()
}

function standardUnitCost(o) {
  return Number(o.standard_material_cost_per_unit || 0)
    + Number(o.standard_labor_cost_per_unit || 0)
    + Number(o.standard_overhead_cost_per_unit || 0)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}
</script>

<style>
.poi-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .poi-kpi-grid { grid-template-columns: 1fr; } }
.bom-version-chip { font-weight: 700; color: var(--zv-text-secondary, #475569); }
</style>
