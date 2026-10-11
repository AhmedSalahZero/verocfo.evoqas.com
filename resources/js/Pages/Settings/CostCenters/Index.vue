<!--
══════════════════════════════════════════════════════════════════
  Settings/CostCenters/Index.vue

  Displays cost centers as a two-level tree:
  Main Cost Centers with their Sub Cost Centers indented below.

  Each Main Cost Center shows:
  — Code, Name, sub-count badge, status, actions
  — Expandable to show its Sub Cost Centers

  Sub Cost Centers show:
  — Code, Name, postable badge, status, actions
  — Indented with connecting line

  Posting rule note shown as info banner at top.

  @layout    AppLayout
  @route     settings.cost-centers.index
  @props     costCenters: CostCenter[] with children loaded
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.costCenters.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.costCenters.index.costCenters') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.costCenters.index.defineOrganizationalUnitsForCostAnd') }}</p>
        </div>
        <Link :href="route('settings.cost-centers.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.costCenters.index.newCostCenter') }}
        </Link>
      </div>

      <!-- ── Posting Rule Info Banner ──────────────────────────── -->
      <div class="zv-alert zv-alert-info mb-5 flex items-start gap-3">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--zv-blue-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="text-sm" style="color:var(--zv-blue-text)">
          {{ t('settings.costCenters.index.whenAMainCostCenterHas') }}
        </span>
      </div>

      <!-- ── Flash Messages ────────────────────────────────────── -->
      <div v-if="$page.props.flash?.success"
           class="zv-alert zv-alert-success mb-4">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error"
           class="zv-alert zv-alert-danger mb-4">
        {{ $page.props.flash.error }}
      </div>

      <!-- ── Empty State ───────────────────────────────────────── -->
      <div v-if="costCenters.length === 0" class="zv-card">
        <div class="zv-empty py-12">
          <svg class="w-12 h-12 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
          </svg>
          <div class="zv-empty-title">{{ t('settings.costCenters.index.noCostCentersYet') }}</div>
          <div class="zv-empty-sub">{{ t('settings.costCenters.index.createYourFirstMainCostCenter') }}</div>
          <Link :href="route('settings.cost-centers.create')" class="zv-btn zv-btn-primary mt-4">
            {{ t('settings.costCenters.index.newCostCenter') }}
          </Link>
        </div>
      </div>

      <!-- ── Cost Centers Tree ─────────────────────────────────── -->
      <div v-for="main in costCenters" :key="main.id" class="cc-main-card mb-3">

        <!-- Main Cost Center Row -->
        <div class="cc-main-row" :class="{ 'cc-row--inactive': !main.is_active }">

          <!-- Expand toggle -->
          <button v-if="main.children?.length"
                  type="button"
                  class="cc-expand-btn"
                  @click="toggleExpand(main.id)">
            <svg class="w-4 h-4 transition-transform duration-200"
                 :class="{ 'rotate-90': expanded[main.id] }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
          <div v-else class="cc-expand-placeholder"></div>

          <!-- Main icon -->
          <div class="cc-main-icon">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>
            </svg>
          </div>

          <!-- Code -->
          <span class="cc-code">{{ main.code }}</span>

          <!-- Name -->
          <div class="cc-name flex-1 min-w-0">
            <span class="font-semibold" style="color:var(--zv-text-primary)">{{ main.name_en }}</span>
            <span v-if="main.name_ar" class="cc-name-ar">{{ main.name_ar }}</span>
          </div>

          <!-- Badges -->
          <div class="cc-badges">
            <span class="zv-badge zv-badge-navy">{{ t('settings.costCenters.index.main') }}</span>

            <span v-if="main.children?.length"
                  class="zv-badge zv-badge-blue">
              {{ main.children.length }} {{ t('settings.costCenters.index.sub') }}
            </span>

            <span v-if="!main.children?.length"
                  class="zv-badge zv-badge-green text-xs">
              {{ t('settings.costCenters.index.postable') }}
            </span>

            <span class="zv-badge" :class="main.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
              <span class="zv-status-dot" :class="main.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
              {{ main.is_active ? t('common.active') : t('common.inactive') }}
            </span>
          </div>

          <!-- Actions -->
          <div class="cc-actions">
            <Link :href="route('settings.cost-centers.create', { parent_id: main.id })"
                  class="zv-btn zv-btn-secondary zv-btn-sm">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('settings.costCenters.index.addSub') }}
            </Link>
            <Link :href="route('settings.cost-centers.edit', main.id)"
                  class="zv-btn zv-btn-secondary zv-btn-sm">
              {{ t('common.edit') }}
            </Link>
            <button type="button"
                    @click="toggle(main)"
                    class="zv-btn zv-btn-sm"
                    :class="main.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
              {{ main.is_active ? t('common.deactivate') : t('common.activate') }}
            </button>
          </div>
        </div>

        <!-- Sub Cost Centers -->
        <Transition name="cc-expand">
          <div v-if="main.children?.length && expanded[main.id]"
               class="cc-subs-wrap">
            <div v-for="sub in main.children" :key="sub.id"
                 class="cc-sub-row"
                 :class="{ 'cc-row--inactive': !sub.is_active }">

              <!-- Connector line -->
              <div class="cc-connector-wrap">
                <div class="cc-connector-line"></div>
              </div>

              <!-- Sub icon -->
              <div class="cc-sub-icon">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                </svg>
              </div>

              <!-- Code -->
              <span class="cc-code" style="color:var(--zv-green-text)">{{ sub.code }}</span>

              <!-- Name -->
              <div class="cc-name flex-1 min-w-0">
                <span class="font-medium" style="color:var(--zv-text-primary)">{{ sub.name_en }}</span>
                <span v-if="sub.name_ar" class="cc-name-ar">{{ sub.name_ar }}</span>
              </div>

              <!-- Badges -->
              <div class="cc-badges">
                <span class="zv-badge zv-badge-green">{{ t('settings.costCenters.index.sub2') }}</span>
                <span class="zv-badge zv-badge-green text-xs">{{ t('settings.costCenters.index.postable') }}</span>
                <span class="zv-badge" :class="sub.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="sub.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ sub.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </div>

              <!-- Actions -->
              <div class="cc-actions">
                <Link :href="route('settings.cost-centers.edit', sub.id)"
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
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  costCenters: Array,
})

