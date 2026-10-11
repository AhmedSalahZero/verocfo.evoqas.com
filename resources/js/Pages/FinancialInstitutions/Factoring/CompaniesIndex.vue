<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Factoring/CompaniesIndex.vue
  Lists Factoring Companies. Shows agreement count per company.
  @layout AppLayout | @route fi.factoring-companies.index
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon fi-icon-gold">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.factoring.companiesIndex.factoringCompanies') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.factoring.companiesIndex.directAndReverseFactoringRelationships') }}</p>
                    </div>
                </div>
                <Link :href="route('fi.factoring-companies.create')" class="fi-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    {{ t('financialInstitutions.factoring.companiesIndex.addFactoringCompany') }}
                </Link>
            </div>

            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.factoring.companiesIndex.companyName') }}</th>
                            <th>{{ t('financialInstitutions.factoring.companiesIndex.contactPerson') }}</th>
                            <th>{{ t('financialInstitutions.factoring.companiesIndex.phone') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.factoring.companiesIndex.agreements') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="companies.data.length === 0">
                            <td colspan="6" class="fi-empty">
                                <p class="text-sm opacity-50">{{ t('financialInstitutions.factoring.companiesIndex.noFactoringCompaniesAddedYet') }}</p>
                            </td>
                        </tr>
                        <tr v-for="co in companies.data" :key="co.id" class="fi-row">
                            <td>
                                <div class="fi-name-primary">{{ co.name_en }}</div>
                                <div v-if="co.name_ar" class="fi-name-ar">{{ co.name_ar }}</div>
                            </td>
                            <td class="fi-muted">{{ co.contact_person || '—' }}</td>
                            <td class="fi-muted">{{ co.phone || '—' }}</td>
                            <td class="text-center"><span class="fi-count-badge fi-count-gold">{{ co.agreements_count ?? 0 }}</span></td>
                            <td class="text-center">
                                <span class="fi-status-badge" :class="co.is_active ? 'fi-active' : 'fi-inactive'">{{ co.is_active ? t('common.active') : t('common.inactive') }}</span>
                            </td>
                            <td class="text-end">
                                <div class="fi-actions">
                                    <Link :href="route('fi.factoring-companies.edit', co.id)" class="fi-action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></Link>
                                    <button class="fi-action-btn" @click="toggle(co)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path v-if="co.is_active" stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/><path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></button>
                                    <Link :href="route('fi.factoring-agreements.index') + '?factoring_company_id=' + co.id" class="fi-action-btn fi-action-accounts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
</template>
<script setup>
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps({ companies: Object, filters: Object })
function toggle(co) { router.patch(route('fi.factoring-companies.toggle', co.id)) }
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
.fi-count-gold { background: rgba(255,191,0,0.12); color: #b8860b; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.fi-action-btn { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--vero-text-muted); text-decoration: none; transition: all 0.15s; }
.fi-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.fi-action-accounts:hover { color: #0F2554; }
.fi-empty { text-align: center; padding: 60px 20px; }
.text-end { text-align: end; }
.text-center { text-align: center; }
</style>
