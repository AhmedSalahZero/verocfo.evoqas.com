<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/FactoringWithRecourseForm.vue

  Create form for a Factoring With Recourse disbursement transaction.
  Ported from CashVero's Factoring/Form.vue (recourseType='with_recourse'),
  rebuilt against Vero CFO's real AR (CustomerInvoice) and accounting
  engine — see FactoringTransactionService header for the full
  CashVero → Vero CFO mapping and the posting rule (Chart of Accounts
  doc, Section 8.2).

  Cascade: Factoring Company → Factoring Agreement (direct + with
  recourse + active, carries the discount rate) → Customer (locked
  to the agreement's Eligible Customer if one is set) → open,
  unfactored Invoice → live calculation.

  Received Amount is shown but NEVER submitted — Vero CFO's journal
  engine requires every entry to balance exactly, so it's always
  server-derived from Factoring Amount − Interest − Other Charges
  (see StoreFactoringTransactionRequest).

  Collect / Reject are not built yet — this form only records the
  disbursement leg. Flagged to Mahmoud as a follow-up.

  @layout    AppLayout
  @route     fi.factoring-with-recourse.create
  @props     factoringCompanies, bankAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.treasury.index')">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.treasuryOperations') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <Link :href="route('fi.factoring-with-recourse.index')">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringWithRecourse') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('financialInstitutions.treasury.factoringWithRecourseForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringWithRecourse') }}</h1>
                </div>

                <div v-if="calc.factoring_amount" class="fwr-summary-panel">
                    <div class="fwr-summary-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.receivedAmount') }}</div>
                    <div class="fwr-summary-value">{{ fmt(previewReceivedAmount) }}</div>
                </div>
            </div>

            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                {{ t('financialInstitutions.treasury.factoringWithRecourseForm.pleaseFixTheErrorsBelowBefore') }}
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════ Section 1 — Agreement & Invoice ══════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.agreementInvoice') }}</div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringDate') }}</label>
                                <input v-model="factoringDate" type="date" class="zv-input" @change="recalculate"/>
                                <p v-if="form.errors.factoring_date" class="zv-field-error">{{ form.errors.factoring_date }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringCompany') }}</label>
                                <select v-model="factoringCompanyId" class="zv-select" @change="onFactoringCompanyChange">
                                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.select') }}</option>
                                    <option v-for="c in factoringCompanies" :key="c.id" :value="c.id">{{ c.name_en }}</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringAgreement') }}</label>
                                <select v-model="form.factoring_agreement_id" class="zv-select" :disabled="!factoringCompanyId" @change="onAgreementChange">
                                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.select') }}</option>
                                    <option v-for="a in agreements" :key="a.id" :value="a.id">{{ a.agreement_reference || ('#' + a.id) }} — {{ Number(a.discount_rate).toFixed(2) }}%</option>
                                </select>
                                <p v-if="form.errors.factoring_agreement_id" class="zv-field-error">{{ form.errors.factoring_agreement_id }}</p>
                            </div>
                        </div>

                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.customer') }}</label>
                                <select v-model="customerId" class="zv-select" :disabled="!form.factoring_agreement_id || customerLocked" @change="onCustomerChange">
                                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.select') }}</option>
                                    <option v-for="p in customers" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                                </select>
                                <p v-if="customerLocked" class="field-hint">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.thisAgreementIsRestrictedToThis') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.invoice') }}</label>
                                <select v-model="form.customer_invoice_id" class="zv-select" :disabled="!customerId" @change="onInvoiceChange">
                                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.select') }}</option>
                                    <option v-for="inv in invoices" :key="inv.id" :value="inv.id">{{ inv.invoice_code }} — {{ fmt(inv.invoice_value_functional) }}</option>
                                </select>
                                <p v-if="form.errors.customer_invoice_id" class="zv-field-error">{{ form.errors.customer_invoice_id }}</p>
                                <p v-if="customerId && invoices.length === 0" class="zv-field-error">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.noOpenUnfactoredInvoicesForThis') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.invoiceDueDate') }}</label>
                                <div class="zv-readonly-field">{{ calc.invoice_due_date || '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════ Section 2 — Calculation ══════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#b8860b">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.calculation') }}</div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.invoiceAmount') }}</label>
                                <div class="zv-readonly-field">{{ calc.invoice_amount != null ? fmt(calc.invoice_amount) : '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringPercentage') }}</label>
                                <input v-model="form.factoring_percentage" type="number" step="0.01" min="0.01" max="100" class="zv-input text-end" @input="recalculate"/>
                                <p v-if="form.errors.factoring_percentage" class="zv-field-error">{{ form.errors.factoring_percentage }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringAmount') }}</label>
                                <div class="zv-readonly-field">{{ calc.factoring_amount != null ? fmt(calc.factoring_amount) : '—' }}</div>
                            </div>
                        </div>
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.contractInterestRate') }}</label>
                                <div class="zv-readonly-field">{{ calc.contract_interest_rate != null ? Number(calc.contract_interest_rate).toFixed(4) + '%' : '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.diffInDays') }}</label>
                                <div class="zv-readonly-field">{{ calc.diff_in_days ?? '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.factoringInterestAmount') }}</label>
                                <div class="zv-readonly-field text-end">{{ calc.factoring_interest_amount != null ? fmt(calc.factoring_interest_amount) : '—' }}</div>
                                <p v-if="form.errors.factoring_interest_amount" class="zv-field-error">{{ form.errors.factoring_interest_amount }}</p>
                            </div>
                        </div>
                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.otherCharges') }}</label>
                                <input v-model="form.other_charges" type="number" step="0.01" min="0" class="zv-input text-end"/>
                            </div>
                            <div class="field-group field-group-span-2">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.receivedAmount') }}</label>
                                <div class="zv-readonly-field fwr-received-highlight">{{ fmt(previewReceivedAmount) }}</div>
                                <p class="field-hint">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.autoCalculatedFactoringAmountMinusInterest') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════ Section 3 — Settlement Bank Account ══════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#1490A8">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 6l7-3 7 3M5 10v8m4-8v8m6-8v8m4-8v8M3 21h18"/>
                            </svg>
                        </div>
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.settlementBankAccount') }}</div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.bankAccount') }}</label>
                                <select v-model="form.bank_account_id" class="zv-select">
                                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.select') }}</option>
                                    <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }}</option>
                                </select>
                                <p v-if="form.errors.bank_account_id" class="zv-field-error">{{ form.errors.bank_account_id }}</p>
                            </div>
                            <div class="field-group field-group-span-2">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseForm.notes') }}</label>
                                <input v-model="form.notes" type="text" class="zv-input"/>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════ Form Actions ══════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route('fi.factoring-with-recourse.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
                        <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : t('financialInstitutions.treasury.factoringWithRecourseForm.disburse') }}
                        </button>
                    </div>
                </div>

            </form>
        </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sub as moneySub } from '@/Utils/money'
