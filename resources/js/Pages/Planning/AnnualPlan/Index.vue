<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Index.vue

  Lists all Annual Plans for the company. Create opens the Setup
  form (Create.vue); each row opens the wizard (Show.vue).

  @layout    AppLayout (applied automatically — see app.js)
  @route     planning.annual-plan.index
  @props     plans: Object (paginator) — filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head title="Annual Plan" />
    <div class="fi-page">
        <div class="fi-header">
            <div class="fi-header-left">
                <div class="fi-header-icon fi-icon-teal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h1 class="fi-title">{{ t('planning.annualPlanIndex.annualPlan') }}</h1>
                    <p class="fi-subtitle">{{ t('planning.annualPlanIndex.twelveMonthOperatingBudget') }}</p>
                </div>
            </div>
            <Link :href="route('planning.annual-plan.create')" class="fi-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                {{ t('planning.annualPlanIndex.newAnnualPlan') }}
            </Link>
        </div>

        <div class="fi-card">
            <table class="fi-table">
                <thead>
                    <tr>
                        <th>{{ t('planning.annualPlanIndex.plan') }}</th>
                        <th>{{ t('planning.annualPlanIndex.currency') }}</th>
                        <th>{{ t('planning.annualPlanIndex.period') }}</th>
                        <th class="text-center">{{ t('planning.annualPlanIndex.status') }}</th>
                        <th class="text-end">{{ t('planning.annualPlanIndex.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="plans.data.length === 0">
                        <td colspan="5" class="fi-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p class="text-sm opacity-50">{{ t('planning.annualPlanIndex.noAnnualPlansYet') }}</p>
                        </td>
                    </tr>
                    <tr v-for="plan in plans.data" :key="plan.id" class="fi-row">
                        <td>
                            <Link :href="route('planning.annual-plan.edit', plan.id)" class="fi-name-primary">{{ plan.name }}</Link>
                            <div class="fi-muted" style="font-size:12px">{{ plan.plan_code }}</div>
                        </td>
                        <td class="fi-muted">{{ plan.currency?.code }}</td>
                        <td class="fi-muted">{{ formatDate(plan.start_date) }} → {{ formatDate(plan.end_date) }}</td>
                        <td class="text-center">
                            <span class="fi-status-badge" :class="plan.status === 'completed' ? 'fi-active' : 'fi-inactive'">{{ plan.status }}</span>
                        </td>
                        <td class="text-end">
                            <div class="fi-actions">
                                <Link :href="route('planning.annual-plan.edit', plan.id)" class="fi-action-btn">{{ t('planning.annualPlanIndex.open') }}</Link>
                                <button v-if="plan.status === 'draft'" class="fi-action-btn fi-action-danger" @click="destroy(plan)">{{ t('planning.annualPlanIndex.delete') }}</button>
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
    if (! confirm(t('planning.annualPlanIndex.deleteConfirm', { name: plan.name }))) return
    router.delete(route('planning.annual-plan.destroy', plan.id))
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
