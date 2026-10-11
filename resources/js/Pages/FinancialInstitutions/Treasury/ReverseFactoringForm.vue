<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/ReverseFactoringForm.vue

  Create form for a Reverse Factoring disbursement transaction — a
  Supplier Bill paid early by a factoring company on the company's
  behalf (COA doc Section 8.3). See ReverseFactoringTransactionService
  header for the full posting rule.

  Cascade: Factoring Date → Factoring Company → Factoring Agreement
  (reverse type, active, carries the discount rate AND
  extended_payment_days — "Reverse Due Days") → Supplier (locked to
  the agreement's Eligible Customer if one is set) → open, unfactored
  Bill → live calculation, including a SUGGESTED Reverse Due Date
  (Bill Date + the agreement's extended_payment_days) that the user
  can freely override, per Mahmoud's explicit request.

  Settlement — chosen at creation, two mutually exclusive paths:
    Due Date  — liability stays open, settled later from the list page.
    Cheques   — a repeater of payable cheques (cheque_number, due_date,
                cheque_amount — deliberately NO reference number field,
                "has no logic" per Mahmoud, same fields as Supplier
                Payment's own cheque, minus that one, plus amount
                since several cheques can split one liability) that
                must sum to EXACTLY the Reverse Factoring Amount —
                enforced server-side (StoreReverseFactoringTransactionRequest
                / ReverseFactoringTransactionService), never trusted
                from the client alone.

  @layout    AppLayout
  @route     fi.reverse-factoring.create
  @props     factoringCompanies, bankAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.treasury.index')">{{ t('financialInstitutions.treasury.reverseFactoringForm.treasuryOperations') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <Link :href="route('fi.reverse-factoring.index')">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoring') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('financialInstitutions.treasury.reverseFactoringForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoring') }}</h1>
                </div>

                <div v-if="calc.reverse_factoring_amount" class="rf-summary-panel">
                    <div class="rf-summary-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringAmount') }}</div>
                    <div class="rf-summary-value">{{ fmt(calc.reverse_factoring_amount) }}</div>
                </div>
            </div>

            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                {{ t('financialInstitutions.treasury.reverseFactoringForm.pleaseFixTheErrorsBelowBefore') }}
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════ Section 1 — Agreement & Bill ══════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.agreementBill') }}</div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringDate') }}</label>
                                <input v-model="reverseFactoringDate" type="date" class="zv-input" @change="recalculate"/>
                                <p v-if="form.errors.reverse_factoring_date" class="zv-field-error">{{ form.errors.reverse_factoring_date }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.factoringCompany') }}</label>
                                <select v-model="factoringCompanyId" class="zv-select" @change="onFactoringCompanyChange">
                                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringForm.select') }}</option>
                                    <option v-for="c in factoringCompanies" :key="c.id" :value="c.id">{{ c.name_en }}</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.factoringAgreement') }}</label>
                                <select v-model="form.factoring_agreement_id" class="zv-select" :disabled="!factoringCompanyId" @change="onAgreementChange">
                                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringForm.select') }}</option>
                                    <option v-for="a in agreements" :key="a.id" :value="a.id">{{ a.agreement_reference || ('#' + a.id) }} — {{ Number(a.discount_rate).toFixed(2) }}%</option>
                                </select>
                                <p v-if="form.errors.factoring_agreement_id" class="zv-field-error">{{ form.errors.factoring_agreement_id }}</p>
                                <p v-if="selectedAgreement" class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseDueDays') }}: {{ selectedAgreement.extended_payment_days ?? 0 }}</p>
                            </div>
                        </div>

                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.supplier') }}</label>
                                <select v-model="partnerId" class="zv-select" :disabled="!form.factoring_agreement_id || supplierLocked" @change="onSupplierChange">
                                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringForm.select') }}</option>
                                    <option v-for="p in suppliers" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                                </select>
                                <p v-if="supplierLocked" class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.thisAgreementIsRestrictedToThis') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.bill') }}</label>
                                <select v-model="form.supplier_bill_id" class="zv-select" :disabled="!partnerId" @change="onBillChange">
                                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringForm.select') }}</option>
                                    <option v-for="b in bills" :key="b.id" :value="b.id">{{ b.bill_code }} — {{ fmt(b.bill_value_functional) }}</option>
                                </select>
                                <p v-if="form.errors.supplier_bill_id" class="zv-field-error">{{ form.errors.supplier_bill_id }}</p>
                                <p v-if="partnerId && bills.length === 0" class="zv-field-error">{{ t('financialInstitutions.treasury.reverseFactoringForm.noOpenUnfactoredBillsForThis') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.billAmount') }}</label>
                                <div class="zv-readonly-field">{{ calc.bill_amount != null ? fmt(calc.bill_amount) : '—' }}</div>
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
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.calculation') }}</div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringPercentage') }}</label>
                                <input v-model="form.reverse_factoring_percentage" type="number" step="0.01" min="0.01" max="100" class="zv-input text-end" @input="recalculate"/>
                                <p v-if="form.errors.reverse_factoring_percentage" class="zv-field-error">{{ form.errors.reverse_factoring_percentage }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringAmount') }}</label>
                                <div class="zv-readonly-field">{{ calc.reverse_factoring_amount != null ? fmt(calc.reverse_factoring_amount) : '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseDueDate') }}</label>
                                <input v-model="form.reverse_due_date" type="date" class="zv-input" @change="onReverseDueDateOverride"/>
                                <p v-if="form.errors.reverse_due_date" class="zv-field-error">{{ form.errors.reverse_due_date }}</p>
                                <p class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.suggestedFromTheAgreementSOwnReverse') }}</p>
                            </div>
                        </div>
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.contractInterestRate') }}</label>
                                <div class="zv-readonly-field">{{ calc.contract_interest_rate != null ? Number(calc.contract_interest_rate).toFixed(4) + '%' : '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.diffInDays') }}</label>
                                <div class="zv-readonly-field">{{ calc.diff_in_days ?? '—' }}</div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringInterestAmount') }}</label>
                                <div class="zv-readonly-field text-end">{{ calc.reverse_factoring_interest_amount != null ? fmt(calc.reverse_factoring_interest_amount) : '—' }}</div>
                                <p class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.interestCalculatedFromAgreement') }}</p>
                                <p v-if="form.errors.reverse_factoring_interest_amount" class="zv-field-error">{{ form.errors.reverse_factoring_interest_amount }}</p>
                            </div>
                        </div>
                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.reverseFactoringCharges') }}</label>
                                <input v-model="form.reverse_factoring_charges" type="number" step="0.01" min="0" class="zv-input text-end"/>
                            </div>
                            <div class="field-group field-group-span-2">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.apClearAmount') }}</label>
                                <div class="zv-readonly-field rf-ap-highlight">{{ fmt(previewApClearAmount) }}</div>
                                <p class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.autoCalculatedWhatActuallyReachesThe') }}</p>
                                <label class="zv-label mt-3">{{ t('financialInstitutions.treasury.reverseFactoringForm.totalRepayable') }}</label>
                                <div class="zv-readonly-field">{{ fmt(previewTotalRepayable) }}</div>
                                <p class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringForm.totalRepayableHint') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════ Section 3 — Settlement ══════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#1490A8">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 6l7-3 7 3M5 10v8m4-8v8m6-8v8m4-8v8M3 21h18"/>
                            </svg>
                        </div>
                        <div class="form-section-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.settlement') }}</div>
                    </div>
                    <div class="form-section-body">

                        <div class="rf-method-tiles mb-5">
                            <button type="button" class="rf-method-tile" :class="{ 'rf-method-tile-active': form.settlement_method === 'due_date' }" @click="form.settlement_method = 'due_date'">
                                <div class="rf-method-tile-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.settleAtDueDate') }}</div>
                                <div class="rf-method-tile-desc">{{ t('financialInstitutions.treasury.reverseFactoringForm.leaveTheLiabilityOpenSettleIt') }}</div>
                            </button>
                            <button type="button" class="rf-method-tile" :class="{ 'rf-method-tile-active': form.settlement_method === 'cheques' }" @click="form.settlement_method = 'cheques'">
                                <div class="rf-method-tile-title">{{ t('financialInstitutions.treasury.reverseFactoringForm.issuePayableCheques') }}</div>
                                <div class="rf-method-tile-desc">{{ t('financialInstitutions.treasury.reverseFactoringForm.coverTheLiabilityNowWithOneOr') }}</div>
                            </button>
                        </div>

                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ form.settlement_method === 'cheques' ? t('financialInstitutions.treasury.reverseFactoringForm.chequesDrawnFromAccount') : t('financialInstitutions.treasury.reverseFactoringForm.settlementBankAccount') }}</label>
                                <select v-model="form.source_bank_account_id" class="zv-select">
                                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringForm.select') }}</option>
                                    <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }}</option>
                                </select>
                                <p v-if="form.errors.source_bank_account_id" class="zv-field-error">{{ form.errors.source_bank_account_id }}</p>
                            </div>
                            <div class="field-group field-group-span-2">
                                <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringForm.notes') }}</label>
                                <input v-model="form.notes" type="text" class="zv-input"/>
                            </div>
                        </div>

                        <!-- ── Cheques repeater ── -->
                        <div v-if="form.settlement_method === 'cheques'">
                            <div class="field-divider mb-4">{{ t('financialInstitutions.treasury.reverseFactoringForm.payableCheques') }}</div>

                            <div v-for="(cheque, idx) in form.cheques" :key="idx" class="rf-cheque-row mb-3">
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.chequeNumber') }}</label>
                                    <input v-model="cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors[`cheques.${idx}.cheque_number`] }"/>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.dueDate') }}</label>
                                    <input v-model="cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors[`cheques.${idx}.due_date`] }"/>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringForm.amount') }}</label>
                                    <input v-model="cheque.cheque_amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors[`cheques.${idx}.cheque_amount`] }"/>
                                </div>
                                <button type="button" class="rf-cheque-remove" :title="t('financialInstitutions.treasury.reverseFactoringForm.removeCheque')" @click="removeCheque(idx)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <button type="button" class="zv-btn zv-btn-secondary mb-4" @click="addCheque">
                                {{ t('financialInstitutions.treasury.reverseFactoringForm.addCheque') }}
                            </button>

                            <div class="rf-cheque-total" :class="{ 'rf-cheque-total-mismatch': !chequesBalance }">
                                {{ t('financialInstitutions.treasury.reverseFactoringForm.chequesTotal') }}: {{ fmt(chequesTotal) }}
                                / {{ t('financialInstitutions.treasury.reverseFactoringForm.required') }}: {{ fmt(calc.total_repayable || 0) }}
                                <span v-if="!chequesBalance">— {{ t('financialInstitutions.treasury.reverseFactoringForm.mustMatchExactly') }}</span>
                            </div>
                            <p v-if="form.errors.cheques" class="zv-field-error mt-2">{{ form.errors.cheques }}</p>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════ Form Actions ══════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route('fi.reverse-factoring.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
                        <button type="submit" :disabled="form.processing || (form.settlement_method === 'cheques' && !chequesBalance)" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : t('financialInstitutions.treasury.reverseFactoringForm.disburse') }}
                        </button>
                    </div>
                </div>

            </form>
        </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sub as moneySub, sum as sumMoney } from '@/Utils/money'
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
    factoring_agreement_id:            '',
    supplier_bill_id:                  '',
    reverse_factoring_date:            todayLocal(),
    reverse_due_date:                  '',
    reverse_factoring_percentage:      '',
    reverse_factoring_interest_amount: '',
    reverse_factoring_charges:         0,
    settlement_method:                 'due_date',
    source_bank_account_id:            '',
    notes:                             '',
    cheques:                           [],
})

