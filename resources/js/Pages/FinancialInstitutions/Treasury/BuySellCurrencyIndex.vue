<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/BuySellCurrencyIndex.vue

  Lists every Buy/Sell Currency transaction for the active company.
  A draft row gets Edit + Post + Delete. A posted row is read-only
  here — its only action is "Set to Draft" (reopens it, reversing
  the journal entry automatically), same lifecycle shape already
  proven on Customer Invoice / Customer Payment / Supplier Payment.

  @layout    AppLayout
  @route     fi.buy-sell-currencies.index
  @props     transactions (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      eyebrow="Financial Institutions"
      :title="t('financialInstitutions.treasury.buySellCurrencyIndex.buySellCurrency')"
      :subtitle="t('financialInstitutions.treasury.buySellCurrencyIndex.currencyConversionBetweenTheCompanySOwn')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v12m0 0l4-4m-4 4l-4-4"/>'
    >
      <template #actions>
        <Link :href="route('fi.buy-sell-currencies.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('financialInstitutions.treasury.buySellCurrencyIndex.newTransaction') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="transactions.total"
        results-label="Transaction"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('financialInstitutions.treasury.buySellCurrencyIndex.searchByTransactionCode')" @input="debouncedSearch" />
        <select v-model="statusFilter" class="zv-select" @change="applyFilters">
          <option value="">{{ t('common.allStatuses') }}</option>
          <option value="draft">{{ t('financialInstitutions.treasury.buySellCurrencyIndex.draft') }}</option>
          <option value="posted">{{ t('financialInstitutions.treasury.buySellCurrencyIndex.posted') }}</option>
        </select>
      </FilterBar>

      <EmptyState
        v-if="transactions.data.length === 0"
        :title="hasActiveFilters ? t('financialInstitutions.treasury.buySellCurrencyIndex.noTransactionsMatchThisFilter') : t('financialInstitutions.treasury.buySellCurrencyIndex.noBuySellCurrencyTransactionsYet')"
        :message="hasActiveFilters ? t('financialInstitutions.treasury.buySellCurrencyIndex.tryADifferentStatusOrClear') : t('financialInstitutions.treasury.buySellCurrencyIndex.useTheButtonAboveToConvert')"
        icon-path="M7 16V4m0 0L3 8m4-4l4 4m6 4v12m0 0l4-4m-4 4l-4-4"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('financialInstitutions.treasury.buySellCurrencyIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.treasury.buySellCurrencyIndex.transactionCode') }}</th>
              <th>{{ t('financialInstitutions.treasury.buySellCurrencyIndex.sold') }}</th>
              <th>{{ t('financialInstitutions.treasury.buySellCurrencyIndex.bought') }}</th>
              <th class="bsc-th-num">{{ t('financialInstitutions.treasury.buySellCurrencyIndex.dealRate') }}</th>
              <th class="bsc-th-num">{{ t('financialInstitutions.treasury.buySellCurrencyIndex.realizedFXGainLoss') }}</th>
              <th>{{ t('financialInstitutions.treasury.buySellCurrencyIndex.transactionDate') }}</th>
              <th>{{ t('common.status') }}</th>
              <th class="bsc-th-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tx in transactions.data" :key="tx.id">
              <td class="font-mono">{{ tx.transaction_code }}</td>
              <td>{{ fmt(tx.currency_to_sell_amount) }} {{ tx.currency_to_sell?.code }}<br><span class="field-hint">{{ legLabel(tx, 'from') }}</span></td>
              <td>{{ fmt(tx.currency_to_buy_amount) }} {{ tx.currency_to_buy?.code }}<br><span class="field-hint">{{ legLabel(tx, 'to') }}</span></td>
              <td class="bsc-th-num">{{ fmtRate(tx.deal_rate) }}</td>
              <td class="bsc-th-num">
                <span v-if="tx.status === 'posted'" :class="gainLossClass(tx.realized_fx_gain_loss)">
                  {{ fmt(Math.abs(Number(tx.realized_fx_gain_loss || 0))) }}
                  {{ Number(tx.realized_fx_gain_loss) > 0 ? t('financialInstitutions.treasury.buySellCurrencyIndex.gain') : (Number(tx.realized_fx_gain_loss) < 0 ? t('financialInstitutions.treasury.buySellCurrencyIndex.loss') : '') }}
                </span>
                <span v-else class="field-hint">—</span>
              </td>
              <td>{{ tx.transaction_date }}</td>
              <td><StatusBadge :status="tx.status" /></td>
              <td class="bsc-th-actions">
                <div class="bsc-actions-inner">
                  <Link :href="route('fi.buy-sell-currencies.edit', tx.id)" class="zv-btn zv-btn-ghost zv-btn-sm">
                    {{ tx.status === 'draft' ? t('common.edit') : t('common.view') }}
                  </Link>
                  <button v-if="tx.status === 'draft'" type="button" class="zv-btn zv-btn-success zv-btn-sm" :disabled="posting === tx.id" @click="confirmPost(tx)">
                    {{ posting === tx.id ? t('financialInstitutions.treasury.buySellCurrencyIndex.posting') : t('financialInstitutions.treasury.buySellCurrencyIndex.post') }}
                  </button>
                  <button v-if="tx.status === 'draft'" type="button" class="zv-btn zv-btn-danger zv-btn-sm" @click="confirmDelete(tx)">{{ t('common.delete') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="transactions" />
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
  transactions: { type: Object, required: true },
  filters:      { type: Object, default: () => ({}) },
})

function legLabel(tx, side) {
  if (tx[`${side}_leg_type`] === 'bank') {
    const acc = tx[`${side}_bank_account`]
    return acc ? `${acc.bank?.name_en ?? ''} — ${acc.account_number}` : '—'
  }
  const acc = tx[`${side}_safe_account`]
  return acc ? acc.name_en : '—'
}

function gainLossClass(val) {
  const n = Number(val || 0)
  if (n > 0) return 'bsc-gain'
  if (n < 0) return 'bsc-loss'
  return ''
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
  router.get(route('fi.buy-sell-currencies.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  applyFilters()
}

const posting = ref(null)
function confirmPost(tx) {
  if (! confirm(t('financialInstitutions.treasury.buySellCurrencyIndex.postThisTransactionThisValuesBoth'))) return
  posting.value = tx.id
  router.patch(route('fi.buy-sell-currencies.post', tx.id), {}, {
    preserveScroll: true,
    onFinish: () => { posting.value = null },
  })
}

function confirmDelete(tx) {
  if (! confirm(t('financialInstitutions.treasury.buySellCurrencyIndex.deleteThisDraftTransactionThisCannot'))) return
  router.delete(route('fi.buy-sell-currencies.destroy', tx.id), { preserveScroll: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtRate(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
}
</script>

<style>
.bsc-th-num { text-align: end; }
.bsc-th-actions { width: 15rem; text-align: end; }
.bsc-actions-inner { display: flex; gap: .375rem; justify-content: flex-end; }
.bsc-gain { color: var(--zv-success, #059669); font-weight: 600; }
.bsc-loss { color: var(--zv-danger, #DC2626); font-weight: 600; }
</style>
