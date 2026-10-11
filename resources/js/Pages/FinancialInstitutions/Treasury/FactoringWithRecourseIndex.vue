<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/FactoringWithRecourseIndex.vue

  Lists ONLY Factoring With Recourse transactions and drives Collect /
  Reject / Set to Draft / Delete (+ Undo actions). Deliberately does
  NOT show Without Recourse transactions and does NOT offer a
  shortcut to create one — see FactoringWithoutRecourseIndex.vue for
  its fully separate sibling. Confirmed with Mahmoud: these are
  different products with different lifecycles (Without Recourse has
  no Reject) and must never look like they share one screen.

  Collect/Reject/Set to Draft/Delete post to the neutral
  fi.factoring-transactions.* routes (FactoringTransactionController)
  since those actions are genuinely identical regardless of recourse
  type — only the LIST itself is type-specific, filtered server-side
  by FactoringTransactionService::paginate($companyId, 'with_recourse', ...).

  Collect and Reject bank accounts default to the transaction's own
  disbursement account — Mahmoud's confirmed design — shown
  pre-selected in each modal, with the full bank account list
  available to override.

  @layout    AppLayout
  @route     fi.factoring-with-recourse.index
  @props     transactions, bankAccounts, filters
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.treasury.index')">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.treasuryOperations') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.factoringWithRecourse') }}</span>
                    </div>
                    <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.factoringWithRecourse') }}</h1>
                </div>
                <Link :href="route('fi.factoring-with-recourse.create')" class="zv-btn zv-btn-primary">
                    {{ t('financialInstitutions.treasury.factoringWithRecourseIndex.newFactoringTransaction') }}
                </Link>
            </div>

            <div class="fi-filters mb-4">
                <select v-model="filters.status" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.allStatuses') }}</option>
                    <option value="draft">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.draft') }}</option>
                    <option value="pending">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.pending') }}</option>
                    <option value="collected">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.collected') }}</option>
                    <option value="rejected">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.rejected') }}</option>
                </select>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.factoringDate') }}</th>
                            <th>{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.invoice') }}</th>
                            <th>{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.customer') }}</th>
                            <th>{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.agreement') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.factoringAmount') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.receivedAmount') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.status') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="transactions.data.length === 0">
                            <td colspan="8" class="fi-empty"><p class="text-sm opacity-50">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.noFactoringTransactionsYet') }}</p></td>
                        </tr>
                        <tr v-for="row in transactions.data" :key="row.id">
                            <td>{{ fmtDate(row.factoring_date) }}</td>
                            <td class="fi-muted">{{ row.customer_invoice?.invoice_code || '—' }}</td>
                            <td>{{ row.partner?.name_en || '—' }}</td>
                            <td class="fi-muted">{{ row.factoring_agreement?.agreement_reference || ('#' + row.factoring_agreement_id) }}</td>
                            <td class="text-end fi-amount">{{ fmt(row.factoring_amount) }}</td>
                            <td class="text-end fi-amount">{{ fmt(row.received_amount) }}</td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="statusClass(row.status)">
                                    {{ t('financialInstitutions.treasury.factoringWithRecourseIndex.' + row.status) }}
                                </span>
                                <div v-if="row.collection_date" class="fi-sub-date">{{ fmtDate(row.collection_date) }}</div>
                                <div v-if="row.rejection_date" class="fi-sub-date">{{ fmtDate(row.rejection_date) }}</div>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <template v-if="row.status === 'pending'">
                                        <button class="fi-action-btn" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.markCollected')" @click="openCollect(row)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </button>
                                        <button class="fi-action-btn fi-action-btn-danger" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.markRejected')" @click="openReject(row)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                        <button class="fi-action-btn" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.setToDraft')" @click="setToDraft(row)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6l7-3 7 3M5 10v8m4-8v8m6-8v8m4-8v8M3 21h18"/></svg>
                                        </button>
                                    </template>
                                    <button v-if="row.status === 'collected'" class="fi-action-btn" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.undoCollect')" @click="undoCollect(row)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/></svg>
                                    </button>
                                    <button v-if="row.status === 'rejected'" class="fi-action-btn" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.undoReject')" @click="undoReject(row)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/></svg>
                                    </button>
                                    <button v-if="row.status === 'draft'" class="fi-action-btn fi-action-btn-danger" :title="t('financialInstitutions.treasury.factoringWithRecourseIndex.deleteDraft')" @click="destroyDraft(row)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9.5 7V4a1 1 0 011-1h3a1 1 0 011 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════════════════ Collect modal ══════════════════════ -->
            <div v-if="collectTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-md">
                    <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.markCollected') }}</h2>
                    <p class="text-xs zv-text-muted mb-4">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.invoiceClearedAgainstAr', { code: collectTarget.customer_invoice?.invoice_code }) }}</p>

                    <div class="fi-modal-grid">
                        <div>
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.collectionDate') }}</label>
                            <input type="date" v-model="collectForm.collection_date" class="zv-input w-full"/>
                            <p v-if="collectErrors.collection_date" class="zv-field-error">{{ collectErrors.collection_date }}</p>
                        </div>
                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.differenceBankAccount') }}</label>
                            <select v-model="collectForm.bank_account_id" class="zv-select w-full">
                                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }}</option>
                            </select>
                            <p class="field-hint">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.defaultsToTheDisbursementAccount') }}</p>
                        </div>
                        <div class="fi-modal-span-2 fi-modal-note">
                            {{ t('financialInstitutions.treasury.factoringWithRecourseIndex.differenceAmountPreview', { amount: fmt(collectTarget.invoice_amount - collectTarget.factoring_amount) }) }}
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="collectTarget = null">{{ t('common.cancel') }}</button>
                        <button type="button" class="zv-btn zv-btn-primary" @click="submitCollect">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.confirmCollected') }}</button>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════ Reject modal ══════════════════════ -->
            <div v-if="rejectTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-md">
                    <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.markRejected') }}</h2>
                    <p class="text-xs zv-text-muted mb-4">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.companyRepaysFactoringCompany', { code: rejectTarget.customer_invoice?.invoice_code }) }}</p>

                    <div class="fi-modal-grid">
                        <div>
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.rejectionDate') }}</label>
                            <input type="date" v-model="rejectForm.rejection_date" class="zv-input w-full"/>
                            <p v-if="rejectErrors.rejection_date" class="zv-field-error">{{ rejectErrors.rejection_date }}</p>
                        </div>
                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.uncollectedInvoiceCharges') }}</label>
                            <input type="number" step="0.01" min="0" v-model="rejectForm.uncollected_invoice_charges" class="zv-input w-full"/>
                        </div>
                        <div class="fi-modal-span-2">
                            <label class="zv-label">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.repaymentBankAccount') }}</label>
                            <select v-model="rejectForm.bank_account_id" class="zv-select w-full">
                                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }}</option>
                            </select>
                            <p class="field-hint">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.defaultsToTheDisbursementAccount') }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="rejectTarget = null">{{ t('common.cancel') }}</button>
                        <button type="button" class="zv-btn zv-btn-danger" @click="submitReject">{{ t('financialInstitutions.treasury.factoringWithRecourseIndex.confirmRejected') }}</button>
                    </div>
                </div>
            </div>

        </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