const reverseFactoringDate = computed({
    get: () => form.reverse_factoring_date,
    set: (v) => { form.reverse_factoring_date = v },
})

// ── Cascade state ────────────────────────────────────────────────
const factoringCompanyId = ref('')
const agreements = ref([])
const suppliers = ref([])
const bills = ref([])
const partnerId = ref('')
const supplierLocked = ref(false)

const selectedAgreement = computed(() => agreements.value.find(a => String(a.id) === String(form.factoring_agreement_id)) || null)

// Whether the user has manually typed a Reverse Due Date — once they
// have, recalculate() stops overwriting it with the suggested value.
const dueDateTouched = ref(false)
function onReverseDueDateOverride() { dueDateTouched.value = true; recalculate() }

async function onFactoringCompanyChange() {
    const seq = ++agreementsReq
    ++partiesReq; ++listReq
    agreements.value = []
    form.factoring_agreement_id = ''
    partnerId.value = ''
    supplierLocked.value = false
    suppliers.value = []
    bills.value = []
    form.supplier_bill_id = ''
    resetCalc()
    const found = factoringCompanyId.value
        ? (await axios.get(route('fi.reverse-factoring.agreements', factoringCompanyId.value))).data
        : []
    if (seq !== agreementsReq) return
    agreements.value = found
}

