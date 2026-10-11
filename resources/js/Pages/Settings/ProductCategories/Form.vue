<!--
══════════════════════════════════════════════════════════════════
  Settings/ProductCategories/Form.vue

  Create and Edit product categories.
  Main Category (no parent) or Sub-Category (with parent).
  Consumable categories need: cost_treatment + expense_account_id.

  @layout    AppLayout
  @route     settings.product-categories.create/edit
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page" style="max-width:680px">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.product-categories.index')" class="zv-text-blue">{{ t('settings.productCategories.form.productCategories') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.productCategories.form.editCategory') : t('settings.productCategories.form.newCategory') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('settings.productCategories.form.editCategory') : t('settings.productCategories.form.newCategory') }}</h1>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- Level selector (create only) -->
        <div v-if="!isEdit" class="zv-card mb-4">
          <div class="zv-card-header"><h2 class="zv-card-title">{{ t('settings.productCategories.form.categoryLevel') }}</h2></div>
          <div class="zv-card-body">
            <div class="loc-toggle-wrap mb-4">
              <button type="button" class="loc-toggle-btn" :class="{ 'loc-toggle-btn--active': !form.parent_id }"
                      @click="form.parent_id = null">
                {{ t('settings.productCategories.form.mainCategory') }}
              </button>
              <button type="button" class="loc-toggle-btn"
                      :class="{ 'loc-toggle-btn--active': form.parent_id !== null }"
                      @click="form.parent_id = props.selected_parent_id ?? (mainCategories[0]?.id ?? null)">
                {{ t('settings.productCategories.form.subCategory') }}
              </button>
            </div>

            <div v-if="form.parent_id !== null">
              <label class="zv-label zv-label-required">{{ t('settings.productCategories.form.underMainCategory') }}</label>
              <div v-if="selectedParent && !showParentSelector" class="parent-pill">
                <span class="font-medium text-sm">{{ selectedParent.name_en }}</span>
                <button type="button" @click="showParentSelector = true" class="zv-btn zv-btn-ghost zv-btn-sm" style="color:var(--zv-blue-text)">{{ t('common.change') }}</button>
              </div>
              <select v-else v-model="form.parent_id" class="zv-select">
                <option :value="null">— {{ t('settings.productCategories.form.selectMainCategory') }} —</option>
                <option v-for="main in mainCategories" :key="main.id" :value="main.id">{{ main.name_en }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Details -->
        <div class="zv-card mb-4">
          <div class="zv-card-header"><h2 class="zv-card-title">{{ t('settings.productCategories.form.categoryDetails') }}</h2></div>
          <div class="zv-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.productCategories.form.nameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>
              <div>
                <label class="zv-label">{{ t('settings.productCategories.form.nameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl" class="zv-input zv-input-ar" />
              </div>
            </div>
          </div>
        </div>

        <!-- Consumable settings -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.productCategories.form.consumableSettings') }}</h2>
            <div class="ms-auto text-xs" style="color:var(--zv-text-muted)">{{ t('settings.productCategories.form.leaveBlankForNonConsumableCategories') }}</div>
          </div>
          <div class="zv-card-body">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

              <div>
                <label class="zv-label">{{ t('settings.productCategories.form.costTreatment') }}</label>
                <select v-model="form.cost_treatment" class="zv-select">
                  <option value="">— {{ t('settings.productCategories.form.notAConsumableCategory') }} —</option>
                  <option value="overhead">{{ t('settings.productCategories.form.overheadAllocatesToProductionCost') }}</option>
                  <option value="period_expense">{{ t('settings.productCategories.form.periodExpenseDirectPLCharge') }}</option>
                </select>
              </div>

              <Transition name="section-slide">
                <div v-if="form.cost_treatment">
                  <label class="zv-label zv-label-required">{{ t('settings.productCategories.form.expenseAccount') }}</label>
                  <select v-model="form.expense_account_id" class="zv-select"
                          :class="{ 'zv-input-error': errors.expense_account_id }">
                    <option :value="null">— {{ t('settings.productCategories.form.selectAccount') }} —</option>
                    <optgroup v-for="group in expenseAccountGroups" :key="group.label" :label="group.label">
                      <option v-for="acc in group.accounts" :key="acc.id" :value="acc.id">
                        {{ acc.code }} — {{ acc.name_en }}
                      </option>
                    </optgroup>
                  </select>
                  <p v-if="errors.expense_account_id" class="zv-field-error">{{ errors.expense_account_id }}</p>
                </div>
              </Transition>

            </div>

          </div>
        </div>

        <!-- Actions -->
        <div class="zv-card">
          <div class="zv-card-footer flex justify-end gap-3" style="border-radius:var(--zv-radius-lg)">
            <Link :href="route('settings.product-categories.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.productCategories.form.createCategory')) }}
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
  category:            Object,
  main_categories:     Array,
  selected_parent_id:  Number,
  selected_parent:     Object,
  expense_accounts:    Array,
})

const isEdit = computed(() => !!props.category?.id)
const mainCategories = computed(() => props.main_categories ?? [])
const errors = computed(() => form.errors)

const form = useForm({
  name_en:            props.category?.name_en            ?? '',
  name_ar:            props.category?.name_ar            ?? '',
  parent_id:          props.category?.parent_id          ?? props.selected_parent_id ?? null,
  cost_treatment:     props.category?.cost_treatment     ?? '',
  expense_account_id: props.category?.expense_account_id ?? null,
})

const selectedParent = computed(() => {
  if (!form.parent_id) return null
  if (props.selected_parent?.id == form.parent_id) return props.selected_parent
  return mainCategories.value.find(m => m.id == form.parent_id) ?? null
})
const showParentSelector = ref(!props.selected_parent && !props.category?.parent_id)

// Group expense accounts by account_type
const expenseAccountGroups = computed(() => {
  const groups = {}
  ;(props.expense_accounts ?? []).forEach(acc => {
    if (!groups[acc.account_type]) groups[acc.account_type] = { label: acc.account_type, accounts: [] }
    groups[acc.account_type].accounts.push(acc)
  })
  return Object.values(groups)
})

function submit() {
  if (isEdit.value) form.put(route('settings.product-categories.update', props.category.id))
  else form.post(route('settings.product-categories.store'))
}
</script>

<style scoped>
.loc-toggle-wrap {
  display: flex; gap: 0.5rem;
  background: var(--zv-bg-surface); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md); padding: 0.25rem;
}
.loc-toggle-btn {
  flex: 1; padding: 0.5rem 1rem; border-radius: var(--zv-radius);
  font-size: 0.875rem; font-weight: 500; color: var(--zv-text-secondary);
  border: none; background: transparent; cursor: pointer; transition: var(--zv-transition);
}
.loc-toggle-btn:hover { background: var(--zv-bg-hover); }
.loc-toggle-btn--active { background: var(--zv-nav-bg); color: #fff; }
.parent-pill {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.625rem 0.875rem;
  border: 2px solid var(--zv-blue-border); border-radius: var(--zv-radius);
  background: var(--zv-blue-light);
}
.section-slide-enter-active { transition: all 0.2s ease; }
.section-slide-leave-active { transition: all 0.15s ease; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
