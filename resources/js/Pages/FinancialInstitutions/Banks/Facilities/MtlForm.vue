<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/MtlForm.vue

  Create + Edit for Medium Term Loan facilities (facility_type =
  'mtl'). Same visual standard as the LG/LC Agreement forms.

  Confirmed structure (from Mahmoud's "Add Loans" reference image):
    Name, Start Date, End Date (maps to review_date), Currency,
    Limit (facility_limit), Account Number, Borrowing Rate %,
    Margin Rate %, Interest Rate % (auto = Borrowing + Margin, same
    convention as Clean OD/LC), Duration in Months (maps to
    tenure_months), Installment Payment Interval.

  Loan Type (New/Existing) placed as the first field in the header
  row — same visual role as Leasing's entry_type field. Drives
  whether the future Accounting Engine posts an initial liability
  entry (see the comment block below, matching the one already
  written into MtlService::create()).

  NO auto-generated amortization anywhere on this form — confirmed
  by Mahmoud, the Loan Schedule is a separate page reached after
  saving (or via the "Loan Schedule" action on the index), entered
  manually or via Excel upload.

  ═══════════════════════════════════════════════════════════════
  ACCOUNTING ENGINE TODO — see MtlService::create() for the full
  comment. Loan Type = 'existing' → no posting ever. 'new' → once
  the GL exists, Dr Bank Current Account / Cr Loan Payable for
  facility_limit, via a pending-GL-posting mtl_movements row
  (same mechanism as lg_issuance_movements). Nothing posts today.
  ═══════════════════════════════════════════════════════════════

  @layout    AppLayout
  @route     fi.facilities.mtl.create / fi.facilities.mtl.edit
  @props     facility, bank, currencies, companyBaseCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.mtlForm.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <Link :href="route('fi.facilities.mtl.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.mtlForm.mediumTermLoan') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.facilities.mtlForm.new') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('financialInstitutions.banks.facilities.mtlForm.editLoan') : t('financialInstitutions.banks.facilities.mtlForm.addLoan') }}</h1>
          <p class="zv-page-subtitle">
            <span class="mtl-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank?.name_en }}
            </span>
          </p>
        </div>

        <div v-if="parseFloat(form.facility_limit) > 0" class="mtl-limit-panel">
          <div class="mtl-limit-label">{{ t('financialInstitutions.banks.facilities.mtlForm.loanLimit') }}</div>
          <div class="mtl-limit-value">{{ selectedCurrencyCode }} {{ fmt(form.facility_limit) }}</div>
          <div class="mtl-limit-sub">{{ t('financialInstitutions.banks.facilities.mtlForm.interestRate') }}: {{ computedInterestRate }}%</div>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        {{ t('financialInstitutions.banks.facilities.mtlForm.pleaseFixTheErrorsBelowBefore') }}
      </div>

      <form @submit.prevent="submit" novalidate>

        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.mtlForm.loanInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.mtlForm.loanTypeDatesLimitAndRates') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-4 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.loanType') }}</label>
                <select v-model="form.entry_type" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.entry_type }">
                  <option value="new">{{ t('financialInstitutions.banks.facilities.mtlForm.new') }}</option>
                  <option value="existing">{{ t('financialInstitutions.banks.facilities.mtlForm.existing') }}</option>
                </select>
                <p v-if="form.errors.entry_type" class="zv-field-error">{{ form.errors.entry_type }}</p>
              </div>
              <div class="field-group field-group-span-2">
                <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
                <input v-model="form.name" type="text" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.name }"
                       :placeholder="t('financialInstitutions.banks.facilities.mtlForm.eGFactoryMTL10000')"/>
                <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.currency') }}</label>
                <select v-model="form.currency_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.currency_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.mtlForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.facilities.mtlForm.functional') + ')' : '' }}
                  </option>
                </select>
                <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
              </div>
            </div>

            <!--
              Linked Bank Account — TRS-002.

              Shown only for a NEW loan, because only a new loan is a
              payment source: the bank does not hand the money over,
              it pays the company's suppliers out of the loan, and
              every such payment has to name the account it settles
              through. An EXISTING loan was drawn and spent before
              this system and is only ever repaid, so it needs none.

              Filtered to the selected currency for the same reason
              the Clean Overdraft is: a facility and its account move
              one currency between them, never converting.
            -->
            <div v-if="form.entry_type === 'new'" class="field-grid field-grid-4 mb-5">
              <div class="field-group field-group-span-2">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.linkedBankAccount') }}</label>
                <select v-model="form.bank_account_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.bank_account_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.mtlForm.select') }}</option>
                  <option v-for="a in bankAccountsForSelectedCurrency" :key="a.id" :value="a.id">
                    {{ a.account_name }} — {{ a.account_number }}
                  </option>
                </select>
                <p v-if="form.errors.bank_account_id" class="zv-field-error">{{ form.errors.bank_account_id }}</p>
                <p v-if="form.currency_id && !bankAccountsForSelectedCurrency.length" class="field-hint">
                  {{ t('financialInstitutions.banks.facilities.mtlForm.noCurrentAccountInThisCurrency') }}
                </p>
                <p v-else class="field-hint">{{ t('financialInstitutions.banks.facilities.mtlForm.theAccountThisLoanSettlesThrough') }}</p>
              </div>
            </div>

            <div class="field-grid field-grid-4 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
                <input v-model="form.start_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.start_date }"/>
                <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.endDate') }}</label>
                <input v-model="form.review_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.review_date }"/>
                <p v-if="form.errors.review_date" class="zv-field-error">{{ form.errors.review_date }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.limit') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.facility_limit" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                </div>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlForm.accountNumber') }}</label>
                <input v-model="form.account_number" type="text" class="zv-input font-mono"
                       :placeholder="t('financialInstitutions.banks.facilities.mtlForm.bankReferenceNumber')"/>
              </div>
            </div>

            <div class="field-grid field-grid-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.borrowingRate') }}</label>
                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.marginRate') }}</label>
                <input v-model="form.bank_margin_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.mtlForm.interestRate2') }}</label>
                <div class="zv-readonly-field">{{ computedInterestRate }}%</div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.durationInMonths') }}</label>
                <input v-model="form.tenure_months" type="number" min="1" class="zv-input text-end" placeholder="36"/>
              </div>
            </div>

            <div class="field-group mt-5" style="max-width: 320px;">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.mtlForm.installmentPaymentInterval') }}</label>
              <select v-model="form.installment_interval" class="zv-select"
                      :class="{ 'zv-input-error': form.errors.installment_interval }">
                <option value="">{{ t('financialInstitutions.banks.facilities.mtlForm.select') }}</option>
                <option value="monthly">{{ t('financialInstitutions.banks.facilities.mtlForm.monthly') }}</option>
                <option value="quarterly">{{ t('financialInstitutions.banks.facilities.mtlForm.quarterly') }}</option>
                <option value="semi_annual">{{ t('financialInstitutions.banks.facilities.mtlForm.semiAnnual') }}</option>
                <option value="annual">{{ t('financialInstitutions.banks.facilities.mtlForm.annual') }}</option>
              </select>
              <p v-if="form.errors.installment_interval" class="zv-field-error">{{ form.errors.installment_interval }}</p>
            </div>
          </div>
        </div>

        <div class="zv-callout mb-5">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span class="text-sm">
            {{ t('financialInstitutions.banks.facilities.mtlForm.theRepaymentScheduleIsEnteredSeparately') }}
          </span>
        </div>

        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.facilities.mtl.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.facilities.mtlForm.createLoan')) }}
            </button>
          </div>
        </div>
      </form>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { add as addMoney } from '@/Utils/money'

