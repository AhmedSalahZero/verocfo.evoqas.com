<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Factoring/AgreementForm.vue

  Create + Edit for Factoring Agreements.
  Shows recourse selector only for Direct agreements.
  Reverse factoring has no recourse concept.

  Redesigned to match the ZAVERO premium form standard (same pattern
  as Projects/Form.vue and FinancialInstitutions/.../ContractForm.vue):
    - zv-page-header with breadcrumb
    - form-section cards with icon headers
    - field-grid / field-group layout
    - <OptionTiles variant="card"> for Agreement Type / Recourse Type
      selection (Recourse Type uses accent="gold")
    - zv-input / zv-select / zv-label base classes (global, from app.css)

  Fix log:
    - filteredPartners() filtered on p.partner_type, a column that
      doesn't exist on Partner — the real field is p.types, a JSON
      array (a partner can be Customer + Supplier at once). The
      Eligible Customer/Supplier dropdown was always empty as a
      result. Fixed to check p.types.includes(...).

  Redesign log (Terms & Accounts rework):
    - Section 1 row reordered: Factoring Company / Start Date /
      End Date / Agreement Reference, on one row with an asymmetric
      grid (dates narrower, Company + Reference wider) — see
      .fa-details-grid.
    - Section 2 (Terms & Accounts): Bank Account selector removed
      (no logic depended on it — settlement account varies per
      factoring transaction, not per agreement). Added Borrowing
      Rate + Discount Margin Rate, with Discount Rate now an
      auto-calculated read-only field (Borrowing + Margin), and a
      "Link to CBE Corridor Rate" toggle — same pattern as the
      Overdraft facility forms (see od-* classes, ported as fa-*
      where agreement-specific). Eligible Customer/Supplier moved
      here from Eligibility Filters.
    - Section 3 (Eligibility Filters): Min Invoice Age / Min Invoice
      Amount removed (unused constraints). Only Extended Payment
      Days remains, so the section now only renders for Reverse
      agreements.

  @layout    AppLayout
  @route     fi.factoring-agreements.create / fi.factoring-agreements.edit
  @props     agreement, factoringCompanies, coaAccounts, partners
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
                        <Link :href="route('fi.factoring-agreements.index')">{{ t('financialInstitutions.factoring.agreementForm.factoringAgreements') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.factoring.agreementForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">
                        {{ isEdit ? t('financialInstitutions.factoring.agreementForm.editFactoringAgreement') : t('financialInstitutions.factoring.agreementForm.newFactoringAgreement') }}
                    </h1>
                </div>
            </div>

            <!-- Error banner -->
            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span>{{ t('financialInstitutions.factoring.agreementForm.pleaseFixTheErrorsBelowBefore') }}</span>
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════════════════════════════════════
                     SECTION 1 — AGREEMENT DETAILS
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.factoring.agreementForm.agreementDetails') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.factoring.agreementForm.factoringCompanyReferenceAndAgreementType') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <div class="fa-details-grid mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.factoring.agreementForm.factoringCompany') }}</label>
                                <select v-model="form.factoring_company_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.factoring_company_id }">
                                    <option value="">{{ t('financialInstitutions.factoring.agreementForm.select') }}</option>
                                    <option v-for="co in factoringCompanies" :key="co.id" :value="co.id">{{ co.name_en }}</option>
                                </select>
                                <p v-if="form.errors.factoring_company_id" class="zv-field-error">{{ form.errors.factoring_company_id }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.factoring.agreementForm.agreementStartDate') }}</label>
                                <input v-model="form.start_date" type="date" class="zv-input"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.factoring.agreementForm.agreementEndDate') }}</label>
                                <input v-model="form.end_date" type="date" class="zv-input"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.factoring.agreementForm.agreementReference') }}</label>
                                <input v-model="form.agreement_reference" type="text" class="zv-input font-mono"
                                       :placeholder="t('financialInstitutions.factoring.agreementForm.factoringCompanyContractNumber')"/>
                            </div>
                        </div>

                        <!-- Agreement Type -->
                        <div class="field-group mb-5">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.factoring.agreementForm.agreementType') }}</label>
                            <OptionTiles
                                :model-value="form.agreement_type" variant="card"
                                :options="[
                                    { value: 'direct', label: t('financialInstitutions.factoring.agreementForm.directFactoring'), description: t('financialInstitutions.factoring.agreementForm.yourCompanySellsReceivablesToThe') },
                                    { value: 'reverse', label: t('financialInstitutions.factoring.agreementForm.reverseFactoring'), description: t('financialInstitutions.factoring.agreementForm.supplierRequestsEarlyPaymentFromFactoring') },
                                ]"
                                @update:model-value="(v) => { form.agreement_type = v; if (v === 'reverse') form.recourse_type = ''; form.eligible_partner_id = '' }"
                            />
                        </div>

                        <!-- Recourse — Direct only -->
                        <div v-if="form.agreement_type === 'direct'" class="field-group">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.factoring.agreementForm.recourseType') }}</label>
                            <OptionTiles
                                :model-value="form.recourse_type" variant="card" accent="gold"
                                :options="[
                                    { value: 'with_recourse', label: t('financialInstitutions.factoring.agreementForm.withRecourse'), description: t('financialInstitutions.factoring.agreementForm.youRemainLiableIfCustomerDefaults') },
                                    { value: 'without_recourse', label: t('financialInstitutions.factoring.agreementForm.withoutRecourse'), description: t('financialInstitutions.factoring.agreementForm.factoringCompanyTakesFullRiskAR') },
                                ]"
                                @update:model-value="(v) => form.recourse_type = v"
                            />
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     SECTION 2 — TERMS & ACCOUNTS
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#b8860b">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.factoring.agreementForm.termsAccounts') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.factoring.agreementForm.discountRateCOAAndSettlementBank') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <!-- Row 1: Borrowing Rate / Discount Margin Rate / Discount Rate (auto) -->
                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">
                                    {{ t('financialInstitutions.factoring.agreementForm.borrowingRate') }}
                                    <span v-if="form.follows_corridor_rate" class="od-corridor-tag">{{ t('financialInstitutions.factoring.agreementForm.cBECorridor') }}</span>
                                </label>
                                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" max="100"
                                       class="zv-input" placeholder="0.0000" @input="calcDiscountRate"/>
                                <p class="field-hint">{{ t('financialInstitutions.factoring.agreementForm.borrowingRateHint') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.factoring.agreementForm.discountMarginRate') }}</label>
                                <input v-model="form.discount_margin_rate" type="number" step="0.0001" min="0" max="100"
                                       class="zv-input" placeholder="0.0000" @input="calcDiscountRate"/>
                                <p class="field-hint">{{ t('financialInstitutions.factoring.agreementForm.discountMarginRateHint') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.factoring.agreementForm.discountRate') }}</label>
                                <div class="od-calc-field">
                                    <div class="od-calc-value">{{ discountRate }}%</div>
                                    <div class="od-calc-badge">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 11h.01M12 11h.01M15 11h.01M12 7h.01M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5z"/>
                                        </svg>
                                        {{ t('financialInstitutions.factoring.agreementForm.discountRateFormula') }}
                                    </div>
                                </div>
                                <p class="field-hint">{{ t('financialInstitutions.factoring.agreementForm.appliedToInvoiceFaceValue') }}</p>
                            </div>
                        </div>

                        <!-- Divider: CBE Corridor Rate -->
                        <div class="field-divider mb-4">{{ t('financialInstitutions.factoring.agreementForm.cBECorridorRateSettings') }}</div>

                        <!-- Corridor Rate Toggle -->
                        <div class="od-toggle-card mb-5">
                            <div class="od-toggle-info">
                                <div class="od-toggle-title">{{ t('financialInstitutions.factoring.agreementForm.linkToCBECorridorRateUpdates') }}</div>
                                <div class="od-toggle-desc">
                                    {{ t('financialInstitutions.factoring.agreementForm.whenEnabledTheBorrowingRateOn') }}
                                </div>
                            </div>
                            <button type="button"
                                    class="od-toggle-switch"
                                    :class="{ 'od-toggle-on': form.follows_corridor_rate }"
                                    @click="form.follows_corridor_rate = !form.follows_corridor_rate">
                                <span class="od-toggle-knob"></span>
                            </button>
                        </div>

                        <!-- Row 2: COA Account / Eligible Customer-Supplier -->
                        <div class="field-grid field-grid-2">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.factoring.agreementForm.cOAAccount') }}</label>
                                <select v-model="form.coa_account_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.coa_account_id }">
                                    <option value="">{{ t('financialInstitutions.factoring.agreementForm.select') }}</option>
                                    <option v-for="acc in coaAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name_en }}</option>
                                </select>
                                <p v-if="form.errors.coa_account_id" class="zv-field-error">{{ form.errors.coa_account_id }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ form.agreement_type === 'reverse' ? t('financialInstitutions.factoring.agreementForm.eligibleSupplier') : t('financialInstitutions.factoring.agreementForm.eligibleCustomer') }}</label>
                                <select v-model="form.eligible_partner_id" class="zv-select">
                                    <option value="">{{ t('financialInstitutions.factoring.agreementForm.allPartners') }}</option>
                                    <option v-for="p in filteredPartners" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                                </select>
                                <p v-if="form.eligible_partner_id && filteredPartners.length === 0" class="zv-field-error">
                                    {{ t('financialInstitutions.factoring.agreementForm.noMatchingPartnersFound') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     SECTION 3 — ELIGIBILITY FILTERS (REVERSE ONLY)
                ══════════════════════════════════════════════════ -->
                <div v-if="form.agreement_type === 'reverse'" class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#1490A8">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.factoring.agreementForm.eligibilityFilters') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.factoring.agreementForm.optionalRestrictThisAgreementToA') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.factoring.agreementForm.extendedPaymentDays') }}</label>
                                <input v-model="form.extended_payment_days" type="number" min="0" class="zv-input text-end" placeholder="30"/>
                                <p class="field-hint">{{ t('financialInstitutions.factoring.agreementForm.daysBeyondOriginalSupplierDueDate') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     FORM ACTIONS
                ══════════════════════════════════════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route('fi.factoring-agreements.index')" class="zv-btn zv-btn-ghost">
                            {{ t('common.cancel') }}
                        </Link>
                        <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.factoring.agreementForm.createAgreement')) }}
                        </button>
                    </div>
                </div>

            </form>
        </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
    agreement:          Object,
    factoringCompanies: Array,
    coaAccounts:        Array,
    partners:           Array,
})

const isEdit = computed(() => !!props.agreement?.id)

const form = useForm({
    factoring_company_id:   props.agreement?.factoring_company_id   ?? '',
    agreement_reference:    props.agreement?.agreement_reference    ?? '',
    agreement_type:         props.agreement?.agreement_type         ?? 'direct',
    recourse_type:          props.agreement?.recourse_type          ?? '',
    borrowing_rate:         props.agreement?.borrowing_rate         ?? '',
    discount_margin_rate:   props.agreement?.discount_margin_rate   ?? '',
    discount_rate:          props.agreement?.discount_rate          ?? '',
    follows_corridor_rate:  props.agreement?.follows_corridor_rate  ?? false,
    coa_account_id:         props.agreement?.coa_account_id         ?? '',
    eligible_partner_id:    props.agreement?.eligible_partner_id    ?? '',
    extended_payment_days:  props.agreement?.extended_payment_days  ?? '',
    start_date:             props.agreement?.start_date?.toString().slice(0, 10) ?? '',
    end_date:               props.agreement?.end_date?.toString().slice(0, 10)   ?? '',
    notes:                  props.agreement?.notes                  ?? '',
})

// FIX: partner_type doesn't exist on Partner — the real field is
// `types`, a JSON array (a partner can be Customer + Supplier at
// once). This was always returning an empty list before.
const filteredPartners = computed(() => {
    if (!props.partners) return []
    if (form.agreement_type === 'reverse') {
        return props.partners.filter(p => (p.types ?? []).includes('supplier'))
    }
    return props.partners.filter(p => (p.types ?? []).includes('customer'))
})

// Discount Rate = Borrowing Rate + Discount Margin Rate (auto-calculated,
// same pattern as Interest Rate on the Overdraft facility forms).
const discountRate = computed(() => {
    const b = parseFloat(form.borrowing_rate       || 0)
    const m = parseFloat(form.discount_margin_rate || 0)
    return (b + m).toFixed(4)
})

function calcDiscountRate() {
    form.discount_rate = discountRate.value
}

function submit() {
    calcDiscountRate()
    isEdit.value
        ? form.put(route('fi.factoring-agreements.update', props.agreement.id))
        : form.post(route('fi.factoring-agreements.store'))
}
</script>

<style>
/* Agreement Type and Recourse Type now use the shared <OptionTiles>
   component (see Components/OptionTiles.vue, accent="gold" for
   Recourse Type) — no page-specific tile CSS needed here anymore. */

@media (max-width: 900px) {
    .field-grid-2, .field-grid-3 { grid-template-columns: 1fr; }
}

/* ── Section 1 row: Factoring Company / Start / End / Reference ───
   Dates need less room than the two text/select fields, so this
   row uses an asymmetric grid instead of the equal-width field-grid
   helpers — keeps Factoring Company and Agreement Reference roomy
   while the two date pickers stay compact. */
.fa-details-grid {
    display: grid;
    grid-template-columns: 1.3fr 0.85fr 0.85fr 1.3fr;
    gap: 16px 20px;
}
@media (max-width: 900px) {
    .fa-details-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .fa-details-grid { grid-template-columns: 1fr; }
}

/* ── Auto-calculated Discount Rate field (Borrowing + Margin) ────── */
.od-calc-field {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; border: 1px solid rgba(184,134,11,0.3); border-radius: var(--zv-radius);
    background: rgba(255,191,0,0.04); min-height: 38px;
}
.od-calc-value { font-size: 18px; font-weight: 800; color: #b8860b; font-variant-numeric: tabular-nums; }
.od-calc-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 12px;
    background: rgba(184,134,11,0.1); color: #b8860b;
    font-size: 11px; font-weight: 600;
}

/* ── CBE Corridor Rate Tag ─────────────────────────────────────── */
.od-corridor-tag {
    display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 10px;
    background: rgba(20,144,168,0.1); color: #1490A8;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    margin-inline-start: 6px;
}

/* ── CBE Corridor Rate Toggle Card ────────────────────────────────  */
.od-toggle-card {
    display: flex; align-items: center; justify-content: space-between; gap: 20px;
    padding: 16px 18px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg);
    background: var(--zv-bg-surface);
}
.od-toggle-info { flex: 1; }
.od-toggle-title { font-size: 13.5px; font-weight: 600; color: var(--zv-text-primary); margin-bottom: 4px; }
.od-toggle-desc  { font-size: 12px; color: var(--zv-text-muted); line-height: 1.5; }
.od-toggle-switch {
    width: 48px; height: 26px; border-radius: 13px; background: var(--zv-border);
    border: none; cursor: pointer; position: relative; transition: background 0.2s; flex-shrink: 0; padding: 0;
}
.od-toggle-on { background: #1490A8; }
.od-toggle-knob {
    position: absolute; top: 3px; left: 3px; width: 20px; height: 20px;
    border-radius: 50%; background: #fff; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.od-toggle-on .od-toggle-knob { left: 25px; }
</style>