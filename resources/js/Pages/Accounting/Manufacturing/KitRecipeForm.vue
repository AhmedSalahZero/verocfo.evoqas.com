<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/KitRecipeForm.vue

  Create and Edit share one page (Rule 2) — mode by props.recipe?.id.
  Unlike BomForm, there is no version chip, no Active badge tied to
  a version, and no locked-once-active state — a Kit recipe is
  always fully editable (see KitRecipeService header for why that's
  safe here). The kit product itself is fixed once created (shown
  read-only in edit mode), same reasoning as BomForm's product_id.

  @layout    AppLayout
  @route     accounting.kit-recipes.create / accounting.kit-recipes.edit
  @props     recipe (edit only), products
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.kitRecipeForm.kitRecipes'), href: route('accounting.kit-recipes.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.kitRecipeForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.kitRecipeForm.editKitRecipe') : t('accounting.manufacturing.kitRecipeForm.newKitRecipe')"
      :subtitle="t('accounting.manufacturing.kitRecipeForm.whichComponentsGoIntoOneUnit')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#2563EB">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.kitRecipeForm.kitProduct') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.kitRecipeForm.theFinishedProductThisRecipe') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.kitRecipeForm.kitProduct') }}</label>
            <div v-if="isEdit" class="zv-readonly-field">{{ recipe.product?.code }} — {{ recipe.product?.name_en }}</div>
            <SelectBox
              v-else
              v-model="form.product_id"
              :options="products"
              value-key="id"
              :label-fn="p => `${p.code} — ${p.name_en}`"
              :placeholder="t('accounting.manufacturing.kitRecipeForm.selectTheProductToBeAssembled')"
              :clearable="false"
              :class="{ 'zv-input-error': form.errors.product_id }"
            />
            <p v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</p>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('common.notes') }}</label>
            <textarea v-model="form.notes" rows="2" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.kitRecipeForm.componentsTitle') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.kitRecipeForm.quantityOfEachComponentNeeded') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap kit-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.manufacturing.kitRecipeForm.component') }}</th>
                  <th class="kit-th-qty">{{ t('accounting.manufacturing.kitRecipeForm.qtyPerOneKitUnit') }}</th>
                  <th class="kit-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.component_product_id" :options="components" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.kitRecipeForm.selectComponent')" :clearable="false" size="sm" />
                  </td>
                  <td>
                    <input v-model="line.quantity_per_kit" type="number" step="0.0001" min="0.0001" class="zv-input text-end"/>
                  </td>
                  <td class="kit-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.manufacturing.kitRecipeForm.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('accounting.manufacturing.kitRecipeForm.addComponent') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.kit-recipes.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.kitRecipeForm.createKitRecipe')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  recipe:   { type: Object, default: null },
  products: { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.recipe?.id)

// The kit product itself can't be picked as its own component —
// filtered out here rather than only relying on the server-side
// guard (KitRecipeService::assertLinesValid), for immediate feedback.
const kitProductId = computed(() => props.recipe?.product_id ?? null)
// The kit itself can never be one of its own components — on a NEW recipe the kit is
// whatever is picked in the Product field right now.
const components = computed(() => {
  const kitId = kitProductId.value ?? form.product_id
  return props.products.filter(p => p.id !== kitId)
})

const form = useForm({
  product_id: props.recipe?.product_id ?? '',
  notes: props.recipe?.notes ?? '',
  lines: props.recipe?.lines?.map(l => ({
    component_product_id: l.component_product_id, quantity_per_kit: l.quantity_per_kit,
  })) ?? [{ component_product_id: '', quantity_per_kit: '' }],
})

// If the kit product is changed to one already chosen as a component, drop that component.
watch(() => form.product_id, (id) => {
  form.lines.forEach(l => { if (id && l.component_product_id === id) l.component_product_id = '' })
})

function addLine() {
  form.lines.push({ component_product_id: '', quantity_per_kit: '' })
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.kit-recipes.update', props.recipe.id))
    : form.post(route('accounting.kit-recipes.store'))
}
</script>

<style>
.kit-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.kit-th-qty { width: 12rem; }
.kit-th-actions { width: 3rem; text-align: center; }
</style>
