<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Create.vue

  Setup step for a new Business Plan & Valuation. Unlike Annual Plan:
  user picks a 3–10 year duration, and WACC / perpetual growth /
  Multiplier Valuation basis+number are all required here — this
  product always produces a Valuation page.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.create / .store
  @props     currencies: Currency[]
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head title="New Business Plan" />
    <div class="pw-page">
        <div class="pw-header">
            <Link :href="route('valuation.business-plan.index')" class="pw-back">{{ t('planning.businessPlanCreate.backToBusinessPlan') }}</Link>
            <h1 class="pw-title">{{ t('planning.businessPlanCreate.newBusinessPlan') }}</h1>
            <p class="pw-subtitle">{{ t('planning.businessPlanCreate.threeToTenYearProjection') }}</p>
        </div>

        <form class="pw-card" @submit.prevent="submit">
            <h2 class="pw-section-title">{{ t('planning.businessPlanCreate.businessDetails') }}</h2>
            <div class="pw-field-grid">
                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.planName') }}</label>
                    <input v-model="form.name" type="text" :placeholder="t('planning.businessPlanCreate.planNamePlaceholder')" />
                    <p v-if="form.errors.name" class="pw-error">{{ form.errors.name }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.currency') }}</label>
                    <select v-model="form.currency_id">
                        <option value="" disabled>{{ t('planning.businessPlanCreate.selectCurrency') }}</option>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                    </select>
                    <p v-if="form.errors.currency_id" class="pw-error">{{ form.errors.currency_id }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.startDate') }}</label>
                    <input v-model="form.start_date" type="date" />
                    <p v-if="form.errors.start_date" class="pw-error">{{ form.errors.start_date }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.durationYears') }}</label>
                    <select v-model.number="form.duration_years">
                        <option v-for="y in [3,4,5,6,7,8,9,10]" :key="y" :value="y">{{ y }} {{ t('planning.businessPlanCreate.yearsSuffix') }}</option>
                    </select>
                    <p v-if="form.errors.duration_years" class="pw-error">{{ form.errors.duration_years }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.businessType') }}</label>
                    <select v-model="form.business_type">
                        <option value="trading">{{ t('planning.businessPlanCreate.trading') }}</option>
                        <option value="manufacturing">{{ t('planning.businessPlanCreate.manufacturing') }}</option>
                        <option value="service">{{ t('planning.businessPlanCreate.service') }}</option>
                        <option value="mixed">{{ t('planning.businessPlanCreate.mixed') }}</option>
                    </select>
                    <p v-if="form.errors.business_type" class="pw-error">{{ form.errors.business_type }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.corporateTaxRate') }}</label>
                    <input v-model="form.corporate_tax_rate" type="number" step="0.01" min="0" max="100" />
                    <p v-if="form.errors.corporate_tax_rate" class="pw-error">{{ form.errors.corporate_tax_rate }}</p>
                </div>
            </div>

            <h2 class="pw-section-title">{{ t('planning.businessPlanCreate.valuationAssumptions') }}</h2>
            <div class="pw-field-grid">
                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.wacc') }}</label>
                    <input v-model="form.wacc_pct" type="number" step="0.01" min="0" max="100" />
                    <p v-if="form.errors.wacc_pct" class="pw-error">{{ form.errors.wacc_pct }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.perpetualGrowthRate') }}</label>
                    <input v-model="form.perpetual_growth_pct" type="number" step="0.01" min="0" max="20" />
                    <p v-if="form.errors.perpetual_growth_pct" class="pw-error">{{ form.errors.perpetual_growth_pct }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.multiplierValuationBasis') }}</label>
                    <select v-model="form.valuation_basis">
                        <option value="revenue">{{ t('planning.businessPlanCreate.revenue') }}</option>
                        <option value="ebitda">{{ t('planning.businessPlanCreate.ebitda') }}</option>
                        <option value="net_profit">{{ t('planning.businessPlanCreate.netProfit') }}</option>
                    </select>
                    <p v-if="form.errors.valuation_basis" class="pw-error">{{ form.errors.valuation_basis }}</p>
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.multiplier') }}</label>
                    <input v-model="form.valuation_multiplier" type="number" step="0.01" min="0" :placeholder="t('planning.businessPlanCreate.multiplierPlaceholder')" />
                    <p v-if="form.errors.valuation_multiplier" class="pw-error">{{ form.errors.valuation_multiplier }}</p>
                    <p class="pw-hint">{{ t('planning.businessPlanCreate.multiplierHint', { basis: basisLabel }) }}</p>
                </div>
            </div>

            <h2 class="pw-section-title">{{ t('planning.businessPlanCreate.manpowerAndOpeningBalance') }}</h2>
            <div class="pw-field-grid">
                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.annualSalaryIncrease') }}</label>
                    <input v-model="form.manpower_annual_increase_pct" type="number" step="0.01" min="0" max="100" />
                </div>

                <div class="pw-field">
                    <label>{{ t('planning.businessPlanCreate.openingBalance') }}</label>
                    <select v-model="form.opening_balance_source">
                        <option value="auto">{{ t('planning.businessPlanCreate.openingBalanceAuto') }}</option>
                        <option value="manual">{{ t('planning.businessPlanCreate.openingBalanceManual') }}</option>
                        <option value="none">{{ t('planning.businessPlanCreate.openingBalanceNone') }}</option>
                    </select>
                </div>
            </div>

            <div class="pw-actions">
                <Link :href="route('valuation.business-plan.index')" class="pw-btn-secondary">{{ t('planning.businessPlanCreate.cancel') }}</Link>
                <button type="submit" class="pw-btn-primary" :disabled="form.processing">{{ t('planning.businessPlanCreate.createPlan') }}</button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps({ currencies: Array })

const form = useForm({
    name: '',
    currency_id: props.currencies?.[0]?.id ?? '',
    start_date: todayLocal(),
    duration_years: 5,
    business_type: 'trading',
    corporate_tax_rate: 22.5,
    wacc_pct: 15,
    perpetual_growth_pct: 3,
    valuation_basis: 'ebitda',
    valuation_multiplier: '',
    manpower_annual_increase_pct: 0,
    opening_balance_source: 'auto',
})

const basisLabel = computed(() => ({
    revenue: t('planning.businessPlanCreate.revenue'),
    ebitda: t('planning.businessPlanCreate.ebitda'),
    net_profit: t('planning.businessPlanCreate.netProfit'),
}[form.valuation_basis] ?? ''))

function submit() {
    form.post(route('valuation.business-plan.store'))
}
</script>

<style scoped>
.pw-page { padding: 28px 32px; max-width: 900px; margin: 0 auto; }
.pw-back { font-size: 13px; color: #1490A8; text-decoration: none; }
.pw-header { margin-bottom: 20px; }
.pw-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 8px 0 2px; }
.pw-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 0; }
.pw-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); padding: 24px; }
.pw-section-title { font-size: 13px; font-weight: 700; color: #0F2554; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 14px; }
.pw-card > .pw-section-title:not(:first-child) { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--vero-border); }
.pw-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.pw-field { display: flex; flex-direction: column; gap: 6px; }
.pw-field label { font-size: 12px; font-weight: 600; color: var(--vero-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.pw-field input, .pw-field select { padding: 9px 12px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); }
.pw-hint { font-size: 11px; color: var(--vero-text-muted); margin: 0; }
.pw-error { font-size: 11px; color: #ef4444; margin: 0; }
.pw-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--vero-border); }
.pw-btn-primary { padding: 9px 20px; background: #0F2554; color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.pw-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.pw-btn-secondary { padding: 9px 20px; background: transparent; color: var(--vero-text-muted); border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; }
</style>
