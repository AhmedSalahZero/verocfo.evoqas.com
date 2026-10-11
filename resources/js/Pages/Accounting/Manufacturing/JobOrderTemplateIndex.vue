<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/JobOrderTemplateIndex.vue

  Listing page for the optional, unversioned Job Order material-list
  templates. Deliberately simpler than BomIndex — no version column,
  no "Make Active" action — templates aren't versioned (see
  JobOrderTemplateController header).

  @layout    AppLayout
  @route     accounting.job-order-templates.index
  @props     templates (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.jobOrderTemplateIndex.jobOrderTemplates')"
      :subtitle="t('accounting.manufacturing.jobOrderTemplateIndex.optionalReusableStartingPointsForA')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.job-order-templates.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.jobOrderTemplateIndex.newTemplate') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="templates.total"
        results-label="template"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input jot-search" :placeholder="t('accounting.manufacturing.jobOrderTemplateIndex.searchByTemplateName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="templates.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.jobOrderTemplateIndex.noTemplatesMatchThisSearch') : t('accounting.manufacturing.jobOrderTemplateIndex.noJobOrderTemplatesYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.jobOrderTemplateIndex.tryADifferentSearchTermOr') : t('accounting.manufacturing.jobOrderTemplateIndex.templatesAreOptionalAJobOrder')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.jobOrderTemplateIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.jobOrderTemplateIndex.templateName') }}</th>
              <th>{{ t('accounting.manufacturing.jobOrderTemplateIndex.usualProduct') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderTemplateIndex.lines') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="temp in templates.data" :key="temp.id">
              <td class="font-semibold">{{ temp.name }}</td>
              <td>{{ temp.product?.name_en ?? '—' }}</td>
              <td class="zv-table-num">{{ temp.lines?.length ?? 0 }}</td>
              <td class="text-center"><StatusBadge :status="temp.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.job-order-templates.edit', temp.id)" class="zv-action-btn" :title="t('common.edit')">
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

      <Pagination :paginator="templates" />
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
  templates: { type: Object, required: true },
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
  router.get(route('accounting.job-order-templates.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}
</script>

<style>
.jot-search { max-width: 20rem; }
</style>