// All Main Cost Centers start expanded
const expanded = reactive(
  Object.fromEntries((props.costCenters ?? []).map(cc => [cc.id, true]))
)

function toggleExpand(id) {
  expanded[id] = !expanded[id]
}

function toggle(cc) {
  router.patch(route('settings.cost-centers.toggle', cc.id))
}
</script>

<style scoped>
/* ── Main Card ──────────────────────────────────────────────── */
.cc-main-card {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  box-shadow: var(--zv-shadow-sm);
}

/* ── Main Row ───────────────────────────────────────────────── */
.cc-main-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.12s ease;
}
.cc-main-row:last-child { border-bottom: none; }
.cc-main-row:hover { background: var(--zv-bg-surface-alt); }

.cc-row--inactive { opacity: 0.55; }

.cc-expand-btn {
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius-sm);
  color: var(--zv-text-muted);
  background: transparent;
  border: none;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.12s ease;
}
.cc-expand-btn:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }
.cc-expand-placeholder { width: 24px; flex-shrink: 0; }

.cc-main-icon {
  width: 32px; height: 32px;
  border-radius: var(--zv-radius);
  background: var(--zv-nav-bg);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

/* ── Sub Rows ───────────────────────────────────────────────── */
.cc-subs-wrap { background: var(--zv-bg-card); }

.cc-sub-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.25rem 0.75rem 2rem;
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.12s ease;
}
.cc-sub-row:last-child { border-bottom: none; }
.cc-sub-row:hover { background: var(--zv-bg-hover); }

.cc-connector-wrap {
  width: 24px;
  display: flex; justify-content: center;
  flex-shrink: 0;
}
.cc-connector-line {
  width: 1px;
  height: 100%;
  min-height: 20px;
  background: var(--zv-border-md);
}

.cc-sub-icon {
  width: 28px; height: 28px;
  border-radius: var(--zv-radius-sm);
  background: var(--zv-green-light);
  color: var(--zv-green-text);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

/* ── Shared ─────────────────────────────────────────────────── */
.cc-code {
  font-family: monospace;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--zv-blue-text);
  min-width: 80px;
  flex-shrink: 0;
}

.cc-name { display: flex; flex-direction: column; }
.cc-name-ar {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  direction: rtl;
}

.cc-badges {
  display: flex;
  gap: 0.375rem;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.cc-actions {
  display: flex;
  gap: 0.375rem;
  flex-shrink: 0;
  margin-inline-start: auto;
}

/* ── Expand Transition ──────────────────────────────────────── */
.cc-expand-enter-active { transition: all 0.2s ease; overflow: hidden; }
.cc-expand-leave-active { transition: all 0.15s ease; overflow: hidden; }
.cc-expand-enter-from, .cc-expand-leave-to { opacity: 0; max-height: 0; }
.cc-expand-enter-to, .cc-expand-leave-from { opacity: 1; max-height: 500px; }
</style>
