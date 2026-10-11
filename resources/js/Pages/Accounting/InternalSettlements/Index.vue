<!--
══════════════════════════════════════════════════════════════════
  Accounting/InternalSettlements/Index.vue

  Lists all Internal Settlements — reached either directly, or via
  the "Internal Settle" button on Customer Balances (which arrives
  here with ?partner_id= pre-filtering the list, per Mahmoud,
  September 2026 — the button lands on this index, not straight into
  Create).

  @layout    AppLayout
  @route     accounting.internal-settlements.index
  @props     internalSettlements (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.internalSettlements.index.internalSettlements')"
      :subtitle="t('accounting.internalSettlements.index.nettingAPartnersReceivableAgainstTheir')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"/>'
    >
      <template #actions>
        <Link :href="route('accounting.internal-settlements.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.internalSettlements.index.newInternalSettlement') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="!!searchTerm"
        :results-count="internalSettlements.total"
        results-label="Settlement"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.internalSettlements.index.searchBySettlementCode')" @input="debouncedSearch" />
      </FilterBar>

      <EmptyState
        v-if="internalSettlements.data.length === 0"
        :title="searchTerm ? t('accounting.internalSettlements.index.noSettlementsMatchThisFilter') : t('accounting.internalSettlements.index.noInternalSettlementsYet')"
        :message="searchTerm ? t('accounting.internalSettlements.index.tryClearingTheSearch') : t('accounting.internalSettlements.index.useTheButtonAboveOrTheInternal')"
        icon-path="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"
      >
        <template v-if="searchTerm" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.internalSettlements.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.internalSettlements.index.settlementCode') }}</th>
              <th>{{ t('common.partner') }}</th>
              <th>{{ t('accounting.internalSettlements.index.currency') }}</th>
              <th>{{ t('accounting.internalSettlements.index.date') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in internalSettlements.data" :key="s.id">
              <td><span class="zv-code">{{ s.settlement_code }}</span></td>
              <td><PartnerCell :name="s.partner?.name_en" /></td>
              <td>{{ s.currency?.code }}</td>
              <td>{{ s.settlement_date }}</td>
              <td class="zv-table-num">{{ fmt(s.amount) }}</td>
              <td class="text-center"><span class="zv-badge" :class="statusMeta(s.status).badge">{{ statusMeta(s.status).label }}</span></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.internal-settlements.edit', s.id)" class="zv-action-btn" :title="s.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="s.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="internalSettlements" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import FilterBar from '@/Components/FilterBar.vue'
import Pagination from '@/Components/Pagination.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  internalSettlements: { type: Object, required: true },
  filters:             { type: Object, default: () => ({}) },
})

const searchTerm = ref(props.filters?.search ?? '')

const STATUS_BADGES = {
  draft:     { label: 'Draft', badge: 'zv-badge-gray' },
  posted:    { label: 'Posted', badge: 'zv-badge-green' },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-danger' },
}

function statusMeta(status) {
  return STATUS_BADGES[status] ?? STATUS_BADGES.draft
}

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.internal-settlements.index'), {
    search: searchTerm.value || undefined,
    partner_id: props.filters?.partner_id || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  router.get(route('accounting.internal-settlements.index'), {}, { preserveState: true, preserveScroll: true, replace: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

