<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/BomIndex.vue

  Listing page for BOMs — ordered active-first, then by version
  descending (see BillOfMaterialService::paginate()), so the version
  actually driving Production Orders right now is always visible
  without scrolling. A product can have several rows here (its
  version history) — that's expected, not a duplicate.

  "Make Active" appears only on inactive versions — clicking it
  calls BillOfMaterialController::activate() to switch which version
  is live without creating a new one.

  @layout    AppLayout
  @route     accounting.bom.index
  @props     boms (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.bomIndex.billOfMaterials')"
      :subtitle="t('accounting.manufacturing.bomIndex.whatGoesIntoProducingOneBatch')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.bom.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.bomIndex.newBillOfMaterials') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="boms.total"
        results-label="version"
        results-label-plural="versions"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input bom-search" :placeholder="t('accounting.manufacturing.bomIndex.searchByProductCodeOrName')" @input="debouncedApplyFilters"/>
      </FilterBar>

      <EmptyState
        v-if="boms.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.bomIndex.noBOMsMatchThisSearch') : t('accounting.manufacturing.bomIndex.noBillsOfMaterialsYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.bomIndex.tryADifferentSearchTermOr') : t('accounting.manufacturing.bomIndex.useTheButtonAboveToDefine')"
        icon-path="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.bomIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.bomIndex.product') }}</th>
              <th>{{ t('accounting.manufacturing.bomIndex.version') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.bomIndex.outputQty') }}</th>
              <th>{{ t('accounting.manufacturing.bomIndex.effectiveDate') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in boms.data" :key="b.id">
              <td>
                <div class="inv-product-cell">
                  <span class="zv-code">{{ b.product?.code }}</span>
                  <span>{{ b.product?.name_en }}</span>
                </div>
              </td>
              <td class="bom-version-chip">{{ t('accounting.manufacturing.bomIndex.v') }}{{ b.version }}</td>
              <td class="zv-table-num">{{ fmtQty(b.output_quantity) }}</td>
              <td>{{ fmtDate(b.effective_date) }}</td>
              <td class="text-center">
                <StatusBadge
                  :status="b.status"
                  :overrides="{ superseded: { label: t('common.superseded'), badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' } }"
                />
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button v-if="b.status !== 'active'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="activate(b)">{{ t('accounting.manufacturing.bomIndex.makeActive') }}</button>
                  <Link :href="route('accounting.bom.edit', b.id)" class="zv-action-btn" :title="b.status === 'draft' ? t('common.edit') : t('accounting.manufacturing.bomIndex.view')">
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

      <Pagination :paginator="boms" />
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
  boms:    { type: Object, required: true },
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
  router.get(route('accounting.bom.index'), { search: search.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  applyFilters()
}

function activate(bom) {
  router.patch(route('accounting.bom.activate', bom.id), {}, { preserveScroll: true })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
.bom-search { max-width: 22rem; }
.bom-version-chip { font-weight: 700; color: var(--zv-text-secondary, #475569); }
.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }
</style>
