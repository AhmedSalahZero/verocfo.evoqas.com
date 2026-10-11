<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/StandardRateIndex.vue

  Listing page for standard rates — one row per fiscal year, either
  labor or a specific overhead pool. Uses the same Labor/Overhead
  tile color vocabulary as StandardRateForm (blue vs purple).
  Monthly override count is shown as a small pill rather than a
  separate column — it's a secondary fact about the row, not a
  primary one worth its own header.

  @layout    AppLayout
  @route     accounting.standard-rates.index
  @props     rates (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.standardRateIndex.standardRates')"
      :subtitle="t('accounting.manufacturing.standardRateIndex.theAnnualRatesProductionOrdersUse')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.standard-rates.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.standardRateIndex.setRate') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="rates.total"
        results-label="rate"
        @clear="clearFilters"
      >
        <SelectBox v-model="rateTypeFilter" :options="[{ value: 'labor', label: t('accounting.manufacturing.standardRateIndex.labor') }, { value: 'overhead_pool', label: t('accounting.manufacturing.standardRateIndex.overheadPool') }, { value: 'labor_category', label: t('accounting.manufacturing.standardRateIndex.laborCategory') }]" :placeholder="t('accounting.manufacturing.standardRateIndex.allTypes')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="rates.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.standardRateIndex.noRatesMatchThisFilter') : t('accounting.manufacturing.standardRateIndex.noStandardRatesSetYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.standardRateIndex.tryADifferentTypeOrClear') : t('accounting.manufacturing.standardRateIndex.useTheButtonAboveToSet')"
        icon-path="M9 3v18M15 3v18M3 9h18M3 15h18"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.standardRateIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.standardRateIndex.fiscalYear') }}</th>
              <th>{{ t('accounting.manufacturing.standardRateIndex.appliesTo') }}</th>
              <th>{{ t('accounting.manufacturing.standardRateIndex.version') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.standardRateIndex.annualRate') }}</th>
              <th>{{ t('accounting.manufacturing.standardRateIndex.effectiveFrom') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th>{{ t('accounting.manufacturing.standardRateIndex.monthlyOverrides') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rates.data" :key="r.id">
              <td class="font-semibold">{{ r.fiscal_year?.name }}</td>
              <td>
                <span class="zv-tag" :class="tagClass(r)">
                  {{ appliesToLabel(r) }}
                </span>
              </td>
              <td class="sr-version-chip">{{ t('accounting.manufacturing.standardRateIndex.v') }}{{ r.version }}</td>
              <td class="zv-table-num">{{ fmt(r.annual_rate) }}</td>
              <td>{{ fmtDate(r.effective_from) }}</td>
              <td class="text-center">
                <StatusBadge
                  :status="r.status"
                  :overrides="{ superseded: { label: t('common.superseded'), badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' } }"
                />
              </td>
              <td>
                <span v-if="r.monthly_overrides?.length" class="sr-override-pill">
                  {{ r.monthly_overrides.length }} {{ r.monthly_overrides.length === 1 ? t('accounting.manufacturing.standardRateIndex.override') : t('accounting.manufacturing.standardRateIndex.overrides') }}
                </span>
                <span v-else class="zv-text-muted">{{ t('accounting.manufacturing.standardRateIndex.none') }}</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button v-if="r.status !== 'active'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="activate(r)">{{ t('accounting.manufacturing.standardRateIndex.makeActive') }}</button>
                  <Link :href="route('accounting.standard-rates.edit', r.id)" class="zv-action-btn" :title="r.status === 'draft' ? t('common.edit') : t('accounting.manufacturing.standardRateIndex.view')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="rates" />
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
  rates:   { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const rateTypeFilter = ref(props.filters?.rate_type ?? '')
const hasActiveFilters = computed(() => !!rateTypeFilter.value)

function applyFilters() {
  router.get(route('accounting.standard-rates.index'), { rate_type: rateTypeFilter.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  rateTypeFilter.value = ''
  applyFilters()
}

function activate(rate) {
  router.patch(route('accounting.standard-rates.activate', rate.id), {}, { preserveScroll: true })
}

// Section 3 (Labor Categories) — a third rate_type alongside the
// existing blanket 'labor' and 'overhead_pool'.
function appliesToLabel(r) {
  if (r.rate_type === 'labor') return t('accounting.manufacturing.standardRateIndex.labor')
  if (r.rate_type === 'labor_category') return r.labor_category?.name ?? t('accounting.manufacturing.standardRateIndex.laborCategory')
  return r.overhead_pool?.name ?? t('accounting.manufacturing.standardRateIndex.overheadPool')
}

function tagClass(r) {
  if (r.rate_type === 'labor') return ''
  if (r.rate_type === 'labor_category') return 'zv-tag-green'
  return 'zv-tag-gray'
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
.sr-override-pill { font-size: 0.75rem; font-weight: 600; color: #7C3AED; background: rgba(124,58,237,0.1); border-radius: 999px; padding: 0.15rem 0.6rem; }
.sr-version-chip { font-weight: 700; color: var(--zv-text-secondary, #475569); }
</style>
