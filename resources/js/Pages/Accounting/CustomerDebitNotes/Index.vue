<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerDebitNotes/Index.vue

  Lists all Customer Debit Notes for the active company. Every row
  shows which invoice it adds to — a Debit Note never exists on its
  own. Mirrors CreditNotes/Index.vue exactly, opposite direction.

  @layout    AppLayout
  @route     accounting.customer-debit-notes.index
  @props     debitNotes (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerDebitNotes.index.debitNotes')"
      :subtitle="t('accounting.customerDebitNotes.index.increasesPartOfAPostedCustomer')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>'
    >
      <template #actions>
        <Link :href="route('accounting.customer-debit-notes.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.customerDebitNotes.index.newDebitNote') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="debitNotes.total"
        results-label="Debit Note"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.customerDebitNotes.index.searchByDebitNoteCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.customerDebitNotes.index.draft') }, { value: 'posted', label: t('accounting.customerDebitNotes.index.posted') }, { value: 'cancelled', label: t('accounting.customerDebitNotes.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="debitNotes.data.length === 0"
        :title="hasActiveFilters ? t('accounting.customerDebitNotes.index.noDebitNotesMatchThisFilter') : t('accounting.customerDebitNotes.index.noDebitNotesYet')"
        :message="hasActiveFilters ? t('accounting.customerDebitNotes.index.tryADifferentStatusOrClear') : t('accounting.customerDebitNotes.index.useTheButtonAboveToRaise')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.customerDebitNotes.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.customerDebitNotes.index.debitNoteCode') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('accounting.customerDebitNotes.index.againstInvoice') }}</th>
              <th>{{ t('accounting.customerDebitNotes.index.date') }}</th>
              <th>{{ t('accounting.customerDebitNotes.index.reason') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerDebitNotes.index.value') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="dn in debitNotes.data" :key="dn.id">
              <td><span class="zv-code">{{ dn.debit_note_code }}</span></td>
              <td><PartnerCell :name="dn.partner?.name_en" /></td>
              <td>
                <span v-if="dn.customer_invoice" class="zv-tag zv-tag-gold">{{ dn.customer_invoice.invoice_code }}</span>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td>{{ dn.debit_date }}</td>
              <td><div class="zv-cell-clamp">{{ dn.debit_reason || '—' }}</div></td>
              <td class="zv-table-num">{{ fmt(dn.debit_value) }} {{ dn.currency?.code }}</td>
              <td class="text-center"><StatusBadge :status="dn.status" :overrides="DN_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.customer-debit-notes.edit', dn.id)" class="zv-action-btn" :title="dn.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="dn.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="dn.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(dn)">{{ t('accounting.customerDebitNotes.index.post') }}</button>
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

      <Pagination :paginator="debitNotes" />
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
  debitNotes: { type: Object, required: true },
  filters:    { type: Object, default: () => ({}) },
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
  router.get(route('accounting.customer-debit-notes.index'), {
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
  if (! confirm(t('accounting.customerDebitNotes.index.postThisDebitNoteThisIncreases'))) return
  router.patch(route('accounting.customer-debit-notes.post', dn.id))
}

function confirmCancel(dn) {
  if (! confirm(t('accounting.customerDebitNotes.index.cancelThisDraftDebitNote'))) return
  router.patch(route('accounting.customer-debit-notes.cancel', dn.id))
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

