<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Reconciliation/Index.vue

  Audit H-25 — Bank Reconciliation: the list of every reconciliation
  and the form that starts a new one (bank account + statement date +
  the closing balance printed on the bank's statement). Starting one
  opens the reconcile screen (Reconcile.vue).

  Only one reconciliation per bank account can be in progress at a
  time, and a new one must be dated after the last completed one.

  @layout    AppLayout
  @route     fi.bank-reconciliation.index
  @props     reconciliations (paginator), bankAccounts, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.reconciliation.index.cashManagement') }, { label: t('financialInstitutions.reconciliation.index.bankReconciliation') }]"
      :title="t('financialInstitutions.reconciliation.index.bankReconciliation')"
      :subtitle="t('financialInstitutions.reconciliation.index.proveTheBooksAgreeWithTheBank')"
    />

    <!-- ═══════════ Start a reconciliation ═══════════ -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#0F2554">
          <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="form-section-title">{{ t('financialInstitutions.reconciliation.index.startNew') }}</div>
      </div>
      <div class="form-section-body">
        <form @submit.prevent="start" novalidate>
          <div class="field-grid field-grid-4">
            <div class="field-group field-group-span-2">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.reconciliation.index.bankAccount') }}</label>
              <select v-model="form.bank_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.reconciliation.index.select') }}</option>
                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.label }} — {{ a.currency_code }}</option>
              </select>
              <p v-if="form.errors.bank_account_id" class="zv-field-error">{{ form.errors.bank_account_id }}</p>
              <p v-if="selectedAccount?.last_completed" class="field-hint">
                {{ t('financialInstitutions.reconciliation.index.lastCompleted') }}: {{ selectedAccount.last_completed }}
              </p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.reconciliation.index.statementDate') }}</label>
              <input v-model="form.statement_date" type="date" :max="today" class="zv-input"/>
              <p v-if="form.errors.statement_date" class="zv-field-error">{{ form.errors.statement_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">
                {{ t('financialInstitutions.reconciliation.index.closingBalanceOnStatement') }}
                <span v-if="selectedAccount">({{ selectedAccount.currency_code }})</span>
              </label>
              <input v-model="form.statement_ending_balance" type="number" step="0.01" class="zv-input text-end"/>
              <p v-if="form.errors.statement_ending_balance" class="zv-field-error">{{ form.errors.statement_ending_balance }}</p>
            </div>
          </div>
          <p class="field-hint mt-2">{{ t('financialInstitutions.reconciliation.index.startHint') }}</p>
          <div class="mt-4">
            <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">
              {{ t('financialInstitutions.reconciliation.index.startReconciling') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ═══════════ History ═══════════ -->
    <div class="zv-card">
      <div class="zv-filter-bar">
        <select v-model="filter.bank_account_id" class="zv-select brc-filter" @change="applyFilter">
          <option value="">{{ t('financialInstitutions.reconciliation.index.allAccounts') }}</option>
          <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.label }}</option>
        </select>
        <select v-model="filter.status" class="zv-select brc-filter" @change="applyFilter">
          <option value="">{{ t('financialInstitutions.reconciliation.index.allStatuses') }}</option>
          <option value="draft">{{ t('financialInstitutions.reconciliation.index.inProgress') }}</option>
          <option value="completed">{{ t('financialInstitutions.reconciliation.index.completed') }}</option>
        </select>
      </div>

      <div v-if="reconciliations.data.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('financialInstitutions.reconciliation.index.noneYet') }}</div>
      </div>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('financialInstitutions.reconciliation.index.statementDate') }}</th>
              <th>{{ t('financialInstitutions.reconciliation.index.bankAccount') }}</th>
              <th class="brc-th-num">{{ t('financialInstitutions.reconciliation.index.closingBalance') }}</th>
              <th class="brc-th-num">{{ t('financialInstitutions.reconciliation.index.difference') }}</th>
              <th>{{ t('common.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in reconciliations.data" :key="r.id" class="brc-row">
              <td>{{ r.statement_date }}</td>
              <td>{{ r.bank_account?.bank?.name_en }} · {{ r.bank_account?.account_name }}</td>
              <td class="brc-num">{{ r.currency_code }} {{ fmt(r.statement_ending_balance) }}</td>
              <td class="brc-num">{{ r.difference !== null ? fmt(r.difference) : '—' }}</td>
              <td>
                <span class="zv-badge" :class="r.status === 'completed' ? 'zv-badge-success' : 'zv-badge-warning'">
                  {{ r.status === 'completed' ? t('financialInstitutions.reconciliation.index.completed') : t('financialInstitutions.reconciliation.index.inProgress') }}
                </span>
              </td>
              <td class="text-end">
                <Link :href="route('fi.bank-reconciliation.show', r.id)" class="zv-btn zv-btn-secondary zv-btn-sm">
                  {{ r.status === 'completed' ? t('financialInstitutions.reconciliation.index.view') : t('financialInstitutions.reconciliation.index.continue') }}
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="reconciliations" />
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import Pagination from '@/Components/Pagination.vue'
import { computed, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  reconciliations: { type: Object, required: true },
  bankAccounts:    { type: Array, default: () => [] },
  filters:         { type: Object, default: () => ({}) },
})

const today = todayLocal()

const form = useForm({
  bank_account_id: '',
  statement_date: today,
  statement_ending_balance: '',
  notes: '',
})

const selectedAccount = computed(() => props.bankAccounts.find(a => a.id == form.bank_account_id))

function start() {
  form.post(route('fi.bank-reconciliation.store'))
}

const filter = reactive({
  bank_account_id: props.filters.bank_account_id ?? '',
  status: props.filters.status ?? '',
})
function applyFilter() {
  router.get(route('fi.bank-reconciliation.index'), { ...filter }, { preserveState: true, replace: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.brc-filter { max-width: 320px; }
.brc-th-num { text-align: end; }
.brc-num { text-align: end; font-variant-numeric: tabular-nums; }
.brc-row:hover { background: var(--zv-bg-surface-alt); }
.brc-page-disabled { opacity: 0.35; pointer-events: none; }
.zv-pagination-pages { display: flex; gap: 4px; }
</style>
