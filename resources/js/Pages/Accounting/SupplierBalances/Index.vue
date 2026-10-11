<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierBalances/Index.vue

  Supplier Balances — payable-side mirror of Customer Balances/
  Index.vue (per Mahmoud, September 2026). Same structure throughout:
  currency tabs + a synthetic Main Currency tab, All Bills/Coming
  Dues/Past Due filters, supplier table with Statement/Invoices
  Report buttons.

  @layout    AppLayout
  @route     accounting.supplier-balances.index
  @props     tabs, suppliers, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.supplierBalances.index.supplierBalances')"
      :subtitle="t('accounting.supplierBalances.index.netBalancePerSupplierByCurrency')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
    />

    <div class="sb-summary-grid mb-5">
      <div v-for="tab in tabs" :key="tab.id ?? 'main'" class="zv-card sb-summary-card">
        <div class="sb-summary-label">{{ tab.label ?? tab.code }}</div>
        <div class="sb-summary-figure">{{ fmt(tab.total) }}</div>
        <div class="sb-summary-actions">
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'all' }" @click="selectTab(tab, 'all')">{{ t('accounting.supplierBalances.index.allBills') }}</button>
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'coming' }" @click="selectTab(tab, 'coming')">{{ t('accounting.supplierBalances.index.comingDues') }}</button>
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :class="{ 'is-active': isActiveTab(tab) && dues === 'past_due' }" @click="selectTab(tab, 'past_due')">{{ t('accounting.supplierBalances.index.pastDue') }}</button>
        </div>
      </div>
    </div>

    <div class="sb-tabs mb-4">
      <button
        v-for="tab in tabs" :key="'tab-' + (tab.id ?? 'main')" type="button"
        class="sb-tab" :class="{ 'is-active': isActiveTab(tab) }"
        @click="selectTab(tab, dues)"
      >{{ tab.label ?? tab.code }}</button>

      <Link :href="route('accounting.supplier-balances.statement', { currency_id: activeCurrencyId || undefined })" class="zv-btn zv-btn-secondary zv-btn-sm sb-statement-all-btn">
        {{ t('accounting.supplierBalances.index.supplierStatementAllInFunctional', { code: functionalCode }) }}
      </Link>
    </div>

    <div class="zv-card">
      <div class="zv-card-body">
        <input v-model="searchTerm" type="text" class="zv-input" style="max-width: 22rem;" :placeholder="t('accounting.supplierBalances.index.searchSuppliers')" @input="debouncedSearch" />
      </div>

      <EmptyState
        v-if="!suppliers.length"
        :title="t('accounting.supplierBalances.index.noSupplierBalancesInThisView')"
        :message="t('accounting.supplierBalances.index.tryADifferentCurrencyOrDuesFilter')"
        icon-path="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.supplierBalances.index.name') }}</th>
              <th>{{ t('accounting.supplierBalances.index.currency') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.index.bills') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.index.downPayments') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.index.netBalance') }}</th>
              <th>{{ t('accounting.supplierBalances.index.statementReport') }}</th>
              <th>{{ t('accounting.supplierBalances.index.internalSettlements') }}</th>
              <th>{{ t('accounting.supplierBalances.index.invoiceReport') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in suppliers" :key="s.partner_id">
              <td><PartnerCell :name="s.name" /></td>
              <td>{{ s.currency_code ?? functionalCode }}</td>
              <td class="zv-table-num">{{ fmt(s.bills) }}</td>
              <td class="zv-table-num">{{ fmt(s.down_payments) }}</td>
              <td class="zv-table-num"><strong>{{ fmt(s.net_balance) }}</strong></td>
              <td>
                <Link :href="statementLink(s)" class="zv-btn zv-btn-success zv-btn-sm">{{ t('accounting.supplierBalances.index.supplierStatement') }}</Link>
              </td>
              <td class="zv-text-muted">—</td>
              <td>
                <Link v-if="activeCurrencyId" :href="route('accounting.supplier-balances.invoices', { partner_id: s.partner_id, currency_id: activeCurrencyId })" class="zv-btn zv-btn-warning zv-btn-sm">
                  {{ t('accounting.supplierBalances.index.invoicesReport') }}
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
  suppliers: { type: Array, default: () => [] },
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
  router.get(route('accounting.supplier-balances.index'), {
    currency_id: activeCurrencyId.value || undefined,
    dues: dues.value,
    search: searchTerm.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function statementLink(s) {
  return route('accounting.supplier-balances.statement', {
    partner_id: s.partner_id,
    currency_id: activeCurrencyId.value || undefined,
  })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.sb-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.sb-summary-card { padding: 1rem 1.25rem; }
.sb-summary-label { font-size: 0.8125rem; color: var(--zv-text-secondary); margin-bottom: 0.25rem; }
.sb-summary-figure { font-size: 1.5rem; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 0.75rem; }
.sb-summary-actions { display: flex; gap: 0.375rem; flex-wrap: wrap; }
.sb-summary-actions .is-active { background: var(--zv-navy, #0F2554); color: #fff; }

.sb-tabs { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; border-bottom: 1px solid var(--zv-border); padding-bottom: 0.5rem; }
.sb-tab { padding: 0.4rem 0.9rem; border-radius: 999px; border: 1px solid var(--zv-border); background: transparent; font-size: 0.8125rem; cursor: pointer; }
.sb-tab.is-active { background: #1490A8; color: #fff; border-color: #1490A8; }
.sb-statement-all-btn { margin-inline-start: auto; }

</style>
