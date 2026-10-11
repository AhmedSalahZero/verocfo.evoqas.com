<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/InternalMoneyTransferIndex.vue

  Lists every Internal Money Transfer for the active company. An
  in-transit row (transfer_days > 0, not yet confirmed) gets two
  actions: Confirm Received (posts the second leg, clears COA 145)
  and Cancel (reverses the initiate entry). A cleared or cancelled
  row is a closed, historical record — no actions.

  @layout    AppLayout
  @route     fi.internal-money-transfers.index
  @props     transfers (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      eyebrow="Financial Institutions"
      :title="t('financialInstitutions.treasury.internalMoneyTransferIndex.internalMoneyTransfer')"
      :subtitle="t('financialInstitutions.treasury.internalMoneyTransferIndex.movesCashBetweenTwoOfThe')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3m4 6l-4-4m0 0l4-4m-4 4h16"/>'
    >
      <template #actions>
        <Link :href="route('fi.internal-money-transfers.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('financialInstitutions.treasury.internalMoneyTransferIndex.newTransfer') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="transfers.total"
        results-label="Transfer"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('financialInstitutions.treasury.internalMoneyTransferIndex.searchByTransferCode')" @input="debouncedSearch" />
        <select v-model="statusFilter" class="zv-select" @change="applyFilters">
          <option value="">{{ t('common.allStatuses') }}</option>
          <option value="in_transit">{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.inTransit') }}</option>
          <option value="cleared">{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.cleared') }}</option>
          <option value="cancelled">{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.cancelled') }}</option>
        </select>
      </FilterBar>

      <EmptyState
        v-if="transfers.data.length === 0"
        :title="hasActiveFilters ? t('financialInstitutions.treasury.internalMoneyTransferIndex.noTransfersMatchThisFilter') : t('financialInstitutions.treasury.internalMoneyTransferIndex.noInternalMoneyTransfersYet')"
        :message="hasActiveFilters ? t('financialInstitutions.treasury.internalMoneyTransferIndex.tryADifferentStatusOrClear') : t('financialInstitutions.treasury.internalMoneyTransferIndex.useTheButtonAboveToMove')"
        icon-path="M17 8l4 4m0 0l-4 4m4-4H3"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.transferCode') }}</th>
              <th>{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.from') }}</th>
              <th>{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.to') }}</th>
              <th class="imt-th-num">{{ t('common.amount') }}</th>
              <th>{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.transferDate') }}</th>
              <th>{{ t('financialInstitutions.treasury.internalMoneyTransferIndex.expectedCleared') }}</th>
              <th>{{ t('common.status') }}</th>
              <th class="imt-th-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tr in transfers.data" :key="tr.id">
              <td class="font-mono">{{ tr.transfer_code }}</td>
              <td>{{ legLabel(tr, 'from') }}</td>
              <td>{{ legLabel(tr, 'to') }}</td>
              <td class="imt-th-num">{{ fmt(tr.amount) }} {{ tr.currency?.code }}</td>
              <td>{{ tr.transfer_date }}</td>
              <td>{{ tr.status === 'in_transit' ? tr.expected_clearing_date : (tr.cleared_date || '—') }}</td>
              <td><StatusBadge :status="tr.status" :overrides="TRANSFER_STATUS_BADGES" /></td>
              <td class="imt-th-actions">
                <div class="imt-actions-inner">
                  <button v-if="tr.status === 'in_transit'" type="button" class="zv-btn zv-btn-success zv-btn-sm" :disabled="confirming === tr.id" @click="confirmReceived(tr)">
                    {{ confirming === tr.id ? t('financialInstitutions.treasury.internalMoneyTransferIndex.confirming') : t('financialInstitutions.treasury.internalMoneyTransferIndex.confirmReceived') }}
                  </button>
                  <button v-if="tr.status === 'in_transit'" type="button" class="zv-btn zv-btn-danger zv-btn-sm" @click="confirmCancel(tr)">{{ t('common.cancel') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="transfers" />
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
  transfers: { type: Object, required: true },
  filters:   { type: Object, default: () => ({}) },
})

const TRANSFER_STATUS_BADGES = {
  in_transit: { label: 'In Transit', badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
  cleared:    { label: 'Cleared',    badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  cancelled:  { label: 'Cancelled',  badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray' },
}

function legLabel(tr, side) {
  if (tr[`${side}_leg_type`] === 'bank') {
    const acc = tr[`${side}_bank_account`]
    return acc ? `${acc.bank?.name_en ?? ''} — ${acc.account_number}` : '—'
  }
  const acc = tr[`${side}_safe_account`]
  return acc ? acc.name_en : '—'
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
  router.get(route('fi.internal-money-transfers.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

const confirming = ref(null)
function confirmReceived(tr) {
  if (! confirm(t('financialInstitutions.treasury.internalMoneyTransferIndex.confirmThisTransferHasLandedIn'))) return
  confirming.value = tr.id
  router.patch(route('fi.internal-money-transfers.confirm', tr.id), {}, {
    preserveScroll: true,
    onFinish: () => { confirming.value = null },
  })
}

function confirmCancel(tr) {
  if (! confirm(t('financialInstitutions.treasury.internalMoneyTransferIndex.cancelThisInTransitTransferThis'))) return
  router.patch(route('fi.internal-money-transfers.cancel', tr.id), {}, { preserveScroll: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.imt-th-num { text-align: end; }
.imt-th-actions { width: 15rem; text-align: end; }
.imt-actions-inner { display: flex; gap: .375rem; justify-content: flex-end; }
</style>
