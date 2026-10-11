<!--
══════════════════════════════════════════════════════════════════
  Companies/Index.vue

  Super Admin companies management page.
  Lists all companies registered on the Vero CFO platform
  with search, status, and tier filtering.

  Key behaviors:
    - "Enter" button starts impersonation via ImpersonateController
      and redirects Super Admin into the company dashboard
    - "Edit" navigates to Companies/Form.vue in edit mode
    - "Activate/Deactivate" toggles company is_active via PATCH
    - Search is debounced 400ms to avoid excessive requests
    - Pagination preserves current filters on page change

  @layout    SuperAdminLayout
  @route     companies.index
  @props     companies: LengthAwarePaginator — paginated company list
             filters: Object — current active search/status/tier values
══════════════════════════════════════════════════════════════════
-->
<template>
    <SuperAdminLayout>

        <!-- ── Page header ──────────────────────────────────────── -->
        <div class="zv-page-header">
            <div>
                <div class="zv-page-title">{{ t('companies.index.companies') }}</div>
                <div class="zv-page-subtitle">
                    {{ companies.total }} {{ t('companies.index.registeredCompanies') }}
                </div>
            </div>
            <Link :href="route('companies.create')" class="zv-btn zv-btn-primary">
                <i class="ti ti-plus" aria-hidden="true"></i>
                {{ t('companies.index.newCompany') }}
            </Link>
        </div>

        <!-- ── Filter bar ────────────────────────────────────────── -->
        <div class="zv-card" style="margin-bottom: 1.25rem;">
            <div class="zv-filter-bar">

                <!-- Search -->
                <div class="zv-search-input" style="flex: 1; max-width: 320px;">
                    <i class="ti ti-search" style="color: var(--zv-text-muted);" aria-hidden="true"></i>
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('companies.index.searchCompanies')"
                        @input="debouncedSearch"
                    />
                </div>

                <!-- Status filter -->
                <select v-model="filters.status" class="zv-select" style="width: 150px;" @change="applyFilters">
                    <option value="">{{ t('companies.index.allStatuses') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="inactive">{{ t('common.inactive') }}</option>
                </select>

                <!-- Tier filter -->
                <select v-model="filters.tier" class="zv-select" style="width: 150px;" @change="applyFilters">
                    <option value="">{{ t('common.allTiers') }}</option>
                    <option v-for="tier in tiers" :key="tier" :value="tier">{{ tier }}</option>
                </select>

            </div>
        </div>

        <!-- ── Table ─────────────────────────────────────────────── -->
        <div class="zv-table-wrap">
            <table class="zv-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>{{ t('companies.index.company') }}</th>
                        <th>{{ t('common.type') }}</th>
                        <th>{{ t('common.tier') }}</th>
                        <th>{{ t('companies.index.users') }}</th>
                        <th>{{ t('companies.index.openingBalance') }}</th>
                        <th>{{ t('common.expires') }}</th>
                        <th>{{ t('common.status') }}</th>
                        <th style="width: 180px;">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="companies.data.length === 0">
                        <td colspan="9">
                            <div class="zv-empty">
                                <i class="ti ti-building-off zv-empty-icon" aria-hidden="true"></i>
                                <div class="zv-empty-title">{{ t('companies.index.noCompaniesFound') }}</div>
                                <div class="zv-empty-sub">{{ t('companies.index.tryAdjustingYourSearchOrFilters') }}</div>
                            </div>
                        </td>
                    </tr>

                    <tr v-for="(company, index) in companies.data" :key="company.id">

                        <!-- # -->
                        <td class="zv-text-muted" style="font-size: 12px;">
                            {{ (companies.current_page - 1) * companies.per_page + index + 1 }}
                        </td>

                        <!-- Company -->
                        <td>
                            <div style="font-weight: 600; color: var(--zv-text-primary);">
                                {{ company.name_en }}
                            </div>
                            <div v-if="company.name_ar" style="font-size: 12px; color: var(--zv-text-muted);">
                                {{ company.name_ar }}
                            </div>
                        </td>

                        <!-- Type -->
                        <td>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <span
                                    v-for="type in (company.company_type ?? [])"
                                    :key="type"
                                    class="zv-badge zv-badge-gray"
                                    style="font-size: 10px;"
                                >
                                    {{ type }}
                                </span>
                            </div>
                        </td>

                        <!-- Tier -->
                        <td>
                            <span class="zv-badge" :class="tierBadge(company.subscription_tier)">
                                {{ company.subscription_tier }}
                            </span>
                        </td>

                        <!-- Users -->
                        <td class="zv-table-num">
                            {{ company.users_count }} / {{ maxUsers(company) }}
                        </td>

                        <!-- Opening Balance Date -->
                        <td style="font-size: 12px; color: var(--zv-text-secondary); white-space: nowrap;">
                            <span v-if="company.opening_balance_date">
                                {{ formatDate(company.opening_balance_date) }}
                            </span>
                            <span v-else class="zv-text-muted">—</span>
                        </td>

                        <!-- Expires -->
                        <td style="font-size: 12px; color: var(--zv-text-secondary);">
                            <span v-if="company.subscription_expires_at" :class="isExpired(company) ? 'zv-text-danger' : ''">
                                {{ formatDate(company.subscription_expires_at) }}
                            </span>
                            <span v-else class="zv-text-muted">—</span>
                        </td>

                        <!-- Status -->
                        <td>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span class="zv-status-dot" :class="company.is_active ? 'zv-status-dot-green' : 'zv-status-dot-gray'"></span>
                                <span style="font-size: 12px;">
                                    {{ company.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div style="display: flex; align-items: center; gap: 6px;">

                                <!-- Open company -->
                                <button
                                    v-if="company.is_active"
                                    type="button"
                                    class="zv-btn zv-btn-primary zv-btn-sm"
                                    @click="openCompany(company)"
                                    :disabled="processing === company.id"
                                >
                                    <i class="ti ti-login" aria-hidden="true"></i>
                                    {{ t('companies.index.enter') }}
                                </button>

                                <!-- Edit -->
                                <Link
                                    :href="route('companies.edit', company.id)"
                                    class="zv-btn zv-btn-secondary zv-btn-sm"
                                >
                                    <i class="ti ti-pencil" aria-hidden="true"></i>
                                    {{ t('common.edit') }}
                                </Link>

                                <!-- Toggle active -->
                                <button
                                    type="button"
                                    class="zv-btn zv-btn-sm"
                                    :class="company.is_active ? 'zv-btn-warning' : 'zv-btn-success'"
                                    @click="toggleActive(company)"
                                    :disabled="processing === company.id"
                                >
                                    <i :class="`ti ${company.is_active ? 'ti-ban' : 'ti-check'}`" aria-hidden="true"></i>
                                    {{ company.is_active ? t('common.deactivate') : t('common.activate') }}
                                </button>

                                <!-- Delete (hard delete) -->
                                <button
                                    type="button"
                                    class="zv-btn zv-btn-danger zv-btn-sm"
                                    @click="openDeleteModal(company)"
                                    :disabled="processing === company.id"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                    {{ t('common.delete') }}
                                </button>

                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="companies.last_page > 1" class="zv-pagination">
                <div style="font-size: 12px; color: var(--zv-text-secondary);">
                    {{ t('common.showing') }} {{ companies.from }}–{{ companies.to }} {{ t('common.of') }} {{ companies.total }}
                </div>
                <div class="zv-pagination-pages">
                    <button
                        v-for="page in companies.last_page"
                        :key="page"
                        type="button"
                        class="zv-page-btn"
                        :class="{ active: page === companies.current_page }"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Delete company confirmation (hard delete) ──────────── -->
        <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div class="zv-modal rounded-lg p-6 w-full max-w-md">
                <h2 class="text-lg font-medium zv-text-danger mb-2">
                    <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                    {{ t('companies.index.deleteCompany') }}
                </h2>
                <p class="text-xs zv-text-secondary mb-1">
                    {{ t('companies.index.thisPermanentlyDeletesEverything') }}
                </p>
                <ul class="text-xs zv-text-secondary mb-4" style="padding-inline-start: 1.1rem; list-style: disc;">
                    <li>{{ t('companies.index.everyTransactionAndSetting') }}</li>
                    <li>{{ t('companies.index.everyUserAccountUnderThisCompany', { count: deleteTarget.users_count }) }}</li>
                </ul>
                <p class="text-xs zv-text-danger mb-4" style="font-weight: 600;">
                    {{ t('companies.index.thisCannotBeUndone') }}
                </p>

                <label class="zv-label mb-1" style="display: block;">
                    {{ t('companies.index.typeToConfirm', { name: deleteTarget.name_en }) }}
                </label>
                <input
                    v-model="deleteConfirmText"
                    type="text"
                    class="zv-input w-full mb-1"
                    :placeholder="deleteTarget.name_en"
                    autocomplete="off"
                    @keyup.enter="confirmDelete"
                />
                <p v-if="deleteError" class="zv-field-error mb-3">{{ deleteError }}</p>
                <div v-else style="height: 0.75rem;"></div>

                <div class="flex justify-end gap-2">
                    <button type="button" class="zv-btn zv-btn-secondary" @click="closeDeleteModal">
                        {{ t('common.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="zv-btn zv-btn-danger"
                        :disabled="deleteConfirmText !== deleteTarget.name_en || processing === deleteTarget.id"
                        @click="confirmDelete"
                    >
                        <i class="ti ti-trash" aria-hidden="true"></i>
                        {{ t('companies.index.permanentlyDelete') }}
                    </button>
                </div>
            </div>
        </div>

    </SuperAdminLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue'

const { t } = useI18n()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    companies: Object,
    filters:   Object,
})

// ── State ─────────────────────────────────────────────────────────
const processing = ref(null)

const filters = reactive({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    tier:   props.filters?.tier   ?? '',
})

const tiers = ['Starter', 'Growth', 'Pro', 'Enterprise']

// ── Tier limits ───────────────────────────────────────────────────
const tierLimits = { Starter: 3, Growth: 5, Pro: 10, Enterprise: 20 }

function maxUsers(company) {
    return (tierLimits[company.subscription_tier] ?? 3) + (company.additional_users ?? 0)
}

// ── Badge helpers ─────────────────────────────────────────────────
function tierBadge(tier) {
    const map = {
        Starter:    'zv-badge-gray',
        Growth:     'zv-badge-blue',
        Pro:        'zv-badge-green',
        Enterprise: 'zv-badge-amber',
    }
    return map[tier] ?? 'zv-badge-gray'
}

function isExpired(company) {
    if (!company.subscription_expires_at) return false
    return new Date(company.subscription_expires_at) < new Date()
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    })
}

// ── Filters ───────────────────────────────────────────────────────
let searchTimer = null

function debouncedSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => applyFilters(), 400)
}

function applyFilters() {
    router.get(route('companies.index'), filters, {
        preserveState: true,
        replace:       true,
    })
}

function goToPage(page) {
    router.get(route('companies.index'), { ...filters, page }, {
        preserveState: true,
    })
}

// ── Actions ───────────────────────────────────────────────────────
function openCompany(company) {
    processing.value = company.id
    router.post(route('impersonate.enter', company.id), {}, {
        onFinish: () => { processing.value = null },
    })
}

function toggleActive(company) {
    processing.value = company.id
    router.patch(route('companies.toggle', company.id), {}, {
        preserveScroll: true,
        onFinish: () => { processing.value = null },
    })
}

// ── Delete (hard delete) ─────────────────────────────────────────
const deleteTarget = ref(null)
const deleteConfirmText = ref('')
const deleteError = ref('')

function openDeleteModal(company) {
    deleteTarget.value = company
    deleteConfirmText.value = ''
    deleteError.value = ''
}

function closeDeleteModal() {
    deleteTarget.value = null
    deleteConfirmText.value = ''
    deleteError.value = ''
}

function confirmDelete() {
    if (deleteConfirmText.value !== deleteTarget.value.name_en) return

    processing.value = deleteTarget.value.id
    router.delete(route('companies.destroy', deleteTarget.value.id), {
        data: { confirm_name: deleteConfirmText.value },
        preserveScroll: true,
        onSuccess: () => { closeDeleteModal() },
        onError: (errors) => { deleteError.value = errors.confirm_name ?? '' },
        onFinish: () => { processing.value = null },
    })
}
</script>