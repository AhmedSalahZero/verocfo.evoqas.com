<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/JobOrderIndex.vue

  Listing page for Job Orders — mirrors ProductionOrderIndex's shape
  (KPI row computed from this page's rows, same status badge colors)
  but shows "Estimated Unit Cost" instead of "Std. Unit Cost", and
  surfaces the linked Sales Order when there is one — a Job Order is
  far more likely than a Production Order to be tied to a specific
  customer order.

  @layout    AppLayout
  @route     accounting.job-orders.index
  @props     jobs (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.jobOrderIndex.jobOrders')"
      :subtitle="t('accounting.manufacturing.jobOrderIndex.customOneOffProductionJobsFrom')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.job-orders.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.jobOrderIndex.newJobOrder') }}
        </Link>
      </template>
    </PageHeader>

    <div class="joi-kpi-grid">
      <KpiCard
        :label="t('accounting.manufacturing.jobOrderIndex.draft')"
        :value="kpis.draft"
        tone="amber"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
      ><template #sub>{{ t('accounting.manufacturing.jobOrderIndex.thisPage') }}</template></KpiCard>
      <KpiCard
        :label="t('accounting.manufacturing.jobOrderIndex.inProgress')"
        :value="kpis.inProgress"
        tone="blue"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      ><template #sub>{{ t('accounting.manufacturing.jobOrderIndex.thisPage') }}</template></KpiCard>
      <KpiCard
        :label="t('accounting.manufacturing.jobOrderIndex.completed')"
        :value="kpis.completed"
        tone="green"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      ><template #sub>{{ t('accounting.manufacturing.jobOrderIndex.thisPage') }}</template></KpiCard>
    </div>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="jobs.total"
        results-label="job"
        @clear="clearFilters"
      >
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.manufacturing.jobOrderIndex.draft') }, { value: 'in_progress', label: t('accounting.manufacturing.jobOrderIndex.inProgress') }, { value: 'completed', label: t('accounting.manufacturing.jobOrderIndex.completed') }, { value: 'closed', label: t('common.closed') }, { value: 'cancelled', label: t('accounting.manufacturing.jobOrderIndex.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="jobs.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.jobOrderIndex.noJobsMatchThisFilter') : t('accounting.manufacturing.jobOrderIndex.noJobOrdersYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.jobOrderIndex.tryADifferentStatusOrClear') : t('accounting.manufacturing.jobOrderIndex.useTheButtonAboveToOpen')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.jobOrderIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.jobOrderIndex.jobCode') }}</th>
              <th>{{ t('accounting.manufacturing.jobOrderIndex.product') }}</th>
              <th>{{ t('accounting.manufacturing.jobOrderIndex.salesOrder') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderIndex.quantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderIndex.estUnitCost') }}</th>
              <th>{{ t('accounting.manufacturing.jobOrderIndex.outputLocation') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="j in jobs.data" :key="j.id">
              <td><span class="zv-code">{{ j.job_code }}</span></td>
              <td><div class="zv-cell-clamp">{{ j.product?.name_en }}</div></td>
              <td>
                <span v-if="j.sales_order" class="zv-tag zv-tag-gold">{{ j.sales_order.so_code }}</span>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td class="zv-table-num">{{ fmtQty(j.quantity_to_produce) }}</td>
              <td class="zv-table-num">{{ fmt(estimatedUnitCost(j)) }}</td>
              <td>{{ j.output_location?.name_en }}</td>
              <td class="text-center"><StatusBadge :status="j.status" :overrides="ORDER_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.job-orders.show', j.id)" class="zv-action-btn" :title="t('accounting.manufacturing.jobOrderIndex.open')">
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

      <Pagination :paginator="jobs" />
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
  jobs:    { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

// Same override map as ProductionOrderIndex.vue — StatusBadge's default
// map doesn't cover in_progress/completed.
const ORDER_STATUS_BADGES = {
  in_progress: { label: 'In Progress', badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue'  },
  completed:   { label: 'Completed',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const kpis = computed(() => {
  const rows = props.jobs.data
  return {
    draft:      rows.filter(j => j.status === 'draft').length,
    inProgress: rows.filter(j => j.status === 'in_progress').length,
    completed:  rows.filter(j => j.status === 'completed').length,
  }
})

const statusFilter = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!statusFilter.value)

function applyFilters() {
  router.get(route('accounting.job-orders.index'), { status: statusFilter.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  statusFilter.value = ''
  applyFilters()
}

function estimatedUnitCost(j) {
  return Number(j.estimated_material_cost_per_unit || 0)
    + Number(j.estimated_labor_cost_per_unit || 0)
    + Number(j.estimated_overhead_cost_per_unit || 0)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}
</script>

<style>
.joi-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .joi-kpi-grid { grid-template-columns: 1fr; } }
</style>
