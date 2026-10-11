<!--
══════════════════════════════════════════════════════════════════
  Accounting/OtherCollections/Index.vue

  Lists all Other Collections — money received from Shareholders,
  Employees, Sister Companies, Suppliers (refunds), and Other
  partners. No "Apply" action here (unlike Customer Collection) —
  there is nothing to allocate against, the credit side was picked
  directly when the collection was recorded.

  @layout    AppLayout
  @route     accounting.other-collections.index
  @props     otherCollections (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.otherCollections.index.otherCollection')"
      :subtitle="t('accounting.otherCollections.index.moneyReceivedFromNonCustomerPartners')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.other-collections.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.otherCollections.index.newCollection') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="otherCollections.total"
        results-label="Collection"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.otherCollections.index.searchByCollectionCode')" @input="debouncedSearch" />
        <SelectBox v-model="partnerTypeFilter" :options="[{ value: 'shareholder', label: t('accounting.otherCollections.form.shareholder') }, { value: 'employee', label: t('accounting.otherCollections.form.employee') }, { value: 'sister_company', label: t('accounting.otherCollections.form.sisterCompany') }, { value: 'supplier', label: t('accounting.otherCollections.form.supplier') }, { value: 'other', label: t('accounting.otherCollections.form.other') }]" :placeholder="t('accounting.otherCollections.index.allPartnerTypes')" @change="applyFilters" />
        <SelectBox v-model="moneyTypeFilter" :options="[{ value: 'bank_deposit', label: t('accounting.otherCollections.index.bankDeposit') }, { value: 'incoming_transfer', label: t('accounting.otherCollections.index.incomingTransfer') }, { value: 'cash_in_safe', label: t('accounting.otherCollections.index.cashInSafe') }, { value: 'cheque', label: t('accounting.otherCollections.index.cheque') }]" :placeholder="t('accounting.otherCollections.index.allMoneyTypes')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="otherCollections.data.length === 0"
        :title="hasActiveFilters ? t('accounting.otherCollections.index.noCollectionsMatchThisFilter') : t('accounting.otherCollections.index.noOtherCollectionsYet')"
        :message="hasActiveFilters ? t('accounting.otherCollections.index.tryADifferentTypeOrClear') : t('accounting.otherCollections.index.useTheButtonAboveToRecord')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.otherCollections.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.otherCollections.index.collectionCode') }}</th>
              <th>{{ t('accounting.otherCollections.index.partnerType') }}</th>
              <th>{{ t('accounting.otherCollections.index.partner') }}</th>
              <th>{{ t('accounting.otherCollections.index.moneyType') }}</th>
              <th>{{ t('accounting.otherCollections.index.date') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th>{{ t('accounting.otherCollections.index.creditAccount') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="oc in otherCollections.data" :key="oc.id">
              <td><span class="zv-code">{{ oc.collection_code }}</span></td>
              <td><span class="zv-tag">{{ partnerTypeLabel(oc.partner_type) }}</span></td>
              <td><PartnerCell :name="oc.partner?.name_en" /></td>
              <td>
                {{ moneyTypeLabel(oc.money_type) }}
                <Link v-if="oc.money_type === 'cheque' && oc.cheque" :href="route('accounting.cheques-receivable.index')" class="oc-cheque-chip" :class="`oc-cheque-${oc.cheque.status}`">
                  {{ chequeStatusLabel(oc.cheque.status) }}
                </Link>
              </td>
              <td>{{ oc.collection_date }}</td>
              <td class="zv-table-num">{{ fmt(oc.amount) }} {{ oc.currency?.code }}</td>
              <td><span class="zv-code">{{ oc.credit_account?.code }}</span><span class="zv-account-name">{{ oc.credit_account?.name_en }}</span></td>
              <td class="text-center">
                <span class="zv-badge" :class="statusMeta(oc.status).badge">{{ t(statusMeta(oc.status).label) }}</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.other-collections.edit', oc.id)" class="zv-action-btn" :title="oc.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="oc.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="oc.status === 'posted' && (!oc.cheque || oc.cheque.status === 'received')" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(oc)">
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

      <Pagination :paginator="otherCollections" />
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

const { t } = useI18n()

const props = defineProps({
  otherCollections: { type: Object, required: true },
  filters:          { type: Object, default: () => ({}) },
})

const searchTerm       = ref(props.filters?.search ?? '')
const partnerTypeFilter = ref(props.filters?.partner_type ?? '')
const moneyTypeFilter   = ref(props.filters?.money_type ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!partnerTypeFilter.value || !!moneyTypeFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.other-collections.index'), {
    search: searchTerm.value || undefined,
    partner_type: partnerTypeFilter.value || undefined,
    money_type: moneyTypeFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  partnerTypeFilter.value = ''
  moneyTypeFilter.value = ''
  applyFilters()
}

function confirmCancel(oc) {
  if (! confirm(t('accounting.otherCollections.index.cancelThisOtherCollectionThisCan'))) return
  router.patch(route('accounting.other-collections.cancel', oc.id))
}

function moneyTypeLabel(type) {
  const map = { bank_deposit: 'Bank Deposit', incoming_transfer: 'Incoming Transfer', cash_in_safe: 'Cash in Safe', cheque: 'Cheque' }
  return t(map[type] ?? type)
}

function partnerTypeLabel(type) {
  const map = {
    shareholder: t('accounting.otherCollections.form.shareholder'),
    employee: t('accounting.otherCollections.form.employee'),
    sister_company: t('accounting.otherCollections.form.sisterCompany'),
    supplier: t('accounting.otherCollections.form.supplier'),
    other: t('accounting.otherCollections.form.other'),
  }
  return map[type] ?? type
}

const STATUS_META = {
  draft:     { label: 'Draft',     badge: 'zv-badge-amber' },
  posted:    { label: 'Posted',    badge: 'zv-badge-green' },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-gray'  },
}
function statusMeta(status) {
  return STATUS_META[status] ?? { label: status, badge: 'zv-badge-gray' }
}

function chequeStatusLabel(status) {
  const map = { received: 'In Safe', sent_for_collection: 'Sent for Collection', collected: 'Collected', rejected: 'Rejected' }
  return t(map[status] ?? status)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.oc-cheque-chip {
  display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 20px;
  font-size: 10.5px; font-weight: 600; text-decoration: none;
}
.oc-cheque-received             { background: rgba(148,163,184,0.15); color: #64748b; }
.oc-cheque-sent_for_collection  { background: rgba(245,158,11,0.12); color: #f59e0b; }
.oc-cheque-collected            { background: rgba(16,185,129,0.12); color: #10b981; }
.oc-cheque-rejected             { background: rgba(239,68,68,0.12); color: #ef4444; }
</style>
