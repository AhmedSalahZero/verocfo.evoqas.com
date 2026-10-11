<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerBalances/Index.vue

  Customer Balances — net AR position per customer, tabbed by
  currency (per Mahmoud, September 2026). Each real currency tab
  (EGP/EUR/USD/...) shows customers who have at least one posted
  invoice in THAT currency, with amounts in that same native
  currency. The synthetic "Main Currency" tab (currency_id = null)
  aggregates every customer regardless of which currency their
  invoices are in, converted to the company's functional currency —
  see CustomerBalanceService's class header for exactly how that
  conversion is done.

  The three "dues" buttons (All Invoices / Coming Dues / Past Due)
  filter which of each customer's invoices count toward their Net
  Balance in the table below — a full page reload via router.get(),
  same pattern as every other filtered index in this app.

  @layout    AppLayout
  @route     accounting.customer-balances.index
  @props     tabs, customers, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerBalances.index.customerBalances')"
      :subtitle="t('accounting.customerBalances.index.netBalancePerCustomerByCurrency')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
    />

    <!-- ══════════════════ Summary cards ══════════════════ -->
    <div class="cb-summary-grid mb-5">
      <div v-for="tab in tabs" :key="tab.id ?? 'main'" class="zv-card cb-summary-card">
        <div class="cb-summary-label">{{ tab.label ?? tab.code }}</div>
        <div class="cb-summary-figure">{{ fmt(tab.total) }}</div>
        <div class="cb-summary-actions">
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'all' }" @click="selectTab(tab, 'all')">{{ t('accounting.customerBalances.index.allInvoices') }}</button>
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'coming' }" @click="selectTab(tab, 'coming')">{{ t('accounting.customerBalances.index.comingDues') }}</button>
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'past_due' }" @click="selectTab(tab, 'past_due')">{{ t('accounting.customerBalances.index.pastDue') }}</button>
        </div>
      </div>
    </div>

    <!-- ══════════════════ Tabs ══════════════════ -->
    <div class="cb-tabs mb-4">
      <button
        v-for="tab in tabs" :key="'tab-' + (tab.id ?? 'main')" type="button"
        class="cb-tab" :class="{ 'is-active': isActiveTab(tab) }"
        @click="selectTab(tab, dues)"
      >{{ tab.label ?? tab.code }}</button>

      <Link :href="route('accounting.customer-balances.statement', { currency_id: activeCurrencyId || undefined })" class="zv-btn zv-btn-secondary zv-btn-sm cb-statement-all-btn">
        {{ t('accounting.customerBalances.index.customerStatementAllInFunctional', { code: functionalCode }) }}
      </Link>
    </div>

    <div class="zv-card">
      <div class="zv-card-body">
        <input v-model="searchTerm" type="text" class="zv-input" style="max-width: 22rem;" :placeholder="t('accounting.customerBalances.index.searchCustomers')" @input="debouncedSearch" />
      </div>

      <EmptyState
        v-if="!customers.length"
        :title="t('accounting.customerBalances.index.noCustomerBalancesInThisView')"
        :message="t('accounting.customerBalances.index.tryADifferentCurrencyOrDuesFilter')"
        icon-path="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.customerBalances.index.name') }}</th>
              <th>{{ t('accounting.customerBalances.index.currency') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.index.invoices') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.index.downPayments') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.index.netBalance') }}</th>
              <th>{{ t('accounting.customerBalances.index.statementReport') }}</th>
              <th>{{ t('accounting.customerBalances.index.internalSettlements') }}</th>
              <th>{{ t('accounting.customerBalances.index.invoiceReport') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in customers" :key="c.partner_id">
              <td><PartnerCell :name="c.name" /></td>
              <td>{{ c.currency_code ?? functionalCode }}</td>
              <td class="zv-table-num">{{ fmt(c.invoices) }}</td>
              <td class="zv-table-num">{{ fmt(c.down_payments) }}</td>
              <td class="zv-table-num"><strong>{{ fmt(c.net_balance) }}</strong></td>
              <td>
                <Link :href="statementLink(c)" class="zv-btn zv-btn-success zv-btn-sm">{{ t('accounting.customerBalances.index.customerStatement') }}</Link>
              </td>
              <td>
                <Link
                  v-if="c.can_internal_settle"
                  :href="route('accounting.internal-settlements.index', { partner_id: c.partner_id })"
                  class="zv-btn zv-btn-secondary zv-btn-sm"
                >{{ t('accounting.customerBalances.index.internalSettle') }}</Link>
                <span v-else class="zv-text-muted">—</span>
              </td>
              <td>
                <Link v-if="activeCurrencyId" :href="route('accounting.customer-balances.invoices', { partner_id: c.partner_id, currency_id: activeCurrencyId })" class="zv-btn zv-btn-warning zv-btn-sm">
                  {{ t('accounting.customerBalances.index.invoicesReport') }}
                </Link>
                <span v-else class="zv-text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  tabs:      { type: Array, default: () => [] },
  customers: { type: Array, default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

const activeCurrencyId = ref(props.filters?.currency_id ?? null)
const dues = ref(props.filters?.dues ?? 'all')
const searchTerm = ref(props.filters?.search ?? '')

const functionalCode = computed(() => props.tabs.find(t => t.id === null)?.label?.match(/\(([^)]+)\)/)?.[1] ?? '')

function isActiveTab(tab) {
  return (tab.id ?? null) === activeCurrencyId.value
}

function selectTab(tab, duesValue) {
  activeCurrencyId.value = tab.id ?? null
  dues.value = duesValue
  applyFilters()
}

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.customer-balances.index'), {
    currency_id: activeCurrencyId.value || undefined,
    dues: dues.value,
    search: searchTerm.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function statementLink(c) {
  return route('accounting.customer-balances.statement', {
    partner_id: c.partner_id,
    currency_id: activeCurrencyId.value || undefined,
  })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.cb-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.cb-summary-card { padding: 1rem 1.25rem; }
.cb-summary-label { font-size: 0.8125rem; color: var(--zv-text-secondary); margin-bottom: 0.25rem; }
.cb-summary-figure { font-size: 1.5rem; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 0.75rem; }
.cb-summary-actions { display: flex; gap: 0.375rem; flex-wrap: wrap; }
.cb-summary-actions .is-active { background: var(--zv-navy, #0F2554); color: #fff; }

.cb-tabs { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; border-bottom: 1px solid var(--zv-border); padding-bottom: 0.5rem; }
.cb-tab { padding: 0.4rem 0.9rem; border-radius: 999px; border: 1px solid var(--zv-border); background: transparent; font-size: 0.8125rem; cursor: pointer; }
.cb-tab.is-active { background: #1490A8; color: #fff; border-color: #1490A8; }
.cb-statement-all-btn { margin-inline-start: auto; }

</style>
