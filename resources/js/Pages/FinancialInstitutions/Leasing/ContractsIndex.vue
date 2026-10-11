<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Leasing/ContractsIndex.vue
  Lists all Leasing Contracts. Shows type, treatment, value, dates, status.
  @layout AppLayout | @route fi.leasing-contracts.index
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon fi-icon-teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.leasing.contractsIndex.leasingContracts') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.leasing.contractsIndex.operationalAndCapitalLeaseContracts') }}</p>
                    </div>
                </div>
                <Link :href="route('fi.leasing-contracts.create')" class="fi-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    {{ t('financialInstitutions.leasing.contractsIndex.newLeasingContract') }}
                </Link>
            </div>

            <!-- Filters -->
            <div class="fi-filters">
                <select v-model="filters.leasing_company_id" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.leasing.contractsIndex.allLeasingCompanies') }}</option>
                    <option v-for="co in leasingCompanies" :key="co.id" :value="co.id">{{ co.name_en }}</option>
                </select>
                <select v-model="filters.contract_type" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('financialInstitutions.leasing.contractsIndex.allTypes') }}</option>
                    <option value="operational">{{ t('financialInstitutions.leasing.contractsIndex.operational') }}</option>
                    <option value="capital">{{ t('financialInstitutions.leasing.contractsIndex.capital') }}</option>
                </select>
                <select v-model="filters.status" class="fi-select" @change="applyFilters">
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option value="draft">{{ t('financialInstitutions.leasing.contractsIndex.draft') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="closed">{{ t('common.closed') }}</option>
                </select>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.leasing.contractsIndex.asset') }}</th>
                            <th>{{ t('financialInstitutions.leasing.contractsIndex.leasingCompany') }}</th>
                            <th>{{ t('common.type') }}</th>
                            <th>{{ t('financialInstitutions.leasing.contractsIndex.treatment') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.leasing.contractsIndex.contractValue') }}</th>
                            <th class="text-end">{{ t('financialInstitutions.leasing.contractsIndex.monthlyPmt') }}</th>
                            <th>{{ t('common.endDate') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="contracts.data.length === 0">
                            <td colspan="9" class="fi-empty"><p class="text-sm opacity-50">{{ t('financialInstitutions.leasing.contractsIndex.noLeasingContractsYet') }}</p></td>
                        </tr>
                        <tr v-for="c in contracts.data" :key="c.id" class="fi-row">
                            <td>
                                <div class="fi-name-primary">{{ c.asset_description }}</div>
                                <div v-if="c.contract_reference" class="fi-muted text-xs">{{ c.contract_reference }}</div>
                            </td>
                            <td class="fi-muted">{{ c.leasing_company?.name_en }}</td>
                            <td>
                                <span class="fi-type-badge" :class="c.contract_type === 'capital' ? 'fi-type-capital' : 'fi-type-op'">
                                    {{ c.contract_type === 'capital' ? t('financialInstitutions.leasing.contractsIndex.capital') : t('financialInstitutions.leasing.contractsIndex.operational') }}
                                </span>
                            </td>
                            <td>
                                <span v-if="c.contract_type === 'operational'" class="fi-treatment-badge" :class="c.accounting_treatment === 'rou_b' ? 'fi-treat-rou' : 'fi-treat-exp'">
                                    {{ c.accounting_treatment === 'rou_b' ? 'ROU / IFRS 16' : t('financialInstitutions.leasing.contractsIndex.directExpense') }}
                                </span>
                                <span v-else class="fi-treatment-badge fi-treat-rou">IFRS 16</span>
                            </td>
                            <td class="text-end fi-amount">{{ fmt(c.total_contract_value) }}</td>
                            <td class="text-end fi-muted">{{ fmt(c.monthly_payment) }}</td>
                            <td class="fi-muted">{{ fmtDate(c.end_date) }}</td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="statusClass(c.status)">{{ t(c.status) }}</span>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <!-- Lease Amortization Engine, July 2026 -->
                                    <button v-if="c.status === 'draft' && (c.contract_type === 'capital' || c.accounting_treatment === 'rou_b')"
                                            type="button" class="zv-btn zv-btn-primary zv-btn-sm" @click="activate(c)">
                                        {{ t('common.activate') }}
                                    </button>
                                    <Link v-if="c.status !== 'draft' && (c.contract_type === 'capital' || c.accounting_treatment === 'rou_b')"
                                          :href="route('fi.leasing-contracts.schedule', c.id)" class="zv-btn zv-btn-secondary zv-btn-sm">
                                        {{ t('financialInstitutions.leasing.contractsIndex.schedule') }}
                                    </Link>
                                    <Link :href="route('fi.leasing-contracts.edit', c.id)" class="fi-action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :paginator="contracts" />
            </div>
        </div>
</template>
<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps({ contracts: Object, leasingCompanies: Array, filters: Object })
const filters = ref({ leasing_company_id: props.filters?.leasing_company_id ?? '', contract_type: props.filters?.contract_type ?? '', status: props.filters?.status ?? '' })
function applyFilters() { router.get(route('fi.leasing-contracts.index'), { leasing_company_id: filters.value.leasing_company_id || undefined, contract_type: filters.value.contract_type || undefined, status: filters.value.status || undefined }, { preserveState: true, replace: true }) }
function activate(contract) {
  if (!confirm(t('financialInstitutions.leasing.contractsIndex.activateThisLeaseItsTermsWill'))) return
  router.post(route('fi.leasing-contracts.activate', contract.id))
}
function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(val) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
function statusClass(s) { return { draft: 'fi-draft', active: 'fi-active', closed: 'fi-inactive', cancelled: 'fi-cancelled' }[s] ?? 'fi-inactive' }
</script>
<style scoped>
.fi-page { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }
.fi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.fi-header-left { display: flex; align-items: center; gap: 14px; }
.fi-header-icon { width: 42px; height: 42px; border-radius: 10px; background: rgba(15,37,84,0.1); display: flex; align-items: center; justify-content: center; color: #0F2554; flex-shrink: 0; }
.fi-icon-teal { background: rgba(20,144,168,0.1); color: #1490A8; }
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
.fi-amount { font-weight: 600; font-variant-numeric: tabular-nums; }
.text-xs { font-size: 11px; }
.fi-type-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.fi-type-capital { background: rgba(15,37,84,0.1); color: #0F2554; }
.fi-type-op { background: rgba(20,144,168,0.1); color: #1490A8; }
.fi-treatment-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
.fi-treat-rou { background: rgba(255,191,0,0.12); color: #b8860b; }
.fi-treat-exp { background: rgba(100,116,139,0.1); color: #64748b; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-draft     { background: rgba(148,163,184,0.12); color: #94a3b8; }
.fi-active    { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive  { background: rgba(100,116,139,0.12); color: #64748b; }
.fi-cancelled { background: rgba(239,68,68,0.12); color: #ef4444; }
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.fi-action-btn { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--vero-text-muted); text-decoration: none; transition: all 0.15s; }
.fi-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.fi-empty { text-align: center; padding: 60px 20px; }
.fi-pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 14px; border-top: 1px solid var(--vero-border); }
.fi-page-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--vero-border); background: var(--vero-card); color: var(--vero-text); font-size: 13px; cursor: pointer; }
.fi-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.fi-page-btn:disabled { opacity: 0.4; cursor: default; }
.fi-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }
.text-end { text-align: end; }
.text-center { text-align: center; }
</style>
