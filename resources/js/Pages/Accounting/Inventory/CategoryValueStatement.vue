<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/CategoryValueStatement.vue

  New report (July 2026). Value ONLY, by design — never quantity. A
  category can hold products counted in different units (Units, KG,
  Cartons...); summing quantity across mixed units would be
  meaningless. Money always sums cleanly, so this is a Value
  Statement grouped by Category, with each Main Category's row
  showing the rolled-up total (its own products + every
  Sub-Category's total) and each Sub-Category listed as a row
  underneath showing just its own — parent/child, for analysis.

  Sub-Categories have no Chart of Accounts entry of their own (see
  ProductService::resolveCategoryInventoryAccount()) — every
  product's real GL posting already rolled up to its Main Category's
  one account. This report re-derives the Sub-Category breakdown
  straight from stock_movements for analysis; it doesn't need (and
  deliberately doesn't create) a second GL account to do it.

  @layout    AppLayout
  @route     accounting.category-value-statement.index
  @props     — (none; data is fetched live for the chosen date range)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.inventory.categoryValueStatement.categoryValueStatement')"
      :subtitle="t('accounting.inventory.categoryValueStatement.inventoryValueByCategoryWithSub')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 4l8 4-8 4-8-4 8-4zm-8 8l8 4 8-4m-16 4l8 4 8-4"/>'
    />

    <div class="zv-card mb-5">
      <div class="zv-card-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.categoryValueStatement.from') }}</label>
            <input v-model="form.date_from" type="date" class="zv-input" />
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.categoryValueStatement.to') }}</label>
            <input v-model="form.date_to" type="date" class="zv-input" />
          </div>
          <div class="field-group is-run-group">
            <button type="button" class="zv-btn zv-btn-primary" :disabled="!canRun || loading" @click="run">
              <div v-if="loading" class="zv-spinner w-4 h-4 me-2"></div>
              {{ loading ? t('accounting.inventory.categoryValueStatement.running') : t('accounting.inventory.categoryValueStatement.runStatement') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="error" class="zv-alert zv-alert-danger mb-5">{{ error }}</div>

    <div v-if="categories" class="zv-card">
      <div class="zv-table-wrap">
        <table class="zv-table cvs-table">
          <thead>
            <tr>
              <th>{{ t('accounting.inventory.categoryValueStatement.category') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.categoryValueStatement.beginningBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.categoryValueStatement.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.categoryValueStatement.credit') }}</th>
              <th class="zv-table-num">{{ t('accounting.inventory.categoryValueStatement.endingBalance') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="main in categories" :key="main.id">
              <tr class="cvs-main-row">
                <td class="cvs-main-name">{{ main.name }}</td>
                <td class="zv-table-num">{{ fmt(main.beginning) }}</td>
                <td class="zv-table-num is-in">{{ fmt(main.debit) }}</td>
                <td class="zv-table-num is-out">{{ fmt(main.credit) }}</td>
                <td class="zv-table-num is-ending">{{ fmt(main.ending) }}</td>
              </tr>
              <tr v-for="sub in main.children" :key="sub.id" class="cvs-sub-row">
                <td class="cvs-sub-name">↳ {{ sub.name }}</td>
                <td class="zv-table-num">{{ fmt(sub.beginning) }}</td>
                <td class="zv-table-num is-in">{{ fmt(sub.debit) }}</td>
                <td class="zv-table-num is-out">{{ fmt(sub.credit) }}</td>
                <td class="zv-table-num">{{ fmt(sub.ending) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <EmptyState
      v-else-if="!loading"
      :title="t('accounting.inventory.categoryValueStatement.noStatementRunYet')"
      :message="t('accounting.inventory.categoryValueStatement.pickADateRangeAboveThen')"
      icon-path="M12 4l8 4-8 4-8-4 8-4zm-8 8l8 4 8-4m-16 4l8 4 8-4"
    />

  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const form = reactive({
  date_from: '',
  date_to: '',
})

const canRun = computed(() => !!form.date_from && !!form.date_to)

const categories = ref(null)
const loading     = ref(false)
const error        = ref('')

async function run() {
  if (!canRun.value) return
  loading.value = true
  error.value = ''
  try {
    const res = await window.axios.get(route('accounting.category-value-statement.run'), { params: { ...form } })
    categories.value = res.data.categories
  } catch (e) {
    error.value = e.response?.data?.message ?? t('accounting.inventory.categoryValueStatement.couldNotRunThisStatementCheck')
    categories.value = null
  } finally {
    loading.value = false
  }
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.is-run-group { display: flex; align-items: flex-end; }
.is-in     { color: var(--zv-green, #10b981); }
.is-out    { color: var(--zv-danger, #ef4444); }
.is-ending { font-weight: 700; }

.cvs-main-row { background-color: var(--zv-bg-surface); }
.cvs-main-name { font-weight: 700; color: #0F2554; }
[data-theme="dark"] .cvs-main-name { color: #90a4c4; }
.cvs-sub-row td { color: var(--zv-text-secondary, #64748b); }
.cvs-sub-name { padding-inline-start: 1.75rem; }
</style>
