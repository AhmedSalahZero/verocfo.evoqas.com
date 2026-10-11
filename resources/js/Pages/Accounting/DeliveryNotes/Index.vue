<!--
══════════════════════════════════════════════════════════════════
  Accounting/DeliveryNotes/Index.vue

  Lists all Delivery Notes — the outbound mirror of Goods Receipt
  Note. Only a draft can be edited or cancelled — posting recognizes
  Revenue and moves Inventory/COGS, and is irreversible (see
  DeliveryNoteService header; a Sales Return mechanism for posted
  deliveries is not yet built).

  @layout    AppLayout
  @route     accounting.delivery-notes.index
  @props     deliveryNotes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.deliveryNotes.index.deliveryNotes')"
      :subtitle="t('accounting.deliveryNotes.index.physicalGoodsShippedAgainstASales')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17h6l3-3.5V9a1 1 0 00-1-1h-4V5a1 1 0 00-1-1H4a1 1 0 00-1 1v11a1 1 0 001 1h2m3 0a2 2 0 11-4 0m4 0a2 2 0 10-4 0m14 0a2 2 0 11-4 0m4 0a2 2 0 10-4 0"/>'
    >
      <template #actions>
        <Link :href="route('accounting.delivery-notes.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.deliveryNotes.index.newDeliveryNote') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="deliveryNotes.total"
        results-label="Delivery"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.deliveryNotes.index.searchByDeliveryCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.deliveryNotes.index.draft') }, { value: 'posted', label: t('accounting.deliveryNotes.index.posted') }, { value: 'cancelled', label: t('accounting.deliveryNotes.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="deliveryNotes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.deliveryNotes.index.noDeliveryNotesMatchThisFilter') : t('accounting.deliveryNotes.index.noDeliveryNotesYet')"
        :message="hasActiveFilters ? t('accounting.deliveryNotes.index.tryADifferentStatusOrClear') : t('accounting.deliveryNotes.index.useTheButtonAboveToRecord')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.deliveryNotes.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.deliveryNotes.index.deliveryCode') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('accounting.deliveryNotes.index.salesOrder') }}</th>
              <th>{{ t('accounting.deliveryNotes.index.fromLocation') }}</th>
              <th>{{ t('accounting.deliveryNotes.index.date') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="dn in deliveryNotes.data" :key="dn.id">
              <td><span class="zv-code">{{ dn.delivery_code }}</span></td>
              <td><PartnerCell :name="dn.partner?.name_en" /></td>
              <td>
                <span v-if="dn.sales_order" class="zv-tag zv-tag-gold">{{ dn.sales_order.so_code }}</span>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td>{{ dn.from_location?.name_en }}</td>
              <td>{{ dn.delivery_date }}</td>
              <td class="text-center"><StatusBadge :status="dn.status" :overrides="DN_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.delivery-notes.edit', dn.id)" class="zv-action-btn" :title="dn.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="dn.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="dn.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(dn)">{{ t('accounting.deliveryNotes.index.post') }}</button>
                  <button v-if="dn.status === 'draft'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(dn)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="deliveryNotes" />
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
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  deliveryNotes: { type: Object, required: true },
  filters:       { type: Object, default: () => ({}) },
})

const DN_STATUS_BADGES = {
  posted: { label: 'Posted', badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const searchTerm   = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!statusFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.delivery-notes.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

function confirmPost(dn) {
  if (! confirm(t('accounting.deliveryNotes.index.postThisDeliveryNoteThisMoves'))) return
  router.patch(route('accounting.delivery-notes.post', dn.id))
}

function confirmCancel(dn) {
  if (! confirm(t('accounting.deliveryNotes.index.cancelThisDraftDeliveryNote'))) return
  router.patch(route('accounting.delivery-notes.cancel', dn.id))
}

// Set to Draft moved into the Form/View page itself (Aug 2026, per
// Mahmoud) — matches Customer Payment's and Goods Receipt Note's own
// pattern, where reopening is only ever reachable from inside the
// opened document, not from a button on this list.
</script>

