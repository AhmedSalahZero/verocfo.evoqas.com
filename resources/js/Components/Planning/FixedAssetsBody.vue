<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/FixedAssetsBody.vue

  Step 6 of 8 — Fixed Assets. Confirmed simple, two-source design
  (spec Section 3.4) — no equity/debt funding split, no construction-
  in-progress phase, unlike CFO Tools' own Fixed Assets step. A plan
  asset is either picked from the company's real Fixed Assets
  register (its remaining depreciation projects forward automatically)
  or a planned future purchase (cost, useful life, purchase month —
  nothing more).

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.fixed-assets / valuation.business-plan.fixed-assets (shared)
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.fixedAssets') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.fixedAssets')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m9-14h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M9 7h.01M15 7h.01"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 6, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="fixed-assets" :completed-keys="completedKeys" />

        <div class="zv-card" style="margin-top:16px">
            <div class="form-section-header">
                <p class="form-section-title">{{ t('planning.wizard.assetsInThisPlan') }}</p>
            </div>
            <div class="form-section-body">
                <EmptyState v-if="!plan.fixed_assets?.length"
                    :title="t('planning.wizard.noAssetsYet')" :message="t('planning.wizard.pickExistingOrAddPlanned')" />

                <div v-else class="zv-table-wrap">
                    <table class="zv-table">
                        <thead>
                            <tr>
                                <th>{{ t('planning.wizard.name') }}</th>
                                <th class="zv-table-num">{{ t('planning.wizard.cost') }}</th>
                                <th class="zv-table-num">{{ t('planning.wizard.usefulLife') }}</th>
                                <th>{{ t('planning.wizard.source') }}</th>
                                <th class="zv-col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in plan.fixed_assets" :key="a.id">
                                <td>{{ a.name }}</td>
                                <td class="zv-table-num">{{ fmt(a.cost_basis) }}</td>
                                <td class="zv-table-num">{{ a.useful_life_months }} {{ t('planning.wizard.months') }}</td>
                                <td>
                                    <span class="zv-badge" :class="a.fixed_asset_id ? 'zv-badge-green' : 'zv-badge-gray'">
                                        {{ a.fixed_asset_id ? t('planning.wizard.existingAsset') : t('planning.wizard.plannedPurchase') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="zv-btn-icon zv-btn-ghost" @click="removeAsset(a)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m14 0H5m4-3h6a1 1 0 011 1v2H8V5a1 1 0 011-1z"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="zv-card" style="margin-top:16px">
            <div class="form-section-header" style="justify-content:space-between">
                <p class="form-section-title">{{ t('planning.wizard.addAnExistingAsset') }}</p>
                <button type="button" class="zv-btn zv-btn-secondary" :disabled="loadingEligible" @click="loadEligible">
                    <svg v-if="loadingEligible" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    {{ t('planning.wizard.loadFixedAssetsRegister') }}
                </button>
            </div>
            <div v-if="eligibleAssets !== null" class="form-section-body">
                <EmptyState v-if="eligibleAssets.length === 0" :title="t('planning.wizard.noEligibleAssets')" />
                <div v-else class="zv-table-wrap">
                    <table class="zv-table">
                        <thead><tr><th>{{ t('planning.wizard.code') }}</th><th>{{ t('planning.wizard.name') }}</th><th class="zv-table-num">{{ t('planning.wizard.cost') }}</th><th class="zv-col-actions"></th></tr></thead>
                        <tbody>
                            <tr v-for="a in eligibleAssets" :key="a.id">
                                <td><span class="zv-code">{{ a.asset_code }}</span></td>
                                <td>{{ a.name }}</td>
                                <td class="zv-table-num">{{ fmt(a.cost_basis) }}</td>
                                <td class="text-end"><button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="addExisting(a)">{{ t('planning.wizard.add') }}</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="zv-card" style="margin-top:16px">
            <div class="form-section-header"><p class="form-section-title">{{ t('planning.wizard.addAPlannedFuturePurchase') }}</p></div>
            <div class="form-section-body">
                <div class="field-grid field-grid-3">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.name') }}</label>
                        <input v-model="form.name" type="text" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.cost') }} ({{ plan.currency?.code }})</label>
                        <input v-model.number="form.cost_basis" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.residualValue') }}</label>
                        <input v-model.number="form.residual_value" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.usefulLifeMonths') }}</label>
                        <input v-model.number="form.useful_life_months" type="number" min="1" step="1" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.purchaseMonthZeroBased') }}</label>
                        <input v-model.number="form.purchase_month" type="number" min="0" step="1" class="zv-input" />
                        <p class="field-hint">{{ t('planning.wizard.depreciationStartsMonthAfter') }}</p>
                    </div>
                </div>
                <button type="button" class="zv-btn zv-btn-secondary" style="margin-top:14px" :disabled="adding" @click="addPlanned">{{ t('planning.wizard.addPlannedAsset') }}</button>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps.expenses" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabExpenses') }}</Link>
            <Link :href="wizardSteps['opening-balance']" class="zv-btn zv-btn-primary">{{ t('planning.wizard.saveAndNext') }}</Link>
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
const completedKeys = computed(() => ['setup', 'sales', 'cogs', 'manpower', 'expenses', ...((props.plan.fixed_assets?.length ?? 0) > 0 ? ['fixed-assets'] : [])])

function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }

const eligibleAssets = ref(null)
const loadingEligible = ref(false)
async function loadEligible() {
    loadingEligible.value = true
    try {
        const { data } = await window.axios.get(route(`${props.routePrefix}.eligible-fixed-assets`, { plan: props.plan.id }))
        eligibleAssets.value = data
    } finally {
        loadingEligible.value = false
    }
}
function addExisting(asset) {
    router.post(route(`${props.routePrefix}.fixed-assets.existing.store`, { plan: props.plan.id, fixedAsset: asset.id }), {}, {
        preserveScroll: true,
        onSuccess: () => { eligibleAssets.value = eligibleAssets.value.filter(a => a.id !== asset.id) },
    })
}

const form = reactive({ name: '', cost_basis: 0, residual_value: 0, useful_life_months: 12, purchase_month: 0 })
const adding = ref(false)
function addPlanned() {
    adding.value = true
    router.post(route(`${props.routePrefix}.fixed-assets.planned.store`, { plan: props.plan.id }), { ...form }, {
        preserveScroll: true,
        onSuccess: () => { form.name = ''; form.cost_basis = 0 },
        onFinish: () => { adding.value = false },
    })
}
function removeAsset(a) {
    if (! confirm(t('planning.wizard.removeAssetConfirm', { name: a.name }))) return
    router.delete(route(`${props.routePrefix}.fixed-assets.destroy`, { planFixedAsset: a.id }), { preserveScroll: true })
}
</script>
