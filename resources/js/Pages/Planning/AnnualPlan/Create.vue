<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Create.vue

  Setup step for a new Annual Plan — Step 1 of the wizard. Duration
  is fixed at 12 months (Plan::ANNUAL_DURATION_YEARS) and never asked
  here; no WACC/perpetual growth/valuation fields — a 12-month
  operating budget has no valuation step.

  Rebuilt (Sept 2026) to match this app's real design system —
  PageHeader, form-section/field-grid, zv-tile for the business-type
  picker, WizardSteps for the step bar — replacing an earlier,
  primitive version that used hand-rolled markup and invented CSS
  classes instead of the shared component library every other page
  in this app already uses.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.create / .store
  @props     currencies: Currency[], planType: 'annual'
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.annualPlanCreate.newAnnualPlan')" />
    <div class="zv-page">
        <PageHeader
            eyebrow="Planning"
            :title="t('planning.annualPlanCreate.newAnnualPlan')"
            :subtitle="t('planning.annualPlanCreate.twelveMonthOperatingBudget')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m4 10v-4m4 4V9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
        />

        <WizardSteps :steps="wizardSteps" current-key="setup" :completed-keys="[]" />

        <form class="zv-card" style="margin-top:16px" @submit.prevent="submit">
            <div class="form-section-body">
                <div class="field-grid field-grid-2">
                    <div class="field-group field-group-span-2">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.planName') }}</label>
                        <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }" :placeholder="t('planning.annualPlanCreate.planNamePlaceholder')" />
                        <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.currency') }}</label>
                        <SelectBox
                            v-model="form.currency_id"
                            :options="currencies"
                            value-key="id"
                            :label-fn="c => c.code"
                            :placeholder="t('planning.annualPlanCreate.selectCurrency')"
                        />
                        <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.startDate') }}</label>
                        <input v-model="form.start_date" type="date" class="zv-input" />
                        <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
                        <p class="field-hint">{{ t('planning.annualPlanCreate.startDateHint') }}</p>
                    </div>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.businessType') }}</div>

                <div class="zv-tile-group" style="grid-template-columns: repeat(4, 1fr)">
                    <button
                        v-for="opt in businessTypeOptions" :key="opt.value" type="button"
                        class="zv-tile" :class="{ 'zv-tile-active': form.business_type === opt.value }"
                        @click="form.business_type = opt.value"
                    >
                        <div class="zv-tile-label">{{ opt.label }}</div>
                    </button>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.corporateTaxRate') }}</div>

                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.annualPlanCreate.corporateTaxRate') }}</label>
                        <input v-model="form.corporate_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input" />
                        <p v-if="form.errors.corporate_tax_rate" class="zv-field-error">{{ form.errors.corporate_tax_rate }}</p>
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.annualPlanCreate.annualSalaryIncrease') }}</label>
                        <input v-model="form.manpower_annual_increase_pct" type="number" step="0.01" min="0" max="100" class="zv-input" />
                        <p class="field-hint">{{ t('planning.annualPlanCreate.annualSalaryIncreaseHint') }}</p>
                    </div>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.openingBalance') }}</div>

                <div class="zv-tile-group" style="grid-template-columns: 1fr 1fr 1fr">
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'auto' }" @click="form.opening_balance_source = 'auto'">
                        <div class="zv-tile-label">{{ t('planning.annualPlanCreate.recommended') }}</div>
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceAuto') }}</div>
                    </button>
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'manual' }" @click="form.opening_balance_source = 'manual'">
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceManual') }}</div>
                    </button>
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'none' }" @click="form.opening_balance_source = 'none'">
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceNone') }}</div>
                    </button>
                </div>
            </div>

            <div class="zv-card-footer" style="display:flex; justify-content:flex-end; gap:10px">
                <Link :href="route('planning.annual-plan.index')" class="zv-btn zv-btn-secondary">{{ t('planning.annualPlanCreate.cancel') }}</Link>
                <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    {{ t('planning.annualPlanCreate.createPlan') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({ currencies: Array, planType: String })

const wizardSteps = computed(() => [
    { key: 'setup', label: t('planning.wizard.tabSetup'), href: '#' },
    { key: 'products', label: t('planning.wizard.tabProducts'), href: '#' },
    { key: 'sales', label: t('planning.wizard.tabSales'), href: '#' },
    { key: 'cogs', label: t('planning.wizard.tabCogs'), href: '#' },
    { key: 'manpower', label: t('planning.wizard.tabManpower'), href: '#' },
    { key: 'expenses', label: t('planning.wizard.tabExpenses'), href: '#' },
    { key: 'fixed-assets', label: t('planning.wizard.tabFixedAssets'), href: '#' },
    { key: 'opening-balance', label: t('planning.wizard.tabOpeningBalance'), href: '#' },
    { key: 'results', label: t('planning.wizard.tabResults'), href: '#' },
])

const businessTypeOptions = computed(() => [
    { value: 'trading', label: t('planning.annualPlanCreate.trading') },
    { value: 'manufacturing', label: t('planning.annualPlanCreate.manufacturing') },
    { value: 'service', label: t('planning.annualPlanCreate.service') },
    { value: 'mixed', label: t('planning.annualPlanCreate.mixed') },
])

const form = useForm({
    name: '',
    currency_id: props.currencies?.[0]?.id ?? '',
    start_date: todayLocal(),
    business_type: 'trading',
    corporate_tax_rate: 22.5,
    manpower_annual_increase_pct: 0,
    opening_balance_source: 'auto',
})

function submit() {
    form.post(route('planning.annual-plan.store'))
}
</script>