import { ref, computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

const props = defineProps({
    factoringCompanies: Array,
    bankAccounts:       Array,
})

const form = useForm({
    factoring_agreement_id:    '',
    customer_invoice_id:       '',
    factoring_date:            todayLocal(),
    factoring_percentage:      '',
    factoring_interest_amount: '',
    other_charges:             0,
    bank_account_id:           '',
    notes:                     '',
})

// factoring_date lives outside `form` state-wise only so @change can
// trigger recalculate() the same way the other cascading fields do —
// it's still read from form.factoring_date on submit either way.
const factoringDate = computed({
    get: () => form.factoring_date,
    set: (v) => { form.factoring_date = v },
})

// ── Cascade state ────────────────────────────────────────────────
const factoringCompanyId = ref('')
const agreements = ref([])
const customers = ref([])
const invoices = ref([])
const customerId = ref('')
const customerLocked = ref(false)

const selectedAgreement = computed(() => agreements.value.find(a => String(a.id) === String(form.factoring_agreement_id)) || null)

async function onFactoringCompanyChange() {
    const seq = ++agreementsReq
    ++partiesReq; ++listReq
    agreements.value = []
    form.factoring_agreement_id = ''
    customerId.value = ''
    customerLocked.value = false
    customers.value = []
    invoices.value = []
    form.customer_invoice_id = ''
    resetCalc()
    const found = factoringCompanyId.value
        ? (await axios.get(route('fi.factoring-with-recourse.agreements', factoringCompanyId.value))).data
        : []
    if (seq !== agreementsReq) return
    agreements.value = found
}

async function onAgreementChange() {
    const seq = ++partiesReq
    ++listReq
    customerId.value = ''
    customerLocked.value = false
    invoices.value = []
    form.customer_invoice_id = ''
    resetCalc()

    if (!form.factoring_agreement_id) { customers.value = []; return }

    const loaded = (await axios.get(route('fi.factoring-transactions.customers', form.factoring_agreement_id))).data
    if (seq !== partiesReq) return
    customers.value = loaded

    if (selectedAgreement.value?.eligible_partner_id) {
        customerId.value = selectedAgreement.value.eligible_partner_id
        customerLocked.value = true
        await onCustomerChange()
    }
}

async function onCustomerChange() {
    const seq = ++listReq
    invoices.value = []
    form.customer_invoice_id = ''
    resetCalc()
    const found = customerId.value
        ? (await axios.get(route('fi.factoring-transactions.invoices', customerId.value))).data
        : []
    if (seq !== listReq) return
    invoices.value = found
}

function onInvoiceChange() {
    recalculate()
}

// ── Live calculation ────────────────────────────────────────────
const calc = ref({})
function resetCalc() { ++calcReq; calc.value = {} }

// A slow reply for an earlier selection or an earlier keystroke must never overwrite
// the screen after a newer one has been asked for — every lookup carries a ticket number.
let agreementsReq = 0, partiesReq = 0, listReq = 0, calcReq = 0

async function recalculate() {
    if (!form.customer_invoice_id || !form.factoring_agreement_id || !form.factoring_percentage) {
        // Something needed for the figures was cleared — do not leave the old figures on screen.
        resetCalc()
        form.factoring_interest_amount = ''
        return
    }
    const seq = ++calcReq

    const { data } = await axios.post(route('fi.factoring-transactions.calculate'), {
        customer_invoice_id: form.customer_invoice_id,
        factoring_agreement_id: form.factoring_agreement_id,
        factoring_percentage: form.factoring_percentage,
        other_charges: form.other_charges || 0,
        factoring_date: form.factoring_date,
    })

    if (seq !== calcReq) return // a newer request was made while this one was travelling
    calc.value = data
    // Only seed the suggested interest amount — never overwrite a
    // value the user has already started editing on a later
    // recalculation (e.g. after they touch Other Charges).
    // H-20 — interest is always the system-calculated figure (read-only on screen).
    form.factoring_interest_amount = data.factoring_interest_amount
}

watch(() => form.other_charges, recalculate)

// Received Amount preview — mirrors the server's own derivation
// (FactoringTransactionService::createWithRecourse()) so what the
// user sees here always matches what actually gets posted.
const previewReceivedAmount = computed(() =>
    moneySub(moneySub(calc.value.factoring_amount || 0, form.factoring_interest_amount || 0), form.other_charges || 0)
)

function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
    form.post(route('fi.factoring-with-recourse.store'))
}
</script>

<style>
.fwr-summary-panel {
    padding: 10px 18px; border-radius: var(--zv-radius-lg);
    background: rgba(184,134,11,0.08); border: 1px solid rgba(184,134,11,0.25);
    text-align: end;
}
.fwr-summary-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #b8860b; }
.fwr-summary-value { font-size: 20px; font-weight: 800; color: #b8860b; font-variant-numeric: tabular-nums; }

.fwr-received-highlight { font-weight: 800; font-size: 15px; color: #0F2554; font-variant-numeric: tabular-nums; }

@media (max-width: 900px) {
    .field-grid-2, .field-grid-3 { grid-template-columns: 1fr; }
}
</style>