const { t } = useI18n()

const props = defineProps({
  facility:              Object,
  bank:                  Object,
  currencies:            Array,
  bankAccounts:          { type: Array, default: () => [] },
  companyBaseCurrencyId: Number,
})

const isEdit = computed(() => !!props.facility?.id)

const form = useForm({
  bank_id:               props.bank?.id ?? '',
  entry_type:            props.facility?.entry_type ?? 'new',
  name:                  props.facility?.name ?? '',
  currency_id:           props.facility?.currency_id ?? props.companyBaseCurrencyId ?? '',
  bank_account_id:       props.facility?.bank_account_id ?? '',
  start_date:            props.facility?.start_date?.toString().slice(0, 10) ?? '',
  review_date:           props.facility?.review_date?.toString().slice(0, 10) ?? '',
  facility_limit:        props.facility?.facility_limit ?? '',
  account_number:        props.facility?.account_number ?? '',
  borrowing_rate:        props.facility?.borrowing_rate ?? '0.00',
  bank_margin_rate:      props.facility?.bank_margin_rate ?? '',
  tenure_months:         props.facility?.tenure_months ?? '',
  installment_interval:  props.facility?.installment_interval ?? '',
})

const selectedCurrencyCode = computed(() => props.currencies.find(c => c.id == form.currency_id)?.code ?? 'CCY')

