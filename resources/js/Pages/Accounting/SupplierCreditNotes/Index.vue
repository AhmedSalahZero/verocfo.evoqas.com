<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierCreditNotes/Index.vue

  Lists all Supplier Credit Notes for the active company. Every row
  shows which bill it adds to — a Credit Note never exists on its
  own. Mirrors SupplierDebitNotes/Index.vue, opposite direction.

  @layout    AppLayout
  @route     accounting.supplier-credit-notes.index
  @props     creditNotes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.supplierCreditNotes.index.creditNotes')"
      :subtitle="t('accounting.supplierCreditNotes.index.increasesPartOfAPostedSupplier')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>'
    >
      <template #actions>
        <Link :href="route('accounting.supplier-credit-notes.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.supplierCreditNotes.index.newCreditNote') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="creditNotes.total"
        results-label="Credit Note"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.supplierCreditNotes.index.searchByCreditNoteCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.supplierCreditNotes.index.draft') }, { value: 'posted', label: t('accounting.supplierCreditNotes.index.posted') }, { value: 'cancelled', label: t('accounting.supplierCreditNotes.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="creditNotes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.supplierCreditNotes.index.noCreditNotesMatchThisFilter') : t('accounting.supplierCreditNotes.index.noCreditNotesYet')"
        :message="hasActiveFilters ? t('accounting.supplierCreditNotes.index.tryADifferentStatusOrClear') : t('accounting.supplierCreditNotes.index.useTheButtonAboveToRaise')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.supplierCreditNotes.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.supplierCreditNotes.index.creditNoteCode') }}</th>
              <th>{{ t('accounting.supplierCreditNotes.index.supplier') }}</th>
              <th>{{ t('accounting.supplierCreditNotes.index.againstBill') }}</th>
              <th>{{ t('accounting.supplierCreditNotes.index.date') }}</th>
              <th>{{ t('accounting.supplierCreditNotes.index.reason') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierCreditNotes.index.value') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cn in creditNotes.data" :key="cn.id">
              <td><span class="zv-code">{{ cn.credit_note_code }}</span></td>
              <td><PartnerCell :name="cn.partner?.name_en" /></td>
              <td>
                <span v-if="cn.supplier_bill" class="zv-tag zv-tag-gold">{{ cn.supplier_bill.bill_code }}</span>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td>{{ cn.credit_date }}</td>
              <td><div class="zv-cell-clamp">{{ cn.credit_reason || '—' }}</div></td>
              <td class="zv-table-num">{{ fmt(cn.credit_value) }} {{ cn.currency?.code }}</td>
              <td class="text-center"><StatusBadge :status="cn.status" :overrides="CN_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.supplier-credit-notes.edit', cn.id)" class="zv-action-btn" :title="cn.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="cn.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="cn.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(cn)">{{ t('accounting.supplierCreditNotes.index.post') }}</button>
                  <button v-if="cn.status === 'draft'" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(cn)">
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

      <Pagination :paginator="creditNotes" />
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
  creditNotes: { type: Object, required: true },
  filters:     { type: Object, default: () => ({}) },
})

const CN_STATUS_BADGES = {
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
  router.get(route('accounting.supplier-credit-notes.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

function confirmPost(cn) {
  if (! confirm(t('accounting.supplierCreditNotes.index.postThisCreditNoteThisIncreases'))) return
  router.patch(route('accounting.supplier-credit-notes.post', cn.id))
}

function confirmCancel(cn) {
  if (! confirm(t('accounting.supplierCreditNotes.index.cancelThisDraftCreditNote'))) return
  router.patch(route('accounting.supplier-credit-notes.cancel', cn.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

