<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/InventoryStatement.vue

  Full redesign (July 2026, third pass) — this is now a true running
  ledger: ONE row per transaction (not per date, not a summary +
  separate movements list), balance carried from each row's Ending
  Balance into the next row's Beginning Balance. Each row also shows
  its Transaction Reference (source document code) and Journal
  Reference (posted entry number) for tracing.

  Filters, top to bottom: Statement Type (Quantity/Value) → Product
  (searchable) → Location (All Locations, or one specific Warehouse —
  Project Sites excluded, see index() doc) → Date Range.

  Location = "All Locations" nets internal transfers between the
  company's own locations to zero automatically (correct — moving
  stock between your own warehouses doesn't change what the company
  owns). Picking one specific location shows only that location's own
  movements, including its own transfer_in/transfer_out rows — this is
  how you trace stock moving between two specific warehouses.

  @layout    AppLayout
  @route     accounting.inventory-statement.index
  @props     locations (array — Master + Warehouse only)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.inventoryStatement.inventoryStatement')"
      :subtitle="t('accounting.inventory.inventoryStatement.quantityAndValueMovementForOne')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
    />

    <div class="zv-card mb-5">
      <div class="zv-card-body">
        <div class="field-grid field-grid-5 is-filter-grid">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.inventoryStatement.statementType') }}</label>
            <SelectBox v-model="form.statement_type" :options="[{ value: 'quantity', label: t('accounting.inventory.inventoryStatement.quantity') }, { value: 'value', label: t('accounting.inventory.inventoryStatement.value') }]" />
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.inventoryStatement.product') }}</label>
            <SearchableSelect
              v-model="form.product_id"
              v-model:label="productLabel"
              :search-url="route('accounting.inventory-statement.products.search')"
              :placeholder="t('accounting.inventory.inventoryStatement.searchByCodeOrName')"
            />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.inventory.inventoryStatement.location') }}</label>
            <SelectBox v-model="form.location_id" :options="locations" value-key="id" :label-fn="loc => loc.name_en" :placeholder="t('accounting.inventory.inventoryStatement.allLocationsCombined')" />
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.inventoryStatement.from') }}</label>
            <input v-model="form.date_from" type="date" class="zv-input" />
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.inventoryStatement.to') }}</label>
            <input v-model="form.date_to" type="date" class="zv-input" />
          </div>
        </div>
        <div class="mt-4">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!canRun || loading" @click="run">
            <div v-if="loading" class="zv-spinner w-4 h-4 me-2"></div>
            {{ loading ? t('accounting.inventory.inventoryStatement.running') : t('accounting.inventory.inventoryStatement.runStatement') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="error" class="zv-alert zv-alert-danger mb-5">{{ error }}</div>

    <div v-if="ledger" class="zv-card">
      <div class="zv-card-header">
        <h2 class="zv-card-title">{{ form.statement_type === 'quantity' ? t('accounting.inventory.inventoryStatement.quantityStatement') : t('accounting.inventory.inventoryStatement.valueStatement') }}</h2>
      </div>

      <div v-if="ledger.data.length" class="zv-table-wrap">
        <table class="zv-table">
          <thead v-if="form.statement_type === 'quantity'">
            <tr>
              <th>{{ t('accounting.inventory.inventoryStatement.date') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.transactionRef') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.journalRef') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.movementType') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.beginningBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.in') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.out') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.endingBalance') }}</th>
            </tr>
          </thead>
          <thead v-else>
            <tr>
              <th>{{ t('accounting.inventory.inventoryStatement.date') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.transactionRef') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.journalRef') }}</th>
              <th>{{ t('accounting.inventory.inventoryStatement.movementType') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.beginningBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.credit') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.endingBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.endingBalanceQuantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.inventoryStatement.wAC') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in ledger.data" :key="row.id">
              <td>{{ row.movement_date }}</td>
              <td><span class="zv-code">{{ row.transaction_reference ?? '—' }}</span></td>
              <td><span class="zv-code">{{ row.journal_reference ?? '—' }}</span></td>
              <td><span class="zv-tag">{{ movementTypeLabel(row.movement_type) }}</span></td>
              <template v-if="form.statement_type === 'quantity'">
                <td class="zv-table-num">{{ fmtQty(row.quantity.beginning_balance) }}</td>
                <td class="zv-table-num is-in">{{ row.quantity.in !== '0.0000' ? fmtQty(row.quantity.in) : '' }}</td>
                <td class="zv-table-num is-out">{{ row.quantity.out !== '0.0000' ? fmtQty(row.quantity.out) : '' }}</td>
                <td class="zv-table-num is-ending">{{ fmtQty(row.quantity.ending_balance) }}</td>
              </template>
              <template v-else>
                <td class="zv-table-num">{{ fmt(row.value.beginning_balance) }}</td>
                <td class="zv-table-num is-in">{{ row.value.debit !== '0.00' ? fmt(row.value.debit) : '' }}</td>
                <td class="zv-table-num is-out">{{ row.value.credit !== '0.00' ? fmt(row.value.credit) : '' }}</td>
                <td class="zv-table-num is-ending">{{ fmt(row.value.ending_balance) }}</td>
                <td class="zv-table-num">{{ fmtQty(row.quantity.ending_balance) }}</td>
                <td class="zv-table-num">{{ fmtQty(row.value.wac) }}</td>
              </template>
            </tr>
          </tbody>
        </table>

        <div class="is-pagination">
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :disabled="ledger.current_page <= 1" @click="loadPage(ledger.current_page - 1)">{{ t('common.previous') }}</button>
          <span class="is-pagination-label">{{ t('accounting.inventory.inventoryStatement.page') }} {{ ledger.current_page }} {{ t('common.of') }} {{ ledger.last_page }} ({{ ledger.total }} {{ t('accounting.inventory.inventoryStatement.total') }})</span>
          <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :disabled="ledger.current_page >= ledger.last_page" @click="loadPage(ledger.current_page + 1)">{{ t('common.next') }}</button>
        </div>
      </div>

      <EmptyState
        v-else
        :title="t('accounting.inventory.inventoryStatement.noTransactionsInThisRange')"
        :message="t('accounting.inventory.inventoryStatement.nothingWasReceivedIssuedTransferredOr')"
        icon-path="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"
      />
    </div>

    <EmptyState
      v-else-if="!loading"
      :title="t('accounting.inventory.inventoryStatement.noStatementRunYet')"
      :message="t('accounting.inventory.inventoryStatement.chooseAStatementTypeAProduct')"
      icon-path="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"
    />

  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import SearchableSelect from '@/Components/SearchableSelect.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

defineProps({
  locations: { type: Array, default: () => [] },
})

const form = reactive({
  statement_type: 'quantity',
  product_id: null,
  location_id: '',
  date_from: '',
  date_to: '',
})
const productLabel = ref('')

const canRun = computed(() => !!form.product_id && !!form.date_from && !!form.date_to)

const ledger  = ref(null)
const loading = ref(false)
const error   = ref('')

function movementTypeLabel(type) {
  const map = {
    receipt: 'Receipt', issue: 'Issue', transfer_out: 'Transfer Out', transfer_in: 'Transfer In',
    adjustment_in: 'Adjustment In', adjustment_out: 'Adjustment Out', production_consumption: 'Production Consumption',
    production_receipt: 'Production Receipt', job_consumption: 'Job Consumption', job_receipt: 'Job Receipt',
    opening_balance: 'Opening Balance',
  }
  return t(map[type] ?? type)
}

async function run() {
  if (!canRun.value) return
  await loadPage(1)
}

async function loadPage(page) {
  loading.value = true
  error.value = ''
  try {
    const res = await window.axios.get(route('accounting.inventory-statement.ledger'), {
      params: { ...form, location_id: form.location_id || undefined, page },
    })
    ledger.value = res.data
  } catch (e) {
    error.value = e.response?.data?.message ?? t('accounting.inventory.inventoryStatement.couldNotRunThisStatementCheck')
    ledger.value = null
  } finally {
    loading.value = false
  }
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}
</script>

<style>
/* Give Statement Type, From, and To less room, and Product/Location
   more — all 5 fields then fit on one line at desktop widths. Scoped
   to min-width so the existing responsive stacking (900px/600px
   breakpoints, in app.css) still takes over on smaller screens. */
@media (min-width: 901px) {
  .is-filter-grid {
    grid-template-columns: 0.8fr 1.6fr 1.2fr 0.8fr 0.8fr;
  }
}
.is-in     { color: var(--zv-green, #10b981); }
.is-out    { color: var(--zv-danger, #ef4444); }
.is-ending { font-weight: 700; }

.is-pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 0.875rem; }
.is-pagination-label { font-size: 0.8125rem; color: var(--zv-text-muted); }
</style>
