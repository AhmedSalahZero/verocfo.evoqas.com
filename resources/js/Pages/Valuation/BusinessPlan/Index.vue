<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Index.vue

  Lists all Business Plan & Valuation studies for the company.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.index
  @props     plans: Object (paginator) — filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head title="Business Plan & Valuation" />
    <div class="fi-page">
        <div class="fi-header">
            <div class="fi-header-left">
                <div class="fi-header-icon fi-icon-teal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2l3 7h7l-5.5 4.5L18.5 21 12 16.5 5.5 21l2-7.5L2 9h7z"/></svg>
                </div>
                <div>
                    <h1 class="fi-title">{{ t('planning.businessPlanIndex.businessPlanValuation') }}</h1>
                    <p class="fi-subtitle">{{ t('planning.businessPlanIndex.threeToTenYearProjection') }}</p>
                </div>
            </div>
            <Link :href="route('valuation.business-plan.create')" class="fi-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                {{ t('planning.businessPlanIndex.newBusinessPlan') }}
            </Link>
        </div>

        <div class="fi-card">
            <table class="fi-table">
                <thead>
                    <tr>
                        <th>{{ t('planning.businessPlanIndex.plan') }}</th>
                        <th>{{ t('planning.businessPlanIndex.currency') }}</th>
                        <th>{{ t('planning.businessPlanIndex.duration') }}</th>
                        <th>{{ t('planning.businessPlanIndex.period') }}</th>
                        <th class="text-center">{{ t('planning.businessPlanIndex.status') }}</th>
                        <th class="text-end">{{ t('planning.businessPlanIndex.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="plans.data.length === 0">
                        <td colspan="6" class="fi-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p class="text-sm opacity-50">{{ t('planning.businessPlanIndex.noBusinessPlansYet') }}</p>
                        </td>
                    </tr>
                    <tr v-for="plan in plans.data" :key="plan.id" class="fi-row">
                        <td>
                            <Link :href="route('valuation.business-plan.edit', plan.id)" class="fi-name-primary">{{ plan.name }}</Link>
                            <div class="fi-muted" style="font-size:12px">{{ plan.plan_code }}</div>
                        </td>
                        <td class="fi-muted">{{ plan.currency?.code }}</td>
                        <td class="fi-muted">{{ plan.duration_years }} {{ t('planning.businessPlanIndex.years') }}</td>
                        <td class="fi-muted">{{ formatDate(plan.start_date) }} → {{ formatDate(plan.end_date) }}</td>
                        <td class="text-center">
                            <span class="fi-status-badge" :class="plan.status === 'completed' ? 'fi-active' : 'fi-inactive'">{{ plan.status }}</span>
                        </td>
                        <td class="text-end">
                            <div class="fi-actions">
                                <Link :href="route('valuation.business-plan.edit', plan.id)" class="fi-action-btn">{{ t('planning.businessPlanIndex.open') }}</Link>
                                <button v-if="plan.status === 'draft'" class="fi-action-btn fi-action-danger" @click="destroy(plan)">{{ t('planning.businessPlanIndex.delete') }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="fi-card-footer">
                <Pagination :paginator="plans" item-label="plan" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()
defineProps({ plans: Object, filters: Object })

function formatDate(d) { return d ? new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—' }

function destroy(plan) {
    if (! confirm(t('planning.businessPlanIndex.deleteConfirm', { name: plan.name }))) return
    router.delete(route('valuation.business-plan.destroy', plan.id))
}
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
.fi-card-footer { padding: 14px; border-top: 1px solid var(--vero-border); }
.fi-table { width: 100%; border-collapse: collapse; }
.fi-table thead tr { background: #0F2554; }
.fi-table thead th { padding: 11px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap; }
.fi-row { border-bottom: 1px solid var(--vero-border); }
.fi-row:last-child { border-bottom: none; }
.fi-row:hover { background: var(--vero-hover); }
.fi-table td { padding: 12px 14px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }
.fi-name-primary { font-weight: 600; color: #0F2554; text-decoration: none; }
.fi-muted { color: var(--vero-text-muted); }
.fi-empty { text-align: center; padding: 48px 0; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
.fi-action-btn { font-size: 12px; font-weight: 600; color: #1490A8; text-decoration: none; background: none; border: none; cursor: pointer; padding: 0; }
.fi-action-danger { color: #ef4444; }
</style>
