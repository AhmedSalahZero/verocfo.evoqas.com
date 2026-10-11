<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/ExpensesBody.vue

  Step 5 of 8 — Operating Expenses. Category tabs (Sales / Marketing
  / General & Admin / Finance — same four categories and icons CFO
  Tools uses, since they map directly onto the real COA Level 2
  groups 52/53/54/55) with a row per expense underneath.

  Deliberately does not include CFO Tools' "Applied to Products"
  multi-select — this plan's expense model is plan-wide, not
  product-scoped (PlanExpenseLine has no product relationship), so
  there's nothing to select. Every other row field maps 1:1 onto
  what PlanExpenseCalculator actually reads.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.expenses / valuation.business-plan.expenses (shared)
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.operatingExpenses') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.operatingExpenses')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-1m0 0V7a2 2 0 012-2h4a2 2 0 012 2v13M7 7h10"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 5, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="expenses" :completed-keys="completedKeys" />

        <div class="zv-card" style="margin-top:16px">
            <div class="manpower-tabs">
                <button v-for="cat in categories" :key="cat.key" type="button"
                    class="manpower-tab" :class="{ 'manpower-tab-active': activeCategory === cat.key }"
                    @click="activeCategory = cat.key">
                    <span>{{ cat.icon }}</span>
                    {{ cat.label }}
                    <span class="manpower-tab-count">{{ linesInCategory(cat.key).length }}</span>
                </button>
            </div>

            <div class="form-section-body">
                <p class="field-hint" style="margin-bottom:16px">{{ activeCategoryObj.description }}</p>

                <EmptyState v-if="linesInCategory(activeCategory).length === 0"
                    :title="t('planning.wizard.noExpensesYet')" :message="t('planning.wizard.addAnExpenseBelow')" />

                <div v-else class="expense-rows">
                    <div v-for="line in linesInCategory(activeCategory)" :key="line.id" class="expense-row">
                        <div class="expense-row-name">{{ line.expense_name }}</div>
                        <span class="zv-badge zv-badge-gray">{{ expenseTypeLabel(line.expense_type) }}</span>
                        <div class="expense-row-amount">{{ fmt(line.amount) }}{{ line.expense_type === 'pct_revenue' ? '%' : ' ' + plan.currency?.code }}</div>
                        <div class="expense-row-meta">{{ t('planning.wizard.annualIncreasePct') }}: {{ line.annual_increase_pct }}%</div>
                        <button type="button" class="zv-btn-icon zv-btn-ghost" style="margin-inline-start:auto" @click="removeLine(line)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m14 0H5m4-3h6a1 1 0 011 1v2H8V5a1 1 0 011-1z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="field-divider" style="margin:20px 0 16px">{{ t('planning.wizard.addExpense') }}</div>
                <div class="field-grid field-grid-3">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.name') }}</label>
                        <input v-model="form.expense_name" type="text" class="zv-input" :placeholder="t('planning.wizard.expenseNamePlaceholder')" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.type') }}</label>
                        <select v-model="form.expense_type" class="zv-select">
                            <option value="pct_revenue">{{ t('planning.wizard.pctOfRevenue') }}</option>
                            <option value="fixed_recurring">{{ t('planning.wizard.fixedMonthly') }}</option>
                            <option value="one_time">{{ t('planning.wizard.oneTimeAmortized') }}</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ form.expense_type === 'pct_revenue' ? t('planning.wizard.percentage') : t('planning.wizard.amount') }}</label>
                        <input v-model.number="form.amount" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                    <div v-if="form.expense_type === 'one_time'" class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.amortizeOverMonths') }}</label>
                        <input v-model.number="form.amortization_months" type="number" min="1" step="1" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.annualIncreasePct') }}</label>
                        <input v-model.number="form.annual_increase_pct" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.startMonthZeroBased') }}</label>
                        <input v-model.number="form.start_month" type="number" min="0" step="1" class="zv-input" />
                    </div>
                </div>
                <button type="button" class="zv-btn zv-btn-secondary" style="margin-top:14px" :disabled="adding" @click="addLine">{{ t('planning.wizard.addExpense') }}</button>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps.manpower" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabManpower') }}</Link>
            <Link :href="wizardSteps['fixed-assets']" class="zv-btn zv-btn-primary">{{ t('planning.wizard.saveAndNext') }}</Link>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, reactive, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({ plan: Object, wizardSteps: Object, routePrefix: { type: String, required: true } })

