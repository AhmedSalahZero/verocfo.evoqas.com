<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/KitRecipeIndex.vue

  Listing page for Kit recipes (Assembly/Kit feature — see
  VeroCFO_Manufacturing_Flexibility_Design_v1_0.md, Section 3).
  Unlike Bill of Materials, there is no version column here — a
  recipe is deliberately unversioned (KitRecipeService header).

  "Deactivate"/"Activate" toggles whether the recipe appears in the
  Assembly picker, without deleting its history.

  @layout    AppLayout
  @route     accounting.kit-recipes.index
  @props     recipes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :eyebrow="t('accounting.manufacturing.kitRecipeIndex.eyebrow')"
      :title="t('accounting.manufacturing.kitRecipeIndex.kitRecipes')"
      :subtitle="t('accounting.manufacturing.kitRecipeIndex.defineWhichComponentsMakeUp')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.kit-recipes.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.kitRecipeIndex.newKitRecipe') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="recipes.total"
        :results-label="t('accounting.manufacturing.kitRecipeIndex.recipe')"
        :results-label-plural="t('accounting.manufacturing.kitRecipeIndex.recipes')"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input kit-search" :placeholder="t('accounting.manufacturing.kitRecipeIndex.searchByProductCodeOrName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="recipes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.kitRecipeIndex.noRecipesMatchThisSearch') : t('accounting.manufacturing.kitRecipeIndex.noKitRecipesYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.kitRecipeIndex.tryADifferentSearchTerm') : t('accounting.manufacturing.kitRecipeIndex.useTheButtonAboveToDefine')"
        icon-path="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.kitRecipeIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.kitRecipeIndex.kitProduct') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.kitRecipeIndex.components') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recipes.data" :key="r.id">
              <td>
                <div class="inv-product-cell">
                  <span class="zv-code">{{ r.product?.code }}</span>
                  <span>{{ r.product?.name_en }}</span>
                </div>
              </td>
              <td class="zv-table-num">{{ r.lines_count }}</td>
              <td class="text-center"><StatusBadge :status="r.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="toggleActive(r)">
                    {{ r.is_active ? t('accounting.manufacturing.kitRecipeIndex.deactivate') : t('accounting.manufacturing.kitRecipeIndex.activate') }}
                  </button>
                  <Link :href="route('accounting.kit-recipes.edit', r.id)" class="zv-action-btn" :title="t('common.edit')">
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

      <Pagination :paginator="recipes" />
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  recipes: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const search = ref(props.filters?.search ?? '')
const hasActiveFilters = computed(() => !!search.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.kit-recipes.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}

function toggleActive(recipe) {
  router.patch(route('accounting.kit-recipes.toggle-active', recipe.id), {}, { preserveScroll: true })
}
</script>

<style>
.kit-search { max-width: 22rem; }
.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }
</style>
