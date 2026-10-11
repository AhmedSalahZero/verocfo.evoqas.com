<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/ProductionOrderForm.vue

  Create-only — a Production Order's core fields never get edited
  after creation (see ProductionOrderController header: correcting a
  mistake means cancelling and opening a new one, not editing this
  one). Standard cost is calculated automatically at save time from
  the BOM and whatever standard rates are in effect — nothing about
  cost is entered here.

  BOM options narrow automatically to whichever active BOMs belong
  to the selected product — no product/BOM mismatch is possible to
  submit.

  @layout    AppLayout
  @route     accounting.production.create
  @props     products, boms, locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.productionOrderForm.productionOrders'), href: route('accounting.production.index') }, { label: t('accounting.manufacturing.productionOrderForm.new') }]"
      :title="t('accounting.manufacturing.productionOrderForm.newProductionOrder')"
      :subtitle="t('accounting.manufacturing.productionOrderForm.standardCostIsCalculatedAutomaticallyFrom')"
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
            <div class="form-section-title">{{ t('accounting.manufacturing.productionOrderForm.whatToProduce') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.productionOrderForm.theFinishedGoodTheBOMVersion') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderForm.product') }}</label>
              <SelectBox v-model="form.product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.productionOrderForm.selectAManufacturedProduct')" :clearable="false" :class="{ 'zv-input-error': form.errors.product_id }" />
              <p v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderForm.quantityToProduce') }}</label>
              <input v-model="form.quantity_to_produce" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                     :class="{ 'zv-input-error': form.errors.quantity_to_produce }"/>
              <p v-if="form.errors.quantity_to_produce" class="zv-field-error">{{ form.errors.quantity_to_produce }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.manufacturing.productionOrderForm.billOfMaterials') }}</label>
            <div v-if="!form.product_id" class="field-hint">{{ t('accounting.manufacturing.productionOrderForm.selectAProductFirst') }}</div>
            <div v-else-if="resolvedBom" class="zv-readonly-field po-bom-preview">
              <span class="bom-version-chip">{{ t('accounting.manufacturing.productionOrderForm.version') }} {{ resolvedBom.version }}</span>
              <span>— {{ t('accounting.manufacturing.productionOrderForm.output') }} {{ resolvedBom.output_quantity }}</span>
              <span v-if="resolvedBom.status === 'superseded'" class="zv-badge zv-badge-gray ms-2">{{ t('accounting.manufacturing.productionOrderForm.historicalVersion') }}</span>
            </div>
            <p v-else class="field-hint zv-field-error">
              {{ t('accounting.manufacturing.productionOrderForm.noBomEffectiveForThisProduct') }}
            </p>
            <p class="field-hint mt-1">
              {{ t('accounting.manufacturing.productionOrderForm.automaticallyResolvedFromBom') }}
            </p>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/>
              <circle cx="12" cy="11" r="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.productionOrderForm.locations') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.productionOrderForm.whereRawMaterialIsDrawnFrom') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderForm.rawMaterialLocation') }}</label>
              <SelectBox v-model="form.raw_material_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.manufacturing.productionOrderForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.raw_material_location_id }" />
              <p v-if="form.errors.raw_material_location_id" class="zv-field-error">{{ form.errors.raw_material_location_id }}</p>
            </div>

            <div class="field-group">
              <div class="po-flow-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
              </div>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderForm.outputLocationFinishedGoods') }}</label>
              <SelectBox v-model="form.output_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.manufacturing.productionOrderForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.output_location_id }" />
              <p v-if="form.errors.output_location_id" class="zv-field-error">{{ form.errors.output_location_id }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#D97706">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.productionOrderForm.schedule') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.productionOrderForm.optionalWhenThisOrderIsExpected') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-4-8">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderForm.plannedStartDate') }}</label>
              <input v-model="form.planned_start_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderForm.notes') }}</label>
              <textarea v-model="form.notes" rows="1" class="zv-input"></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.production.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('accounting.manufacturing.productionOrderForm.creating') : t('accounting.manufacturing.productionOrderForm.createProductionOrder') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  products:  { type: Array, default: () => [] },
  boms:      { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
})

const availableBoms = computed(() => props.boms.filter(b => b.product_id === form.product_id))

// Client-side preview only, mirroring BillOfMaterialService::resolveEffective()
// exactly (latest effective_date on or before the chosen date, among
// Active/Superseded versions) — purely so the user can see which version
// will apply before submitting. The server always resolves this again
// itself; nothing computed here is sent to or trusted by the backend.
const resolvedBom = computed(() => {
  const date = form.planned_start_date || todayLocal()
  return availableBoms.value
    .filter(b => b.effective_date && b.effective_date.slice(0, 10) <= date)
    .sort((a, b) => (a.effective_date < b.effective_date ? 1 : a.effective_date > b.effective_date ? -1 : b.version - a.version))[0] ?? null
})

const form = useForm({
  product_id: '',
  quantity_to_produce: '',
  raw_material_location_id: '',
  output_location_id: '',
  planned_start_date: '',
  notes: '',
})

function submit() {
  form.post(route('accounting.production.store'))
}
</script>

<style>
.po-flow-arrow { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--zv-text-muted, #94a3b8); padding-top: 1.25rem; }
.po-bom-preview { display: flex; align-items: center; gap: 0.4rem; }
.bom-version-chip { display: inline-flex; align-items: center; font-size: 0.8rem; font-weight: 700; color: var(--zv-text-secondary, #475569); background: var(--zv-subtle-bg, #f1f5f9); border-radius: 999px; padding: 0.15rem 0.7rem; }
</style>
