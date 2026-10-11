<!--
══════════════════════════════════════════════════════════════════
  Accounting/FixedAssetReceipts/Index.vue

  Fixed Asset Receipts — a dedicated, simplified list, separate from
  the general Goods Receipt Notes screen (confirmed with Mahmoud).
  Same underlying GoodsReceiptNote/GRNI-clearing engine — this page
  just filters to receipts that contain at least one Fixed Asset
  line, so it never shows an unrelated inventory receipt.

  @layout    AppLayout
  @route     accounting.fixed-asset-receipts.index
  @props     goodsReceiptNotes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.fixedAssetReceipts.index.fixedAssetReceipts')"
      :subtitle="t('accounting.fixedAssetReceipts.index.receivesANewlyPurchasedFixedAsset')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M3 17h1a2 2 0 002-2V7a2 2 0 00-2-2H3v12zm4 0h7V7H7v10zm7 0h1.5l2.5-4h-4v4zM7 19a2 2 0 104 0m6 0a2 2 0 104 0"/>'
    >
      <template #actions>
        <Link :href="route('accounting.fixed-asset-receipts.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.fixedAssetReceipts.index.newFixedAssetReceipt') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="goodsReceiptNotes.total"
        results-label="receipt"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.fixedAssetReceipts.index.searchByCodeOrSupplierReference')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.fixedAssetReceipts.index.draft') }, { value: 'posted', label: t('accounting.fixedAssetReceipts.index.posted') }, { value: 'cancelled', label: t('accounting.fixedAssetReceipts.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="goodsReceiptNotes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.fixedAssetReceipts.index.noReceiptsMatchThisFilter') : t('accounting.fixedAssetReceipts.index.noFixedAssetReceiptsYet')"
        :message="hasActiveFilters ? t('accounting.fixedAssetReceipts.index.tryADifferentStatusOrClear') : t('accounting.fixedAssetReceipts.index.useTheButtonAboveToReceive')"
        icon-path="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.fixedAssetReceipts.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.fixedAssetReceipts.index.receiptCode') }}</th>
              <th>{{ t('accounting.fixedAssetReceipts.index.purchaseOrder') }}</th>
              <th>{{ t('accounting.fixedAssetReceipts.index.supplier') }}</th>
              <th>{{ t('accounting.fixedAssetReceipts.index.receivedDate') }}</th>
              <th class="zv-table-num">{{ t('accounting.fixedAssetReceipts.index.value') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="grn in goodsReceiptNotes.data" :key="grn.id">
              <td><span class="zv-code">{{ grn.grn_code }}</span></td>
              <td>
                <span v-if="grn.purchase_order" class="zv-tag zv-tag-gold">{{ grn.purchase_order.po_code }}</span>
                <span v-else class="zv-tag zv-tag-gray">{{ t('accounting.fixedAssetReceipts.index.adHoc') }}</span>
              </td>
              <td><PartnerCell :name="grn.partner?.name_en" /></td>
              <td>{{ grn.received_date }}</td>
              <td class="zv-table-num">{{ fmt(grn.grn_value) }}</td>
              <td class="text-center"><StatusBadge :status="grn.status" :overrides="RECEIPT_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.fixed-asset-receipts.edit', grn.id)" class="zv-action-btn" :title="grn.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="grn.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="grn.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(grn)">{{ t('accounting.fixedAssetReceipts.index.post') }}</button>
                  <button v-if="grn.status === 'draft'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(grn)">
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

      <Pagination :paginator="goodsReceiptNotes" />
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
  goodsReceiptNotes: { type: Object, required: true },
  filters:           { type: Object, default: () => ({}) },
})

const RECEIPT_STATUS_BADGES = {
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
  router.get(route('accounting.fixed-asset-receipts.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

function confirmPost(grn) {
  if (! confirm(t('accounting.fixedAssetReceipts.index.postThisFixedAssetReceiptThis'))) return
  router.patch(route('accounting.fixed-asset-receipts.post', grn.id))
}

function confirmCancel(grn) {
  if (! confirm(t('accounting.fixedAssetReceipts.index.cancelThisDraftFixedAssetReceipt'))) return
  router.patch(route('accounting.fixed-asset-receipts.cancel', grn.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