async function onAgreementChange() {
    const seq = ++partiesReq
    ++listReq
    partnerId.value = ''
    supplierLocked.value = false
    bills.value = []
    form.supplier_bill_id = ''
    dueDateTouched.value = false
    form.reverse_due_date = ''
    resetCalc()

    if (!form.factoring_agreement_id) { suppliers.value = []; return }

    const loaded = (await axios.get(route('fi.reverse-factoring.suppliers', form.factoring_agreement_id))).data
    if (seq !== partiesReq) return
    suppliers.value = loaded

    if (selectedAgreement.value?.eligible_partner_id) {
        partnerId.value = selectedAgreement.value.eligible_partner_id
        supplierLocked.value = true
        await onSupplierChange()
    }
}

async function onSupplierChange() {
    const seq = ++listReq
    bills.value = []
    form.supplier_bill_id = ''
    resetCalc()
    const found = partnerId.value
        ? (await axios.get(route('fi.reverse-factoring.bills', partnerId.value))).data
        : []
    if (seq !== listReq) return
    bills.value = found
}

function onBillChange() {
    dueDateTouched.value = false
    recalculate()
}

// ── Live calculation ────────────────────────────────────────────
const calc = ref({})
function resetCalc() { ++calcReq; calc.value = {} }

// A slow reply for an earlier selection or an earlier keystroke must never overwrite
// the screen after a newer one has been asked for — every lookup carries a ticket number.
let agreementsReq = 0, partiesReq = 0, listReq = 0, calcReq = 0

