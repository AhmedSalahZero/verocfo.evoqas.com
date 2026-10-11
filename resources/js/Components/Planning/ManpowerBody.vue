<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/ManpowerBody.vue

  Step 4 of 8 — Manpower. Confirmed simple design (spec Section 3.2):
  title-level, never named individuals. Category tabs borrow CFO
  Tools' own department-tab pattern (icon + label + count badge) —
  the one piece of that page's UX genuinely worth keeping — but the
  table underneath is deliberately much simpler than CFO Tools' own
  month-by-month per-employee grid: one row per job title, existing
  headcount + average salary snapshotted from real Payroll data, and
  a planned-hires-per-year count, not a monthly figure.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.manpower / valuation.business-plan.manpower (shared)
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.manpower') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.manpower')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-1.657-2.686-3-6-3s-6 1.343-6 3"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 4, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="manpower" :completed-keys="completedKeys" />

        <div class="zv-card" style="margin-top:16px">
            <div class="form-section-header" style="justify-content:space-between">
                <div>
                    <p class="form-section-title">{{ t('planning.wizard.existingTeam') }}</p>
                    <p class="form-section-sub">{{ t('planning.wizard.pullExistingTeamHint') }}</p>
                </div>
                <button type="button" class="zv-btn zv-btn-secondary" :disabled="snapshotting" @click="snapshot">
                    <svg v-if="snapshotting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    {{ t('planning.wizard.pullExistingTeam') }}
                </button>
            </div>

            <!-- Category tabs -->
            <div class="manpower-tabs">
                <button v-for="cat in categories" :key="cat.key" type="button"
                    class="manpower-tab" :class="{ 'manpower-tab-active': activeCategory === cat.key }"
                    @click="activeCategory = cat.key"
                >
                    <span>{{ cat.icon }}</span>
                    {{ cat.label }}
                    <span class="manpower-tab-count">{{ titlesInCategory(cat.key).length }}</span>
                </button>
            </div>

            <div class="form-section-body">
                <EmptyState v-if="titlesInCategory(activeCategory).length === 0"
                    :title="t('planning.wizard.noTitlesInCategory')" :message="t('planning.wizard.addATitleBelowOrPull')" />

                <div v-else class="zv-table-wrap">
                    <table class="zv-table">
                        <thead>
                            <tr>
                                <th>{{ t('planning.wizard.jobTitle') }}</th>
                                <th class="zv-table-num">{{ t('planning.wizard.existingHeadcount') }}</th>
                                <th class="zv-table-num">{{ t('planning.wizard.avgSalary') }}</th>
                                <th v-for="y in plan.duration_years" :key="y" class="zv-table-num">{{ t('planning.wizard.newInYearN', { n: y }) }}</th>
                                <th class="zv-col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="title in titlesInCategory(activeCategory)" :key="title.id">
                                <td>{{ title.job_title }} <span v-if="!title.is_existing" class="zv-badge zv-badge-gray">{{ t('planning.wizard.planned') }}</span></td>
                                <td class="zv-table-num">{{ title.existing_headcount }}</td>
                                <td class="zv-table-num">{{ fmt(title.avg_current_salary) }}</td>
                                <td v-for="y in plan.duration_years" :key="y" class="zv-table-num">
                                    <input type="number" min="0" step="1" class="manpower-hiring-input"
                                        :value="hiringCount(title, y)"
                                        @change="saveHiring(title, y, $event.target.value)" />
                                </td>
                                <td class="text-end">
                                    <button type="button" class="zv-btn-icon zv-btn-ghost" @click="removeTitle(title)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m14 0H5m4-3h6a1 1 0 011 1v2H8V5a1 1 0 011-1z"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="field-divider" style="margin:20px 0 16px">{{ t('planning.wizard.addAPlannedTitle') }}</div>
                <div class="field-grid field-grid-3">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.jobTitle') }}</label>
                        <input v-model="titleForm.job_title" type="text" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.category') }}</label>
                        <select v-model="titleForm.expense_category" class="zv-select">
                            <option v-for="cat in categories" :key="cat.key" :value="cat.key">{{ cat.icon }} {{ cat.label }}</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.estimatedSalary') }}</label>
                        <input v-model.number="titleForm.avg_current_salary" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                </div>
                <button type="button" class="zv-btn zv-btn-secondary" style="margin-top:14px" :disabled="adding" @click="addTitle">{{ t('planning.wizard.addTitle') }}</button>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps.cogs" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabCogs') }}</Link>
            <Link :href="wizardSteps.expenses" class="zv-btn zv-btn-primary">{{ t('planning.wizard.saveAndNext') }}</Link>
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
const completedKeys = computed(() => ['setup', 'sales', 'cogs', ...((props.plan.manpower_titles?.length ?? 0) > 0 ? ['manpower'] : [])])

