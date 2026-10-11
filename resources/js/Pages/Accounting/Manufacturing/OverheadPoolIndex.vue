<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/OverheadPoolIndex.vue

  Listing page for user-defined overhead pools. Each allocation base
  carries the exact same color used on OverheadPoolForm's tiles
  (blue = labor hours, amber = machine hours, green = production
  units) — the same visual vocabulary a reader already learned on
  the Form page, kept consistent here rather than defaulting to a
  generic gray badge.

  @layout    AppLayout
  @route     accounting.overhead-pools.index
  @props     pools (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.overheadPoolIndex.overheadPools')"
      :subtitle="t('accounting.manufacturing.overheadPoolIndex.costBucketsSpreadAcrossProductionRent')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.overhead-pools.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.overheadPoolIndex.newPool') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="pools.total"
        results-label="pool"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input pool-search" :placeholder="t('accounting.manufacturing.overheadPoolIndex.searchByName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="pools.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.overheadPoolIndex.noPoolsMatchThisSearch') : t('accounting.manufacturing.overheadPoolIndex.noOverheadPoolsYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.overheadPoolIndex.tryADifferentSearchTermOr') : t('accounting.manufacturing.overheadPoolIndex.useTheButtonAboveToSet')"
        icon-path="M4 6a2 2 0 012-2h12a2 2 0 012 2v2H4V6zm0 5h16v2H4v-2zm0 5h16v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.overheadPoolIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.overheadPoolIndex.poolName') }}</th>
              <th>{{ t('accounting.manufacturing.overheadPoolIndex.allocationBase') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in pools.data" :key="p.id">
              <td class="font-semibold">{{ p.name }}</td>
              <td>
                <span class="zv-tag" :class="baseTagClass(p.allocation_base)">
                  {{ baseLabel(p.allocation_base) }}
                </span>
              </td>
              <td class="text-center"><StatusBadge :status="p.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.overhead-pools.edit', p.id)" class="zv-action-btn" :title="t('common.edit')">
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

      <Pagination :paginator="pools" />
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

const { t } = useI18n()

const props = defineProps({
  pools:   { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

// Same color vocabulary as OverheadPoolForm's allocation-base tiles —
// kept consistent so a reader recognizes the base at a glance here too.
function baseLabel(base) {
  return { labor_hours: t('accounting.manufacturing.overheadPoolIndex.laborHours'), machine_hours: t('accounting.manufacturing.overheadPoolIndex.machineHours'), production_units: t('accounting.manufacturing.overheadPoolIndex.productionUnits') }[base] ?? base
}
function baseTagClass(base) {
  return { labor_hours: '', machine_hours: 'zv-tag-amber', production_units: 'zv-tag-green' }[base] ?? 'zv-tag-gray'
}

const search = ref(props.filters?.search ?? '')
const hasActiveFilters = computed(() => !!search.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.overhead-pools.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}
</script>

<style>
.pool-search { max-width: 20rem; }
</style>
