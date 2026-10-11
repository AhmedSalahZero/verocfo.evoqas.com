<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/KitAssemblyForm.vue

  The "assemble N units of this kit" action. Picking a recipe shows
  a live preview of exactly how much of each component will be
  consumed, scaled by the quantity entered — pure client-side math
  (recipe.lines[].quantity_per_kit × quantity), matching exactly
  what KitAssemblyService::assemble() will do server-side.

  One location for both sides (components drawn from it, kit
  received into it) — deliberately simpler than Production Order,
  see migration 000253 header.

  @layout    AppLayout
  @route     accounting.kit-assemblies.create
  @props     recipes, locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.kitAssemblyForm.kitAssemblies'), href: route('accounting.kit-assemblies.index') }, { label: t('accounting.manufacturing.kitAssemblyForm.assemble') }]"
      :title="t('accounting.manufacturing.kitAssemblyForm.assembleKit')"
      :subtitle="t('accounting.manufacturing.kitAssemblyForm.consumeComponentsFromStock')"
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
            <div class="form-section-title">{{ t('accounting.manufacturing.kitAssemblyForm.kitAndQuantity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.kitAssemblyForm.whichKitHowManyAndWhere') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-2-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.kitAssemblyForm.kitRecipe') }}</label>
              <SelectBox
                v-model="form.kit_recipe_id"
                :options="recipes"
                value-key="id"
                :label-fn="r => `${r.product?.code} — ${r.product?.name_en}`"
                :placeholder="t('accounting.manufacturing.kitAssemblyForm.selectAKitRecipe')"
                :clearable="false"
                :class="{ 'zv-input-error': form.errors.kit_recipe_id }"
              />
              <p v-if="form.errors.kit_recipe_id" class="zv-field-error">{{ form.errors.kit_recipe_id }}</p>
              <p v-if="selectedRecipe && selectedRecipe.lines.length === 0" class="field-hint zv-field-error">
                {{ t('accounting.manufacturing.kitAssemblyForm.thisRecipeHasNoComponents') }}
              </p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.kitAssemblyForm.quantityToAssemble') }}</label>
              <input v-model="form.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                     :class="{ 'zv-input-error': form.errors.quantity }"/>
              <p v-if="form.errors.quantity" class="zv-field-error">{{ form.errors.quantity }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.kitAssemblyForm.date') }}</label>
              <input v-model="form.assembly_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.assembly_date }"/>
              <p v-if="form.errors.assembly_date" class="zv-field-error">{{ form.errors.assembly_date }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.kitAssemblyForm.location') }}</label>
            <p class="field-hint mb-1">{{ t('accounting.manufacturing.kitAssemblyForm.componentsDrawnFromHere') }}</p>
            <SelectBox
              v-model="form.inventory_location_id"
              :options="locations"
              value-key="id"
              :label-fn="l => `${l.code} — ${l.name_en}`"
              :placeholder="t('accounting.manufacturing.kitAssemblyForm.selectLocation')"
              :clearable="false"
              :class="{ 'zv-input-error': form.errors.inventory_location_id }"
            />
            <p v-if="form.errors.inventory_location_id" class="zv-field-error">{{ form.errors.inventory_location_id }}</p>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('common.notes') }}</label>
            <textarea v-model="form.notes" rows="2" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div v-if="selectedRecipe && selectedRecipe.lines.length > 0" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.kitAssemblyForm.componentsThatWillBeConsumed') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.kitAssemblyForm.basedOnTheRecipeScaled') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.manufacturing.kitAssemblyForm.component') }}</th>
                  <th class="zv-table-num">{{ t('accounting.manufacturing.kitAssemblyForm.qtyPerKit') }}</th>
                  <th class="zv-table-num">{{ t('accounting.manufacturing.kitAssemblyForm.totalNeeded') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in selectedRecipe.lines" :key="line.id">
                  <td>
                    <div class="inv-product-cell">
                      <span class="zv-code">{{ line.component_product?.code }}</span>
                      <span>{{ line.component_product?.name_en }}</span>
                    </div>
                  </td>
                  <td class="zv-table-num">{{ fmtQty(line.quantity_per_kit) }}</td>
                  <td class="zv-table-num">{{ fmtQty(totalNeeded(line.quantity_per_kit)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="field-hint mt-2">{{ t('accounting.manufacturing.kitAssemblyForm.eachComponentIsDrawnFromStock') }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.kit-assemblies.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('accounting.manufacturing.kitAssemblyForm.assembling') : t('accounting.manufacturing.kitAssemblyForm.assembleKit') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  recipes:   { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
})

const form = useForm({
  kit_recipe_id: '',
  quantity: '',
  inventory_location_id: '',
  assembly_date: todayLocal(),
  notes: '',
})

const selectedRecipe = computed(() => props.recipes.find(r => r.id === form.kit_recipe_id) ?? null)

function totalNeeded(qtyPerKit) {
  const qty = Number(form.quantity || 0)
  return qty > 0 ? Number(qtyPerKit) * qty : 0
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function submit() {
  form.post(route('accounting.kit-assemblies.store'))
}
</script>

<style>
.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }
</style>
