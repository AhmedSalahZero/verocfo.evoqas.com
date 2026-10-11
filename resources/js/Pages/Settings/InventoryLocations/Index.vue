<!--
══════════════════════════════════════════════════════════════════
  Settings/InventoryLocations/Index.vue

  Two-level inventory location tree:
  Main Locations with Sub-Locations indented below.

  Location types:
  — master       → gold star badge, cannot edit/deactivate
  — warehouse    → standard blue badge
  — project_site → teal badge, auto-created with projects

  Each Main Location shows:
  — Code, Name, type badge, sub-count, status, actions
  — Expandable to show sub-locations

  Info banner explains the two-level structure and project sites.

  @layout    AppLayout
  @route     settings.locations.index
  @props     locations: InventoryLocation[] with children loaded
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.inventoryLocations.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.inventoryLocations.index.inventoryLocations') }}</h1>
          <p class="zv-page-subtitle">
            {{ t('settings.inventoryLocations.index.defineWarehousesAndStorageLocationsProject') }}
          </p>
        </div>
        <Link :href="route('settings.locations.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.inventoryLocations.index.newLocation') }}
        </Link>
      </div>

      <!-- ── Flash Messages ────────────────────────────────────── -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="zv-alert zv-alert-danger mb-4">
        {{ $page.props.flash.error }}
      </div>

      <!-- ── Info Banner ───────────────────────────────────────── -->
      <div class="zv-alert zv-alert-info mb-5 flex items-start gap-3">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--zv-blue-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="text-sm" style="color:var(--zv-blue-text)">
          {{ t('settings.inventoryLocations.index.theMasterWarehouseIsCreatedAutomatically') }}
        </span>
      </div>

      <!-- ── Empty State ───────────────────────────────────────── -->
      <div v-if="locations.length === 0" class="zv-card">
        <div class="zv-empty py-12">
          <svg class="w-12 h-12 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <div class="zv-empty-title">{{ t('settings.inventoryLocations.index.noLocationsYet') }}</div>
          <div class="zv-empty-sub">{{ t('settings.inventoryLocations.index.theMasterWarehouseIsCreatedAutomatically2') }}</div>
        </div>
      </div>

      <!-- ── Location Tree ─────────────────────────────────────── -->
      <div v-for="location in locations" :key="location.id"
           class="loc-card mb-3"
           :class="typeCardClass(location.type)">

        <!-- Main Location Row -->
        <div class="loc-main-row" :class="{ 'loc-row--inactive': !location.is_active }">

          <!-- Expand toggle -->
          <button v-if="location.children?.length"
                  type="button" class="loc-expand-btn"
                  @click="toggleExpand(location.id)">
            <svg class="w-4 h-4 transition-transform duration-200"
                 :class="{ 'rotate-90': expanded[location.id] }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
          <div v-else class="loc-expand-placeholder"></div>

          <!-- Location icon -->
          <div class="loc-icon" :class="typeIconClass(location.type)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
          </div>

          <!-- Code -->
          <span class="loc-code">{{ location.code }}</span>

          <!-- Name -->
          <div class="loc-name flex-1 min-w-0">
            <span class="font-semibold" style="color:var(--zv-text-primary)">{{ location.name_en }}</span>
            <span v-if="location.name_ar" class="loc-name-ar">{{ location.name_ar }}</span>
          </div>

          <!-- Badges -->
          <div class="loc-badges">
            <span class="zv-badge" :class="typeBadgeClass(location.type)">
              {{ typeLabel(location.type) }}
            </span>

            <span v-if="location.children?.length" class="zv-badge zv-badge-blue">
              {{ location.children.length }} {{ t('settings.inventoryLocations.index.sub') }}
            </span>

            <span v-if="!location.children?.length" class="zv-badge zv-badge-green text-xs">
              {{ t('settings.inventoryLocations.index.postable') }}
            </span>

            <span class="zv-badge" :class="location.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
              <span class="zv-status-dot" :class="location.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
              {{ location.is_active ? t('common.active') : t('common.inactive') }}
            </span>
          </div>

          <!-- Actions -->
          <div class="loc-actions">
            <template v-if="!location.isMaster">
              <!-- Add Sub button -->
              <Link :href="route('settings.locations.create', { parent_id: location.id })"
                    class="zv-btn zv-btn-secondary zv-btn-sm">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                {{ t('settings.inventoryLocations.index.addSub') }}
              </Link>
              <Link v-if="location.type !== 'project_site'"
                    :href="route('settings.locations.edit', location.id)"
                    class="zv-btn zv-btn-secondary zv-btn-sm">
                {{ t('common.edit') }}
              </Link>
              <button type="button"
                      @click="toggle(location)"
                      class="zv-btn zv-btn-sm"
                      :class="location.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                {{ location.is_active ? t('common.deactivate') : t('common.activate') }}
              </button>
            </template>

            <!-- Master — locked -->
            <template v-else>
              <span class="flex items-center gap-1 text-xs" style="color:var(--zv-text-muted)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                {{ t('settings.inventoryLocations.index.system') }}
              </span>
              <Link :href="route('settings.locations.create', { parent_id: location.id })"
                    class="zv-btn zv-btn-secondary zv-btn-sm">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                {{ t('settings.inventoryLocations.index.addSub') }}
              </Link>
            </template>
          </div>
        </div>

        <!-- Sub-Locations -->
        <Transition name="loc-expand">
          <div v-if="location.children?.length && expanded[location.id]" class="loc-subs-wrap">
            <div v-for="sub in location.children" :key="sub.id"
                 class="loc-sub-row"
                 :class="{ 'loc-row--inactive': !sub.is_active }">

              <div class="loc-connector-wrap">
                <div class="loc-connector-line"></div>
              </div>

              <div class="loc-sub-icon">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
              </div>

              <span class="loc-code" style="color:var(--zv-green-text)">{{ sub.code }}</span>

              <div class="loc-name flex-1 min-w-0">
                <span class="font-medium" style="color:var(--zv-text-primary)">{{ sub.name_en }}</span>
                <span v-if="sub.name_ar" class="loc-name-ar">{{ sub.name_ar }}</span>
              </div>

              <div class="loc-badges">
                <span class="zv-badge zv-badge-green text-xs">{{ t('settings.inventoryLocations.index.sub2') }}</span>
                <span class="zv-badge zv-badge-green text-xs">{{ t('settings.inventoryLocations.index.postable') }}</span>
                <span class="zv-badge" :class="sub.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="sub.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ sub.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </div>

              <div class="loc-actions">
                <Link :href="route('settings.locations.edit', sub.id)"
                      class="zv-btn zv-btn-secondary zv-btn-sm">
                  {{ t('common.edit') }}
                </Link>
                <button type="button"
                        @click="toggle(sub)"
                        class="zv-btn zv-btn-sm"
                        :class="sub.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                  {{ sub.is_active ? t('common.deactivate') : t('common.activate') }}
                </button>
              </div>
            </div>
          </div>
        </Transition>

      </div>

    </div>
