<!--
══════════════════════════════════════════════════════════════════
  Settings/ProductCategories/Index.vue

  Two-level product category tree.
  Shows Main categories with Sub-categories indented below.
  Consumable categories show their expense account and treatment.

  @layout    AppLayout
  @route     settings.product-categories.index
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.productCategories.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.productCategories.index.productCategories') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.productCategories.index.organiseYourProductsIntoCategoriesAnd') }}</p>
        </div>
        <Link :href="route('settings.product-categories.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.productCategories.index.newCategory') }}
        </Link>
      </div>

      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="zv-alert zv-alert-danger mb-4">{{ $page.props.flash.error }}</div>

      <!-- Empty State -->
      <div v-if="categories.length === 0" class="zv-card">
        <div class="zv-empty py-12">
          <svg class="w-12 h-12 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
          </svg>
          <div class="zv-empty-title">{{ t('settings.productCategories.index.noCategoriesYet') }}</div>
          <div class="zv-empty-sub">{{ t('settings.productCategories.index.createYourFirstProductCategoryTo') }}</div>
          <Link :href="route('settings.product-categories.create')" class="zv-btn zv-btn-primary mt-4">
            {{ t('settings.productCategories.index.newCategory') }}
          </Link>
        </div>
      </div>

      <!-- Category Tree -->
      <div v-for="cat in categories" :key="cat.id" class="cat-card mb-3">

        <!-- Main Row -->
        <div class="cat-main-row" :class="{ 'cat-row--inactive': !cat.is_active }">
          <button v-if="cat.children?.length" type="button" class="cat-expand-btn" @click="toggleExpand(cat.id)">
            <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-90': expanded[cat.id] }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
          <div v-else class="w-6 flex-shrink-0"></div>

          <div class="cat-icon">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
            </svg>
          </div>

          <div class="flex-1 min-w-0">
            <div class="font-semibold" style="color:var(--zv-text-primary)">{{ cat.name_en }}</div>
            <div v-if="cat.name_ar" class="text-xs" style="color:var(--zv-text-muted);direction:rtl">{{ cat.name_ar }}</div>
          </div>

          <div class="flex gap-2 flex-shrink-0 flex-wrap items-center">
            <span v-if="cat.cost_treatment" class="zv-badge zv-badge-amber text-xs">
              {{ cat.cost_treatment === 'overhead' ? t('settings.productCategories.index.overhead') : t('settings.productCategories.index.periodExpense') }}
            </span>
            <span v-if="cat.expense_account" class="text-xs font-mono" style="color:var(--zv-text-muted)">
              {{ cat.expense_account.code }}
            </span>
            <span v-if="cat.children?.length" class="zv-badge zv-badge-blue text-xs">
              {{ cat.children.length }} {{ t('settings.productCategories.index.sub') }}
            </span>
            <span class="zv-badge" :class="cat.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
              <span class="zv-status-dot" :class="cat.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
              {{ cat.is_active ? t('common.active') : t('common.inactive') }}
            </span>
          </div>

          <div class="flex gap-2 flex-shrink-0">
            <Link :href="route('settings.product-categories.create', { parent_id: cat.id })"
                  class="zv-btn zv-btn-secondary zv-btn-sm">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('settings.productCategories.index.addSub') }}
            </Link>
            <Link :href="route('settings.product-categories.edit', cat.id)"
                  class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('common.edit') }}</Link>
            <button type="button" @click="toggle(cat)"
                    class="zv-btn zv-btn-sm" :class="cat.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
              {{ cat.is_active ? t('common.deactivate') : t('common.activate') }}
            </button>
          </div>
        </div>

        <!-- Sub-Categories -->
        <Transition name="cat-expand">
          <div v-if="cat.children?.length && expanded[cat.id]">
            <div v-for="sub in cat.children" :key="sub.id"
                 class="cat-sub-row" :class="{ 'cat-row--inactive': !sub.is_active }">
              <div class="w-6 flex-shrink-0"></div>
              <div class="w-1 bg-zv-border mx-1 flex-shrink-0 h-full min-h-5"></div>
              <div class="cat-sub-icon">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
              </div>

              <div class="flex-1 min-w-0">
                <div class="font-medium text-sm" style="color:var(--zv-text-primary)">{{ sub.name_en }}</div>
                <div v-if="sub.name_ar" class="text-xs" style="color:var(--zv-text-muted);direction:rtl">{{ sub.name_ar }}</div>
              </div>

              <div class="flex gap-2 flex-shrink-0 items-center">
                <span v-if="sub.cost_treatment" class="zv-badge zv-badge-amber text-xs">
                  {{ sub.cost_treatment === 'overhead' ? t('settings.productCategories.index.overhead') : t('settings.productCategories.index.periodExpense') }}
                </span>
                <span class="zv-badge" :class="sub.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="sub.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ sub.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </div>

              <div class="flex gap-2 flex-shrink-0">
                <Link :href="route('settings.product-categories.edit', sub.id)"
                      class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('common.edit') }}</Link>
                <button type="button" @click="toggle(sub)"
                        class="zv-btn zv-btn-sm" :class="sub.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
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
const props = defineProps({ categories: Array })

const expanded = reactive(
  Object.fromEntries((props.categories ?? []).map(c => [c.id, true]))
)
function toggleExpand(id) { expanded[id] = !expanded[id] }
function toggle(cat) { router.patch(route('settings.product-categories.toggle', cat.id)) }
</script>

<style scoped>
.cat-card {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-inline-start: 4px solid var(--zv-blue);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  box-shadow: var(--zv-shadow-sm);
}
.cat-main-row {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
}
.cat-main-row:hover { background: var(--zv-bg-surface-alt); }
.cat-row--inactive { opacity: 0.55; }
.cat-expand-btn {
  width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius-sm); border: none; background: transparent;
  cursor: pointer; color: var(--zv-text-muted); flex-shrink: 0;
}
.cat-icon {
  width: 32px; height: 32px; border-radius: var(--zv-radius);
  background: var(--zv-nav-bg); color: #fff;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.cat-sub-row {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.625rem 1.25rem 0.625rem 2rem;
  border-bottom: 1px solid var(--zv-border);
  background: var(--zv-bg-card);
}
.cat-sub-row:last-child { border-bottom: none; }
.cat-sub-row:hover { background: var(--zv-bg-hover); }
.cat-sub-icon {
  width: 26px; height: 26px; border-radius: var(--zv-radius-sm);
  background: var(--zv-blue-light); color: var(--zv-blue-text);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.cat-expand-enter-active { transition: all 0.2s ease; overflow: hidden; }
.cat-expand-leave-active { transition: all 0.15s ease; overflow: hidden; }
.cat-expand-enter-from, .cat-expand-leave-to { opacity: 0; max-height: 0; }
.cat-expand-enter-to, .cat-expand-leave-from { opacity: 1; max-height: 600px; }
</style>
