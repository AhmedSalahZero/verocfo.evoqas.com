<!--
══════════════════════════════════════════════════════════════════
  Projects/Index.vue

  Lists all Projects & Contracts for the active company.
  Includes search, status filter, and quick status change actions.
  Each row shows contract code, customer, value, dates, status, SO count.

  @layout    AppLayout
  @route     projects.index
  @props     projects: PaginatedCollection, filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="vp-page">

            <!-- Page header -->
            <div class="vp-header">
                <div class="vp-header-left">
                    <div class="vp-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V7"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="vp-title">{{ t('projects.index.projectsContracts') }}</h1>
                        <p class="vp-subtitle">{{ t('projects.index.manageContractsTrackDeliverablesAndProject') }}</p>
                    </div>
                </div>
                <Link :href="route('projects.create')" class="vp-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    {{ t('projects.index.newProject') }}
                </Link>
            </div>

            <!-- Filters bar -->
            <div class="vp-filters">
                <div class="vp-search-wrap">
                    <svg class="vp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
                    </svg>
                    <input
                        v-model="form.search"
                        type="text"
                        :placeholder="t('projects.index.searchByNameOrContractCode')"
                        class="vp-search"
                        @input="debouncedSearch"
                    />
                </div>

                <select v-model="form.status" class="vp-select" @change="applyFilters">
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="on_hold">{{ t('projects.index.onHold') }}</option>
                    <option value="closed">{{ t('common.closed') }}</option>
                    <option value="cancelled">{{ t('projects.index.cancelled') }}</option>
                </select>

                <button v-if="hasFilters" class="vp-btn-ghost" @click="clearFilters">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    {{ t('projects.index.clear') }}
                </button>
            </div>

            <!-- Table -->
            <div class="vp-card">
                <table class="vp-table">
                    <thead>
                        <tr>
                            <th>{{ t('projects.index.contractCode') }}</th>
                            <th>{{ t('projects.index.projectName') }}</th>
                            <th>{{ t('common.customer') }}</th>
                            <th class="text-end">{{ t('projects.index.contractValue') }}</th>
                            <th>{{ t('common.startDate') }}</th>
                            <th>{{ t('common.endDate') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="projects.data.length === 0">
                            <td colspan="8" class="vp-empty">
                                <div class="vp-empty-content">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V7"/>
                                    </svg>
                                    <p class="text-sm opacity-50">{{ t('projects.index.noProjectsFoundCreateYourFirst') }}</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="p in projects.data" :key="p.id" class="vp-row">
                            <td>
                                <span class="vp-code">{{ p.contract_code }}</span>
                            </td>
                            <td>
                                <div class="vp-name-primary">{{ p.name_en }}</div>
                                <div v-if="p.name_ar" class="vp-name-secondary">{{ p.name_ar }}</div>
                            </td>
                            <td>
                                <span class="vp-partner">{{ p.partner?.name_en }}</span>
                            </td>
                            <td class="text-end">
                                <span class="vp-amount">{{ formatAmount(p.contract_value) }}</span>
                                <span class="vp-currency">{{ p.currency?.code }}</span>
                            </td>
                            <td class="vp-date">{{ formatDate(p.start_date) }}</td>
                            <td class="vp-date" :class="{ 'vp-date-warn': isEndingSoon(p.end_date), 'vp-date-expired': isExpired(p.end_date) }">
                                {{ formatDate(p.end_date) }}
                            </td>
                            <td class="text-center">
                                <span class="vp-badge" :class="`vp-badge-${p.status}`">
                                    {{ t(statusLabel(p.status)) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="vp-actions">
                                    <Link :href="route('projects.edit', p.id)" class="vp-action-btn" :title="t('common.edit')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </Link>
                                    <button
                                        v-if="p.status === 'active'"
                                        class="vp-action-btn vp-action-hold"
                                        :title="t('projects.index.putOnHold')"
                                        @click="changeStatus(p, 'on_hold')"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>
                                    <button
                                        v-if="p.status === 'on_hold'"
                                        class="vp-action-btn vp-action-activate"
                                        :title="t('projects.index.reactivate')"
                                        @click="changeStatus(p, 'active')"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>
                                    <Link
                                        :href="route('projects.sales-orders.index') + '?project_id=' + p.id"
                                        class="vp-action-btn vp-action-so"
                                        :title="t('projects.index.viewSalesOrders')"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <Pagination :paginator="projects" />
            </div>
        </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    projects: Object,
    filters:  Object,
})

const form = ref({
    search:    props.filters?.search    ?? '',
    status:    props.filters?.status    ?? '',
    partner_id:props.filters?.partner_id ?? '',
})

const hasFilters = computed(() => form.value.search || form.value.status)

let searchTimer = null
function debouncedSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
    router.get(route('projects.index'), {
        search:    form.value.search    || undefined,
        status:    form.value.status    || undefined,
        partner_id:form.value.partner_id || undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    form.value = { search: '', status: '', partner_id: '' }
    router.get(route('projects.index'), {}, { preserveState: false })
}

function changeStatus(project, newStatus) {
    router.patch(route('projects.status', project.id), { status: newStatus }, {
        onSuccess: () => {},
    })
}

function formatAmount(val) {
    if (!val) return '—'
    return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDate(val) {
    if (!val) return '—'
    return new Date(val).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

function isEndingSoon(dateStr) {
    if (!dateStr) return false
    const d = new Date(dateStr)
    const diff = (d - Date.now()) / (1000 * 60 * 60 * 24)
    return diff >= 0 && diff <= 30
}

function isExpired(dateStr) {
    if (!dateStr) return false
    return new Date(dateStr) < new Date()
}

function statusLabel(status) {
    const map = { active: 'Active', on_hold: 'On Hold', closed: 'Closed', cancelled: 'Cancelled' }
    return map[status] ?? status
}
</script>

<style scoped>
.vp-page { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }

.vp-header {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;
}
.vp-header-left { display: flex; align-items: center; gap: 14px; }
.vp-header-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: rgba(20,144,168,0.12); display: flex; align-items: center; justify-content: center;
    color: #1490A8; flex-shrink: 0;
}
.vp-header-icon svg { width: 22px; height: 22px; }
.vp-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 0; line-height: 1.2; }
.vp-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 2px 0 0; }

.vp-btn-primary {
    display: flex; align-items: center; gap: 7px; padding: 9px 18px;
    background: #0F2554; color: #fff; border-radius: 8px; font-size: 13px;
    font-weight: 600; text-decoration: none; transition: background 0.15s;
}
.vp-btn-primary:hover { background: #1a3a7a; }

.vp-btn-ghost {
    display: flex; align-items: center; gap: 6px; padding: 8px 14px;
    border: 1px solid var(--vero-border); border-radius: 8px; background: transparent;
    color: var(--vero-text-muted); font-size: 13px; cursor: pointer; transition: all 0.15s;
}
.vp-btn-ghost:hover { border-color: #e74c3c; color: #e74c3c; }

.vp-filters {
    display: flex; align-items: center; gap: 10px; margin-bottom: 18px; flex-wrap: wrap;
}
.vp-search-wrap { position: relative; flex: 1; min-width: 240px; }
.vp-search-icon {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    width: 15px; height: 15px; color: var(--vero-text-muted);
}
.vp-search {
    width: 100%; padding: 8px 12px 8px 34px; border: 1px solid var(--vero-border);
    border-radius: 8px; font-size: 13px; background: var(--vero-card);
    color: var(--vero-text); outline: none; transition: border-color 0.15s;
}
.vp-search:focus { border-color: #1490A8; }
.vp-select {
    padding: 8px 12px; border: 1px solid var(--vero-border); border-radius: 8px;
    font-size: 13px; background: var(--vero-card); color: var(--vero-text); outline: none;
    cursor: pointer; min-width: 140px;
}

.vp-card {
    background: var(--vero-card); border-radius: 12px;
    border: 1px solid var(--vero-border); overflow: hidden;
}
.vp-table { width: 100%; border-collapse: collapse; }
.vp-table thead tr {
    background: #0F2554;
}
.vp-table thead th {
    padding: 11px 16px; font-size: 11.5px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap;
}
.vp-row { border-bottom: 1px solid var(--vero-border); transition: background 0.12s; }
.vp-row:last-child { border-bottom: none; }
.vp-row:hover { background: var(--vero-hover); }
.vp-table td { padding: 12px 16px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }

.vp-code { font-family: monospace; font-size: 12px; font-weight: 600; color: #1490A8; }
.vp-name-primary { font-weight: 500; }
.vp-name-secondary { font-size: 12px; color: var(--vero-text-muted); margin-top: 2px; }
.vp-partner { font-size: 13px; }
.vp-amount { font-weight: 600; }
.vp-currency { font-size: 11px; color: var(--vero-text-muted); margin-left: 4px; }
.vp-date { font-size: 13px; white-space: nowrap; }
.vp-date-warn { color: #f59e0b; font-weight: 600; }
.vp-date-expired { color: #ef4444; font-weight: 600; }

/* Status badges */
.vp-badge {
    display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vp-badge-active    { background: rgba(16,185,129,0.12); color: #10b981; }
.vp-badge-on_hold   { background: rgba(245,158,11,0.12); color: #f59e0b; }
.vp-badge-closed    { background: rgba(100,116,139,0.12); color: #64748b; }
.vp-badge-cancelled { background: rgba(239,68,68,0.12); color: #ef4444; }

.vp-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
.vp-action-btn {
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: transparent; cursor: pointer; color: var(--vero-text-muted);
    text-decoration: none; transition: all 0.15s;
}
.vp-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.vp-action-hold:hover { color: #f59e0b; }
.vp-action-activate:hover { color: #10b981; }
.vp-action-so:hover { color: #0F2554; }

.vp-empty { text-align: center; padding: 60px 20px; }
.vp-empty-content { display: flex; flex-direction: column; align-items: center; }

.vp-pagination {
    display: flex; align-items: center; justify-content: center; gap: 4px;
    padding: 14px 16px; border-top: 1px solid var(--vero-border);
}
.vp-page-btn {
    min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px;
    border: 1px solid var(--vero-border); background: var(--vero-card);
    color: var(--vero-text); font-size: 13px; cursor: pointer; transition: all 0.12s;
}
.vp-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.vp-page-btn:disabled { opacity: 0.4; cursor: default; }
.vp-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }

.text-end { text-align: end; }
.text-center { text-align: center; }
</style>
