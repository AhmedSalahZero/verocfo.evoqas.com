<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/MtlSettlementForm.vue

  Reached via the $ action on a Loan Schedule row. Matches Mahmoud's
  "Loan Schedule Payment" reference image: most fields read-only
  (pulled from the schedule line), Settlement Date + Settlement
  Amount + Current Account are the real inputs, with a settlement
  history table below since ONE schedule line can have MANY
  settlements — confirmed business case (partial payments when the
  company doesn't have full funds available).

  ═══════════════════════════════════════════════════════════════
  ACCOUNTING ENGINE TODO — see MtlService::recordSettlement() for
  the full comment. Applies to BOTH New and Existing loans (unlike
  the initial liability posting, which is New-only) — paying down
  an existing loan is real money movement happening now. Once the
  GL exists: Dr Loan Payable (principal) + Dr Interest Expense
  (interest) / Cr current_account_id, via a pending-GL-posting
  mtl_movements row. Nothing posts today.
  ═══════════════════════════════════════════════════════════════

  @layout    AppLayout
  @route     fi.mtl.settlement-form
  @props     scheduleLine, settledAmount, remainingAmount,
             settlements, currentAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.facilities.mtl.schedule', scheduleLine.credit_facility_id)" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.loanSchedule') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.loanSchedulePayment') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.loanSchedulePayment') }}</h1>
        </div>

        <div class="mtl-remaining-panel">
          <div class="mtl-remaining-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.remainingOnThisInstallment') }}</div>
          <div class="mtl-remaining-value" :class="{ 'mtl-fully-settled': parseFloat(remainingAmount) <= 0 }">
            {{ fmt(remainingAmount) }}
          </div>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        {{ t('financialInstitutions.banks.facilities.mtlSettlementForm.pleaseFixTheErrorsBelowBefore') }}
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.loanSchedulePayment') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.installmentDetailsAreReadOnlyPulled') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3 mb-4">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.loanName') }}</label>
              <div class="zv-readonly-field">{{ scheduleLine.credit_facility?.name }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.installmentDueDate') }}</label>
              <div class="zv-readonly-field">{{ fmtDate(scheduleLine.due_date) }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.currency') }}</label>
              <div class="zv-readonly-field">{{ scheduleLine.credit_facility?.currency?.code }}</div>
            </div>
          </div>

          <div class="field-grid field-grid-3 mb-4">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.beginningBalance') }}</label>
              <div class="zv-readonly-field">{{ fmt(scheduleLine.beginning_balance) }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.schedulePayment') }}</label>
              <div class="zv-readonly-field">{{ fmt(scheduleLine.schedule_payment) }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.interestAmount') }}</label>
              <div class="zv-readonly-field">{{ fmt(scheduleLine.interest_amount) }}</div>
            </div>
          </div>

          <div class="field-grid field-grid-3 mb-4">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.principalAmount') }}</label>
              <div class="zv-readonly-field">{{ fmt(scheduleLine.principal_amount) }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.endBalance') }}</label>
              <div class="zv-readonly-field">{{ fmt(scheduleLine.end_balance) }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.settlementDate') }}</label>
              <input v-model="form.settlement_date" type="date" class="zv-input"
                     :class="{ 'zv-input-error': form.errors.settlement_date }"/>
              <p v-if="form.errors.settlement_date" class="zv-field-error">{{ form.errors.settlement_date }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.settlementAmount') }}</label>
              <input v-model="form.settlement_amount" type="number" step="0.01" min="0.01" class="zv-input"
                     :class="{ 'zv-input-error': form.errors.settlement_amount }"
                     :placeholder="remainingAmount"/>
              <p v-if="form.errors.settlement_amount" class="zv-field-error">{{ form.errors.settlement_amount }}</p>
              <p v-else class="field-hint">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.canBeLessThanTheFull') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.currentAccount') }}</label>
              <select v-model="form.current_account_id" class="zv-select"
                      :class="{ 'zv-input-error': form.errors.current_account_id }">
                <option value="">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.select') }}</option>
                <option v-for="a in currentAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
              <p v-if="form.errors.current_account_id" class="zv-field-error">{{ form.errors.current_account_id }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.notes') }}</label>
              <input v-model="form.notes" type="text" class="zv-input" :placeholder="t('financialInstitutions.banks.facilities.mtlSettlementForm.optional')"/>
            </div>
          </div>
        </div>
      </div>

      <div class="zv-card mb-5">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('fi.facilities.mtl.schedule', scheduleLine.credit_facility_id)" class="zv-btn zv-btn-ghost">
            {{ t('financialInstitutions.banks.facilities.mtlSettlementForm.backToLoanSchedule') }}
          </Link>
          <button type="button" :disabled="form.processing || parseFloat(remainingAmount) <= 0" class="zv-btn zv-btn-primary" @click="submit">
            {{ form.processing ? t('common.saving') : t('financialInstitutions.banks.facilities.mtlSettlementForm.save') }}
          </button>
        </div>
      </div>

      <!-- Settlement history — one installment, many settlements -->
      <div class="zv-card">
        <div class="mtl-history-header">
          {{ t('financialInstitutions.banks.facilities.mtlSettlementForm.settlementHistory') }}
          <span class="mtl-history-total">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.totalSettled') }}: {{ fmt(settledAmount) }}</span>
        </div>
        <div v-if="settlements.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.noSettlementsRecordedYet') }}</div>
        </div>
        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.date') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.mtlSettlementForm.accountNumber') }}</th>
                <th class="text-end">{{ t('common.amount') }}</th>
                <th class="mtl-th-actions">{{ t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(s, idx) in settlements" :key="s.id">
                <td>{{ idx + 1 }}</td>
                <td>{{ fmtDate(s.settlement_date) }}</td>
                <td class="font-mono">{{ s.current_account?.account_number }}</td>
                <td class="text-end">{{ fmt(s.settlement_amount) }}</td>
                <td class="mtl-th-actions">
                  <button type="button" class="mtl-delete-btn" @click="deleteSettlement(s)" :title="t('financialInstitutions.banks.facilities.mtlSettlementForm.remove')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { useForm, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  scheduleLine:     { type: Object, required: true },
  settledAmount:    [String, Number],
  remainingAmount:  [String, Number],
  settlements:      { type: Array, default: () => [] },
  currentAccounts:  { type: Array, default: () => [] },
})

const form = useForm({
  settlement_date:     todayLocal(),
  settlement_amount:   props.remainingAmount ?? '',
  current_account_id:  '',
  notes:               '',
})

function submit() {
  form.post(route('fi.mtl.settle', props.scheduleLine.id), {
    preserveScroll: true,
    // A refusal from the server (amount above what is left, no exchange
    // rate...) comes back as a normal page with an error message, so
    // onSuccess still runs. Keep what the user typed in that case.
    onSuccess: (page) => { if (!page.props.flash?.error) { form.settlement_amount = ''; form.notes = '' } },
  })
}

function deleteSettlement(settlement) {
  if (confirm(t('financialInstitutions.banks.facilities.mtlSettlementForm.removeThisSettlement'))) {
    router.delete(route('fi.mtl.settlement.delete', settlement.id), { preserveScroll: true })
  }
}

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
</script>

<style>
.mtl-remaining-panel { min-width: 220px; text-align: end; background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 14px 18px; box-shadow: var(--zv-shadow-sm); }
.mtl-remaining-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.mtl-remaining-value { font-size: 20px; font-weight: 800; color: #f59e0b; font-variant-numeric: tabular-nums; }
.mtl-fully-settled { color: #10b981; }

.zv-readonly-field { padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius); background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 600; min-height: 38px; }
.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }

.mtl-history-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; font-size: 14px; font-weight: 700; color: var(--zv-text-primary); border-bottom: 1px solid var(--zv-border); }
.mtl-history-total { font-size: 12.5px; font-weight: 600; color: #1490A8; }
.mtl-th-actions { width: 70px; text-align: end; }
.text-end { text-align: end; }
.mtl-delete-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer; color: var(--zv-text-muted); }
.mtl-delete-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }
.zv-empty { padding: 40px 24px; text-align: center; color: var(--zv-text-muted); }

/* ── Self-contained grid fallback ─────────────────────────────────── */
.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }

@media (max-width: 900px) { .field-grid-3 { grid-template-columns: 1fr; } .zv-page-header { flex-direction: column; } }
</style>
