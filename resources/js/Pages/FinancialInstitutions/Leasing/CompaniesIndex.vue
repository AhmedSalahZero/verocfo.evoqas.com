<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Leasing/CompaniesIndex.vue
  Lists all Leasing Companies. Shows contract count per company.
  @layout AppLayout | @route fi.leasing-companies.index
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon fi-icon-teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/></svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.leasing.companiesIndex.leasingCompanies') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.leasing.companiesIndex.fRARegulatedLeasingInstitutionsRatesSet') }}</p>
                    </div>
                </div>
                <Link :href="route('fi.leasing-companies.create')" class="fi-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    {{ t('financialInstitutions.leasing.companiesIndex.addLeasingCompany') }}
                </Link>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.leasing.companiesIndex.companyName') }}</th>
                            <th>{{ t('financialInstitutions.leasing.companiesIndex.contactPerson') }}</th>
                            <th>{{ t('financialInstitutions.leasing.companiesIndex.phone') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.leasing.companiesIndex.contracts') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="companies.data.length === 0">
                            <td colspan="6" class="fi-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5"/></svg>
                                <p class="text-sm opacity-50">{{ t('financialInstitutions.leasing.companiesIndex.noLeasingCompaniesAddedYet') }}</p>
                            </td>
                        </tr>
                        <tr v-for="co in companies.data" :key="co.id" class="fi-row">
                            <td>
                                <div class="fi-name-primary">{{ co.name_en }}</div>
                                <div v-if="co.name_ar" class="fi-name-ar">{{ co.name_ar }}</div>
                            </td>
                            <td class="fi-muted">{{ co.contact_person || '—' }}</td>
                            <td class="fi-muted">{{ co.phone || '—' }}</td>
                            <td class="text-center">
                                <span class="fi-count-badge fi-count-teal">{{ co.contracts_count ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="co.is_active ? 'fi-active' : 'fi-inactive'">
                                    {{ co.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <Link :href="route('fi.leasing-companies.edit', co.id)" class="fi-action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></Link>
                                    <button class="fi-action-btn" @click="toggle(co)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path v-if="co.is_active" stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/><path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></button>
                                    <Link :href="route('fi.leasing-contracts.index') + '?leasing_company_id=' + co.id" class="fi-action-btn fi-action-accounts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :paginator="companies" />
            </div>
        </div>
</template>
<script setup>
import Pagination from '@/Components/Pagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps({ companies: Object, filters: Object })
function toggle(co) { router.patch(route('fi.leasing-companies.toggle', co.id)) }
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
.fi-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); overflow: hidden; }
.fi-table { width: 100%; border-collapse: collapse; }
.fi-table thead tr { background: #0F2554; }
.fi-table thead th { padding: 11px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap; }
.fi-row { border-bottom: 1px solid var(--vero-border); }
.fi-row:last-child { border-bottom: none; }
.fi-row:hover { background: var(--vero-hover); }
.fi-table td { padding: 12px 14px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }
.fi-name-primary { font-weight: 600; }
.fi-name-ar { font-size: 12px; color: var(--vero-text-muted); direction: rtl; }
.fi-muted { color: var(--vero-text-muted); }
.fi-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: 6px; font-size: 12px; font-weight: 700; padding: 0 6px; }
.fi-count-teal { background: rgba(20,144,168,0.12); color: #1490A8; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.fi-action-btn { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--vero-text-muted); text-decoration: none; transition: all 0.15s; }
.fi-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.fi-action-accounts:hover { color: #0F2554; }
.fi-empty { text-align: center; padding: 60px 20px; display: flex; flex-direction: column; align-items: center; }
.fi-pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 14px; border-top: 1px solid var(--vero-border); }
.fi-page-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--vero-border); background: var(--vero-card); color: var(--vero-text); font-size: 13px; cursor: pointer; }
.fi-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.fi-page-btn:disabled { opacity: 0.4; cursor: default; }
.fi-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }
.text-end { text-align: end; }
.text-center { text-align: center; }
</style>
