<!--
══════════════════════════════════════════════════════════════════
  Settings/CostCenters/Form.vue

  Create and Edit cost centers — one page.
  Mode detected by whether props.costCenter exists.

  On create:
  — If no parent selected → creating a Main Cost Center
  — If parent selected → creating a Sub Cost Center
  — Parent selector only shows Main Cost Centers (level 1)
  — If parent_id passed in URL (from Add Sub button), pre-selected

  On edit:
  — Only name_en and name_ar can change
  — Code and level are shown as read-only info

  @layout    AppLayout
  @route     settings.cost-centers.create
             settings.cost-centers.edit
  @props     costCenter: CostCenter|null
             main_cost_centers: CostCenter[] — level 1 only
             selected_parent_id: Number|null
             selected_parent: CostCenter|null
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page" style="max-width:680px">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.cost-centers.index')" class="zv-text-blue">
              {{ t('settings.costCenters.form.costCenters') }}
            </Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.costCenters.form.editCostCenter') : t('settings.costCenters.form.newCostCenter') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('settings.costCenters.form.editCostCenter') : t('settings.costCenters.form.newCostCenter') }}
          </h1>
          <p v-if="isEdit" class="zv-page-subtitle">
            <span class="font-mono font-semibold" style="color:var(--zv-blue-text)">{{ costCenter.code }}</span>
            <span class="mx-2" style="color:var(--zv-border-md)">·</span>
            <span class="zv-badge" :class="costCenter.level === 1 ? 'zv-badge-navy' : 'zv-badge-green'">
              {{ costCenter.level === 1 ? t('settings.costCenters.form.mainCostCenter') : t('settings.costCenters.form.subCostCenter') }}
            </span>
          </p>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- ── Type Indicator (create only) ─────────────────────── -->
        <div v-if="!isEdit" class="cc-type-indicator mb-4"
             :class="isCreatingSub ? 'cc-type-indicator--sub' : 'cc-type-indicator--main'">
          <div class="cc-type-icon">
            <svg v-if="isCreatingSub" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>
            </svg>
          </div>
          <div>
            <div class="cc-type-label">
              {{ isCreatingSub ? t('settings.costCenters.form.newSubCostCenter') : t('settings.costCenters.form.newMainCostCenter') }}
            </div>
            <div class="cc-type-desc">
              {{ isCreatingSub
                ? t('settings.costCenters.form.transactionsWillPostDirectlyToThis')
                : t('settings.costCenters.form.aTopLevelCostCenterAdd') }}
            </div>
          </div>
        </div>

        <!-- ── Parent Selection ──────────────────────────────────── -->
        <div v-if="!isEdit" class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.costCenters.form.costCenterType') }}</h2>
          </div>
          <div class="zv-card-body">

            <!-- Toggle Main / Sub -->
            <div class="cc-toggle-wrap mb-4">
              <button type="button"
                      class="cc-toggle-btn"
                      :class="{ 'cc-toggle-btn--active': !form.parent_id }"
                      @click="form.parent_id = null">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>
                </svg>
                {{ t('settings.costCenters.form.mainCostCenter') }}
              </button>
              <button type="button"
                      class="cc-toggle-btn"
                      :class="{ 'cc-toggle-btn--active': !!form.parent_id }"
                      @click="form.parent_id = props.selected_parent_id ?? (mainCostCenters[0]?.id ?? null)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                </svg>
                {{ t('settings.costCenters.form.subCostCenter') }}
              </button>
            </div>

            <!-- Parent selector — shown when Sub is selected -->
            <div v-if="form.parent_id !== null">
              <label class="zv-label zv-label-required">{{ t('settings.costCenters.form.underMainCostCenter') }}</label>

              <!-- Pre-selected parent pill -->
              <div v-if="selectedParentObj && !showParentSelector"
                   class="parent-pill">
                <div class="flex items-center gap-2">
                  <span class="font-mono text-sm font-semibold" style="color:var(--zv-blue-text)">
                    {{ selectedParentObj.code }}
                  </span>
                  <span style="color:var(--zv-border-md)">—</span>
                  <span class="font-medium text-sm" style="color:var(--zv-text-primary)">
                    {{ selectedParentObj.name_en }}
                  </span>
                </div>
                <button type="button" @click="showParentSelector = true"
                        class="zv-btn zv-btn-ghost zv-btn-sm" style="color:var(--zv-blue-text)">
                  {{ t('common.change') }}
                </button>
              </div>

              <select v-else
                      v-model="form.parent_id" class="zv-select"
                      :class="{ 'zv-input-error': errors.parent_id }">
                <option :value="null">— {{ t('settings.costCenters.form.selectMainCostCenter') }} —</option>
                <option v-for="main in mainCostCenters" :key="main.id" :value="main.id">
                  {{ main.code }} — {{ main.name_en }}
                </option>
              </select>
              <p v-if="errors.parent_id" class="zv-field-error">{{ errors.parent_id }}</p>
            </div>

          </div>
        </div>

        <!-- ── Name Fields ───────────────────────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.costCenters.form.costCenterDetails') }}</h2>
          </div>
          <div class="zv-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

              <!-- Name EN -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.costCenters.form.nameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       :placeholder="t('settings.costCenters.form.eGOperationsDivision')" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <!-- Name AR -->
              <div>
                <label class="zv-label">{{ t('settings.costCenters.form.nameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl"
                       class="zv-input zv-input-ar"
                       placeholder="مثال: إدارة العمليات" />
              </div>

            </div>

            <!-- Code preview — create only -->
            <div v-if="!isEdit" class="mt-4 p-3 rounded-lg" style="background:var(--zv-bg-surface);border:1px solid var(--zv-border)">
              <div class="text-xs" style="color:var(--zv-text-muted)">{{ t('settings.costCenters.form.autoGeneratedCodePreview') }}</div>
              <div class="font-mono font-semibold text-sm mt-1" style="color:var(--zv-blue-text)">
                {{ codePreview }}
              </div>
            </div>

          </div>
        </div>

        <!-- ── Actions ──────────────────────────────────────────── -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3"
               style="border-radius:var(--zv-radius-lg)">
            <Link :href="route('settings.cost-centers.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.costCenters.form.createCostCenter')) }}
            </button>
          </div>
        </div>

      </form>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  costCenter:          Object,
  main_cost_centers:   Array,
  selected_parent_id:  Number,
  selected_parent:     Object,
})

const isEdit = computed(() => !!props.costCenter?.id)
const mainCostCenters = computed(() => props.main_cost_centers ?? [])

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  name_en:   props.costCenter?.name_en ?? '',
  name_ar:   props.costCenter?.name_ar ?? '',
  parent_id: props.costCenter?.parent_id ?? props.selected_parent_id ?? null,
})

