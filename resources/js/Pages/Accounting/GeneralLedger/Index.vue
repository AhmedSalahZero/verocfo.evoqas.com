<!--
══════════════════════════════════════════════════════════════════
  Accounting/GeneralLedger/Index.vue

  Accounting > General Ledger > General Ledger — the picker form.
  Pick any postable Chart of Accounts row (any class — asset,
  liability, equity, revenue, expense), optionally narrow to one
  cost center, pick a date range, then view the running-balance
  ledger. Same shape as the Bank Statement picker — this page only
  picks WHAT to view; LedgerStatementService does the real work.

  @layout    AppLayout
  @route     accounting.general-ledger.index
  @props     accounts, costCenters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.generalLedger.index.generalLedger') }]"
      :title="t('accounting.generalLedger.index.generalLedger')"
      :subtitle="t('accounting.generalLedger.index.pickAnAccountAndDateRange')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div v-if="accounts.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('accounting.generalLedger.index.noAccountsFound') }}</div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group field-group-span-2">
            <label class="zv-label zv-label-required">{{ t('accounting.generalLedger.index.account') }}</label>
            <!-- This was the one <optgroup> select in Accounting: accounts
                 banded by class (Assets, Liabilities, …). SelectBox has no
                 group headers, so the class rides along as each row's
                 second line instead — which keeps the information and
                 also makes it searchable, where an <optgroup> label never
                 was. -->
            <SelectBox v-model="form.account_id" :options="accountOptions" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" sub-key="_class" :placeholder="t('accounting.generalLedger.index.selectAccount')" :clearable="false" />
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('accounting.generalLedger.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.generalLedger.index.allCostCenters')" />
            <p class="field-hint">{{ t('accounting.generalLedger.index.costCenterHint') }}</p>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input"/>
            <p class="field-hint">{{ t('accounting.generalLedger.index.leaveBlankForFromTheBeginning') }}</p>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" :max="today" :class="{ 'zv-input-error': endDateError }"/>
            <p v-if="endDateError" class="zv-field-error">{{ endDateError }}</p>
            <p class="field-hint">{{ t('accounting.generalLedger.index.leaveBlankForUpToToday') }}</p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!form.account_id || !!endDateError" @click="viewLedger">
            {{ t('accounting.generalLedger.index.viewLedger') }}
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
  accounts:    { type: Array, default: () => [] },
  costCenters: { type: Array, default: () => [] },
})

// End date can never be after today — the "max" attribute stops most
// cases at the calendar-picker level, but a typed-in date bypasses
// that, so this check is the one that actually matters (server-side
// validation is the real backstop).
const today = todayLocal()

const form = reactive({
  account_id: '',
  cost_center_id: '',
  start_date: '',
  end_date: '',
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('accounting.generalLedger.index.endDateCannotBeInTheFuture')
  }
  return ''
})

const CLASS_ORDER = ['assets', 'liabilities', 'equity', 'revenue', 'expenses']

function classLabel(cls) {
  return {
    assets: t('accounting.generalLedger.index.classAssets'),
    liabilities: t('accounting.generalLedger.index.classLiabilities'),
    equity: t('accounting.generalLedger.index.classEquity'),
    revenue: t('accounting.generalLedger.index.classRevenue'),
    expenses: t('accounting.generalLedger.index.classExpenses'),
  }[cls] ?? cls
}

// One <optgroup> per account class, in a fixed, statement-order
// sequence rather than however the backend happened to return them.
const groupedAccounts = computed(() => {
  return CLASS_ORDER
    .map((cls) => ({
      key: cls,
      label: classLabel(cls),
      accounts: props.accounts.filter((a) => a.class === cls),
    }))
    .filter((g) => g.accounts.length > 0)
})

// Flattened for the account picker: one row per account, carrying its
// class label so the grouping survives as a per-row caption.
const accountOptions = computed(() =>
  groupedAccounts.value.flatMap((g) => g.accounts.map((a) => ({ ...a, _class: g.label })))
)

function viewLedger() {
  if (endDateError.value) return
  router.get(route('accounting.general-ledger.result'), {
    account_id: form.account_id,
    cost_center_id: form.cost_center_id || undefined,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
  })
}
</script>
