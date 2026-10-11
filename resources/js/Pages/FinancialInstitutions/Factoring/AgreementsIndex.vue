<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Factoring/AgreementsIndex.vue
  Lists Factoring Agreements. Shows type, recourse, rate, reference, status.
  @layout AppLayout | @route fi.factoring-agreements.index
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon fi-icon-gold">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.factoring.agreementsIndex.factoringAgreements') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.factoring.agreementsIndex.directAndReverseFactoringContracts') }}</p>
                    </div>
                </div>
                <Link :href="route('fi.factoring-agreements.create')" class="fi-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    {{ t('financialInstitutions.factoring.agreementsIndex.newAgreement') }}
                </Link>
            </div>

            <div class="fi-filters">
                <select v-model="filters.factoring_company_id" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.factoring.agreementsIndex.allFactoringCompanies') }}</option>
                    <option v-for="co in factoringCompanies" :key="co.id" :value="co.id">{{ co.name_en }}</option>
                </select>
                <select v-model="filters.agreement_type" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.factoring.agreementsIndex.allTypes') }}</option>
                    <option value="direct">{{ t('financialInstitutions.factoring.agreementsIndex.direct') }}</option>
                    <option value="reverse">{{ t('financialInstitutions.factoring.agreementsIndex.reverse') }}</option>
                </select>
                <select v-model="filters.status" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="inactive">{{ t('common.inactive') }}</option>
                </select>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.factoring.agreementsIndex.factoringCompany') }}</th>
                            <th>{{ t('common.type') }}</th>
                            <th>{{ t('financialInstitutions.factoring.agreementsIndex.recourse') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.factoring.agreementsIndex.discountRate') }}</th>
                            <th>{{ t('financialInstitutions.factoring.agreementsIndex.reference') }}</th>
                            <th>{{ t('financialInstitutions.factoring.agreementsIndex.renewal') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="agreements.data.length === 0">
                            <td colspan="8" class="fi-empty"><p class="text-sm opacity-50">{{ t('financialInstitutions.factoring.agreementsIndex.noFactoringAgreementsYet') }}</p></td>
                        </tr>
                        <tr v-for="agr in agreements.data" :key="agr.id" class="fi-row">
                            <td class="fi-name-primary">{{ agr.factoring_company?.name_en }}</td>
                            <td>
                                <span class="fi-type-badge" :class="agr.agreement_type === 'direct' ? 'fi-type-direct' : 'fi-type-reverse'">
                                    {{ agr.agreement_type === 'direct' ? t('financialInstitutions.factoring.agreementsIndex.direct') : t('financialInstitutions.factoring.agreementsIndex.reverse') }}
                                </span>
                            </td>
                            <td>
                                <span v-if="agr.agreement_type === 'direct'" class="fi-recourse" :class="agr.recourse_type === 'with_recourse' ? 'fi-recourse-with' : 'fi-recourse-without'">
                                    {{ agr.recourse_type === 'with_recourse' ? t('financialInstitutions.factoring.agreementsIndex.withRecourse') : t('financialInstitutions.factoring.agreementsIndex.withoutRecourse') }}
                                </span>
                                <span v-else class="fi-muted">—</span>
                            </td>
                            <td class="text-end fi-amount">{{ agr.discount_rate }}%</td>
                            <td class="fi-muted">{{ agr.agreement_reference || '—' }}</td>
                            <td>
                                <span class="fi-renewal" :class="endDateClass(agr)">
                                    <span class="zv-status-dot" :class="endDateDotClass(agr)"></span>
                                    {{ fmtDate(agr.end_date) }}
                                </span>
                                <button
                                    v-if="hasRenewals(agr)"
                                    type="button"
                                    class="fi-renewal-badge"
                                    @click="openHistory(agr)"
                                    :title="t('financialInstitutions.factoring.agreementsIndex.viewRenewalHistory')"
                                >{{ agr.terms_histories.length - 1 }} {{ agr.terms_histories.length - 1 === 1 ? t('financialInstitutions.factoring.agreementsIndex.renewal2') : t('financialInstitutions.factoring.agreementsIndex.renewals') }}</button>
                            </td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="agr.status === 'active' ? 'fi-active' : 'fi-inactive'">
                                    {{ agr.status === 'active' ? t('common.active') : t('common.inactive') }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <Link :href="route('fi.factoring-agreements.edit', agr.id)" class="fi-action-btn" :title="t('common.edit')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></Link>
                                    <button class="fi-action-btn" :title="t('financialInstitutions.factoring.agreementsIndex.renewAgreement')" @click="openRenew(agr)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/></svg>
                                    </button>
                                    <button
                                        v-if="hasRenewals(agr)"
                                        class="fi-action-btn fi-action-btn-danger"
                                        :title="t('financialInstitutions.factoring.agreementsIndex.deleteLatestRenewal')"
                                        @click="confirmDeleteRenewal(agr)"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9l6 6m0-6l-6 6"/></svg>
                                    </button>
                                    <button class="fi-action-btn" :title="agr.status === 'active' ? t('common.deactivate') : t('common.activate')" @click="toggle(agr)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path v-if="agr.status === 'active'" stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/><path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 RENEW MODAL — Agreement Renewal
            ══════════════════════════════════════════════════════ -->
            <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-2xl">
                    <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.factoring.agreementsIndex.renewAgreement') }} — {{ renewTarget.factoring_company?.name_en }}</h2>
                    <div class="fi-renew-current mb-4">
                        <span class="fi-renew-current-label">{{ t('financialInstitutions.factoring.agreementsIndex.currentEndDate') }}:</span>
                        <strong>{{ fmtDate(currentEndDate) }}</strong>
                    </div>

                    <div class="fi-renew-grid">
                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.effectiveDate') }} *</label>
                            <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
                            <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
                        </div>
                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.newEndDate') }} *</label>
                            <input type="date" v-model="renewForm.end_date" class="zv-input w-full" />
                            <p v-if="renewErrors.end_date" class="zv-field-error">{{ renewErrors.end_date }}</p>
                        </div>

                        <div v-if="!renewTarget.follows_corridor_rate">
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.borrowingRate') }}</label>
                            <input type="number" step="0.0001" v-model="renewForm.borrowing_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.factoring.agreementsIndex.unchanged')" />
                        </div>
                        <div v-else class="fi-renew-locked">
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.borrowingRate') }}</label>
                            <div class="zv-readonly-field">
                                {{ Number(renewTarget.borrowing_rate).toFixed(4) }}%
                                <span class="fi-corridor-tag">{{ t('financialInstitutions.factoring.agreementsIndex.cBE') }}</span>
                            </div>
                            <p class="field-hint">{{ t('financialInstitutions.factoring.agreementsIndex.drivenByTheCBECorridorRate') }}</p>
                        </div>

                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.discountMarginRate') }}</label>
                            <input type="number" step="0.0001" v-model="renewForm.discount_margin_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.factoring.agreementsIndex.unchanged')" />
                        </div>

                        <div v-if="renewTarget.agreement_type === 'reverse'">
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.extendedPaymentDays') }}</label>
                            <input type="number" v-model="renewForm.extended_payment_days" class="zv-input w-full" :placeholder="t('financialInstitutions.factoring.agreementsIndex.unchanged')" />
                        </div>

                        <div class="fi-renew-span-2">
                            <label class="zv-label">{{ t('financialInstitutions.factoring.agreementsIndex.notes') }}</label>
                            <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.factoring.agreementsIndex.egFactoringRenewalLetterReference')"></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
                        <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.factoring.agreementsIndex.recordRenewal') }}</button>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 HISTORY MODAL — read-only chapter timeline
            ══════════════════════════════════════════════════════ -->
            <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-4xl">
                    <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.factoring.agreementsIndex.renewalHistory') }} — {{ historyTarget.factoring_company?.name_en }}</h2>
                    <div class="zv-table-wrap">
                        <table class="zv-table fi-history-table">
                            <thead>
                                <tr>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.effectiveDate') }}</th>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.endDate') }}</th>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.borrowingRate') }}</th>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.discountMarginRate') }}</th>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.discountRate') }}</th>
                                    <th>{{ t('financialInstitutions.factoring.agreementsIndex.chapter') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="term in historyTarget.terms_histories" :key="term.id">
                                    <td>{{ fmtDate(term.effective_date) }}</td>
                                    <td>{{ fmtDate(term.end_date) }}</td>
                                    <td>{{ Number(term.borrowing_rate).toFixed(4) }}%</td>
                                    <td>{{ Number(term.discount_margin_rate).toFixed(4) }}%</td>
                                    <td>{{ Number(term.discount_rate).toFixed(4) }}%</td>
                                    <td class="fi-muted">{{ term.is_original ? t('financialInstitutions.factoring.agreementsIndex.original') : t('financialInstitutions.factoring.agreementsIndex.renewalChapter') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end mt-4">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.factoring.agreementsIndex.close') }}</button>
                    </div>
                </div>
            </div>

            <!-- Delete Renewal confirmation -->
            <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
                    <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.factoring.agreementsIndex.deleteThisRenewal') }}</h2>
                    <p class="text-xs zv-text-muted mb-4">
                        {{ t('financialInstitutions.factoring.agreementsIndex.theAgreementWillRevertToIts') }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.factoring.agreementsIndex.close') }}</button>
                        <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.factoring.agreementsIndex.confirmDelete') }}</button>
                    </div>
                </div>
            </div>
        </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps({ agreements: Object, factoringCompanies: Array, filters: Object })
const filters = ref({ factoring_company_id: props.filters?.factoring_company_id ?? '', agreement_type: props.filters?.agreement_type ?? '', status: props.filters?.status ?? '' })
function applyFilters() { router.get(route('fi.factoring-agreements.index'), { factoring_company_id: filters.value.factoring_company_id || undefined, agreement_type: filters.value.agreement_type || undefined, status: filters.value.status || undefined }, { preserveState: true, replace: true }) }
function toggle(agr) { router.patch(route('fi.factoring-agreements.toggle', agr.id)) }

function fmtDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── End-date urgency (mirrors the ODA review-date coloring) ───────
function daysUntil(dateStr) {
    if (!dateStr) return null
    return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}
function endDateClass(agr) {
    const d = daysUntil(agr.end_date)
    if (d === null) return 'zv-text-muted'
    if (d < 0) return 'zv-text-danger'
    if (d <= 30) return 'zv-text-amber'
    return 'zv-text-secondary'
}
function endDateDotClass(agr) {
    const d = daysUntil(agr.end_date)
    if (d === null) return 'zv-status-dot-gray'
    if (d < 0) return 'zv-status-dot-danger'
    if (d <= 30) return 'zv-status-dot-amber'
    return 'zv-status-dot-green'
}

// ── Agreement Renewal ──────────────────────────────────────────────
function hasRenewals(agr) {
    return (agr.terms_histories?.length || 0) > 1
}

// ── Renew modal ─────────────────────────────────────────────────────
const renewTarget = ref(null)
const renewForm = ref(blankRenewForm())
const renewErrors = ref({})

function blankRenewForm() {
    return {
        effective_date: '',
        end_date: '',
        borrowing_rate: '',
        discount_margin_rate: '',
        extended_payment_days: '',
        notes: '',
    }
}

function openRenew(agr) {
    renewTarget.value = agr
    renewErrors.value = {}
    renewForm.value = blankRenewForm()
}
function cancelRenew() { renewTarget.value = null }

const currentEndDate = computed(() => {
    if (!renewTarget.value) return null
    const history = renewTarget.value.terms_histories || []
    const latest = history[history.length - 1]
    return latest?.end_date || renewTarget.value.end_date
})

function submitRenew() {
    // Blank fields mean "unchanged from the previous chapter" —
    // handled server-side in FactoringAgreement::renew().
    router.post(route('fi.factoring-agreements.renew', renewTarget.value.id), renewForm.value, {
        preserveScroll: true,
        onError: (errors) => { renewErrors.value = errors },
        onSuccess: () => { renewTarget.value = null },
    })
}

// ── History modal (read-only) ──────────────────────────────────────
const historyTarget = ref(null)
function openHistory(agr) { historyTarget.value = agr }

// ── Delete renewal ──────────────────────────────────────────────────
const deleteRenewalTarget = ref(null)
function confirmDeleteRenewal(agr) { deleteRenewalTarget.value = agr }
function destroyRenewal() {
    router.delete(route('fi.factoring-agreements.delete-renewal', deleteRenewalTarget.value.id), {
        preserveScroll: true,
        onFinish: () => { deleteRenewalTarget.value = null },
    })
}
</script>
<style scoped>
.fi-page { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }
.fi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.fi-header-left { display: flex; align-items: center; gap: 14px; }
.fi-header-icon { width: 42px; height: 42px; border-radius: 10px; background: rgba(15,37,84,0.1); display: flex; align-items: center; justify-content: center; color: #0F2554; flex-shrink: 0; }
.fi-icon-gold { background: rgba(255,191,0,0.1); color: #b8860b; }
.fi-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 0; }
.fi-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 2px 0 0; }
.fi-btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #0F2554; color: #fff; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; }
.fi-btn-primary:hover { background: #1a3a7a; }
.fi-filters { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
.fi-select { padding: 8px 12px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); outline: none; min-width: 160px; }
.fi-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); overflow: hidden; }
.fi-table { width: 100%; border-collapse: collapse; }
.fi-table thead tr { background: #0F2554; }
.fi-table thead th { padding: 11px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap; }
.fi-row { border-bottom: 1px solid var(--vero-border); }
.fi-row:last-child { border-bottom: none; }
.fi-row:hover { background: var(--vero-hover); }
.fi-table td { padding: 12px 14px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }
.fi-name-primary { font-weight: 600; }
.fi-muted { color: var(--vero-text-muted); }
.fi-amount { font-weight: 700; font-variant-numeric: tabular-nums; }
.fi-type-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.fi-type-direct  { background: rgba(15,37,84,0.1); color: #0F2554; }
.fi-type-reverse { background: rgba(20,144,168,0.1); color: #1490A8; }
.fi-recourse { font-size: 12px; font-weight: 600; }
.fi-recourse-with    { color: #f59e0b; }
.fi-recourse-without { color: #10b981; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.fi-action-btn { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--vero-text-muted); text-decoration: none; transition: all 0.15s; }
.fi-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.fi-empty { text-align: center; padding: 60px 20px; }
.text-end { text-align: end; }
.text-center { text-align: center; }
.text-sm { font-size: 13px; }

/* ── Agreement Renewal ─────────────────────────────────────────── */
.fi-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 500; }
.fi-renewal-badge {
    display: block; margin-top: 3px; padding: 0; border: none; background: none;
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
    color: #1490A8; cursor: pointer;
}
.fi-renewal-badge:hover { text-decoration: underline; }

.fi-action-btn-danger { color: var(--vero-danger, #ef4444); }
.fi-action-btn-danger:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.fi-corridor-tag {
    display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 9px;
    background: rgba(20,144,168,0.1); color: #1490A8;
    font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
    margin-inline-start: 5px;
}

.fi-renew-current {
    display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
    background: var(--zv-bg-hover); font-size: 0.8125rem;
}
.fi-renew-current-label { color: var(--vero-text-muted); }

.fi-renew-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1rem; }
.fi-renew-span-2 { grid-column: span 2; }
.fi-renew-locked .zv-readonly-field { display: flex; align-items: center; gap: 6px; }

.fi-history-table th, .fi-history-table td { white-space: nowrap; }
</style>
