<!--
══════════════════════════════════════════════════════════════════
  Accounting/CashExpensePayments/Index.vue

  Lists all Cash Expense Payments — direct expense payments with no
  Supplier Bill behind them. No "Apply" action here — nothing to
  allocate against, same as Other Payment.

  @layout    AppLayout
  @route     accounting.cash-expense-payments.index
  @props     cashExpensePayments (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.cashExpensePayments.index.cashExpensePayment')"
      :subtitle="t('accounting.cashExpensePayments.index.directExpensePaymentsWithNoSupplier')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-2m-2 5h10a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.cash-expense-payments.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.cashExpensePayments.index.newPayment') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="cashExpensePayments.total"
        results-label="Payment"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.cashExpensePayments.index.searchByPaymentCode')" @input="debouncedSearch" />
        <SelectBox v-model="moneyTypeFilter" :options="[{ value: 'cash_payment', label: t('accounting.cashExpensePayments.index.cashPayment') }, { value: 'outgoing_transfer', label: t('accounting.cashExpensePayments.index.outgoingTransfer') }, { value: 'payable_cheque', label: t('accounting.cashExpensePayments.index.payableCheque') }]" :placeholder="t('accounting.cashExpensePayments.index.allMoneyTypes')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="cashExpensePayments.data.length === 0"
        :title="hasActiveFilters ? t('accounting.cashExpensePayments.index.noPaymentsMatchThisFilter') : t('accounting.cashExpensePayments.index.noCashExpensePaymentsYet')"
        :message="hasActiveFilters ? t('accounting.cashExpensePayments.index.tryADifferentTypeOrClear') : t('accounting.cashExpensePayments.index.useTheButtonAboveToRecord')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.cashExpensePayments.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.cashExpensePayments.index.paymentCode') }}</th>
              <th>{{ t('accounting.cashExpensePayments.index.paidTo') }}</th>
              <th>{{ t('accounting.cashExpensePayments.index.expenseLines') }}</th>
              <th>{{ t('accounting.cashExpensePayments.index.moneyType') }}</th>
              <th>{{ t('accounting.cashExpensePayments.index.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.cashExpensePayments.index.netAmount') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cep in cashExpensePayments.data" :key="cep.id">
              <td><span class="zv-code">{{ cep.payment_code }}</span></td>
              <td><PartnerCell :name="cep.partner?.name_en ?? cep.payee_description" /></td>
              <td>
                <span class="zv-code">{{ cep.lines?.[0]?.expense_account?.code }}</span><span class="zv-account-name">{{ cep.lines?.[0]?.expense_account?.name_en }}</span>
                <span v-if="(cep.lines?.length ?? 0) > 1" class="cep-more-lines-chip">+{{ cep.lines.length - 1 }}</span>
              </td>
              <td>
                {{ moneyTypeLabel(cep.money_type) }}
                <Link v-if="cep.money_type === 'payable_cheque' && cep.cheque" :href="route('accounting.cheques-payable.index')" class="cep-cheque-chip" :class="`cep-cheque-${cep.cheque.status}`">
                  {{ chequeStatusLabel(cep.cheque.status) }}
                </Link>
              </td>
              <td>{{ cep.payment_date }}</td>
              <td class="zv-table-num">{{ fmt(cep.net_amount) }} {{ cep.currency?.code }}</td>
              <td class="text-center">
                <span class="zv-badge" :class="statusMeta(cep.status).badge">{{ t(statusMeta(cep.status).label) }}</span>
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.cash-expense-payments.edit', cep.id)" class="zv-action-btn" :title="cep.status === 'draft' ? t('common.edit') : t('common.view')">
                    <svg v-if="cep.status === 'draft'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </Link>
                  <button v-if="cep.status === 'posted' && (!cep.cheque || ['issued','void','stopped'].includes(cep.cheque.status))" type="button" class="zv-action-btn zv-action-btn-danger" :title="t('common.cancel')" @click="confirmCancel(cep)">
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

      <Pagination :paginator="cashExpensePayments" />
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
  cashExpensePayments: { type: Object, required: true },
  filters:             { type: Object, default: () => ({}) },
})

const searchTerm     = ref(props.filters?.search ?? '')
const moneyTypeFilter = ref(props.filters?.money_type ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!moneyTypeFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.cash-expense-payments.index'), {
    search: searchTerm.value || undefined,
    money_type: moneyTypeFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  moneyTypeFilter.value = ''
  applyFilters()
}

function confirmCancel(cep) {
  if (! confirm(t('accounting.cashExpensePayments.index.cancelThisCashExpensePaymentThis'))) return
  router.patch(route('accounting.cash-expense-payments.cancel', cep.id))
}

function moneyTypeLabel(type) {
  const map = { cash_payment: 'Cash Payment', outgoing_transfer: 'Outgoing Transfer', payable_cheque: 'Payable Cheque' }
  return t(map[type] ?? type)
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
  const map = { issued: 'Issued', paid: 'Paid' }
  return t(map[status] ?? status)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.cep-cheque-chip {
  display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 20px;
  font-size: 10.5px; font-weight: 600; text-decoration: none;
}
.cep-cheque-issued { background: rgba(245,158,11,0.12); color: #f59e0b; }
.cep-cheque-paid   { background: rgba(16,185,129,0.12); color: #10b981; }

.cep-more-lines-chip {
  display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 20px;
  font-size: 10px; font-weight: 600; background: rgba(15,37,84,0.08); color: #0F2554;
}
</style>