const props = defineProps({ transactions: Object, bankAccounts: Array, filters: Object })
const filters = reactive({ status: props.filters?.status ?? '' })

function applyFilters() {
    router.get(route('fi.factoring-with-recourse.index'), { status: filters.status || undefined }, { preserveState: true, replace: true })
}

function fmtDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function statusClass(status) {
    return { draft: 'fi-status-draft', pending: 'fi-status-pending', collected: 'fi-status-active', rejected: 'fi-status-inactive' }[status] || ''
}

// ── Collect ──────────────────────────────────────────────────────
const collectTarget = ref(null)
const collectForm = reactive({ collection_date: '', bank_account_id: '' })
const collectErrors = ref({})

function openCollect(row) {
    collectTarget.value = row
    collectErrors.value = {}
    collectForm.collection_date = todayLocal()
    collectForm.bank_account_id = row.bank_account_id // defaults to the disbursement account
}

function submitCollect() {
    router.post(route('fi.factoring-transactions.collect', collectTarget.value.id), { ...collectForm }, {
        preserveScroll: true,
        onError: (errors) => { collectErrors.value = errors },
        onSuccess: () => { collectTarget.value = null },
    })
}

function undoCollect(row) {
    router.delete(route('fi.factoring-transactions.collect.undo', row.id), { preserveScroll: true })
}

