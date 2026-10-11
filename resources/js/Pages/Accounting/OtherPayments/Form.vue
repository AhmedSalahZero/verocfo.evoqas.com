<!--
══════════════════════════════════════════════════════════════════
  Accounting/OtherPayments/Form.vue

  Other Payment — see VeroCFO_Other_Payment_Design_v1_0.md. Money
  paid to a Shareholder / Employee / Sister Company / Customer
  (refund) / Other partner. Payable-side mirror of Other Collection's
  Form.vue — Money Type has only THREE values (Cash Payment /
  Outgoing Transfer / Payable Cheque, same as Supplier Payment), and
  the manual account picker is the DEBIT side here (no AP to
  auto-resolve to) instead of the credit side.

  POSTS IMMEDIATELY ON SAVE — no draft stage on create, same posture
  as Supplier Payment. "Set to Draft" is the only way back to an
  editable state.

  @layout    AppLayout
  @route     accounting.other-payments.create / .edit
  @props     partnerTypes, currencies, functionalCurrencyId,
             otherPayment, periodClosed
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.otherPayments.form.otherPayment'), href: route('accounting.other-payments.index') }, { label: isEdit ? otherPayment.payment_code : t('accounting.otherPayments.form.new') }]"
      :title="isEdit ? otherPayment.payment_code : t('accounting.otherPayments.form.newOtherPayment')"
      :subtitle="t('accounting.otherPayments.form.recordsMoneyPaidToANonSupplier')"
    />

    <div v-if="isEdit && otherPayment.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.otherPayments.form.thisPaymentIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.otherPayments.form.thisPaymentIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && otherPayment.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.otherPayments.form.thisPaymentWasCancelledItCannot') }}
    </div>
    <div v-else-if="isEdit && otherPayment.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.otherPayments.form.thisPaymentWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        <div>
          <div>{{ t('accounting.otherPayments.form.pleaseFixTheFollowingBeforeSaving') }}</div>
          <ul style="margin: 4px 0 0 18px; list-style: disc;">
            <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
          </ul>
        </div>
      </div>

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Debit Side ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.otherPayments.form.moneyPaid') }}</div>
            <div class="form-section-sub">{{ t('accounting.otherPayments.form.whoWasPaidInWhatCurrency') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3-3-6">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.paymentDate') }}</label>
              <input v-model="form.payment_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.payment_date }"/>
              <p v-if="form.errors.payment_date" class="zv-field-error">{{ form.errors.payment_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.partnerType') }}</label>
              <SelectBox v-model="form.partner_type" :options="partnerTypes" :value-fn="pt => pt" :label-fn="pt => partnerTypeLabel(pt)" :placeholder="t('accounting.otherPayments.form.selectPartnerType')" :clearable="false" @change="onPartnerTypeChange" :class="{ 'zv-input-error': form.errors.partner_type }" />
              <p v-if="form.errors.partner_type" class="zv-field-error">{{ form.errors.partner_type }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.partnerName') }}</label>
              <SelectBox v-model="form.partner_id" :options="partners" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="form.partner_type ? t('accounting.otherPayments.form.selectPartner') : t('accounting.otherPayments.form.selectPartnerTypeFirst')" :clearable="false" :disabled="!form.partner_type" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>
          </div>

          <!-- Advance Type (Employee only) + Currency + Debit Account —
               one row. Column count shifts: 3 columns when Advance Type
               is showing (Employee), 2 otherwise. Debit Account moved up
               from its own section so all three sit together. -->
          <div class="field-grid mt-4" :class="form.partner_type === 'employee' ? 'field-grid-3-3-6' : 'field-grid-3-3-6'">
            <div class="field-group" v-if="form.partner_type === 'employee'">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.advanceType') }}</label>
              <SelectBox v-model="form.employee_advance_type" :options="employeeAdvanceTypes" value-key="value" label-key="label" :placeholder="t('accounting.otherPayments.form.selectAdvanceType')" :clearable="false" @change="onEmployeeAdvanceTypeChange" :class="{ 'zv-input-error': form.errors.employee_advance_type }" />
              <p v-if="form.errors.employee_advance_type" class="zv-field-error">{{ form.errors.employee_advance_type }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.currency') }}</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :class="{ 'zv-input-error': form.errors.currency_id }" />
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.debitAccount') }}</label>
              <div v-if="!form.partner_type || !form.partner_id" class="text-sm opacity-50" style="padding: 8px 0;">
                {{ t('accounting.otherPayments.form.selectPartnerAndTypeFirst') }}
              </div>
              <div v-else-if="form.partner_type === 'employee' && !form.employee_advance_type" class="text-sm opacity-50" style="padding: 8px 0;">
                {{ t('accounting.otherPayments.form.selectAdvanceTypeFirst') }}
              </div>
              <template v-else>
                <SearchableSelect
                  v-model="form.debit_account_id"
                  v-model:label="debitAccountLabel"
                  :search-url="route('accounting.other-payments.debit-accounts', debitAccountClass ? { class: debitAccountClass } : {})"
                  :placeholder="t('accounting.otherPayments.form.searchAccountCodeOrName')"
                  :min-chars="0"
                />
                <p v-if="form.errors.debit_account_id" class="zv-field-error">{{ form.errors.debit_account_id }}</p>
                <p v-else-if="prefillAccountApplied" class="field-hint">{{ t('accounting.otherPayments.form.prefilledFromWhereYouCameFrom') }}</p>
                <p v-else-if="suggestedAccountApplied" class="field-hint">{{ t('accounting.otherPayments.form.suggestedForThisPartnerType') }}</p>
                <p v-else-if="form.employee_advance_type === 'per_diem'" class="field-hint">{{ t('accounting.otherPayments.form.perDiemIsAnOutrightExpensePick') }}</p>
                <p v-else-if="form.employee_advance_type === 'reimbursement'" class="field-hint">{{ t('accounting.otherPayments.form.reimbursementIsAnOutrightExpensePick') }}</p>
              </template>
            </div>
          </div>

          <!-- Money Type tiles — only THREE values, same as Supplier Payment -->
          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.moneyType') }}</label>
            <OptionTiles
              :model-value="form.money_type" variant="icon" :columns="3"
              :options="[
                { value: 'cash_payment', label: t('accounting.otherPayments.form.cashPayment'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z\'/>' },
                { value: 'outgoing_transfer', label: t('accounting.otherPayments.form.outgoingTransfer'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M16 17l4-4m0 0l-4-4m4 4H4m12 6H4m0 0l4 4m-4-4l4-4\'/>' },
                { value: 'payable_cheque', label: t('accounting.otherPayments.form.payableCheque'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01\'/>' },
              ]"
              @update:model-value="setMoneyType"
            />
          </div>
        </div>
      </div>

      <!-- ══════════════════ Money Type block ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ moneyTypeBlockTitle }}</div>
            <div class="form-section-sub">{{ t('accounting.otherPayments.form.exactlyWhereThisMoneyLeftFrom') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Outgoing Transfer / Payable Cheque — shared source picker -->
          <template v-if="form.money_type === 'outgoing_transfer' || form.money_type === 'payable_cheque'">
            <div class="field-grid field-grid-3-5-2-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.accountType') }}</label>
                <SelectBox v-model="form.source_type" :options="[{ value: 'bank_account', label: t('accounting.otherPayments.form.currentAccount') }, { value: 'credit_facility', label: t('accounting.otherPayments.form.overdraftFacility') }]" @change="form.source_bank_account_id = ''; form.source_credit_facility_id = ''" :class="{ 'zv-input-error': form.errors.source_type }" />
                <p v-if="form.errors.source_type" class="zv-field-error">{{ form.errors.source_type }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.accountNumber') }}</label>
                <SelectBox v-model="form.source_bank_account_id" :options="bankAccountSources" value-key="id" :label-fn="s => s.label" :placeholder="t('accounting.otherPayments.form.selectAccount')" :clearable="false" v-if="form.source_type === 'bank_account'" :class="{ 'zv-input-error': form.errors.source_bank_account_id }" />
                <SelectBox v-model="form.source_credit_facility_id" :options="creditFacilitySources" value-key="id" :label-fn="s => s.label" :placeholder="t('accounting.otherPayments.form.selectFacility')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.source_credit_facility_id }" />
                <p v-if="form.errors.source_bank_account_id" class="zv-field-error">{{ form.errors.source_bank_account_id }}</p>
                <p v-if="form.errors.source_credit_facility_id" class="zv-field-error">{{ form.errors.source_credit_facility_id }}</p>
              </div>
            
            
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }} [{{ currencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
                <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.otherPayments.form.referenceNumber') }}</label>
                <input v-model="form.reference_number" type="text" class="zv-input" :placeholder="t('accounting.otherPayments.form.transferRef')"/>
              </div>
            </div>

            <template v-if="form.money_type === 'payable_cheque'">
              <div class="field-grid field-grid-4 mt-4">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.dueDate') }}</label>
                  <input v-model="form.cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.due_date'] }"/>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.chequeNumber') }}</label>
                  <input v-model="form.cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.cheque_number'] }"/>
                </div>
              </div>
              <div class="zv-alert zv-alert-info mt-4">
                {{ t('accounting.otherPayments.form.issuingAChequeRecordsThisPayment') }}
              </div>
            </template>
          </template>

          <!-- Cash Payment -->
          <template v-else-if="form.money_type === 'cash_payment'">
            <div class="field-grid field-grid-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.payingBranch') }}</label>
                <SelectBox v-model="form.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.otherPayments.form.selectSafe')" :clearable="false" :class="{ 'zv-input-error': form.errors.safe_account_id }" />
                <p v-if="form.errors.safe_account_id" class="zv-field-error">{{ form.errors.safe_account_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherPayments.form.paidAmount') }} [{{ currencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.otherPayments.form.receiptNumber') }}</label>
                <input v-model="form.receipt_number" type="text" class="zv-input"/>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- ══════════════════ Comment ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.otherPayments.form.comment') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && otherPayment.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.otherPayments.form.journalEntry') }} — {{ otherPayment.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.otherPayments.form.account') }}</th><th class="zv-table-num">{{ t('accounting.otherPayments.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.otherPayments.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in otherPayment.journal_entry.lines" :key="line.id">
                <td><span class="zv-code">{{ line.chart_of_account?.code }}</span><span class="zv-account-name">{{ line.chart_of_account?.name_en }}</span></td>
                <td class="zv-table-num">{{ fmt(line.debit) }}</td>
                <td class="zv-table-num">{{ fmt(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.other-payments.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.otherPayments.form.close') }}</Link>

          <button
            v-if="isEdit && otherPayment.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.otherPayments.form.setToDraft') }}</button>

          <button
            v-if="isEdit && otherPayment.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('accounting.otherPayments.form.deleting') : t('common.delete') }}</button>

          <button
            v-if="!isEdit || otherPayment.status === 'draft'"
            type="submit" :disabled="form.processing || !canSubmit" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('accounting.otherPayments.form.saveRePost') : t('accounting.otherPayments.form.save')) }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { todayLocal } from '@/Utils/dates'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'
import SearchableSelect from '@/Components/SearchableSelect.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  partnerTypes:          { type: Array, default: () => [] },
  employeeAdvanceTypes:  { type: Array, default: () => [] },
  currencies:            { type: Array, default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  otherPayment:          { type: Object, default: null },
  periodClosed:          { type: Boolean, default: false },
  prefill:               { type: Object, default: () => ({}) },
  prefillDebitAccountLabel: { type: String, default: null },
})

const isEdit = computed(() => !!props.otherPayment?.id)
const isReadOnly = computed(() => isEdit.value && props.otherPayment.status !== 'draft')

const p = props.otherPayment

const form = useForm({
  partner_id: p?.partner_id ?? props.prefill?.partner_id ?? '',
  partner_type: p?.partner_type ?? props.prefill?.partner_type ?? '',
  employee_advance_type: p?.employee_advance_type ?? props.prefill?.employee_advance_type ?? '',
  payment_date: p?.payment_date ?? todayLocal(),
  money_type: p?.money_type ?? 'outgoing_transfer',
  reference_number: p?.reference_number ?? '',

  currency_id: p?.currency_id ?? props.functionalCurrencyId ?? '',
  exchange_rate: p?.exchange_rate ?? '1',
  amount: p?.amount ?? '',

  source_type: p?.source_type ?? 'bank_account',
  source_bank_account_id: p?.source_bank_account_id ?? '',
  source_credit_facility_id: p?.source_credit_facility_id ?? '',

  safe_account_id: p?.safe_account_id ?? '',
  receipt_number: p?.receipt_number ?? '',

  debit_account_id: p?.debit_account_id ?? props.prefill?.debit_account_id ?? '',

  cheque: {
    cheque_number: p?.cheque?.cheque_number ?? '',
    due_date: p?.cheque?.due_date ?? '',
  },

  internal_notes: p?.internal_notes ?? '',
})

const debitAccountLabel = ref(p?.debit_account ? `${p.debit_account.code} — ${p.debit_account.name_en}` : (props.prefillDebitAccountLabel ?? ''))
const suggestedAccountApplied = ref(false)
// Distinct from suggestedAccountApplied — this account didn't come
// from the partner-type default, it was passed in directly via a
// deep link (e.g. Government Remittance's "Remit Tax" button), so it
// gets its own, more accurate hint text.
const prefillAccountApplied = ref(!!props.prefillDebitAccountLabel)

// Per Diem and Reimbursement are outright expenses — search accounts
// with class=expenses specifically (NOT section=income_statement,
// which would also include Revenue accounts — see
// OtherPaymentController::debitAccounts() doc block for the bug this
// fixes). Everyone else (Custody, Advance from Salary) keeps
// searching Balance Sheet, same as before this feature existed.
const debitAccountClass = computed(() => ['per_diem', 'reimbursement'].includes(form.employee_advance_type) ? 'expenses' : null)

function setMoneyType(type) {
  if (isReadOnly.value) return
  form.money_type = type
}

const moneyTypeBlockTitle = computed(() => {
  const map = {
    cash_payment: t('accounting.otherPayments.form.cashInformation'),
    outgoing_transfer: t('accounting.otherPayments.form.outgoingTransferInformation'),
    payable_cheque: t('accounting.otherPayments.form.payableChequeInformation'),
  }
  return map[form.money_type] ?? ''
})

const currencyCode = computed(() => props.currencies.find(c => c.id == form.currency_id)?.code ?? '')

function partnerTypeLabel(type) {
  const map = {
    shareholder: t('accounting.otherPayments.form.shareholder'),
    employee: t('accounting.otherPayments.form.employee'),
    sister_company: t('accounting.otherPayments.form.sisterCompany'),
    customer: t('accounting.otherPayments.form.customer'),
    other: t('accounting.otherPayments.form.other'),
  }
  return map[type] ?? type
}

// ── Sources (Current Account or Overdraft Facility) ─────────

const allSources = ref([])
async function fetchSources() {
  const res = await fetch(route('accounting.other-payments.sources'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allSources.value = data.sources ?? []
}
fetchSources()

const bankAccountSources = computed(() => allSources.value.filter(s => s.type === 'bank_account'))
const creditFacilitySources = computed(() => allSources.value.filter(s => s.type === 'credit_facility'))

// ── Safe accounts (131xxx) ────────────────────────────────────────

const safeAccounts = ref([])
async function fetchSafeAccounts() {
  const res = await fetch(route('accounting.other-payments.safe-accounts'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  safeAccounts.value = data.accounts ?? []
}
fetchSafeAccounts()

// ── Partners filtered by Partner Type ─────────────────────────────

const partners = ref(p?.partner ? [p.partner] : [])
// Only the answer to the LATEST request may fill the list — a slow reply for a
// previously chosen Partner Type must not overwrite the current one.
let partnersReq = 0
async function fetchPartners() {
  const myReq = ++partnersReq
  if (! form.partner_type) { partners.value = []; return }
  const res = await fetch(route('accounting.other-payments.partners', { partner_type: form.partner_type }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  if (myReq !== partnersReq) return
  partners.value = data.partners ?? []
}

function onPartnerTypeChange() {
  form.partner_id = ''
  form.employee_advance_type = ''
  form.debit_account_id = ''
  debitAccountLabel.value = ''
  fetchPartners()
  // Non-employee types can resolve their default immediately — employee
  // waits for the Advance Type selector (see onEmployeeAdvanceTypeChange).
  if (form.partner_type !== 'employee') fetchDefaultDebitAccount()
}

function onEmployeeAdvanceTypeChange() {
  form.debit_account_id = ''
  debitAccountLabel.value = ''
  fetchDefaultDebitAccount()
}

function onPartnerChange() {
  // Nothing to fetch — the partner_type-driven suggestion already
  // covers the debit account.
}

if (isEdit.value && form.partner_type) {
  fetchPartners()
}

// Arrived via a deep link (e.g. Advances & Settlements "Give New
// Advance") with partner_type/partner_id/employee_advance_type
// already set — fetch the matching partner list and resolve the
// default account, same as if the user had just picked them.
if (!isEdit.value && props.prefill?.partner_type) {
  fetchPartners()
  fetchDefaultDebitAccount()
}

// ── Default debit account suggestion (user can always override) ──

async function fetchDefaultDebitAccount() {
  if (! form.partner_type || form.debit_account_id) return
  if (form.partner_type === 'employee' && ! form.employee_advance_type) return
  const res = await fetch(route('accounting.other-payments.default-debit-account', { partner_type: form.partner_type, employee_advance_type: form.employee_advance_type || undefined }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  if (data.account) {
    form.debit_account_id = data.account.id
    debitAccountLabel.value = `${data.account.code} — ${data.account.name_en}`
    suggestedAccountApplied.value = true
  }
}

watch(() => form.debit_account_id, (val) => {
  if (! val) { suggestedAccountApplied.value = false; prefillAccountApplied.value = false }
})

// ── Submit ─────────────────────────────────────────────────────────

const canSubmit = computed(() => !!form.partner_id && !!form.partner_type && !!form.money_type && !!form.amount && !!form.debit_account_id && (form.partner_type !== 'employee' || !!form.employee_advance_type))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  // Blanking of the unused destination/source and cheque fields is done on
  // the OUTGOING copy only (form.transform). Mutating the form itself used to
  // wipe the user's cheque / bank choices on screen when the server rejected
  // the save and the user then switched Money Type back.
  form.transform(data => {
    const d = { ...data }
    if (d.money_type === 'cash_payment') {
      d.source_type = null; d.source_bank_account_id = ''; d.source_credit_facility_id = ''
    }
    if (d.money_type !== 'payable_cheque') d.cheque = { cheque_number: null, due_date: null }
    return d
  })

  if (isEdit.value) {
    form.put(route('accounting.other-payments.update', props.otherPayment.id))
  } else {
    form.post(route('accounting.other-payments.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.otherPayments.form.reopenThisPaymentForEditingThis'))) return

  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.other-payments.set-to-draft', props.otherPayment.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.otherPayments.form.deleteThisPaymentThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.other-payments.destroy', props.otherPayment.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>