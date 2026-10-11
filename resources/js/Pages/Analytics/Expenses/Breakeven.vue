<!--
  Analytics > Expense Analysis > Breakeven.
  The calculation lives in ExpenseAnalysisService::breakeven().
  This page only asks for a date range and shows the result.
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="Analytics"
      :title="t('analytics.expenseAnalysis.breakeven.title')"
      :subtitle="t('analytics.expenseAnalysis.breakeven.subtitle')"
    />

    <div v-if="!hasData" class="zv-card">
      <div class="zv-empty">
        <div class="zv-empty-title">{{ t('analytics.expenseAnalysis.breakeven.noExpenses') }}</div>
      </div>
    </div>

    <template v-else>
      <div class="zv-card mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label">{{ t('common.startDate') }}</label>
              <input v-model="dateFrom" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('common.endDate') }}</label>
              <input v-model="dateTo" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group" style="align-self: end;">
              <button type="button" class="zv-btn zv-btn-primary" :disabled="loading" @click="load">
                {{ loading ? t('common.loading') : t('analytics.expenseAnalysis.breakeven.calculate') }}
              </button>
            </div>
          </div>
          <p v-if="error" class="zv-field-error mt-2">{{ error }}</p>
        </div>
      </div>

      <template v-if="data">
        <div class="fid-kpi-row-3">
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.revenue') }}</div>
              <div class="fid-kpi-value">{{ fmt(data.total_revenue) }}</div>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.fixedCosts') }}</div>
              <div class="fid-kpi-value">{{ fmt(data.total_fixed) }}</div>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.variableCosts') }}</div>
              <div class="fid-kpi-value">{{ fmt(data.total_variable) }}</div>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.contributionMargin') }}</div>
              <div class="fid-kpi-value">{{ fmt(data.cm_ratio) }}%</div>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.breakevenRevenue') }}</div>
              <div class="fid-kpi-value">{{ fmt(data.breakeven_revenue) }}</div>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div>
              <div class="fid-kpi-label">{{ t('analytics.expenseAnalysis.breakeven.safetyMargin') }}</div>
              <div class="fid-kpi-value" :class="data.safety_margin < 0 ? 'fid-num-red' : 'fid-num-green'">{{ fmt(data.safety_margin) }}</div>
            </div>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('analytics.expenseAnalysis.breakeven.account') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.breakeven.amount') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.breakeven.correlation') }}</th>
                  <th>{{ t('analytics.expenseAnalysis.breakeven.classification') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.items?.length">
                  <td colspan="4" class="fid-muted">{{ t('analytics.expenseAnalysis.breakeven.noRows') }}</td>
                </tr>
                <tr v-for="row in data.items" :key="row.account">
                  <td>{{ row.account }}</td>
                  <td class="fid-num">{{ fmt(row.total) }}</td>
                  <td class="fid-num">{{ row.correlation }}</td>
                  <td>{{ row.classification === 'variable' ? t('analytics.expenseAnalysis.breakeven.variable') : t('analytics.expenseAnalysis.breakeven.fixed') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  defaultFrom: { type: String, default: '' },
  defaultTo:   { type: String, default: '' },
  hasData:     { type: Boolean, default: false },
})

const today = todayLocal()
const dateFrom = ref(props.defaultFrom)
const dateTo = ref(props.defaultTo)
const loading = ref(false)
const error = ref('')
const data = ref(null)

async function load() {
  if (!dateFrom.value || !dateTo.value) return
  loading.value = true
  error.value = ''
  try {
    const { data: response } = await axios.post(route('analytics.expenses.breakeven.calculate'), {
      date_from: dateFrom.value,
      date_to: dateTo.value,
    })
    data.value = response
  } catch (e) {
    error.value = e.response?.data?.message || t('analytics.expenseAnalysis.breakeven.failed')
  } finally {
    loading.value = false
  }
}
load()

function fmt(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.fid-kpi-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 800px) { .fid-kpi-row-3 { grid-template-columns: 1fr; } }
.fid-kpi-card { padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }
.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); }
</style>
