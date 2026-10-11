<!--
══════════════════════════════════════════════════════════════════
  Accounting/Accruals/Index.vue

  Lists all Accruals for the active company. Same shared PageHeader /
  FilterBar / EmptyState / zv-table-wrap pattern as GoodsReceiptNotes/
  Index.vue. An Accrual has no edit route — it posts its GL entry
  immediately on creation (see AccrualService header) — so the only
  row action is "Cancel", and only while nothing has been settled
  against it yet (settled_amount = 0).

  @layout    AppLayout
  @route     accounting.accruals.index
  @props     accruals (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.accruals.index.accruals')"
      :subtitle="t('accounting.accruals.index.expensesBookedBeforeTheSupplierSBill')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.accruals.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.accruals.index.newAccrual') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="accruals.total"
        results-label="Accrual"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.accruals.index.searchByAccrualCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'open', label: t('accounting.accruals.index.open') }, { value: 'partially_settled', label: t('accounting.accruals.index.partiallySettled') }, { value: 'fully_settled', label: t('accounting.accruals.index.fullySettled') }, { value: 'cancelled', label: t('accounting.accruals.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="accruals.data.length === 0"
        :title="hasActiveFilters ? t('accounting.accruals.index.noAccrualsMatchThisFilter') : t('accounting.accruals.index.noAccrualsYet')"
        :message="hasActiveFilters ? t('accounting.accruals.index.tryADifferentStatusOrClear') : t('accounting.accruals.index.recordAnAccrualWhenAnExpense')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.accruals.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.accruals.index.accrualCode') }}</th>
              <th>{{ t('accounting.accruals.index.supplier') }}</th>
              <th>{{ t('accounting.accruals.index.account') }}</th>
              <th>{{ t('accounting.accruals.index.date') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th class="zv-table-num">{{ t('accounting.accruals.index.settled') }}</th>
              <th class="zv-table-num">{{ t('accounting.accruals.index.remaining') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in accruals.data" :key="a.id">
              <td><span class="zv-code">{{ a.accrual_code }}</span></td>
              <td><PartnerCell :name="a.partner?.name_en" /></td>
              <td><span class="zv-code">{{ a.chart_of_account?.code }}</span><span class="zv-account-name">{{ a.chart_of_account?.name_en }}</span></td>
              <td>{{ a.accrual_date }}</td>
              <td class="zv-table-num">{{ fmt(a.amount) }}</td>
              <td class="zv-table-num">{{ fmt(a.settled_amount) }}</td>
              <td class="zv-table-num">{{ fmt(a.amount - a.settled_amount) }}</td>
              <td class="text-center">
                <ProgressBadge
                  :label="t(settlementMeta(a).label)"
                  :badge="settlementMeta(a).badge"
                  :percent="settlementPercent(a)"
                  :tone="settlementMeta(a).tone"
                />
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button v-if="a.status === 'open'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(a)">
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

      <Pagination :paginator="accruals" />
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
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'
import ProgressBadge from '@/Components/ProgressBadge.vue'

const { t } = useI18n()

const props = defineProps({
  accruals: { type: Object, required: true },
  filters:  { type: Object, default: () => ({}) },
})

// Accrual's own vocabulary isn't in StatusBadge's default map — same labels
// and colors as before, now shown as a ProgressBadge with settled/amount.
const ACCRUAL_STATUS_BADGES = {
  open:               { label: 'Open', badge: 'zv-badge-amber', tone: 'amber' },
  partially_settled:  { label: 'Partially Settled', badge: 'zv-badge-blue', tone: 'blue' },
  fully_settled:      { label: 'Fully Settled', badge: 'zv-badge-green', tone: 'green' },
  cancelled:          { label: 'Cancelled', badge: 'zv-badge-danger', tone: 'danger' },
}

function settlementMeta(a) {
  return ACCRUAL_STATUS_BADGES[a.status] || { label: a.status, badge: 'zv-badge-gray', tone: 'blue' }
}

function settlementPercent(a) {
  const amount = Number(a.amount || 0)
  if (amount <= 0) return 0
  return (Number(a.settled_amount || 0) / amount) * 100
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
  router.get(route('accounting.accruals.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

function confirmCancel(accrual) {
  if (! confirm(t('accounting.accruals.index.cancelThisAccrualItsOriginalJournal'))) return
  router.patch(route('accounting.accruals.cancel', accrual.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