</template>

<script setup>
import { reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  locations: Array,
})

// All main locations start expanded
const expanded = reactive(
  Object.fromEntries((props.locations ?? []).map(l => [l.id, true]))
)

function toggleExpand(id) { expanded[id] = !expanded[id] }

function toggle(location) {
  router.patch(route('settings.locations.toggle', location.id))
}

// ── Type helpers ──────────────────────────────────────────────────
function typeLabel(type) {
  return {
    master:       t('settings.inventoryLocations.index.master'),
    warehouse:    t('settings.inventoryLocations.index.warehouse'),
    project_site: t('settings.inventoryLocations.index.projectSite'),
  }[type] ?? type
}

function typeBadgeClass(type) {
  return {
    master:       'zv-badge-amber',
    warehouse:    'zv-badge-blue',
    project_site: 'zv-badge-teal',
  }[type] ?? 'zv-badge-gray'
}

function typeIconClass(type) {
  return {
    master:       'loc-icon--master',
    warehouse:    'loc-icon--warehouse',
    project_site: 'loc-icon--project',
  }[type] ?? 'loc-icon--warehouse'
}

function typeCardClass(type) {
  return {
    master:       'loc-card--master',
    warehouse:    'loc-card--warehouse',
    project_site: 'loc-card--project',
  }[type] ?? 'loc-card--warehouse'
}

