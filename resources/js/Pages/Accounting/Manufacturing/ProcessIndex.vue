<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/ProcessIndex.vue

  Listing page for user-defined manufacturing processes (Welding,
  Cutting, Assembly, ...) — VeroCFO_Manufacturing_Costing_Redesign_v1_
  0.md, Section 4.1. Mirrors LaborCategoryIndex.vue exactly.

  @layout    AppLayout
  @route     accounting.processes.index
  @props     processes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.processIndex.processes')"
      :subtitle="t('accounting.manufacturing.processIndex.categoriesOfWorkWeldingCutting')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>'
    >
      <template #actions>
        <Link :href="route('accounting.processes.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.processIndex.newProcess') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="processes.total"
        results-label="process"
        results-label-plural="processes"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input process-search" :placeholder="t('accounting.manufacturing.processIndex.searchByName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="processes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.processIndex.noProcessesMatchThisSearch') : t('accounting.manufacturing.processIndex.noProcessesYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.processIndex.tryADifferentSearchTermOr') : t('accounting.manufacturing.processIndex.useTheButtonAboveToDefine')"
        icon-path="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.processIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.processIndex.processName') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in processes.data" :key="p.id">
              <td class="font-semibold">{{ p.name }}</td>
              <td class="text-center"><StatusBadge :status="p.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.processes.edit', p.id)" class="zv-action-btn" :title="t('common.edit')">
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

      <Pagination :paginator="processes" />
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
  processes: { type: Object, required: true },
  filters:   { type: Object, default: () => ({}) },
})

const search = ref(props.filters?.search ?? '')
const hasActiveFilters = computed(() => !!search.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.processes.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}
</script>

<style>
.process-search { max-width: 20rem; }
</style>