const errors = computed(() => form.errors)

// ── Parent helpers ────────────────────────────────────────────────
const isCreatingSub = computed(() => form.parent_id !== null)

const selectedParentObj = computed(() => {
  if (!form.parent_id) return null
  if (props.selected_parent && props.selected_parent.id == form.parent_id) {
    return props.selected_parent
  }
  return mainCostCenters.value.find(m => m.id == form.parent_id) ?? null
})

const showParentSelector = ref(!props.selected_parent && !props.costCenter?.parent_id)

// ── Code preview ──────────────────────────────────────────────────
const codePreview = computed(() => {
  if (isCreatingSub.value) {
    const parent = selectedParentObj.value
    return parent ? parent.code + '-01' : t('settings.costCenters.form.selectParentFirst')
  }
  return t('settings.costCenters.form.cC001AutoAssigned')
})

// ── Submit ────────────────────────────────────────────────────────
function submit() {
  if (isEdit.value) {
    form.put(route('settings.cost-centers.update', props.costCenter.id))
  } else {
    form.post(route('settings.cost-centers.store'))
  }
}
</script>

<style scoped>
/* ── Type Indicator ─────────────────────────────────────────── */
.cc-type-indicator {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-radius: var(--zv-radius-lg);
  border: 2px solid;
}
.cc-type-indicator--main {
  background: #EEF2FF;
  border-color: #0F2554;
}
.cc-type-indicator--sub {
  background: var(--zv-green-light);
  border-color: var(--zv-green-border);
}

.cc-type-icon {
  width: 40px; height: 40px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  background: rgba(255,255,255,0.6);
}
.cc-type-indicator--main .cc-type-icon { color: #0F2554; }
.cc-type-indicator--sub  .cc-type-icon { color: var(--zv-green-text); }

.cc-type-label {
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--zv-text-primary);
}
.cc-type-desc {
  font-size: 0.8125rem;
  color: var(--zv-text-secondary);
  margin-top: 0.25rem;
}

/* ── Toggle ─────────────────────────────────────────────────── */
.cc-toggle-wrap {
  display: flex;
  gap: 0.5rem;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.25rem;
}
.cc-toggle-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: var(--zv-radius);
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--zv-text-secondary);
  border: none;
  background: transparent;
  cursor: pointer;
  transition: var(--zv-transition);
}
.cc-toggle-btn:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }
.cc-toggle-btn--active {
  background: var(--zv-nav-bg);
  color: #fff;
}

/* ── Parent Pill ────────────────────────────────────────────── */
.parent-pill {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.625rem 0.875rem;
  border: 2px solid var(--zv-blue-border);
  border-radius: var(--zv-radius);
  background: var(--zv-blue-light);
}
</style>
