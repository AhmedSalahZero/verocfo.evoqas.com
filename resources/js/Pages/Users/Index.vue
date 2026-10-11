<!--
══════════════════════════════════════════════════════════════════
  Users/Index.vue

  Company users management page.
  Lists all users belonging to the active company.
  Accessible by Super Admin (via impersonation) and Company Admin.

  Key behaviors:
    - Search by name or email (debounced 400ms)
    - Filter by role and active status
    - "Edit" → Users/Form.vue in edit mode
    - "Permissions" → Users/Permissions.vue (role=user only)
    - "Activate/Deactivate" toggles is_active via PATCH
    - Last login shown as relative time (e.g. "2 hours ago")
    - Current user cannot deactivate themselves

  @layout    AppLayout
  @route     users.index
  @props     users: LengthAwarePaginator
             filters: Object — current search/role/status values
             seats: { used, max } — active users vs plan limit (Audit C5)
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ── Page header ────────────────────────────────── -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <span>{{ t('users.index.settings') }}</span>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('users.index.users') }}</span>
                    </div>
                    <div class="zv-page-title">{{ t('users.index.users') }}</div>
                    <div class="zv-page-subtitle">
                        {{ users.total }} {{ t('users.index.usersInThisCompany') }}
                        <template v-if="seats">
                            · <strong>{{ seats.used }} / {{ seats.max }}</strong> {{ t('users.index.activeSeatsUsed') }}
                        </template>
                    </div>
                </div>
                <Link :href="route('users.create')" class="zv-btn zv-btn-primary">
                    <i class="ti ti-user-plus" aria-hidden="true"></i>
                    {{ t('users.index.newUser') }}
                </Link>
            </div>

            <!-- ── Filter bar ──────────────────────────────────── -->
            <div class="zv-card" style="margin-bottom: 1.25rem;">
                <div class="zv-filter-bar">
                    <div class="zv-search-input" style="flex: 1; max-width: 300px;">
                        <i class="ti ti-search" style="color: var(--zv-text-muted);" aria-hidden="true"></i>
                        <input
                            v-model="filters.search"
                            type="search"
                            :placeholder="t('users.index.searchByNameOrEmail')"
                            @input="debouncedSearch"
                        />
                    </div>
                    <select v-model="filters.role" class="zv-select" style="width: 160px;" @change="applyFilters">
                        <option value="">{{ t('common.allRoles') }}</option>
                        <option value="company_admin">{{ t('users.index.companyAdmin') }}</option>
                        <option value="user">{{ t('users.index.user') }}</option>
                    </select>
                    <select v-model="filters.status" class="zv-select" style="width: 140px;" @change="applyFilters">
                        <option value="">{{ t('users.index.allStatuses') }}</option>
                        <option value="active">{{ t('common.active') }}</option>
                        <option value="inactive">{{ t('common.inactive') }}</option>
                    </select>
                </div>
            </div>

            <!-- ── Table ──────────────────────────────────────── -->
            <div class="zv-table-wrap">
                <table class="zv-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>{{ t('common.name') }}</th>
                            <th>{{ t('users.index.email') }}</th>
                            <th>{{ t('users.index.role') }}</th>
                            <th>{{ t('users.index.jobLevel') }}</th>
                            <th>{{ t('users.index.lastLogin') }}</th>
                            <th>{{ t('common.status') }}</th>
                            <th style="width: 210px;">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="users.data.length === 0">
                            <td colspan="8">
                                <div class="zv-empty">
                                    <i class="ti ti-users-off zv-empty-icon" aria-hidden="true"></i>
                                    <div class="zv-empty-title">{{ t('users.index.noUsersFound') }}</div>
                                    <div class="zv-empty-sub">{{ t('users.index.tryAdjustingYourSearchOrFilters') }}</div>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="(user, index) in users.data" :key="user.id">

                            <!-- # -->
                            <td class="zv-text-muted" style="font-size: 12px;">
                                {{ (users.current_page - 1) * users.per_page + index + 1 }}
                            </td>

                            <!-- Name -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="user-avatar">{{ initials(user.name) }}</div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--zv-text-primary);">
                                            {{ user.name }}
                                        </div>
                                        <div v-if="user.name_ar" style="font-size: 12px; color: var(--zv-text-muted);">
                                            {{ user.name_ar }}
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Email -->
                            <td style="font-size: 13px; color: var(--zv-text-secondary);">
                                {{ user.email }}
                            </td>

                            <!-- Role -->
                            <td>
                                <span class="zv-badge"
                                      :class="user.role === 'company_admin' ? 'zv-badge-blue' : 'zv-badge-gray'">
                                    {{ user.role === 'company_admin' ? t('users.index.companyAdmin') : t('users.index.user') }}
                                </span>
                            </td>

                            <!-- Job Level -->
                            <td>
                                <span v-if="user.job_level" class="zv-badge zv-badge-gray"
                                      style="text-transform: capitalize;">
                                    {{ t(capitalize(user.job_level)) }}
                                </span>
                                <span v-else class="zv-text-muted">—</span>
                            </td>

                            <!-- Last login -->
                            <td style="font-size: 12px; color: var(--zv-text-muted);">
                                {{ user.last_login_at ? relativeTime(user.last_login_at) : t('common.never') }}
                            </td>

                            <!-- Status -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="zv-status-dot"
                                          :class="user.is_active ? 'zv-status-dot-green' : 'zv-status-dot-gray'">
                                    </span>
                                    <span style="font-size: 12px;">
                                        {{ user.is_active ? t('common.active') : t('common.inactive') }}
                                    </span>
                                </div>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                    <Link :href="route('users.edit', user.id)"
                                          class="zv-btn zv-btn-secondary zv-btn-sm">
                                        <i class="ti ti-pencil" aria-hidden="true"></i>
                                        {{ t('common.edit') }}
                                    </Link>

                                    <Link v-if="user.role === 'user'"
                                          :href="route('users.permissions', user.id)"
                                          class="zv-btn zv-btn-secondary zv-btn-sm">
                                        <i class="ti ti-shield" aria-hidden="true"></i>
                                        {{ t('users.index.permissions') }}
                                    </Link>

                                    <button
                                        v-if="user.id !== currentUserId"
                                        type="button"
                                        class="zv-btn zv-btn-sm"
                                        :class="user.is_active ? 'zv-btn-warning' : 'zv-btn-success'"
                                        @click="toggleActive(user)"
                                        :disabled="processing === user.id"
                                    >
                                        <i :class="`ti ${user.is_active ? 'ti-ban' : 'ti-check'}`"
                                           aria-hidden="true"></i>
                                        {{ user.is_active ? t('common.deactivate') : t('common.activate') }}
                                    </button>
                                </div>
                            </td>

                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="users.last_page > 1" class="zv-pagination">
                    <div style="font-size: 12px; color: var(--zv-text-secondary);">
                        {{ t('common.showing') }} {{ users.from }}–{{ users.to }}
                        {{ t('common.of') }} {{ users.total }}
                    </div>
                    <div class="zv-pagination-pages">
                        <button
                            v-for="pg in users.last_page"
                            :key="pg"
                            type="button"
                            class="zv-page-btn"
                            :class="{ active: pg === users.current_page }"
                            @click="goToPage(pg)"
                        >{{ pg }}</button>
                    </div>
                </div>
            </div>

        </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const page  = usePage()

