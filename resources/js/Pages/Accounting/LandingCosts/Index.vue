<!--
══════════════════════════════════════════════════════════════════
  Accounting/LandingCosts/Index.vue

  Lists all Landing Cost records. Three independent things show per
  row: its own liability status (draft/posted/paid/cancelled),
  whether it's been paid, and whether it's been attached to a GRN —
  see LandingCost model header for why these are independent.

  @layout    AppLayout
  @route     accounting.landing-costs.index
  @props     landingCosts (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.landingCosts.index.landingCosts')"
      :subtitle="t('accounting.landingCosts.index.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4m16 0l-6-6m6 6l-6 6"/>'
    >
      <template #actions>
        <Link :href="route('accounting.landing-costs.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.landingCosts.index.newLandingCost') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="landingCosts.total"
        results-label="Landing Cost"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.landingCosts.index.searchByLandingCostCode')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'draft', label: t('accounting.landingCosts.index.draft') }, { value: 'posted', label: t('accounting.landingCosts.index.posted') }, { value: 'paid', label: t('accounting.landingCosts.index.paid') }, { value: 'cancelled', label: t('accounting.landingCosts.index.cancelled') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="landingCosts.data.length === 0"
        :title="hasActiveFilters ? t('accounting.landingCosts.index.noLandingCostsMatchThisFilter') : t('accounting.landingCosts.index.noLandingCostsYet')"
        :message="hasActiveFilters ? t('accounting.landingCosts.index.tryADifferentStatusOrClear') : t('accounting.landingCosts.index.useTheButtonAboveToRaise')"
        icon-path="M20 12H4m16 0l-6-6m6 6l-6 6"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.landingCosts.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.landingCosts.index.landingCostCode') }}</th>
              <th>{{ t('accounting.landingCosts.index.supplier') }}</th>
              <th>{{ t('accounting.landingCosts.index.account') }}</th>
              <th>{{ t('accounting.landingCosts.index.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.landingCosts.index.amount') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th>{{ t('accounting.landingCosts.index.attachedTo') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="lc in landingCosts.data" :key="lc.id">
              <td><span class="zv-code">{{ lc.landing_cost_code }}</span></td>
              <td><PartnerCell :name="lc.partner?.name_en" /></td>
              <td><span class="zv-code">{{ lc.chart_of_account?.code }}</span><span class="zv-account-name">{{ lc.chart_of_account?.name_en }}</span></td>
              <td>{{ lc.cost_date }}</td>
              <td class="zv-table-num">{{ fmt(lc.amount) }}</td>
              <td class="text-center"><StatusBadge :status="lc.status" :overrides="LC_STATUS_BADGES" /></td>
              <td>
                <span v-if="lc.goods_receipt_note" class="zv-tag zv-tag-gold">{{ lc.goods_receipt_note.grn_code }}</span>
                <span v-else class="opacity-50">{{ t('accounting.landingCosts.index.notYetAttached') }}</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link v-if="lc.status === 'draft'" :href="route('accounting.landing-costs.edit', lc.id)" class="zv-action-btn" :title="t('common.edit')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>
                  <button v-if="lc.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="confirmPost(lc)">{{ t('accounting.landingCosts.index.post') }}</button>
                  <button v-if="lc.status === 'posted' && !lc.goods_receipt_note" type="button" class="zv-action-btn" :title="t('accounting.landingCosts.index.setToDraft')" @click="confirmSetToDraft(lc)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
                    </svg>
                  </button>
                  <button v-if="lc.status === 'posted' || lc.status === 'paid'" type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="lc.status === 'paid'" @click="openPayModal(lc)">{{ t('accounting.landingCosts.index.pay') }}</button>
                  <button v-if="lc.status === 'paid' && !lc.goods_receipt_note_id" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="undoPayment(lc)">Undo payment</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="landingCosts" />
    </div>

    <!-- Pay modal -->
    <div v-if="payTarget" class="lc-modal-backdrop" @click.self="closePayModal">
      <div class="lc-modal zv-card">
        <div class="zv-card-header">
          <strong>{{ t('accounting.landingCosts.index.payModalTitle') }} — {{ payTarget.landing_cost_code }}</strong>
        </div>
        <div class="zv-card-body">
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.index.bankAccount') }}</label>
            <SelectBox v-model="payForm.bank_account_id" :options="payBankAccounts" value-key="id" :label-fn="b => b.label" :placeholder="t('accounting.landingCosts.index.selectBankAccount')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.index.paidDate') }}</label>
            <input v-model="payForm.paid_date" type="date" class="zv-input"/>
          </div>
        </div>
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <button type="button" class="zv-btn zv-btn-ghost" @click="closePayModal">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="payProcessing" @click="submitPay">
            {{ payProcessing ? t('common.saving') : t('accounting.landingCosts.index.confirmPay') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
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
  landingCosts: { type: Object, required: true },
  filters:      { type: Object, default: () => ({}) },
})

const LC_STATUS_BADGES = {
  posted: { label: 'Posted', badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  paid:   { label: 'Paid',   badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue' },
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
  router.get(route('accounting.landing-costs.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

function confirmPost(lc) {
  if (! confirm(t('accounting.landingCosts.index.postThisLandingCost'))) return
  router.patch(route('accounting.landing-costs.post', lc.id))
}

function confirmSetToDraft(lc) {
  if (! confirm(t('accounting.landingCosts.index.confirmSetToDraft'))) return
  router.patch(route('accounting.landing-costs.set-to-draft', lc.id))
}

// ── Pay modal ────────────────────────────────────────────────────

const payTarget = ref(null)
const payBankAccounts = ref([])
const payForm = ref({ bank_account_id: '', paid_date: todayLocal() })
const payProcessing = ref(false)

// M-32: a paid Landing Cost can now be un-paid (the payment entry is reversed).
function undoPayment(lc) {
  if (!confirm('Undo the payment of ' + lc.landing_cost_code + '? The payment entry will be reversed and the cost goes back to Posted.')) return
  router.patch(route('accounting.landing-costs.unpay', lc.id), {}, { preserveScroll: true })
}

async function openPayModal(lc) {
  payTarget.value = lc
  payForm.value = { bank_account_id: '', paid_date: todayLocal() }
  const res = await fetch(route('accounting.landing-costs.bank-accounts'), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  payBankAccounts.value = data.bank_accounts ?? []
}

function closePayModal() {
  payTarget.value = null
}

function submitPay() {
  if (! payForm.value.bank_account_id || ! payForm.value.paid_date) return
  payProcessing.value = true
  router.post(route('accounting.landing-costs.pay', payTarget.value.id), payForm.value, {
    onFinish: () => { payProcessing.value = false; closePayModal() },
  })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.lc-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 37, 84, 0.45); display: flex; align-items: center; justify-content: center; z-index: 50; }
.lc-modal { width: 100%; max-width: 28rem; }
</style>