// ── Reject ───────────────────────────────────────────────────────
const rejectTarget = ref(null)
const rejectForm = reactive({ rejection_date: '', uncollected_invoice_charges: 0, bank_account_id: '' })
const rejectErrors = ref({})

function openReject(row) {
    rejectTarget.value = row
    rejectErrors.value = {}
    rejectForm.rejection_date = todayLocal()
    rejectForm.uncollected_invoice_charges = 0
    rejectForm.bank_account_id = row.bank_account_id // defaults to the disbursement account
}

function submitReject() {
    router.post(route('fi.factoring-transactions.reject', rejectTarget.value.id), { ...rejectForm }, {
        preserveScroll: true,
        onError: (errors) => { rejectErrors.value = errors },
        onSuccess: () => { rejectTarget.value = null },
    })
}

function undoReject(row) {
    router.delete(route('fi.factoring-transactions.reject.undo', row.id), { preserveScroll: true })
}

// ── Set to Draft / Delete ──────────────────────────────────────────
function setToDraft(row) {
    if (! confirm(t('financialInstitutions.treasury.factoringWithRecourseIndex.setToDraftConfirm'))) return
    router.post(route('fi.factoring-transactions.set-to-draft', row.id), {}, { preserveScroll: true })
}

function destroyDraft(row) {
    if (! confirm(t('financialInstitutions.treasury.factoringWithRecourseIndex.deleteDraftConfirm'))) return
    router.delete(route('fi.factoring-transactions.destroy', row.id), { preserveScroll: true })
}
</script>

<style scoped>
.fi-filters { display: flex; gap: 10px; }
.fi-select { padding: 7px 10px; border-radius: var(--zv-radius); border: 1px solid var(--zv-border); font-size: 13px; background: var(--zv-bg-surface); }
.fi-card { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); background: var(--zv-bg-surface); overflow: hidden; }
.fi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fi-table thead { background: #0F2554; }
.fi-table thead th { padding: 10px 14px; text-align: start; color: #fff; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.03em; }
.fi-table tbody td { padding: 10px 14px; border-top: 1px solid var(--zv-border); vertical-align: top; }
.fi-muted { color: var(--zv-text-muted); }
.fi-amount { font-variant-numeric: tabular-nums; }
.fi-empty { text-align: center; padding: 60px 20px; }
.text-end { text-align: end; }
.text-center { text-align: center; }
.text-sm { font-size: 13px; }

.fi-status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
.fi-status-draft     { background: rgba(148,163,184,0.15); color: #64748b; }
.fi-status-pending  { background: rgba(184,134,11,0.12); color: #b8860b; }
.fi-status-active   { background: rgba(34,197,94,0.12); color: #16a34a; }
.fi-status-inactive { background: rgba(239,68,68,0.12); color: #ef4444; }
.fi-sub-date { font-size: 10.5px; color: var(--zv-text-muted); margin-top: 3px; }

.fi-actions { display: inline-flex; gap: 4px; }
.fi-action-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: none; background: transparent; color: var(--zv-text-secondary); cursor: pointer; }
.fi-action-btn:hover { background: var(--zv-bg-hover); }
.fi-action-btn-danger { color: #ef4444; }
.fi-action-btn-danger:hover { background: rgba(239,68,68,0.1); }

.fi-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1rem; }
.fi-modal-span-2 { grid-column: span 2; }
.fi-modal-note { font-size: 12px; color: var(--zv-text-muted); padding: 8px 10px; border-radius: 8px; background: var(--zv-bg-hover); }
</style>