const categories = computed(() => [
    { key: 'sales', icon: '📈', label: t('planning.wizard.sales') },
    { key: 'marketing', icon: '📣', label: t('planning.wizard.marketing') },
    { key: 'general_admin', icon: '🏢', label: t('planning.wizard.generalAdmin') },
    { key: 'cost_of_service', icon: '⚙️', label: t('planning.wizard.costOfService') },
    { key: 'manufacturing', icon: '🏭', label: t('planning.wizard.manufacturing') },
])
const activeCategory = ref('sales')

function titlesInCategory(cat) {
    return (props.plan.manpower_titles ?? []).filter(title => title.expense_category === cat)
}
function hiringCount(title, year) {
    return (title.hiring_lines ?? []).find(h => h.plan_year === year)?.new_hires_count ?? 0
}
function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }

const snapshotting = ref(false)
function snapshot() {
    snapshotting.value = true
    router.post(route(`${props.routePrefix}.manpower.snapshot`, { plan: props.plan.id }), {}, {
        preserveScroll: true, onFinish: () => { snapshotting.value = false },
    })
}

const titleForm = reactive({ job_title: '', expense_category: 'sales', avg_current_salary: 0 })
const adding = ref(false)
function addTitle() {
    adding.value = true
    router.post(route(`${props.routePrefix}.manpower.titles.store`, { plan: props.plan.id }), { ...titleForm }, {
        preserveScroll: true,
        onSuccess: () => { titleForm.job_title = ''; titleForm.avg_current_salary = 0 },
        onFinish: () => { adding.value = false },
    })
}

function removeTitle(title) {
    if (! confirm(t('planning.wizard.removeTitleConfirm', { name: title.job_title }))) return
    router.delete(route(`${props.routePrefix}.manpower.titles.destroy`, { planManpowerTitle: title.id }), { preserveScroll: true })
}

function saveHiring(title, year, value) {
    router.post(route(`${props.routePrefix}.manpower.hiring.store`, { planManpowerTitle: title.id }), {
        plan_year: year, new_hires_count: Number(value) || 0,
    }, { preserveScroll: true })
}
</script>

<style scoped>
.manpower-tabs { display: flex; gap: 8px; padding: 14px 20px 0; overflow-x: auto; border-bottom: 1px solid var(--zv-border); background: var(--zv-bg-surface); }
.manpower-tab { display: flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 8px 8px 0 0; font-size: 13px; font-weight: 600; white-space: nowrap; border: none; border-bottom: 2px solid transparent; background: none; color: var(--zv-text-muted); cursor: pointer; }
.manpower-tab:hover { color: var(--zv-text-primary); }
.manpower-tab-active { background: var(--zv-bg-card); border-bottom-color: var(--zv-blue); color: var(--zv-text-primary); }
.manpower-tab-count { background: var(--zv-bg-surface-alt); color: var(--zv-text-muted); font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; }
.manpower-tab-active .manpower-tab-count { background: var(--zv-blue-light); color: #fff; }
.manpower-hiring-input { width: 64px; padding: 5px 6px; border: 1px solid var(--zv-border); border-radius: 6px; font-size: 12px; text-align: end; background: var(--zv-bg-input); color: var(--zv-text-primary); }
</style>
