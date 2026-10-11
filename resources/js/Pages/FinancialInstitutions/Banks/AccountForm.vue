<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/AccountForm.vue

  Create + Edit for Bank Accounts (Current, Time Deposit, Escrow,
  LG/LC Cash Cover). This file did not exist before — the controller
  referenced it, but it was never built (Open Item: "Bank Accounts —
  still routed through generic page, not dedicated yet").

  CREATE mode: repeater — the user can open several accounts at the
  same bank in one save (e.g. EGP + USD current accounts at once).
  Each row is a full card, since fields are conditional per account
  type (interest fields for Current, td_* fields for Time Deposit).

  EDIT mode: single account, plus a read-only Terms History panel
  showing past interest_rate / minimum_balance_for_interest changes
  (bank_account_terms_history) — banks change these terms over time,
  and every change is logged rather than silently overwritten.

  COA selector: simple dropdown over existing postable 132-series
  accounts. No auto-creation — Chart of Accounts stays under the
  accounting manager's control, as agreed.

  Soft validation note: this form does not block anything about
  functional-currency coverage. That warning lives on the Banks
  index page (per-bank badge), not here.

  @layout    AppLayout
  @route     fi.bank-accounts.create / fi.bank-accounts.edit
  @props     account, bank, banks, currencies, companyBaseCurrencyId, coaAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ══════════════════════════════════════════════════════
                 PAGE HEADER
            ══════════════════════════════════════════════════════ -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.bank-accounts.index')">{{ t('financialInstitutions.banks.accountForm.bankAccounts') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.accountForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">
                        {{ isEdit ? t('financialInstitutions.banks.accountForm.editBankAccount') : t('financialInstitutions.banks.accountForm.newBankAccount') }}
                    </h1>
                    <p v-if="bank" class="zv-page-subtitle">
                        <span class="ba-bank-chip">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                            </svg>
                            {{ bank.name_en }}
                        </span>
                    </p>
                </div>
            </div>

            <!-- Error banner -->
            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span>{{ t('financialInstitutions.banks.accountForm.pleaseFixTheErrorsBelowBefore') }}</span>
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════════════════════════════════════
                     BANK SELECTOR — applies to all accounts in this
                     submission (one bank per save)
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.banks.accountForm.financialInstitution') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.banks.accountForm.theBankTheseAccountSBelong') }}</div>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="field-group" style="max-width: 420px;">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.bank') }}</label>
                            <select v-model="selectedBankId" class="zv-select" :disabled="isEdit">
                                <option value="">{{ t('financialInstitutions.banks.accountForm.selectBank') }}</option>
                                <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     ACCOUNT CARDS (repeater in create mode)
                ══════════════════════════════════════════════════ -->
                <div v-for="(row, idx) in accountRows" :key="idx" class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#b8860b">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H5a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="form-section-title">
                                {{ isEdit ? t('financialInstitutions.banks.accountForm.accountDetails') : t('financialInstitutions.banks.accountForm.account') + ' ' + (idx + 1) }}
                            </div>
                            <div class="form-section-sub">{{ t('financialInstitutions.banks.accountForm.numberCurrencyAccountTypeAndCOA') }}</div>
                        </div>
                        <button v-if="!isEdit && accountRows.length > 1" type="button" class="so-remove-btn" @click="removeAccountRow(idx)" :title="t('financialInstitutions.banks.accountForm.removeThisAccount')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="form-section-body">
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.accountNumber') }}</label>
                                <input v-model="row.account_number" type="text" class="zv-input font-mono"
                                       :class="{ 'zv-input-error': errFor(idx, 'account_number') }"
                                       :placeholder="t('financialInstitutions.banks.accountForm.bankAccountNumber')"/>
                                <p v-if="errFor(idx, 'account_number')" class="zv-field-error">{{ errFor(idx, 'account_number') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.banks.accountForm.accountName') }}</label>
                                <input v-model="row.account_name" type="text" class="zv-input"
                                       :placeholder="t('financialInstitutions.banks.accountForm.eGQNBCurrentEGP')"/>
                                <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.internalLabelDefaultsToBankCurrency') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.currency') }}</label>
                                <select v-model="row.currency_id" class="zv-select"
                                        :class="{ 'zv-input-error': errFor(idx, 'currency_id') }">
                                    <option value="">{{ t('financialInstitutions.banks.accountForm.select') }}</option>
                                    <option v-for="c in currencies" :key="c.id" :value="c.id">
                                        {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.accountForm.functional') + ')' : '' }}
                                    </option>
                                </select>
                                <p v-if="errFor(idx, 'currency_id')" class="zv-field-error">{{ errFor(idx, 'currency_id') }}</p>
                            </div>
                        </div>

                        <!-- Account Type -->
                        <div class="field-group mb-5">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.accountType') }}</label>
                            <OptionTiles
                                :model-value="row.account_type" variant="chip" :columns="3"
                                :options="[
                                    { value: 'current', label: t('financialInstitutions.banks.accountForm.current'), accent: 'teal' },
                                    { value: 'time_deposit', label: t('financialInstitutions.banks.accountForm.timeDeposit'), accent: 'green' },
                                    { value: 'escrow', label: t('financialInstitutions.banks.accountForm.escrow'), accent: 'amber' },
                                ]"
                                @update:model-value="(v) => row.account_type = v"
                            />
                        </div>

                        <!-- COA selector -->
                        <div class="field-group mb-5">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.cOAAccount') }}</label>
                            <select v-model="row.coa_account_id" class="zv-select"
                                    :class="{ 'zv-input-error': errFor(idx, 'coa_account_id') }">
                                <option value="">{{ t('financialInstitutions.banks.accountForm.selectCOAAccount') }}</option>
                                <option v-for="acc in coaAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name_en }}</option>
                            </select>
                            <p v-if="errFor(idx, 'coa_account_id')" class="zv-field-error">{{ errFor(idx, 'coa_account_id') }}</p>
                            <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.selectThe132SubAccountFor') }}</p>
                        </div>

                        <!-- Current account — interest terms -->
                        <div v-if="row.account_type === 'current'" class="field-group">
                            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.accountForm.interestTermsOptional') }}</div>
                            <div class="field-grid field-grid-2">
                                <div class="field-group">
                                    <label class="zv-label">{{ t('financialInstitutions.banks.accountForm.interestRatePa') }}</label>
                                    <input v-model="row.interest_rate" type="number" step="0.0001" min="0" max="100"
                                           class="zv-input text-end" placeholder="0.0000"/>
                                    <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.dailyInterestTheBankPaysOn') }}</p>
                                </div>
                                <div v-if="row.interest_rate" class="field-group">
                                    <label class="zv-label">{{ t('financialInstitutions.banks.accountForm.minimumBalanceForInterest') }}</label>
                                    <div class="zv-input-group">
                                        <span class="zv-input-prefix text-xs font-semibold">{{ currencyCodeFor(row) }}</span>
                                        <input v-model="row.minimum_balance_for_interest" type="number" step="0.01" min="0"
                                               class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                                    </div>
                                    <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.someBanksOnlyPayInterestAbove') }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Time Deposit terms -->
                        <div v-if="row.account_type === 'time_deposit'" class="field-group">
                            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.accountForm.timeDepositTerms') }}</div>

                            <!-- New / Existing -->
                            <div class="field-group mb-4">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.entryType') }}</label>
                                <div style="max-width: 420px;">
                                    <OptionTiles
                                        :model-value="row.td_entry_type" variant="chip" :columns="2"
                                        :options="[
                                            { value: 'new', label: t('financialInstitutions.banks.accountForm.new'), accent: 'green' },
                                            { value: 'existing', label: t('financialInstitutions.banks.accountForm.existing'), accent: 'green' },
                                        ]"
                                        @update:model-value="(v) => row.td_entry_type = v"
                                    />
                                </div>
                                <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.newOpeningTodayRealCashMoves') }}</p>
                            </div>

                            <!-- Funding account — New only -->
                            <div v-if="row.td_entry_type === 'new'" class="field-group mb-4" style="max-width: 420px;">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.deductedFromAccount') }}</label>
                                <select v-model="row.td_funding_bank_account_id" class="zv-select"
                                        :class="{ 'zv-input-error': errFor(idx, 'td_funding_bank_account_id') }">
                                    <option value="">{{ t('financialInstitutions.banks.accountForm.selectCurrentAccount') }}</option>
                                    <option v-for="a in currentAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }} ({{ a.account_number || t('financialInstitutions.banks.accountForm.noNumber') }})</option>
                                </select>
                                <p v-if="errFor(idx, 'td_funding_bank_account_id')" class="zv-field-error">{{ errFor(idx, 'td_funding_bank_account_id') }}</p>
                                <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.theCurrentAccountThisDepositIs') }}</p>
                            </div>

                            <!-- Dates — moved above Amount/Rate -->
                            <div class="field-grid field-grid-4 mb-4">
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
                                    <input v-model="row.td_start_date" type="date" class="zv-input"/>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.maturityDate') }}</label>
                                    <input v-model="row.td_maturity_date" type="date" class="zv-input"/>
                                </div>
                            </div>

                            <div class="field-grid field-grid-4">
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.depositAmount') }}</label>
                                    <input v-model="row.td_amount" type="number" step="0.01" min="0" class="zv-input text-end" placeholder="0.00"/>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountForm.interestRatePa') }}</label>
                                    <input v-model="row.td_interest_rate" type="number" step="0.0001" min="0" max="100" class="zv-input text-end" placeholder="0.0000"/>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label">Tax withheld on interest (%)</label>
                                    <input v-model="row.td_wht_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end" placeholder="20"/>
                                    <p class="field-hint">Egyptian banks withhold 20% of deposit interest. It is booked as a tax credit (Debit WHT).</p>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label">{{ t('financialInstitutions.banks.accountForm.estimatedInterest') }}</label>
                                    <input :value="estimatedInterest(row)" type="text" class="zv-input text-end" readonly disabled/>
                                    <p class="field-hint">{{ t('financialInstitutions.banks.accountForm.365DayBasisInformationalOnly') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add another account (create mode only) -->
                <div v-if="!isEdit" class="mb-5">
                    <button type="button" class="od-add-row-btn" @click="addAccountRow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ t('financialInstitutions.banks.accountForm.addAnotherAccount') }}
                    </button>
                </div>

                <!-- ══════════════════════════════════════════════════
                     TERMS HISTORY — edit mode only
                ══════════════════════════════════════════════════ -->
                <div v-if="isEdit && account?.terms_history?.length" class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#1490A8">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.banks.accountForm.termsHistory') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.banks.accountForm.everyChangeToInterestRateOr') }}</div>
                        </div>
                    </div>
                    <div class="form-section-body" style="padding: 0;">
                        <div class="zv-table-wrap" style="border: none; box-shadow: none;">
                            <table class="zv-table">
                                <thead>
                                    <tr>
                                        <th>{{ t('financialInstitutions.banks.accountForm.date') }}</th>
                                        <th>{{ t('financialInstitutions.banks.accountForm.field') }}</th>
                                        <th>{{ t('financialInstitutions.banks.accountForm.oldValue') }}</th>
                                        <th>{{ t('financialInstitutions.banks.accountForm.newValue') }}</th>
                                        <th>{{ t('financialInstitutions.banks.accountForm.changedBy') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="h in account.terms_history" :key="h.id">
                                        <td>{{ h.effective_date }}</td>
                                        <td>{{ h.field === 'interest_rate' ? t('financialInstitutions.banks.accountForm.interestRate') : t('financialInstitutions.banks.accountForm.minimumBalance') }}</td>
                                        <td>{{ h.old_value ?? '—' }}</td>
                                        <td>{{ h.new_value ?? '—' }}</td>
                                        <td>{{ h.changed_by?.name ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     FORM ACTIONS
                ══════════════════════════════════════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route('fi.bank-accounts.index')" class="zv-btn zv-btn-ghost">
                            {{ t('common.cancel') }}
                        </Link>
                        <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.accountForm.createAccountS')) }}
                        </button>
                    </div>
                </div>

            </form>
        </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
    account:               Object,
    bank:                  Object,
    banks:                 Array,
    currencies:            Array,
    companyBaseCurrencyId: Number,
    currentAccounts:       Array,
    coaAccounts:           Array,
})

const isEdit = computed(() => !!props.account?.id)

function blankRow() {
    return {
        bank_id: '',
        account_number: '',
        account_name: '',
        account_type: 'current',
        currency_id: props.companyBaseCurrencyId ?? '',
        coa_account_id: '',
        interest_rate: '',
        minimum_balance_for_interest: '',
        td_amount: '',
        td_interest_rate: '',
        td_wht_rate: 20,
        td_entry_type: 'new',
        td_funding_bank_account_id: '',
        td_start_date: '',
        td_maturity_date: '',
        is_active: true,
    }
}

const selectedBankId = ref(props.account?.bank_id ?? props.bank?.id ?? '')

// EDIT mode: single row. CREATE mode: repeater of rows.
const accountRows = ref(
    isEdit.value
        ? [{
            account_number:                 props.account.account_number ?? '',
            account_name:                   props.account.account_name ?? '',
            account_type:                   props.account.account_type ?? 'current',
            currency_id:                    props.account.currency_id ?? '',
            coa_account_id:                 props.account.coa_account_id ?? '',
            interest_rate:                  props.account.interest_rate ?? '',
            minimum_balance_for_interest:   props.account.minimum_balance_for_interest ?? '',
            td_amount:                      props.account.td_amount ?? '',
            td_interest_rate:               props.account.td_interest_rate ?? '',
            td_wht_rate:                    props.account.td_wht_rate ?? 20,
            td_entry_type:                  props.account.td_entry_type ?? 'new',
            td_funding_bank_account_id:     props.account.td_funding_bank_account_id ?? '',
            td_start_date:                  props.account.td_start_date?.toString().slice(0, 10) ?? '',
            td_maturity_date:               props.account.td_maturity_date?.toString().slice(0, 10) ?? '',
            is_active:                      props.account.is_active ?? true,
        }]
        : [blankRow()]
)

function addAccountRow() {
    accountRows.value.push(blankRow())
}

function removeAccountRow(idx) {
    accountRows.value.splice(idx, 1)
}

function currencyCodeFor(row) {
    const c = (props.currencies ?? []).find(c => c.id == row.currency_id)
    return c?.code ?? 'CCY'
}

/**
 * Read-only preview only — 365-day basis, confirmed with Mahmoud
 * (banks use 360 for credit facilities, 365 for Time Deposits).
 * Purely informational; the actual interest applied later through
 * Apply Interest / Break / Maturity / Renew is whatever the user
 * enters at that moment, not recalculated from this preview.
 */
function estimatedInterest(row) {
    const amount = parseFloat(row.td_amount)
    const rate   = parseFloat(row.td_interest_rate)
    if (!amount || !rate || !row.td_start_date || !row.td_maturity_date) return '—'

    const start = new Date(row.td_start_date)
    const end   = new Date(row.td_maturity_date)
    const days  = Math.round((end - start) / 86400000)
    if (days <= 0) return '—'

    const interest = amount * (rate / 100) * (days / 365)
    return interest.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const form = useForm(
    isEdit.value
        ? { ...accountRows.value[0], bank_id: selectedBankId.value }
        : { accounts: [] }
)

function errFor(idx, field) {
    return isEdit.value
        ? form.errors[field]
        : form.errors[`accounts.${idx}.${field}`]
}

// A row only carries the fields of its own account type. Without this, a
// user who filled Time Deposit terms and then switched back to Current
// would still send the old td_* values (and vice-versa for interest terms).
const TD_FIELDS = ['td_amount','td_interest_rate','td_wht_rate','td_entry_type','td_funding_bank_account_id','td_start_date','td_maturity_date']
function cleanRow(r) {
    const out = { ...r }
    if (out.account_type !== 'time_deposit') TD_FIELDS.forEach(k => { out[k] = null })
    if (out.account_type !== 'current') { out.interest_rate = null; out.minimum_balance_for_interest = null }
    return out
}

function submit() {
    if (isEdit.value) {
        form.bank_id = selectedBankId.value
        Object.assign(form, cleanRow(accountRows.value[0]))
        form.put(route('fi.bank-accounts.update', props.account.id))
    } else {
        form.accounts = accountRows.value.map(r => ({ ...cleanRow(r), bank_id: selectedBankId.value }))
        form.post(route('fi.bank-accounts.store'))
    }
}
</script>

<style>
.ba-bank-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 20px;
    background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
    font-size: 12.5px; font-weight: 600; color: #0F2554;
}
[data-theme="dark"] .ba-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }

/* Account Type and Entry Type now use the shared <OptionTiles
   variant="chip"> component (see Components/OptionTiles.vue) — teal/
   green/amber accents preserved via each option's `accent` field.
   No page-specific tile CSS needed here anymore. */

.so-remove-btn {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
    color: var(--zv-text-muted); transition: all 0.12s;
}
.so-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.od-add-row-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 1px dashed var(--zv-border);
    border-radius: var(--zv-radius); background: transparent;
    color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.od-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

@media (max-width: 900px) {
    .field-grid-2, .field-grid-3 { grid-template-columns: 1fr; }
    .ba-type-tiles { grid-template-columns: 1fr; }
}
</style>
