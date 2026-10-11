<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/AccountsIndex.vue

  Lists all Bank Accounts for the active company. This file did not
  exist before — BankController::accountsIndex() referenced it, but
  it was never built, exactly like AccountForm.vue.

  Filterable by Bank and Account Type. Shows account number/name,
  type badge, currency, interest terms (when set), COA link, and
  active status. Edit + toggle actions per row.

  @layout    AppLayout
  @route     fi.bank-accounts.index
  @props     accounts: PaginatedCollection, banks: Bank[], filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H5a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.banks.accountsIndex.bankAccounts') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.banks.accountsIndex.currentAccountsTimeDepositsAndCash') }}</p>
                    </div>
                </div>
                <div class="fi-header-actions">
                    <Link :href="route('fi.bank-accounts.create')" class="fi-btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ t('financialInstitutions.banks.accountsIndex.addAccount') }}
                    </Link>
                </div>
            </div>

            <!-- Tabs -->
            <div class="ai-tabs">
                <button type="button" class="ai-tab" :class="{ 'ai-tab-active': filterType === '' }" @click="setTab('')">
                    {{ t('financialInstitutions.banks.accountsIndex.all') }}
                </button>
                <button type="button" class="ai-tab" :class="{ 'ai-tab-active': filterType === 'current' }" @click="setTab('current')">
                    {{ t('financialInstitutions.banks.accountsIndex.current') }}
                </button>
                <button type="button" class="ai-tab" :class="{ 'ai-tab-active': filterType === 'time_deposit' }" @click="setTab('time_deposit')">
                    {{ t('financialInstitutions.banks.accountsIndex.timeDeposit') }}
                </button>
                <button type="button" class="ai-tab" :class="{ 'ai-tab-active': filterType === 'escrow' }" @click="setTab('escrow')">
                    {{ t('financialInstitutions.banks.accountsIndex.escrow') }}
                </button>
                <button type="button" class="ai-tab" :class="{ 'ai-tab-active': filterType === 'cash_cover' }" @click="setTab('cash_cover')">
                    {{ t('financialInstitutions.banks.accountsIndex.cashCover') }}
                </button>
            </div>

            <!-- Filters -->
            <div class="ai-filters">
                <select v-model="filterBank" class="zv-select ai-filter-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.banks.accountsIndex.allBanks') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                </select>
                <button v-if="filterBank" type="button" class="ai-clear-btn" @click="clearFilters">
                    {{ t('financialInstitutions.banks.accountsIndex.clearFilters') }}
                </button>
            </div>

            <div class="fi-card">
                <table class="zv-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.banks.accountsIndex.bank') }}</th>
                            <th>{{ t('financialInstitutions.banks.accountsIndex.account') }}</th>
                            <th>{{ t('common.type') }}</th>
                            <th>{{ t('financialInstitutions.banks.accountsIndex.currency') }}</th>
                            <th>{{ t('financialInstitutions.banks.accountsIndex.interestTerms') }}</th>
                            <th>{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!accounts.data.length">
                            <td colspan="7">
                                <div class="fi-empty">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-10 h-10 opacity-30 mb-2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H5a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                    </svg>
                                    <p class="text-sm opacity-50">{{ t('financialInstitutions.banks.accountsIndex.noBankAccountsYetAddYour') }}</p>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="account in accounts.data" :key="account.id">
                            <td>
                                <div class="fi-name-primary">{{ account.bank?.name_en }}</div>
                            </td>
                            <td>
                                <div class="fi-name-primary">{{ account.account_name || '—' }}</div>
                                <div class="fi-name-ar font-mono" style="direction: ltr;">{{ account.account_number || '—' }}</div>
                            </td>
                            <td>
                                <span class="ai-type-badge" :class="`ai-type-${account.account_type}`">
                                    {{ typeLabel(account.account_type) }}
                                </span>
                            </td>
                            <td class="fi-muted">{{ account.currency?.code ?? '—' }}</td>
                            <td class="fi-muted">
                                <span v-if="account.account_type === 'current' && account.interest_rate">
                                    {{ account.interest_rate }}%
                                    <span v-if="account.minimum_balance_for_interest" class="ai-min-bal">
                                        ({{ t('financialInstitutions.banks.accountsIndex.min') }} {{ fmt(account.minimum_balance_for_interest) }})
                                    </span>
                                </span>
                                <span v-else-if="account.account_type === 'time_deposit' && account.td_interest_rate">
                                    {{ account.td_interest_rate }}%
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td>
                                <span class="fi-status-badge" :class="account.is_active ? 'fi-active' : 'fi-inactive'">
                                    {{ account.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </td>
                            <td>
                                <div class="fi-actions">
                                    <Link :href="route('fi.bank-accounts.edit', account.id)" class="fi-action-btn" :title="t('common.edit')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </Link>
                                    <Link :href="route('fi.bank-accounts.toggle', account.id)" method="patch" as="button"
                                          class="fi-action-btn" :class="account.is_active ? 'fi-action-deactivate' : 'fi-action-activate'"
                                          :title="account.is_active ? t('common.deactivate') : t('common.activate')">
                                        <svg v-if="account.is_active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/>
                                        </svg>
                                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </Link>

                                    <template v-if="account.account_type === 'time_deposit'">
                                        <button type="button" class="fi-action-btn" :title="t('financialInstitutions.banks.accountsIndex.history')" @click="openHistory(account)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        </button>

                                        <div v-if="account.is_active" class="ai-td-menu">
                                            <button type="button" class="fi-action-btn" :title="t('financialInstitutions.banks.accountsIndex.timeDepositActions')" @click="toggleTdMenu(account.id)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01"/>
                                                </svg>
                                            </button>
                                            <div v-if="openTdMenuId === account.id" class="ai-td-dropdown" @click.self.stop>
                                                <button type="button" class="ai-td-dropdown-item" @click="openTdAction(account, 'interest')">{{ t('financialInstitutions.banks.accountsIndex.applyInterest') }}</button>
                                                <button type="button" class="ai-td-dropdown-item" @click="openTdAction(account, 'renew')">{{ t('financialInstitutions.banks.accountsIndex.renew') }}</button>
                                                <button type="button" class="ai-td-dropdown-item" @click="openTdAction(account, 'break')">{{ t('financialInstitutions.banks.accountsIndex.breakTD') }}</button>
                                                <button type="button" class="ai-td-dropdown-item" @click="openTdAction(account, 'maturity')">{{ t('financialInstitutions.banks.accountsIndex.applyAtMaturity') }}</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <Pagination :paginator="accounts" />
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TD ACTION MODAL — Apply Interest / Renew / Break / Maturity
        ══════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="tdAction.account" class="ai-modal-overlay" @click.self="closeTdAction">
                <div class="ai-modal">
                    <h3 class="ai-modal-title">{{ tdActionTitle }} — {{ tdAction.account.account_name }}</h3>
                    <p class="ai-modal-hint">{{ tdActionHint }}</p>

                    <div v-if="tdActionError" class="zv-alert zv-alert-danger mb-3">{{ tdActionError }}</div>

                    <div class="field-group mb-3">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountsIndex.date') }}</label>
                        <input v-model="tdAction.form.event_date" type="date" class="zv-input"/>
                    </div>

                    <template v-if="tdAction.type === 'break' || tdAction.type === 'maturity'">
                        <div class="field-group mb-3">
                            <label class="zv-label">{{ t('financialInstitutions.banks.accountsIndex.tDAmountPrincipal') }}</label>
                            <input :value="fmt(tdAction.account.td_amount)" type="text" class="zv-input text-end" readonly disabled/>
                            <p class="field-hint">{{ t('financialInstitutions.banks.accountsIndex.alwaysTheFullDepositThisAction') }}</p>
                        </div>
                    </template>

                    <template v-if="tdAction.type === 'renew'">
                        <div class="field-grid field-grid-2 mb-3">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountsIndex.newMaturityDate') }}</label>
                                <input v-model="tdAction.form.new_maturity_date" type="date" class="zv-input"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountsIndex.interestRatePa') }}</label>
                                <input v-model="tdAction.form.new_interest_rate" type="number" step="0.0001" min="0" max="100" class="zv-input text-end"/>
                            </div>
                        </div>
                    </template>

                    <div class="field-group mb-3">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountsIndex.interestAmount') }}</label>
                        <input v-model="tdAction.form.interest_amount" type="number" step="0.01" min="0" class="zv-input text-end" placeholder="0.00"/>
                        <p class="field-hint">{{ t('financialInstitutions.banks.accountsIndex.whateverTheBankActuallyPaidCredited') }}</p>
                    </div>

                    <div class="field-group mb-4">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.accountsIndex.addToAccount') }}</label>
                        <select v-model="tdAction.form.credit_bank_account_id" class="zv-select">
                            <option value="">{{ t('financialInstitutions.banks.accountsIndex.selectCurrentAccount') }}</option>
                            <option v-for="a in currentAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_name }} ({{ a.account_number || t('financialInstitutions.banks.accountsIndex.noNumber') }})</option>
                        </select>
                    </div>

                    <div class="ai-modal-actions">
                        <button type="button" class="zv-btn zv-btn-ghost" @click="closeTdAction">{{ t('common.cancel') }}</button>
                        <button type="button" class="zv-btn zv-btn-primary" :disabled="tdActionSubmitting" @click="submitTdAction">
                            {{ tdActionSubmitting ? t('common.saving') : t('common.confirm') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══════════════════════════════════════════════════════
             TD HISTORY MODAL
        ══════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="historyAccount" class="ai-modal-overlay" @click.self="historyAccount = null">
                <div class="ai-modal ai-modal-wide">
                    <h3 class="ai-modal-title">{{ t('financialInstitutions.banks.accountsIndex.timeDepositHistory') }} — {{ historyAccount.account_name }}</h3>
                    <p class="ai-modal-hint">{{ t('financialInstitutions.banks.accountsIndex.everyInterestApplicationBreakMaturityAnd') }}</p>

                    <div v-if="historyLoading" class="text-sm opacity-50" style="padding: 20px 0;">{{ t('common.loading') }}</div>
                    <div v-else-if="!historyEvents.length" class="text-sm opacity-50" style="padding: 20px 0;">{{ t('financialInstitutions.banks.accountsIndex.noEventsRecordedYet') }}</div>
                    <div v-else class="zv-table-wrap" style="border: none; box-shadow: none;">
                        <table class="zv-table">
                            <thead>
                                <tr>
                                    <th>{{ t('financialInstitutions.banks.accountsIndex.date') }}</th>
                                    <th>{{ t('financialInstitutions.banks.accountsIndex.event') }}</th>
                                    <th class="text-end">{{ t('financialInstitutions.banks.accountsIndex.interest') }}</th>
                                    <th class="text-end">{{ t('financialInstitutions.banks.accountsIndex.principal') }}</th>
                                    <th>{{ t('financialInstitutions.banks.accountsIndex.maturityChange') }}</th>
                                    <th>{{ t('financialInstitutions.banks.accountsIndex.creditedTo') }}</th>
                                    <th>{{ t('financialInstitutions.banks.accountsIndex.by') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ev in historyEvents" :key="ev.id">
                                    <td>{{ ev.event_date }}</td>
                                    <td><span class="ai-event-badge" :class="`ai-event-${ev.event_type}`">{{ eventLabel(ev.event_type) }}</span></td>
                                    <td class="text-end">{{ fmt(ev.interest_amount) }}</td>
                                    <td class="text-end">{{ ev.principal_amount ? fmt(ev.principal_amount) : '—' }}</td>
                                    <td>
                                        <span v-if="ev.event_type === 'renewal'">{{ ev.old_maturity_date }} → {{ ev.new_maturity_date }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td>{{ ev.credit_bank_account?.account_name ?? '—' }}</td>
                                    <td>{{ ev.created_by?.name ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="ai-modal-actions">
                        <button type="button" class="zv-btn zv-btn-ghost" @click="historyAccount = null">{{ t('financialInstitutions.banks.accountsIndex.close') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import Pagination from '@/Components/Pagination.vue'
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    accounts:        Object,
    banks:           Array,
    currentAccounts: Array,
    filters:         Object,
})

const filterBank = ref(props.filters?.bank_id ?? '')
const filterType = ref(props.filters?.account_type ?? '')

function applyFilters() {
    router.get(route('fi.bank-accounts.index'), {
        bank_id: filterBank.value || undefined,
        account_type: filterType.value || undefined,
    }, { preserveState: true, replace: true })
}

function setTab(type) {
    filterType.value = type
    applyFilters()
}

function clearFilters() {
    filterBank.value = ''
    applyFilters()
}

function typeLabel(type) {
    const map = {
        current: 'Current',
        time_deposit: 'Time Deposit',
        escrow: 'Escrow',
        lg_cash_cover: 'LG Cash Cover',
        lc_cash_cover: 'LC Cash Cover',
    }
    return t(map[type] ?? type)
}

function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Time Deposit action dropdown (per row) ──────────────────────────

const openTdMenuId = ref(null)
function toggleTdMenu(accountId) {
    openTdMenuId.value = openTdMenuId.value === accountId ? null : accountId
}

// ── Time Deposit action modal — Apply Interest / Renew / Break / Maturity ──

const tdAction = ref({ type: null, account: null, form: {} })
const tdActionSubmitting = ref(false)
const tdActionError = ref('')

const todayIso = () => todayLocal()

function openTdAction(account, type) {
    openTdMenuId.value = null
    tdActionError.value = ''
    tdAction.value = {
        type,
        account,
        form: {
            event_date: todayIso(),
            interest_amount: '',
            credit_bank_account_id: '',
            ...(type === 'renew' ? { new_maturity_date: '', new_interest_rate: account.td_interest_rate ?? '' } : {}),
        },
    }
}

function closeTdAction() {
    tdAction.value = { type: null, account: null, form: {} }
    tdActionError.value = ''
}

const tdActionTitle = computed(() => {
    const map = { interest: t('financialInstitutions.banks.accountsIndex.applyInterest'), renew: t('financialInstitutions.banks.accountsIndex.renew'), break: t('financialInstitutions.banks.accountsIndex.breakTD'), maturity: t('financialInstitutions.banks.accountsIndex.applyAtMaturity') }
    return map[tdAction.value.type] ?? ''
})

const tdActionHint = computed(() => {
    const map = {
        interest: t('financialInstitutions.banks.accountsIndex.recordsAPeriodicInterestPaymentThe'),
        renew:    t('financialInstitutions.banks.accountsIndex.realizesInterestForTheCompletedTerm'),
        break:    t('financialInstitutions.banks.accountsIndex.closesThisTimeDepositEarlyFull'),
        maturity: t('financialInstitutions.banks.accountsIndex.closesThisTimeDepositOnSchedule'),
    }
    return map[tdAction.value.type] ?? ''
})

function submitTdAction() {
    const routeMap = {
        interest: 'fi.bank-accounts.td.interest',
        renew:    'fi.bank-accounts.td.renew',
        break:    'fi.bank-accounts.td.break',
        maturity: 'fi.bank-accounts.td.mature',
    }
    tdActionSubmitting.value = true
    tdActionError.value = ''

    fetch(route(routeMap[tdAction.value.type], tdAction.value.account.id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            'Accept': 'application/json',
        },
        body: JSON.stringify(tdAction.value.form),
    })
        .then(async (res) => {
            const data = await res.json()
            if (!res.ok) { tdActionError.value = data.message || t('financialInstitutions.banks.accountsIndex.somethingWentWrongPleaseTryAgain'); return }
            closeTdAction()
            router.reload({ preserveScroll: true })
        })
        .catch(() => { tdActionError.value = t('financialInstitutions.banks.accountsIndex.somethingWentWrongPleaseTryAgain') })
        .finally(() => { tdActionSubmitting.value = false })
}

// ── Time Deposit history modal ──────────────────────────────────────

const historyAccount = ref(null)
const historyEvents = ref([])
const historyLoading = ref(false)

function openHistory(account) {
    historyAccount.value = account
    historyEvents.value = []
    historyLoading.value = true

    fetch(route('fi.bank-accounts.td.history', account.id), {
        headers: { 'Accept': 'application/json' },
    })
        .then(res => res.json())
        .then(data => { historyEvents.value = data.events ?? [] })
        .finally(() => { historyLoading.value = false })
}

function eventLabel(type) {
    const map = { interest: 'Interest', break: 'Break', maturity: 'Maturity', renewal: 'Renewal' }
    return t(map[type] ?? type)
}
</script>

<style>
.fi-page { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }
.fi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 14px; }
.fi-header-left { display: flex; align-items: center; gap: 14px; }
.fi-header-icon {
    width: 44px; height: 44px; border-radius: 10px; background: rgba(15,37,84,0.08);
    display: flex; align-items: center; justify-content: center; color: #0F2554; flex-shrink: 0;
}
[data-theme="dark"] .fi-header-icon { background: rgba(255,255,255,0.06); color: #90a4c4; }
.fi-title { font-size: 20px; font-weight: 700; color: var(--vero-text); margin: 0; }
.fi-subtitle { font-size: 12.5px; color: var(--vero-text-muted); margin: 2px 0 0; }
.fi-header-actions { display: flex; align-items: center; gap: 10px; }

.fi-btn-primary {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
    background: #0F2554; color: #fff; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; text-decoration: none; transition: background 0.15s;
}
.fi-btn-primary:hover { background: #1a3a7a; }

.ai-filters { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.ai-filter-select { max-width: 220px; }
.ai-clear-btn {
    padding: 8px 14px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
    background: transparent; color: var(--zv-text-muted); font-size: 12.5px; cursor: pointer;
}
.ai-clear-btn:hover { color: #ef4444; border-color: #ef4444; }

.fi-card { background: var(--vero-card); border: 1px solid var(--vero-border); border-radius: 12px; overflow: hidden; }

.fi-name-primary { font-weight: 600; color: var(--vero-text); }
.fi-name-ar { font-size: 12px; color: var(--vero-text-muted); margin-top: 2px; }
.fi-muted { color: var(--vero-text-muted); font-size: 13px; }

.ai-type-badge {
    display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
}
.ai-type-current       { background: rgba(16,185,129,0.12); color: #10b981; }
.ai-type-time_deposit  { background: rgba(20,144,168,0.12); color: #1490A8; }
.ai-type-escrow        { background: rgba(245,158,11,0.12); color: #f59e0b; }
.ai-type-lg_cash_cover,
.ai-type-lc_cash_cover { background: rgba(15,37,84,0.1);  color: #0F2554; }

.ai-min-bal { font-size: 11px; color: var(--vero-text-muted); }

.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }

.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
.fi-action-btn {
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: transparent; cursor: pointer; color: var(--vero-text-muted);
    text-decoration: none; transition: all 0.15s; flex-shrink: 0;
}
.fi-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.fi-action-deactivate:hover { color: #f59e0b; }
.fi-action-activate:hover   { color: #10b981; }

.fi-empty { text-align: center; padding: 60px 20px; display: flex; flex-direction: column; align-items: center; }
.fi-pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 14px; border-top: 1px solid var(--vero-border); }
.fi-page-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--vero-border); background: var(--vero-card); color: var(--vero-text); font-size: 13px; cursor: pointer; transition: all 0.12s; }
.fi-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.fi-page-btn:disabled { opacity: 0.4; cursor: default; }
.fi-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }

.text-end { text-align: end; }
.text-sm { font-size: 13px; }

/* ── Tabs ─────────────────────────────────────────────────────── */
.ai-tabs { display: flex; align-items: center; gap: 4px; margin-bottom: 14px; border-bottom: 1px solid var(--vero-border); }
.ai-tab {
    padding: 9px 16px; border: none; background: transparent; cursor: pointer;
    font-size: 13px; font-weight: 600; color: var(--vero-text-muted);
    border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s;
}
.ai-tab:hover { color: #0F2554; }
.ai-tab-active { color: #0F2554; border-bottom-color: #FFBF00; }
[data-theme="dark"] .ai-tab-active { color: #90a4c4; }

/* ── TD action dropdown ──────────────────────────────────────────── */
.ai-td-menu { position: relative; }
.ai-td-dropdown {
    position: absolute; top: 100%; right: 0; z-index: 30; margin-top: 4px;
    background: var(--vero-card); border: 1px solid var(--vero-border); border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 170px; padding: 4px; display: flex; flex-direction: column;
}
.ai-td-dropdown-item {
    text-align: left; padding: 8px 10px; border: none; background: transparent; cursor: pointer;
    font-size: 12.5px; color: var(--vero-text); border-radius: 6px; white-space: nowrap;
}
.ai-td-dropdown-item:hover { background: var(--vero-hover); color: #1490A8; }

/* ── Action / History modal ──────────────────────────────────────── */
.ai-modal-overlay {
    position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.ai-modal {
    background: var(--vero-card); border-radius: 12px; padding: 24px;
    width: 100%; max-width: 440px; max-height: 88vh; overflow-y: auto;
}
.ai-modal-wide { max-width: 760px; }
.ai-modal-title { font-size: 15px; font-weight: 700; color: var(--vero-text); margin: 0 0 4px; }
.ai-modal-hint { font-size: 12.5px; color: var(--vero-text-muted); margin: 0 0 16px; }
.ai-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 6px; }

.ai-event-badge {
    display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
}
.ai-event-interest { background: rgba(20,144,168,0.12); color: #1490A8; }
.ai-event-renewal  { background: rgba(16,185,129,0.12); color: #10b981; }
.ai-event-break    { background: rgba(239,68,68,0.12);  color: #ef4444; }
.ai-event-maturity { background: rgba(245,158,11,0.12); color: #f59e0b; }
</style>
