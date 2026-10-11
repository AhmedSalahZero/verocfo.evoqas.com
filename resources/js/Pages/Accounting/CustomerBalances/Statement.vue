<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerBalances/Statement.vue

  Customer Statement — every AR-affecting movement for one customer
  within a date range, running End Balance. currency_id blank/absent
  means the Main Currency tab: every invoice/collection regardless of
  native currency, converted to functional — and, per Mahmoud
  (September 2026), an FX Gain/Loss column showing what each
  Collection generated (informational only — doesn't feed the running
  balance, see CustomerBalanceService::statement()'s own comment).

  Audit H11 (Sept 2026): the first row is always the Opening Balance
  ("balance brought forward") up to the day before the start date,
  so the End Balance is right for any date range.

  @layout    AppLayout
  @route     accounting.customer-balances.statement
  @props     customers, currencies, movements, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerBalances.statement.customerStatement', { name: selectedCustomerName, code: currencyLabel })"
      :subtitle="t('accounting.customerBalances.statement.movementsForTheSelectedDateRange')"
    />

    <div class="zv-card mb-5">
      <div class="zv-card-body">
        <div class="field-grid field-grid-4">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
            <SelectBox v-model="form.partner_id" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.customerBalances.statement.selectCustomer')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerBalances.statement.currency') }}</label>
            <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :placeholder="t('accounting.customerBalances.statement.mainCurrency')" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerBalances.statement.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerBalances.statement.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" />
          </div>
        </div>
        <button type="button" class="zv-btn zv-btn-primary mt-4" :disabled="!form.partner_id" @click="submit">
          {{ t('accounting.customerBalances.statement.submit') }}
        </button>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="!movements.length"
        :title="t('accounting.customerBalances.statement.noMovementsInThisRange')"
        :message="t('accounting.customerBalances.statement.pickACustomerAndDateRangeThen')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('accounting.customerBalances.statement.date') }}</th>
              <th>{{ t('accounting.customerBalances.statement.documentType') }}</th>
              <th>{{ t('accounting.customerBalances.statement.documentNo') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.statement.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.statement.credit') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.statement.endBalance') }}</th>
              <th v-if="isMainCurrency" class="zv-table-num">{{ t('accounting.customerBalances.statement.fxGainLoss') }}</th>
              <th>{{ t('accounting.customerBalances.statement.comment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in movements" :key="row.n" :class="{ 'st-opening': row.is_opening }">
              <td>{{ row.n }}</td>
              <td>{{ row.date }}</td>
              <td><span class="zv-tag">{{ row.is_opening ? t('accounting.customerBalances.statement.openingBalance') : row.type }}</span></td>
              <td><span class="zv-code">{{ row.doc_no }}</span></td>
              <td class="zv-table-num">{{ !row.is_opening && row.debit !== '0.00' ? fmt(row.debit) : '' }}</td>
              <td class="zv-table-num">{{ !row.is_opening && row.credit !== '0.00' ? fmt(row.credit) : '' }}</td>
              <td class="zv-table-num"><strong>{{ fmt(row.end_balance) }}</strong></td>
              <td v-if="isMainCurrency" class="zv-table-num" :class="{ 'st-fx-loss': Number(row.fx_gain_loss) < 0, 'st-fx-gain': Number(row.fx_gain_loss) > 0 }">
                {{ row.fx_gain_loss && row.fx_gain_loss !== '0.00' ? fmt(row.fx_gain_loss) : '' }}
              </td>
              <td><div class="zv-cell-clamp">{{ row.is_opening ? t('accounting.customerBalances.statement.broughtForward') : (row.comment || '—') }}</div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  customers:  { type: Array, default: () => [] },
  currencies: { type: Array, default: () => [] },
  movements:  { type: Array, default: () => [] },
  filters:    { type: Object, default: () => ({}) },
})

const form = reactive({
  partner_id:  props.filters?.partner_id ?? '',
  currency_id: props.filters?.currency_id ?? '',
  start_date:  props.filters?.start_date ?? '',
  end_date:    props.filters?.end_date ?? '',
})

const isMainCurrency = computed(() => !form.currency_id)

const selectedCustomerName = computed(() => props.customers.find(c => c.id === form.partner_id)?.name_en ?? '')
const currencyLabel = computed(() => {
  if (! form.currency_id) return t('accounting.customerBalances.statement.mainCurrency')
  return props.currencies.find(c => c.id === form.currency_id)?.code ?? ''
})

function submit() {
  router.get(route('accounting.customer-balances.statement'), {
    partner_id: form.partner_id || undefined,
    currency_id: form.currency_id || undefined,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.st-opening td { background: var(--zv-surface-muted, rgba(15,37,84,0.04)); font-weight: 600; }
.st-fx-gain { color: #16a34a; }
.st-fx-loss { color: #ef4444; }
</style>