const props = defineProps({
    users:   Object,
    filters: Object,
    seats:   { type: Object, default: null },
})

const currentUserId = computed(() => page.props.auth?.user?.id)
const processing    = ref(null)

const filters = reactive({
    search: props.filters?.search ?? '',
    role:   props.filters?.role   ?? '',
    status: props.filters?.status ?? '',
})

function initials(name) {
    return (name ?? '').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
}

function relativeTime(dateStr) {
    const diff = Date.now() - new Date(dateStr).getTime()
    const mins = Math.floor(diff / 60000)
    if (mins < 1)  return 'Just now'
    if (mins < 60) return `${mins}m ago`
    const hrs = Math.floor(mins / 60)
    if (hrs < 24)  return `${hrs}h ago`
    return `${Math.floor(hrs / 24)}d ago`
}

let searchTimer = null

function debouncedSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => applyFilters(), 400)
}

function applyFilters() {
    router.get(route('users.index'), filters, { preserveState: true, replace: true })
}

function goToPage(pg) {
    router.get(route('users.index'), { ...filters, page: pg }, { preserveState: true })
}

function toggleActive(user) {
    processing.value = user.id
    router.patch(route('users.toggle', user.id), {}, {
        preserveScroll: true,
        onFinish: () => { processing.value = null },
    })
}
</script>

<style scoped>
.user-avatar {
    width: 32px; height: 32px;
    background: var(--zv-blue-light);
    color: var(--zv-blue-text);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}
</style>
