<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierBalances/Statement.vue

  Payable-side mirror of Customer Balances/Statement.vue. Every
  AP-affecting movement for one supplier within a date range, running
  End Balance. currency_id blank/absent means the Main Currency tab,
  with an FX Gain/Loss column on Payment rows (informational only,
  doesn't feed the running balance — see SupplierBalanceService::
  statement()).

  Audit H11 (Sept 2026): the first row is always the Opening Balance
  ("balance brought forward") up to the day before the start date,
  so the End Balance is right for any date range.

  @layout    AppLayout
  @route     accounting.supplier-balances.statement
  @props     suppliers, currencies, movements, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.supplierBalances.statement.supplierStatement', { name: selectedSupplierName, code: currencyLabel })"
      :subtitle="t('accounting.supplierBalances.statement.movementsForTheSelectedDateRange')"
    />

    <div class="zv-card mb-5">
      <div class="zv-card-body">
        <div class="field-grid field-grid-4">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
            <SelectBox v-model="form.partner_id" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.supplierBalances.statement.selectSupplier')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.supplierBalances.statement.currency') }}</label>
            <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :placeholder="t('accounting.supplierBalances.statement.mainCurrency')" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.supplierBalances.statement.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.supplierBalances.statement.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" />
          </div>
        </div>
        <button type="button" class="zv-btn zv-btn-primary mt-4" :disabled="!form.partner_id" @click="submit">
          {{ t('accounting.supplierBalances.statement.submit') }}
        </button>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="!movements.length"
        :title="t('accounting.supplierBalances.statement.noMovementsInThisRange')"
        :message="t('accounting.supplierBalances.statement.pickASupplierAndDateRangeThen')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('accounting.supplierBalances.statement.date') }}</th>
              <th>{{ t('accounting.supplierBalances.statement.documentType') }}</th>
              <th>{{ t('accounting.supplierBalances.statement.documentNo') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.statement.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.statement.credit') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.statement.endBalance') }}</th>
              <th v-if="isMainCurrency" class="zv-table-num">{{ t('accounting.supplierBalances.statement.fxGainLoss') }}</th>
              <th>{{ t('accounting.supplierBalances.statement.comment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in movements" :key="row.n" :class="{ 'st-opening': row.is_opening }">
              <td>{{ row.n }}</td>
              <td>{{ row.date }}</td>
              <td><span class="zv-tag">{{ row.is_opening ? t('accounting.supplierBalances.statement.openingBalance') : row.type }}</span></td>
              <td><span class="zv-code">{{ row.doc_no }}</span></td>
              <td class="zv-table-num">{{ !row.is_opening && row.debit !== '0.00' ? fmt(row.debit) : '' }}</td>
              <td class="zv-table-num">{{ !row.is_opening && row.credit !== '0.00' ? fmt(row.credit) : '' }}</td>
              <td class="zv-table-num"><strong>{{ fmt(row.end_balance) }}</strong></td>
              <td v-if="isMainCurrency" class="zv-table-num" :class="{ 'sst-fx-loss': Number(row.fx_gain_loss) < 0, 'sst-fx-gain': Number(row.fx_gain_loss) > 0 }">
                {{ row.fx_gain_loss && row.fx_gain_loss !== '0.00' ? fmt(row.fx_gain_loss) : '' }}
              </td>
              <td><div class="zv-cell-clamp">{{ row.is_opening ? t('accounting.supplierBalances.statement.broughtForward') : (row.comment || '—') }}</div></td>
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
  suppliers:  { type: Array, default: () => [] },
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

const selectedSupplierName = computed(() => props.suppliers.find(s => s.id === form.partner_id)?.name_en ?? '')
const currencyLabel = computed(() => {
  if (! form.currency_id) return t('accounting.supplierBalances.statement.mainCurrency')
  return props.currencies.find(c => c.id === form.currency_id)?.code ?? ''
})

function submit() {
  router.get(route('accounting.supplier-balances.statement'), {
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
.sst-fx-gain { color: #16a34a; }
.sst-fx-loss { color: #ef4444; }
</style>
