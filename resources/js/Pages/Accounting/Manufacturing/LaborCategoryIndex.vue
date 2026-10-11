<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/LaborCategoryIndex.vue

  Listing page for user-defined labor categories — replaces the
  single company-wide blended labor rate (VeroCFO_Manufacturing_
  Costing_Redesign_v1_0.md, Section 3). Mirrors OverheadPoolIndex.vue,
  simpler — no allocation base concept here (see LaborCategory
  model header).

  @layout    AppLayout
  @route     accounting.labor-categories.index
  @props     categories (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.laborCategoryIndex.laborCategories')"
      :subtitle="t('accounting.manufacturing.laborCategoryIndex.namedLaborTypesEachWithItsOwnRate')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.labor-categories.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.laborCategoryIndex.newCategory') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="categories.total"
        results-label="category"
        results-label-plural="categories"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input category-search" :placeholder="t('accounting.manufacturing.laborCategoryIndex.searchByName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="categories.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.laborCategoryIndex.noCategoriesMatchThisSearch') : t('accounting.manufacturing.laborCategoryIndex.noLaborCategoriesYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.laborCategoryIndex.tryADifferentSearchTermOr') : t('accounting.manufacturing.laborCategoryIndex.useTheButtonAboveToDefine')"
        icon-path="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.laborCategoryIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.laborCategoryIndex.categoryName') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in categories.data" :key="c.id">
              <td class="font-semibold">{{ c.name }}</td>
              <td class="text-center"><StatusBadge :status="c.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.labor-categories.edit', c.id)" class="zv-action-btn" :title="t('common.edit')">
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

      <Pagination :paginator="categories" />
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
  categories: { type: Object, required: true },
  filters:    { type: Object, default: () => ({}) },
})

const search = ref(props.filters?.search ?? '')
const hasActiveFilters = computed(() => !!search.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.labor-categories.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}
</script>

<style>
.category-search { max-width: 20rem; }
</style>
