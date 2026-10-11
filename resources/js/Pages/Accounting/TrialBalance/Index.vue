<!--
══════════════════════════════════════════════════════════════════
  Accounting/TrialBalance/Index.vue

  Accounting > Financial Reports > Trial Balance — the picker form.
  Three view modes, confirmed with Mahmoud (2026-09):
    - Single date  — one date range, one 4-column block (default)
    - Monthly      — one fiscal year's real accounting periods,
                     side by side
    - Quarterly    — those same periods grouped into 4 quarters
  Monthly/Quarterly need a Fiscal Year picked first — periods are
  always read from the company's real accounting calendar, never a
  hardcoded calendar-month grid (see TrialBalanceService header).

  @layout    AppLayout
  @route     accounting.trial-balance.index
  @props     costCenters, fiscalYears
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.trialBalance.index.trialBalance') }]"
      :title="t('accounting.trialBalance.index.trialBalance')"
      :subtitle="t('accounting.trialBalance.index.pickAViewAndADateRange')"
    />

    <div class="zv-card">
      <div class="form-section-body">

        <!-- ── Mode picker ─────────────────────────────────── -->
        <label class="zv-label mb-2 d-block">{{ t('accounting.trialBalance.index.viewMode') }}</label>
        <div class="zv-tile-group tb-mode-group">
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'single' }" @click="form.mode = 'single'">
            <div class="zv-tile-label">{{ t('accounting.trialBalance.index.singleDate') }}</div>
            <div class="zv-tile-desc">{{ t('accounting.trialBalance.index.singleDateDesc') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'monthly' }" @click="form.mode = 'monthly'">
            <div class="zv-tile-label">{{ t('accounting.trialBalance.index.monthly') }}</div>
            <div class="zv-tile-desc">{{ t('accounting.trialBalance.index.monthlyDesc') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'quarterly' }" @click="form.mode = 'quarterly'">
            <div class="zv-tile-label">{{ t('accounting.trialBalance.index.quarterly') }}</div>
            <div class="zv-tile-desc">{{ t('accounting.trialBalance.index.quarterlyDesc') }}</div>
          </button>
        </div>

        <div class="field-divider"></div>

        <!-- ── Single date fields ──────────────────────────── -->
        <div v-if="form.mode === 'single'" class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('common.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input"/>
            <p class="field-hint">{{ t('accounting.trialBalance.index.leaveBlankForFromTheBeginning') }}</p>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('common.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" :max="today" :class="{ 'zv-input-error': endDateError }"/>
            <p v-if="endDateError" class="zv-field-error">{{ endDateError }}</p>
            <p class="field-hint">{{ t('accounting.trialBalance.index.leaveBlankForUpToToday') }}</p>
          </div>
        </div>

        <!-- ── Fiscal Year picker (Monthly / Quarterly) ────────── -->
        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.trialBalance.index.fiscalYear') }}</label>
            <SelectBox v-model="form.fiscal_year_id" :options="fiscalYears" value-key="id" :label-fn="fy => `${fy.name} (${fy.status})`" :placeholder="t('accounting.trialBalance.index.selectFiscalYear')" :clearable="false" />
            <p v-if="fiscalYears.length === 0" class="field-hint">{{ t('accounting.trialBalance.index.noFiscalYearsFound') }}</p>
          </div>
        </div>

        <div class="field-divider"></div>

        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.trialBalance.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.trialBalance.index.allCostCenters')" />
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('accounting.trialBalance.index.accounts') }}</label>
            <div class="zv-toggle-row">
              <button type="button" class="zv-toggle" :class="{ 'zv-toggle-on': form.show_all }" @click="form.show_all = !form.show_all">
                <span class="zv-toggle-knob"></span>
              </button>
              <span>{{ form.show_all ? t('accounting.trialBalance.index.showingAllAccounts') : t('accounting.trialBalance.index.showingAccountsWithActivityOnly') }}</span>
            </div>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit" @click="viewReport">
            {{ t('accounting.trialBalance.index.viewTrialBalance') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  costCenters: { type: Array, default: () => [] },
  fiscalYears: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  mode: 'single',
  fiscal_year_id: '',
  cost_center_id: '',
  start_date: '',
  end_date: '',
  show_all: false,
})

const endDateError = computed(() => {
  if (form.mode === 'single' && form.end_date && form.end_date > today) {
    return t('accounting.trialBalance.index.endDateCannotBeInTheFuture')
  }
  return ''
})

const canSubmit = computed(() => {
  if (endDateError.value) return false
  if (form.mode !== 'single') return !!form.fiscal_year_id
  return true
})

function viewReport() {
  if (!canSubmit.value) return
  router.get(route('accounting.trial-balance.result'), {
    mode: form.mode,
    fiscal_year_id: form.mode !== 'single' ? form.fiscal_year_id : undefined,
    cost_center_id: form.cost_center_id || undefined,
    start_date: form.mode === 'single' ? (form.start_date || undefined) : undefined,
    end_date: form.mode === 'single' ? (form.end_date || undefined) : undefined,
    show_all: form.show_all ? 1 : undefined,
  })
}
</script>

<style>
.tb-mode-group { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 700px) { .tb-mode-group { grid-template-columns: 1fr; } }
.d-block { display: block; }
</style>