// Expose isMaster for template
const locations = computed(() =>
  (props.locations ?? []).map(l => ({ ...l, isMaster: l.type === 'master' }))
)
</script>

<style scoped>
/* ── Cards ──────────────────────────────────────────────────── */
.loc-card {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  box-shadow: var(--zv-shadow-sm);
}
.loc-card--master   { border-inline-start: 4px solid #FFBF00; }
.loc-card--warehouse { border-inline-start: 4px solid var(--zv-blue); }
.loc-card--project  { border-inline-start: 4px solid #1490A8; }

/* ── Main Row ───────────────────────────────────────────────── */
.loc-main-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.12s ease;
}
.loc-main-row:hover { background: var(--zv-bg-surface-alt); }
.loc-row--inactive  { opacity: 0.55; }

.loc-expand-btn {
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius-sm);
  border: none; background: transparent;
  cursor: pointer; color: var(--zv-text-muted);
  flex-shrink: 0;
  transition: background 0.12s ease;
}
.loc-expand-btn:hover { background: var(--zv-bg-hover); }
.loc-expand-placeholder { width: 24px; flex-shrink: 0; }

/* ── Icons ──────────────────────────────────────────────────── */
.loc-icon {
  width: 32px; height: 32px;
  border-radius: var(--zv-radius);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.loc-icon--master   { background: #FFBF00; color: #0F2554; }
.loc-icon--warehouse { background: var(--zv-nav-bg); color: #fff; }
.loc-icon--project  { background: #1490A8; color: #fff; }

/* ── Sub Rows ───────────────────────────────────────────────── */
.loc-subs-wrap { background: var(--zv-bg-card); }
.loc-sub-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.25rem 0.75rem 2rem;
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.1s ease;
}
.loc-sub-row:last-child { border-bottom: none; }
.loc-sub-row:hover { background: var(--zv-bg-hover); }

.loc-connector-wrap {
  width: 20px;
  display: flex; justify-content: center;
  flex-shrink: 0;
}
.loc-connector-line {
  width: 1px; min-height: 20px;
  background: var(--zv-border-md);
}

.loc-sub-icon {
  width: 28px; height: 28px;
  border-radius: var(--zv-radius-sm);
  background: var(--zv-green-light);
  color: var(--zv-green-text);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

/* ── Shared ─────────────────────────────────────────────────── */
.loc-code {
  font-family: monospace;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--zv-blue-text);
  min-width: 80px;
  flex-shrink: 0;
}
.loc-name { display: flex; flex-direction: column; }
.loc-name-ar {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  direction: rtl;
}
.loc-badges {
  display: flex; gap: 0.375rem;
  flex-wrap: wrap; flex-shrink: 0;
}
.loc-actions {
  display: flex; gap: 0.375rem;
  flex-shrink: 0; margin-inline-start: auto;
}

/* ── teal badge ─────────────────────────────────────────────── */
:deep(.zv-badge-teal) {
  background: #E0F7FA;
  color: #006064;
  border: 1px solid #80DEEA;
}

/* ── Expand Transition ──────────────────────────────────────── */
.loc-expand-enter-active { transition: all 0.2s ease; overflow: hidden; }
.loc-expand-leave-active { transition: all 0.15s ease; overflow: hidden; }
.loc-expand-enter-from, .loc-expand-leave-to { opacity: 0; max-height: 0; }
.loc-expand-enter-to, .loc-expand-leave-from { opacity: 1; max-height: 500px; }
</style>