async function recalculate() {
    if (!form.supplier_bill_id || !form.factoring_agreement_id || !form.reverse_factoring_percentage) {
        // Something needed for the figures was cleared — do not leave the old figures on screen.
        resetCalc()
        form.reverse_factoring_interest_amount = ''
        return
    }
    const seq = ++calcReq

    const { data } = await axios.post(route('fi.reverse-factoring.calculate'), {
        supplier_bill_id: form.supplier_bill_id,
        factoring_agreement_id: form.factoring_agreement_id,
        reverse_factoring_percentage: form.reverse_factoring_percentage,
        reverse_factoring_charges: form.reverse_factoring_charges || 0,
        reverse_factoring_date: form.reverse_factoring_date,
        reverse_due_date: dueDateTouched.value ? form.reverse_due_date : null,
    })

    if (seq !== calcReq) return // a newer request was made while this one was travelling
    calc.value = data
    // H-20 — interest is always the system-calculated figure (read-only on screen).
    form.reverse_factoring_interest_amount = data.reverse_factoring_interest_amount
    if (! dueDateTouched.value) {
        form.reverse_due_date = data.suggested_reverse_due_date
    }
}

watch(() => form.reverse_factoring_charges, recalculate)

// H-20 — the supplier's AP is cleared by the whole advance; the company
// then owes the factoring company advance + interest + charges.
const previewApClearAmount = computed(() => calc.value.ap_clear_amount || '0.00')
const previewTotalRepayable = computed(() => calc.value.total_repayable || '0.00')

function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Cheques repeater ────────────────────────────────────────────
function addCheque() {
    form.cheques.push({ cheque_number: '', due_date: '', cheque_amount: '' })
}
function removeCheque(idx) {
    form.cheques.splice(idx, 1)
}

// Audit L6: exact decimal sum
const chequesTotal = computed(() => Number(sumMoney(form.cheques.map(c => c.cheque_amount))))
const chequesBalance = computed(() => {
    if (form.settlement_method !== 'cheques') return true
    const gap = moneySub(chequesTotal.value, calc.value.total_repayable || 0)
    const abs = gap.startsWith('-') ? gap.slice(1) : gap
    return form.cheques.length > 0 && Number(abs) < 0.01
})

watch(() => form.settlement_method, (method) => {
    if (method === 'cheques' && form.cheques.length === 0) addCheque()
})

function submit() {
    form.post(route('fi.reverse-factoring.store'))
}
</script>

<style>
.rf-summary-panel {
    padding: 10px 18px; border-radius: var(--zv-radius-lg);
    background: rgba(184,134,11,0.08); border: 1px solid rgba(184,134,11,0.25);
    text-align: end;
}
.rf-summary-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #b8860b; }
.rf-summary-value { font-size: 20px; font-weight: 800; color: #b8860b; font-variant-numeric: tabular-nums; }

.rf-ap-highlight { font-weight: 800; font-size: 15px; color: #0F2554; font-variant-numeric: tabular-nums; }

.rf-method-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.rf-method-tile {
    text-align: start; padding: 14px 16px; border-radius: var(--zv-radius-lg);
    border: 1.5px solid var(--zv-border); background: var(--zv-bg-surface); cursor: pointer;
}
.rf-method-tile-active { border-color: #1490A8; background: rgba(20,144,168,0.06); }
.rf-method-tile-title { font-weight: 700; font-size: 13.5px; color: var(--zv-text-primary); margin-bottom: 4px; }
.rf-method-tile-desc { font-size: 12px; color: var(--zv-text-muted); line-height: 1.5; }

.rf-cheque-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
.rf-cheque-remove {
    display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px;
    border-radius: 8px; border: none; background: transparent; color: #ef4444; cursor: pointer;
}
.rf-cheque-remove:hover { background: rgba(239,68,68,0.1); }

.rf-cheque-total { font-size: 13px; font-weight: 600; color: var(--zv-text-secondary); padding: 8px 12px; border-radius: 8px; background: var(--zv-bg-hover); }
.rf-cheque-total-mismatch { color: #ef4444; background: rgba(239,68,68,0.08); }

@media (max-width: 900px) {
    .field-grid-2, .field-grid-3 { grid-template-columns: 1fr; }
    .rf-method-tiles { grid-template-columns: 1fr; }
    .rf-cheque-row { grid-template-columns: 1fr; }
}
</style>
