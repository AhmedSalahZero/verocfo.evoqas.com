<!--
══════════════════════════════════════════════════════════════════
  Projects/SalesOrders/Index.vue

  Lists all Sales Orders for the active company.
  Can be filtered by project to show only SOs under a given project.
  Shows SO code, customer, project link, value, invoiced, remaining, status.

  @layout    AppLayout
  @route     projects.sales-orders.index
  @props     salesOrders: PaginatedCollection, filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="so-page">

            <!-- Header -->
            <div class="so-header">
                <div class="so-header-left">
                    <div class="so-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="so-title">
                            {{ projectFilter ? t('projects.salesOrders.index.salesOrders') + ' — ' + projectFilter : t('projects.salesOrders.index.allSalesOrders') }}
                        </h1>
                        <p class="so-subtitle">{{ t('projects.salesOrders.index.everyInvoiceMustBeLinkedTo') }}</p>
                    </div>
                </div>
                <Link :href="route('projects.sales-orders.create')" class="so-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    {{ t('projects.salesOrders.index.newSalesOrder') }}
                </Link>
            </div>

            <!-- Filters -->
            <div class="so-filters">
                <div class="so-search-wrap">
                    <svg class="so-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
                    </svg>
                    <input v-model="form.search" type="text" :placeholder="t('projects.salesOrders.index.searchBySOCodeOrSubject')" class="so-search" @input="debouncedSearch"/>
                </div>

                <select v-model="form.status" class="so-select" @change="applyFilters">
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option value="draft">{{ t('projects.salesOrders.index.draft') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="on_hold">{{ t('projects.salesOrders.index.onHold') }}</option>
                    <option value="closed">{{ t('common.closed') }}</option>
                    <option value="cancelled">{{ t('projects.salesOrders.index.cancelled') }}</option>
                </select>

                <button v-if="hasFilters" class="so-btn-ghost" @click="clearFilters">
                    {{ t('projects.salesOrders.index.clear') }}
                </button>
            </div>

            <!-- Table -->
            <div class="so-card">
                <table class="so-table">
                    <thead>
                        <tr>
                            <th>{{ t('projects.salesOrders.index.sOCode') }}</th>
                            <th>{{ t('projects.salesOrders.index.subject') }}</th>
                            <th>{{ t('common.customer') }}</th>
                            <th>{{ t('projects.salesOrders.index.project') }}</th>
                            <th class="text-end">{{ t('projects.salesOrders.index.sOValue') }}</th>
                            <th class="text-end">{{ t('projects.salesOrders.index.invoiced') }}</th>
                            <th class="text-end">{{ t('projects.salesOrders.index.remaining') }}</th>
                            <th>{{ t('common.endDate') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="salesOrders.data.length === 0">
                            <td colspan="10" class="so-empty">
                                <div class="so-empty-content">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-sm opacity-50">{{ t('projects.salesOrders.index.noSalesOrdersFound') }}</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="so in salesOrders.data" :key="so.id" class="so-row">
                            <td>
                                <span class="so-code">{{ so.so_code }}</span>
                            </td>
                            <td>
                                <div class="so-subject">{{ so.subject || '—' }}</div>
                            </td>
                            <td>
                                <span class="so-partner">{{ so.partner?.name_en }}</span>
                            </td>
                            <td>
                                <Link v-if="so.project" :href="route('projects.edit', so.project.id)" class="so-project-link">
                                    <span class="so-project-code">{{ so.project.contract_code }}</span>
                                </Link>
                                <span v-else class="so-standalone">{{ t('projects.salesOrders.index.standalone') }}</span>
                            </td>
                            <td class="text-end">
                                <span class="so-amount">{{ fmt(so.so_value) }}</span>
                                <span class="so-ccy">{{ so.currency?.code }}</span>
                            </td>
                            <td class="text-end">
                                <span class="so-invoiced">{{ fmt(so.invoiced_amount) }}</span>
                            </td>
                            <td class="text-end">
                                <span class="so-remaining" :class="{ 'so-remaining-zero': remaining(so) === '0.00' }">
                                    {{ fmt(remaining(so)) }}
                                </span>
                            </td>
                            <td>
                                <span class="so-date" :class="{ 'so-date-warn': isEndingSoon(so.end_date) }">
                                    {{ fmtDate(so.end_date) }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="so-badge" :class="`so-badge-${so.status}`">
                                    {{ t(statusLabel(so.status)) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="so-actions">
                                    <Link :href="route('projects.sales-orders.edit', so.id)" class="so-action-btn" :title="t('common.edit')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </Link>
                                    <button v-if="so.status === 'draft'" class="so-action-btn so-action-activate" :title="t('common.activate')" @click="activate(so)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>
                                    <button v-if="so.status === 'active'" class="so-action-btn so-action-close" :title="t('projects.salesOrders.index.closeSO')" @click="closeSo(so)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>
                                    <button class="so-action-btn" :title="t('projects.salesOrders.index.deliveredQuantities')" @click="openDelivered(so)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a1 1 0 00-1-1H4a1 1 0 00-1 1v11a1 1 0 001 1h9m3 3l3-3-3-3m3 3H10"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <Pagination :paginator="salesOrders" />
            </div>

        </div>

        <!-- ══════════════════ Delivered Quantities modal ══════════════════ -->
        <Teleport to="body">
            <div v-if="deliveredModal.so" class="so-modal-overlay" @click.self="closeDelivered">
                <div class="so-modal">
                    <h3 class="so-modal-title">{{ t('projects.salesOrders.index.deliveredQuantities') }} — {{ deliveredModal.so.so_code }}</h3>

                    <div v-if="deliveredLoading" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('common.loading') }}</div>
                    <div v-else-if="!deliveredLines.length" class="text-sm opacity-50" style="padding: 12px 0;">
                        {{ t('projects.salesOrders.index.thisSalesOrderHasNoPhysical') }}
                    </div>
                    <div v-else class="zv-table-wrap">
                        <table class="zv-table">
                            <thead>
                                <tr>
                                    <th>{{ t('projects.salesOrders.index.product') }}</th>
                                    <th class="text-center">{{ t('projects.salesOrders.index.ordered') }}</th>
                                    <th class="text-center">{{ t('projects.salesOrders.index.delivered') }}</th>
                                    <th class="text-center">{{ t('projects.salesOrders.index.remaining') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in deliveredLines" :key="line.id">
                                    <td>{{ line.product_name ?? line.description }}</td>
                                    <td class="text-center">{{ line.ordered_quantity }}</td>
                                    <td class="text-center">{{ line.delivered_quantity }}</td>
                                    <td class="text-center">{{ line.remaining_quantity }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="so-modal-actions">
                        <button type="button" class="zv-btn zv-btn-ghost" @click="closeDelivered">{{ t('projects.salesOrders.index.close') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    salesOrders: Object,
    filters:     Object,
})

// ── Delivered Quantities modal ────────────────────────────────────

const deliveredModal = ref({ so: null })
const deliveredLines = ref([])
const deliveredLoading = ref(false)

function openDelivered(so) {
    deliveredModal.value = { so }
    deliveredLoading.value = true
    deliveredLines.value = []

    fetch(route('projects.sales-orders.delivered-quantities', so.id), { headers: { Accept: 'application/json' } })
        .then(res => res.json())
        .then(data => { deliveredLines.value = data.lines ?? [] })
        .finally(() => { deliveredLoading.value = false })
}

function closeDelivered() {
    deliveredModal.value = { so: null }
}

const form = ref({
    search:     props.filters?.search     ?? '',
    status:     props.filters?.status     ?? '',
    project_id: props.filters?.project_id ?? '',
})

const projectFilter = computed(() => {
    if (!props.filters?.project_id) return null
    const found = props.salesOrders?.data?.find(s => s.project?.id == props.filters.project_id)
    return found?.project?.contract_code ?? null
})

const hasFilters = computed(() => form.value.search || form.value.status)

let searchTimer = null
function debouncedSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
    router.get(route('projects.sales-orders.index'), {
        search:     form.value.search     || undefined,
        status:     form.value.status     || undefined,
        project_id: form.value.project_id || undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    form.value = { search: '', status: '', project_id: '' }
    router.get(route('projects.sales-orders.index'))
}

function activate(so) {
    router.patch(route('projects.sales-orders.activate', so.id))
}

function closeSo(so) {
    if (confirm(t('projects.salesOrders.index.closeThisSalesOrderThisAction'))) {
        router.patch(route('projects.sales-orders.close', so.id))
    }
}

function fmt(val) {
    if (!val && val !== 0) return '—'
    return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(val) {
    if (!val) return '—'
    return new Date(val).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

function isEndingSoon(d) {
    if (!d) return false
    const diff = (new Date(d) - Date.now()) / (1000 * 60 * 60 * 24)
    return diff >= 0 && diff <= 30
}

function remaining(so) {
    const r = Number(so.so_value) - Number(so.invoiced_amount)
    return r.toFixed(2)
}

function statusLabel(s) {
    const map = { draft: 'Draft', active: 'Active', on_hold: 'On Hold', closed: 'Closed', cancelled: 'Cancelled' }
    return map[s] ?? s
}
</script>

<style scoped>
.so-page { padding: 28px 32px; max-width: 1500px; margin: 0 auto; }
.so-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.so-header-left { display: flex; align-items: center; gap: 14px; }
.so-header-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: rgba(255,191,0,0.1); display: flex; align-items: center; justify-content: center; color: #b8860b;
}
.so-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 0; }
.so-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 2px 0 0; }

.so-btn-primary {
    display: flex; align-items: center; gap: 7px; padding: 9px 18px;
    background: #0F2554; color: #fff; border-radius: 8px; font-size: 13px; font-weight: 600;
    text-decoration: none; transition: background 0.15s;
}
.so-btn-primary:hover { background: #1a3a7a; }
.so-btn-ghost {
    padding: 8px 14px; border: 1px solid var(--vero-border); border-radius: 8px;
    background: transparent; color: var(--vero-text-muted); font-size: 13px; cursor: pointer;
}

.so-filters { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
.so-search-wrap { position: relative; flex: 1; min-width: 240px; }
.so-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--vero-text-muted); }
.so-search { width: 100%; padding: 8px 12px 8px 34px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); outline: none; }
.so-search:focus { border-color: #1490A8; }
.so-select { padding: 8px 12px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); outline: none; min-width: 140px; }

.so-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); overflow: hidden; }
.so-table { width: 100%; border-collapse: collapse; }
.so-table thead tr { background: #0F2554; }
.so-table thead th { padding: 11px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap; }
.so-row { border-bottom: 1px solid var(--vero-border); transition: background 0.12s; }
.so-row:last-child { border-bottom: none; }
.so-row:hover { background: var(--vero-hover); }
.so-table td { padding: 11px 14px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }

.so-code { font-family: monospace; font-size: 12px; font-weight: 600; color: #1490A8; white-space: nowrap; }
.so-subject { font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.so-partner { font-size: 13px; }
.so-project-link { text-decoration: none; }
.so-project-code { font-family: monospace; font-size: 11px; color: #FFBF00; background: rgba(255,191,0,0.1); padding: 2px 6px; border-radius: 4px; white-space: nowrap; }
.so-standalone { font-size: 12px; color: var(--vero-text-muted); font-style: italic; }
.so-amount { font-weight: 600; font-variant-numeric: tabular-nums; }
.so-ccy { font-size: 11px; color: var(--vero-text-muted); margin-left: 3px; }
.so-invoiced { font-size: 13px; color: #10b981; font-variant-numeric: tabular-nums; }
.so-remaining { font-size: 13px; font-weight: 600; color: #0F2554; font-variant-numeric: tabular-nums; }
.so-remaining-zero { color: var(--vero-text-muted); font-weight: 400; }
.so-date { font-size: 13px; white-space: nowrap; }
.so-date-warn { color: #f59e0b; font-weight: 600; }

.so-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.so-badge-draft      { background: rgba(148,163,184,0.12); color: #94a3b8; }
.so-badge-active     { background: rgba(16,185,129,0.12); color: #10b981; }
.so-badge-on_hold    { background: rgba(245,158,11,0.12); color: #f59e0b; }
.so-badge-closed     { background: rgba(100,116,139,0.12); color: #64748b; }
.so-badge-cancelled  { background: rgba(239,68,68,0.12); color: #ef4444; }

.so-actions { display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.so-action-btn { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--vero-text-muted); text-decoration: none; transition: all 0.15s; }
.so-action-btn:hover { background: var(--vero-hover); color: #1490A8; }
.so-action-activate:hover { color: #10b981; }
.so-action-close:hover { color: #64748b; }

.so-empty { text-align: center; padding: 60px 20px; }
.so-empty-content { display: flex; flex-direction: column; align-items: center; }

.so-pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 14px; border-top: 1px solid var(--vero-border); }
.so-page-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--vero-border); background: var(--vero-card); color: var(--vero-text); font-size: 13px; cursor: pointer; transition: all 0.12s; }
.so-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.so-page-btn:disabled { opacity: 0.4; cursor: default; }
.so-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }

.text-end { text-align: end; }
.text-center { text-align: center; }

.so-modal-overlay {
    position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.so-modal { background: var(--vero-card); border-radius: 12px; padding: 24px; width: 100%; max-width: 560px; max-height: 85vh; overflow-y: auto; }
.so-modal-title { font-size: 15px; font-weight: 700; margin: 0 0 16px; }
.so-modal-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