// Only accounts in the loan's own currency can be linked — the
// facility and its account move one currency between them.
const bankAccountsForSelectedCurrency = computed(() =>
  props.bankAccounts.filter(a => a.currency_id == form.currency_id))

// Changing the currency can strip the chosen account out of the list.
// Clearing it here means the user picks again rather than submitting a
// stale id the validator would reject with no visible cause.
watch(() => form.currency_id, () => {
  if (form.bank_account_id
      && !bankAccountsForSelectedCurrency.value.some(a => a.id == form.bank_account_id)) {
    form.bank_account_id = ''
  }
})

// An existing loan is never drawn from, so it carries no linked
// account — and must not send a stale one if the user switches type.
watch(() => form.entry_type, (type) => {
  if (type === 'existing') form.bank_account_id = ''
})

// Exact decimal sum, shown to 4 places like the server stores it
// (bcadd(..., 4)). The old float sum was shown to 2 places, so a rate of
// 13.3457% appeared as 13.35%.
const computedInterestRate = computed(() => {
  const borrow = parseFloat(form.borrowing_rate)
  const margin = parseFloat(form.bank_margin_rate)
  if (isNaN(borrow) || isNaN(margin)) return '0.0000'
  return addMoney(form.borrowing_rate, form.bank_margin_rate, 4)
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  if (isEdit.value) {
    form.put(route('fi.facilities.mtl.update', props.facility.id))
  } else {
    form.post(route('fi.facilities.mtl.store'))
  }
}
</script>

<style>
.mtl-bank-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12); font-size: 12.5px; font-weight: 600; color: #0F2554; }
[data-theme="dark"] .mtl-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }

.mtl-limit-panel { min-width: 220px; background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 14px 18px; box-shadow: var(--zv-shadow-sm); }
.mtl-limit-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.mtl-limit-value { font-size: 18px; font-weight: 800; color: #0F2554; font-variant-numeric: tabular-nums; }
[data-theme="dark"] .mtl-limit-value { color: #90a4c4; }
.mtl-limit-sub { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 4px; }

.zv-callout { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: rgba(20,144,168,0.05); border: 1px solid rgba(20,144,168,0.15); border-radius: var(--zv-radius-md); color: #1490A8; }
.zv-readonly-field { padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius); background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 600; min-height: 38px; }
.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }

/* ── Self-contained grid fallback — same defensive pattern as
     LgIssuanceForm.vue / LetterOfCreditForm.vue / ContractForm.vue ── */
.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
.field-grid-4 { grid-template-columns: repeat(4, 1fr); }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field-group-span-2 { grid-column: span 2; }

@media (max-width: 1100px) { .field-grid-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 900px) { .zv-page-header { flex-direction: column; } }
@media (max-width: 600px) { .field-grid-4 { grid-template-columns: 1fr; } .field-group-span-2 { grid-column: span 1; } }
</style>
