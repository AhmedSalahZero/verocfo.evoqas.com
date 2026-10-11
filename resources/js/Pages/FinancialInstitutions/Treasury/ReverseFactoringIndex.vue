<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/ReverseFactoringIndex.vue

  Lists Reverse Factoring transactions and drives Settle (due-date
  path only) / Set to Draft / Delete (+ Undo Settle). A cheque-settled
  transaction has nothing to do here after creation — its individual
  cheques are tracked and marked paid from the existing Cheques
  Payable screen, not from this page.

  @layout    AppLayout
  @route     fi.reverse-factoring.index
  @props     transactions, bankAccounts, filters
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.treasury.index')">{{ t('financialInstitutions.treasury.reverseFactoringIndex.treasuryOperations') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('financialInstitutions.treasury.reverseFactoringIndex.reverseFactoring') }}</span>
                    </div>
                    <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.reverseFactoringIndex.reverseFactoring') }}</h1>
                </div>
                <Link :href="route('fi.reverse-factoring.create')" class="zv-btn zv-btn-primary">
                    {{ t('financialInstitutions.treasury.reverseFactoringIndex.newReverseFactoring') }}
                </Link>
            </div>

            <div class="fi-filters mb-4">
                <select v-model="filters.status" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.treasury.reverseFactoringIndex.allStatuses') }}</option>
                    <option value="draft">{{ t('financialInstitutions.treasury.reverseFactoringIndex.draft') }}</option>
                    <option value="pending">{{ t('financialInstitutions.treasury.reverseFactoringIndex.pending') }}</option>
                    <option value="settled">{{ t('financialInstitutions.treasury.reverseFactoringIndex.settled') }}</option>
                </select>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.treasury.reverseFactoringIndex.reverseFactoringDate') }}</th>
                            <th>{{ t('financialInstitutions.treasury.reverseFactoringIndex.bill') }}</th>
                            <th>{{ t('financialInstitutions.treasury.reverseFactoringIndex.supplier') }}</th>
                            <th>{{ t('financialInstitutions.treasury.reverseFactoringIndex.agreement') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.treasury.reverseFactoringIndex.reverseFactoringAmount') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.treasury.reverseFactoringIndex.settlementMethod') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.treasury.reverseFactoringIndex.status') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.treasury.reverseFactoringIndex.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="transactions.data.length === 0">
                            <td colspan="8" class="fi-empty"><p class="text-sm opacity-50">{{ t('financialInstitutions.treasury.reverseFactoringIndex.noReverseFactoringTransactionsYet') }}</p></td>
                        </tr>
                        <tr v-for="row in transactions.data" :key="row.id">
                            <td>{{ fmtDate(row.reverse_factoring_date) }}</td>
                            <td class="fi-muted">{{ row.supplier_bill?.bill_code || '—' }}</td>
                            <td>{{ row.partner?.name_en || '—' }}</td>
                            <td class="fi-muted">{{ row.factoring_agreement?.agreement_reference || ('#' + row.factoring_agreement_id) }}</td>
                            <td class="text-end fi-amount">{{ fmt(row.reverse_factoring_amount) }}</td>
                            <td class="text-center">
                                <span class="fi-method-tag">{{ row.settlement_method === 'cheques' ? t('financialInstitutions.treasury.reverseFactoringIndex.cheques') : t('financialInstitutions.treasury.reverseFactoringIndex.dueDate') }}</span>
                            </td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="statusClass(row.status)">
                                    {{ t('financialInstitutions.treasury.reverseFactoringIndex.' + row.status) }}
                                </span>
                                <div v-if="row.settlement_date" class="fi-sub-date">{{ fmtDate(row.settlement_date) }}</div>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <template v-if="row.status === 'pending' && row.settlement_method === 'due_date'">
                                        <button class="fi-action-btn" :title="t('financialInstitutions.treasury.reverseFactoringIndex.settle')" @click="openSettle(row)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </button>
                                        <button class="fi-action-btn" :title="t('financialInstitutions.treasury.reverseFactoringIndex.setToDraft')" @click="setToDraft(row)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6l7-3 7 3M5 10v8m4-8v8m6-8v8m4-8v8M3 21h18"/></svg>
                                        </button>
                                    </template>
                                    <button v-if="row.status === 'settled' && row.settlement_method === 'due_date'" class="fi-action-btn" :title="t('financialInstitutions.treasury.reverseFactoringIndex.undoSettle')" @click="undoSettle(row)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/></svg>
                                    </button>
                                    <span v-if="row.status === 'settled' && row.settlement_method === 'cheques'" class="fi-muted text-xs">{{ t('financialInstitutions.treasury.reverseFactoringIndex.seeChequesPayable') }}</span>
                                    <button v-if="row.status === 'draft'" class="fi-action-btn fi-action-btn-danger" :title="t('financialInstitutions.treasury.reverseFactoringIndex.deleteDraft')" @click="destroyDraft(row)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9.5 7V4a1 1 0 011-1h3a1 1 0 011 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════════════════ Settle modal ══════════════════════ -->
            <div v-if="settleTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="zv-modal rounded-lg p-6 w-full max-w-md">
                    <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.treasury.reverseFactoringIndex.settle') }}</h2>
                    <p class="text-xs zv-text-muted mb-4">{{ t('financialInstitutions.treasury.reverseFactoringIndex.companyRepaysFactoringCompany', { code: settleTarget.supplier_bill?.bill_code, amount: fmt(Number(settleTarget.reverse_factoring_amount||0)+Number(settleTarget.reverse_factoring_interest_amount||0)+Number(settleTarget.reverse_factoring_charges||0)) }) }}</p>

                    <div class="fi-modal-grid">
                        <div>
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.reverseFactoringIndex.settlementDate') }}</label>
                            <input type="date" v-model="settleForm.settlement_date" class="zv-input w-full"/>
                            <p v-if="settleErrors.settlement_date" class="zv-field-error">{{ settleErrors.settlement_date }}</p>
                        </div>
                        <div>
                            <label class="zv-label">{{ t('financialInstitutions.treasury.reverseFactoringIndex.bankAccount') }}</label>
                            <select v-model="settleForm.bank_account_id" class="zv-select w-full">
                                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }}</option>
                            </select>
                            <p class="field-hint">{{ t('financialInstitutions.treasury.reverseFactoringIndex.defaultsToTheSourceAccount') }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" class="zv-btn zv-btn-secondary" @click="settleTarget = null">{{ t('common.cancel') }}</button>
                        <button type="button" class="zv-btn zv-btn-primary" @click="submitSettle">{{ t('financialInstitutions.treasury.reverseFactoringIndex.confirmSettled') }}</button>
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
    router.get(route('fi.reverse-factoring.index'), { status: filters.status || undefined }, { preserveState: true, replace: true })
}

function fmtDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function statusClass(status) {
    return { draft: 'fi-status-draft', pending: 'fi-status-pending', settled: 'fi-status-active' }[status] || ''
}

// ── Settle ───────────────────────────────────────────────────────
const settleTarget = ref(null)
const settleForm = reactive({ settlement_date: '', bank_account_id: '' })
const settleErrors = ref({})

function openSettle(row) {
    settleTarget.value = row
    settleErrors.value = {}
    settleForm.settlement_date = todayLocal()
    settleForm.bank_account_id = row.source_bank_account_id
}

function submitSettle() {
    router.post(route('fi.reverse-factoring.settle', settleTarget.value.id), { ...settleForm }, {
        preserveScroll: true,
        onError: (errors) => { settleErrors.value = errors },
        onSuccess: () => { settleTarget.value = null },
    })
}

function undoSettle(row) {
    router.delete(route('fi.reverse-factoring.settle.undo', row.id), { preserveScroll: true })
}

// ── Set to Draft / Delete ──────────────────────────────────────────
function setToDraft(row) {
    if (! confirm(t('financialInstitutions.treasury.reverseFactoringIndex.setToDraftConfirm'))) return
    router.post(route('fi.reverse-factoring.set-to-draft', row.id), {}, { preserveScroll: true })
}

function destroyDraft(row) {
    if (! confirm(t('financialInstitutions.treasury.reverseFactoringIndex.deleteDraftConfirm'))) return
    router.delete(route('fi.reverse-factoring.destroy', row.id), { preserveScroll: true })
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
.text-xs { font-size: 11px; }

.fi-status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
.fi-status-draft    { background: rgba(148,163,184,0.15); color: #64748b; }
.fi-status-pending  { background: rgba(184,134,11,0.12); color: #b8860b; }
.fi-status-active   { background: rgba(34,197,94,0.12); color: #16a34a; }
.fi-sub-date { font-size: 10.5px; color: var(--zv-text-muted); margin-top: 3px; }

.fi-method-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; background: rgba(20,144,168,0.1); color: #1490A8; }

.fi-actions { display: inline-flex; gap: 4px; align-items: center; }
.fi-action-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: none; background: transparent; color: var(--zv-text-secondary); cursor: pointer; }
.fi-action-btn:hover { background: var(--zv-bg-hover); }
.fi-action-btn-danger { color: #ef4444; }
.fi-action-btn-danger:hover { background: rgba(239,68,68,0.1); }

.fi-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1rem; }
</style>