const wizardStepsList = computed(() => [
    { key: 'setup', label: t('planning.wizard.tabSetup'), href: props.wizardSteps.setup },
    { key: 'sales', label: t('planning.wizard.tabSales'), href: props.wizardSteps.sales },
    { key: 'cogs', label: t('planning.wizard.tabCogs'), href: props.wizardSteps.cogs },
    { key: 'manpower', label: t('planning.wizard.tabManpower'), href: props.wizardSteps.manpower },
    { key: 'expenses', label: t('planning.wizard.tabExpenses'), href: props.wizardSteps.expenses },
    { key: 'fixed-assets', label: t('planning.wizard.tabFixedAssets'), href: props.wizardSteps['fixed-assets'] },
    { key: 'opening-balance', label: t('planning.wizard.tabOpeningBalance'), href: props.wizardSteps['opening-balance'] },
    { key: 'results', label: t('planning.wizard.tabResults'), href: props.wizardSteps.results },
])
const completedKeys = computed(() => ['setup', 'sales', 'cogs', 'manpower', ...((props.plan.expense_lines?.length ?? 0) > 0 ? ['expenses'] : [])])

const categories = computed(() => [
    { key: 'sales', icon: '🤝', label: t('planning.wizard.sales'), description: t('planning.wizard.salesExpensesDesc') },
    { key: 'marketing', icon: '📣', label: t('planning.wizard.marketing'), description: t('planning.wizard.marketingExpensesDesc') },
    { key: 'general_admin', icon: '🏢', label: t('planning.wizard.generalAdmin'), description: t('planning.wizard.generalAdminExpensesDesc') },
    { key: 'finance', icon: '🏦', label: t('planning.wizard.finance'), description: t('planning.wizard.financeExpensesDesc') },
])
const activeCategory = ref('sales')
const activeCategoryObj = computed(() => categories.value.find(c => c.key === activeCategory.value))

function linesInCategory(cat) {
    return (props.plan.expense_lines ?? []).filter(line => line.category === cat)
}
function expenseTypeLabel(type) {
    return { pct_revenue: t('planning.wizard.pctOfRevenue'), fixed_recurring: t('planning.wizard.fixedMonthly'), one_time: t('planning.wizard.oneTimeAmortized') }[type] ?? type
}
function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }

const form = reactive({ expense_name: '', category: 'sales', expense_type: 'fixed_recurring', amount: 0, amortization_months: 1, annual_increase_pct: 0, start_month: 0 })
const adding = ref(false)
function addLine() {
    adding.value = true
    router.post(route(`${props.routePrefix}.expenses.store`, { plan: props.plan.id }), { ...form, category: activeCategory.value }, {
        preserveScroll: true,
        onSuccess: () => { form.expense_name = ''; form.amount = 0 },
        onFinish: () => { adding.value = false },
    })
}
function removeLine(line) {
    if (! confirm(t('planning.wizard.removeExpenseConfirm', { name: line.expense_name }))) return
    router.delete(route(`${props.routePrefix}.expenses.destroy`, { planExpenseLine: line.id }), { preserveScroll: true })
}
</script>

<style scoped>
.manpower-tabs { display: flex; gap: 8px; padding: 14px 20px 0; overflow-x: auto; border-bottom: 1px solid var(--zv-border); background: var(--zv-bg-surface); }
.manpower-tab { display: flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 8px 8px 0 0; font-size: 13px; font-weight: 600; white-space: nowrap; border: none; border-bottom: 2px solid transparent; background: none; color: var(--zv-text-muted); cursor: pointer; }
.manpower-tab:hover { color: var(--zv-text-primary); }
.manpower-tab-active { background: var(--zv-bg-card); border-bottom-color: var(--zv-blue); color: var(--zv-text-primary); }
.manpower-tab-count { background: var(--zv-bg-surface-alt); color: var(--zv-text-muted); font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; }
.manpower-tab-active .manpower-tab-count { background: var(--zv-blue-light); color: #fff; }
.expense-rows { display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px; }
.expense-row { display: flex; align-items: center; gap: 14px; padding: 11px 4px; border-bottom: 1px solid var(--zv-border); }
.expense-row:last-child { border-bottom: none; }
.expense-row-name { font-size: 13px; font-weight: 600; color: var(--zv-text-primary); min-width: 160px; }
.expense-row-amount { font-size: 13px; font-weight: 600; color: var(--zv-num); font-variant-numeric: tabular-nums; }
.expense-row-meta { font-size: 12px; color: var(--zv-text-muted); }
</style>
