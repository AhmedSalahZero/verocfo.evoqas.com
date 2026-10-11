<!--
══════════════════════════════════════════════════════════════════
  Settings/InventoryLocations/Form.vue

  Create and Edit inventory locations — one page.
  Same Main/Sub toggle pattern as Cost Centers.

  On create:
  — Main Location → no parent
  — Sub-Location  → select a parent main location
  — parent_id pre-selected from URL query (Add Sub button)

  On edit:
  — Only name_en and name_ar can change
  — Code and level are read-only

  Project site locations cannot be edited (managed by Projects).

  @layout    AppLayout
  @route     settings.locations.create / settings.locations.edit
  @props     location: InventoryLocation|null
             main_locations: InventoryLocation[]
             selected_parent_id: Number|null
             selected_parent: Object|null
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page" style="max-width:680px">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.locations.index')" class="zv-text-blue">
              {{ t('settings.inventoryLocations.form.inventoryLocations') }}
            </Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.inventoryLocations.form.editLocation') : t('settings.inventoryLocations.form.newLocation') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('settings.inventoryLocations.form.editLocation') : t('settings.inventoryLocations.form.newLocation') }}
          </h1>
          <p v-if="isEdit" class="zv-page-subtitle">
            <span class="font-mono font-semibold" style="color:var(--zv-blue-text)">
              {{ location.code }}
            </span>
            <span class="mx-2" style="color:var(--zv-border-md)">·</span>
            <span class="zv-badge" :class="location.level === 1 ? 'zv-badge-navy' : 'zv-badge-green'">
              {{ location.level === 1 ? t('settings.inventoryLocations.form.mainLocation') : t('settings.inventoryLocations.form.subLocation') }}
            </span>
          </p>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- ── Location Type Toggle (create only) ───────────────── -->
        <div v-if="!isEdit" class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.inventoryLocations.form.locationType') }}</h2>
          </div>
          <div class="zv-card-body">

            <!-- Type indicator -->
            <div class="loc-type-indicator mb-4"
                 :class="isCreatingSub ? 'loc-type--sub' : 'loc-type--main'">
              <div class="loc-type-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
              </div>
              <div>
                <div class="loc-type-label">
                  {{ isCreatingSub ? t('settings.inventoryLocations.form.newSubLocation') : t('settings.inventoryLocations.form.newMainLocation') }}
                </div>
                <div class="loc-type-desc">
                  {{ isCreatingSub
                    ? t('settings.inventoryLocations.form.aSubLocationWithinAMain')
                    : t('settings.inventoryLocations.form.aTopLevelLocationAddSub') }}
                </div>
              </div>
            </div>

            <!-- Main / Sub toggle -->
            <div class="loc-toggle-wrap mb-4">
              <button type="button"
                      class="loc-toggle-btn"
                      :class="{ 'loc-toggle-btn--active': !form.parent_id }"
                      @click="form.parent_id = null">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>
                </svg>
                {{ t('settings.inventoryLocations.form.mainLocation') }}
              </button>
              <button type="button"
                      class="loc-toggle-btn"
                      :class="{ 'loc-toggle-btn--active': !!form.parent_id }"
                      @click="form.parent_id = selectedParentObj?.id ?? (mainLocations[0]?.id ?? null)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                {{ t('settings.inventoryLocations.form.subLocation') }}
              </button>
            </div>

            <!-- Parent selector -->
            <div v-if="form.parent_id !== null">
              <label class="zv-label zv-label-required">{{ t('settings.inventoryLocations.form.underMainLocation') }}</label>

              <!-- Pre-selected pill -->
              <div v-if="selectedParentObj && !showParentSelector" class="parent-pill">
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

              <select v-else v-model="form.parent_id" class="zv-select"
                      :class="{ 'zv-input-error': errors.parent_id }">
                <option :value="null">— {{ t('settings.inventoryLocations.form.selectMainLocation') }} —</option>
                <option v-for="main in mainLocations" :key="main.id" :value="main.id">
                  {{ main.code }} — {{ main.name_en }}
                </option>
              </select>
              <p v-if="errors.parent_id" class="zv-field-error">{{ errors.parent_id }}</p>
            </div>

          </div>
        </div>

        <!-- ── Location Details ──────────────────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.inventoryLocations.form.locationDetails') }}</h2>
          </div>
          <div class="zv-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

              <div>
                <label class="zv-label zv-label-required">{{ t('settings.inventoryLocations.form.nameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       :placeholder="t('settings.inventoryLocations.form.eGAlexandriaBranch')" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <div>
                <label class="zv-label">{{ t('settings.inventoryLocations.form.nameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl"
                       class="zv-input zv-input-ar"
                       placeholder="مثال: فرع الإسكندرية" />
              </div>

            </div>

            <!-- Code preview -->
            <div v-if="!isEdit" class="mt-4 p-3 rounded-lg"
                 style="background:var(--zv-bg-surface);border:1px solid var(--zv-border)">
              <div class="text-xs" style="color:var(--zv-text-muted)">
                {{ t('settings.inventoryLocations.form.autoGeneratedCodePreview') }}
              </div>
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
            <Link :href="route('settings.locations.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.inventoryLocations.form.createLocation')) }}
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
  location:            Object,
  main_locations:      Array,
  selected_parent_id:  Number,
  selected_parent:     Object,
})

const isEdit = computed(() => !!props.location?.id)
const mainLocations = computed(() => props.main_locations ?? [])
const errors = computed(() => form.errors)

const form = useForm({
  name_en:   props.location?.name_en ?? '',
  name_ar:   props.location?.name_ar ?? '',
  parent_id: props.location?.parent_id ?? props.selected_parent_id ?? null,
})

const isCreatingSub = computed(() => form.parent_id !== null)

const selectedParentObj = computed(() => {
  if (!form.parent_id) return null
  if (props.selected_parent && props.selected_parent.id == form.parent_id) {
    return props.selected_parent
  }
  return mainLocations.value.find(m => m.id == form.parent_id) ?? null
})

const showParentSelector = ref(!props.selected_parent && !props.location?.parent_id)

const codePreview = computed(() => {
  if (isCreatingSub.value) {
    const parent = selectedParentObj.value
    return parent ? parent.code + '-01' : t('settings.inventoryLocations.form.selectParentFirst')
  }
  return t('settings.inventoryLocations.form.lOCXXXAutoAssigned')
})

function submit() {
  if (isEdit.value) {
    form.put(route('settings.locations.update', props.location.id))
  } else {
    form.post(route('settings.locations.store'))
  }
}
</script>

<style scoped>
.loc-type-indicator {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-radius: var(--zv-radius-lg);
  border: 2px solid;
}
.loc-type--main { background: #EEF2FF; border-color: #0F2554; }
.loc-type--sub  { background: var(--zv-green-light); border-color: var(--zv-green-border); }

.loc-type-icon {
  width: 40px; height: 40px;
  border-radius: var(--zv-radius-md);
  background: rgba(255,255,255,0.6);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.loc-type--main .loc-type-icon { color: #0F2554; }
.loc-type--sub  .loc-type-icon { color: var(--zv-green-text); }

.loc-type-label {
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--zv-text-primary);
}
.loc-type-desc {
  font-size: 0.8125rem;
  color: var(--zv-text-secondary);
  margin-top: 0.25rem;
}

.loc-toggle-wrap {
  display: flex;
  gap: 0.5rem;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.25rem;
}
.loc-toggle-btn {
  flex: 1;
  display: flex; align-items: center; justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: var(--zv-radius);
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--zv-text-secondary);
  border: none; background: transparent;
  cursor: pointer;
  transition: var(--zv-transition);
}
.loc-toggle-btn:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }
.loc-toggle-btn--active { background: var(--zv-nav-bg); color: #fff; }

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
