<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/JobOrderForm.vue

  Create-only — same immutability rule as ProductionOrderForm. The
  one genuinely new interaction versus Mass Production: Material
  Source is a real choice here (confirmed: support both) — start
  from a saved template (preview scales automatically to this job's
  quantity, read-only) or enter the material list fresh. Only one of
  job_order_template_id / lines is ever sent, matching
  JobOrderRequest's required_without rule.

  No cost calculation shown for material here — JobOrderService
  auto-calculates estimated_material_cost_per_unit server-side from
  whichever lines are submitted × current WAC. Only labor/overhead
  estimates are entered directly, since a one-off job has no
  repeatable routing figure to calculate them from.

  @layout    AppLayout
  @route     accounting.job-orders.create
  @props     products, templates, locations, salesOrders
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.jobOrderForm.jobOrders'), href: route('accounting.job-orders.index') }, { label: t('accounting.manufacturing.jobOrderForm.new') }]"
      :title="t('accounting.manufacturing.jobOrderForm.newJobOrder')"
      :subtitle="t('accounting.manufacturing.jobOrderForm.materialCostIsCalculatedAutomaticallyFrom')"
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
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderForm.whatToProduce') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderForm.theFinishedGoodQuantityAndThe') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-2-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderForm.product') }}</label>
              <SelectBox v-model="form.product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.product_id }" />
              <p v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderForm.quantityToProduce') }}</label>
              <input v-model="form.quantity_to_produce" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                     :class="{ 'zv-input-error': form.errors.quantity_to_produce }"/>
              <p v-if="form.errors.quantity_to_produce" class="zv-field-error">{{ form.errors.quantity_to_produce }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderForm.salesOrder') }}</label>
              <SelectBox v-model="form.sales_order_id" :options="salesOrders" value-key="id" :label-fn="so => `${so.so_code}${so.subject ? ' — ' + so.subject : ''}`" :placeholder="t('accounting.manufacturing.jobOrderForm.notLinkedToOne')" />
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderForm.materialSource') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderForm.startFromASavedTemplateOr') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="jo-source-tiles mb-5">
            <button type="button" class="jo-source-tile" :class="{ 'jo-source-tile--selected': materialMode === 'template' }" @click="materialMode = 'template'">
              <div class="jo-source-tile-label">{{ t('accounting.manufacturing.jobOrderForm.useATemplate') }}</div>
              <div class="jo-source-tile-desc">{{ t('accounting.manufacturing.jobOrderForm.pickASavedMaterialListIt') }}</div>
            </button>
            <button type="button" class="jo-source-tile" :class="{ 'jo-source-tile--selected': materialMode === 'manual' }" @click="materialMode = 'manual'">
              <div class="jo-source-tile-label">{{ t('accounting.manufacturing.jobOrderForm.enterMaterialsManually') }}</div>
              <div class="jo-source-tile-desc">{{ t('accounting.manufacturing.jobOrderForm.aOneOffListJustFor') }}</div>
            </button>
          </div>

          <!-- ── Template mode ─────────────────────────────────── -->
          <template v-if="materialMode === 'template'">
            <div class="field-group mb-4">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderForm.template') }}</label>
              <SelectBox v-model="form.job_order_template_id" :options="visibleTemplates" value-key="id" :label-fn="tp => tp.name" :placeholder="t('accounting.manufacturing.jobOrderForm.selectATemplate')" :clearable="false" :class="{ 'zv-input-error': form.errors.job_order_template_id }" />
              <p v-if="form.errors.job_order_template_id" class="zv-field-error">{{ form.errors.job_order_template_id }}</p>
            </div>

            <div v-if="selectedTemplate" class="zv-table-wrap jo-lines-wrap">
              <table class="zv-table">
                <thead><tr><th>{{ t('accounting.manufacturing.jobOrderForm.component') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderForm.requiredQty') }}</th></tr></thead>
                <tbody>
                  <tr v-for="line in scaledTemplateLines" :key="line.component_product_id">
                    <td>{{ line.component_product?.name_en }}</td>
                    <td class="zv-table-num">{{ fmtQty(line.scaledQuantity) }}</td>
                  </tr>
                </tbody>
              </table>
              <p class="field-hint jo-preview-hint">{{ t('accounting.manufacturing.jobOrderForm.readOnlyPreviewScaledToThe') }}</p>
            </div>
          </template>

          <!-- ── Manual mode ───────────────────────────────────── -->
          <template v-else>
            <div class="zv-table-wrap jo-lines-wrap">
              <table class="zv-table">
                <thead>
                  <tr>
                    <th>{{ t('accounting.manufacturing.jobOrderForm.component') }}</th>
                    <th class="jo-th-qty">{{ t('accounting.manufacturing.jobOrderForm.quantityTotalForThisJob') }}</th>
                    <th>{{ t('accounting.manufacturing.jobOrderForm.uOM') }}</th>
                    <th class="jo-th-actions"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(line, idx) in form.lines" :key="idx">
                    <td>
                      <SelectBox v-model="line.component_product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderForm.selectComponent')" :clearable="false" size="sm" />
                    </td>
                    <td><input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"/></td>
                    <td>
                      <SelectBox v-model="line.uom_id" :options="uoms" value-key="id" :label-fn="u => u.code" :placeholder="'—'" size="sm" />
                    </td>
                    <td class="jo-th-actions">
                      <button type="button" class="zv-btn-remove-row" :title="t('accounting.manufacturing.jobOrderForm.removeLine')" @click="removeLine(idx)">
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
              {{ t('accounting.manufacturing.jobOrderForm.addComponent') }}
            </button>
          </template>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
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
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderForm.locations') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderForm.whereRawMaterialIsDrawnFrom') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderForm.rawMaterialLocation') }}</label>
              <SelectBox v-model="form.raw_material_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.manufacturing.jobOrderForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.raw_material_location_id }" />
              <p v-if="form.errors.raw_material_location_id" class="zv-field-error">{{ form.errors.raw_material_location_id }}</p>
            </div>

            <div class="field-group">
              <div class="jo-flow-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
              </div>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderForm.outputLocationFinishedGoods') }}</label>
              <SelectBox v-model="form.output_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.manufacturing.jobOrderForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.output_location_id }" />
              <p v-if="form.errors.output_location_id" class="zv-field-error">{{ form.errors.output_location_id }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#D97706">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderForm.costEstimate') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderForm.quotedAtOpeningComparedAgainstActual') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p class="field-hint mb-3">{{ t('accounting.manufacturing.jobOrderForm.materialEstimateIsCalculatedAutomaticallyFrom') }}</p>
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderForm.estimatedLaborCostPerUnit') }}</label>
              <input v-model="form.estimated_labor_cost_per_unit" type="number" step="0.0001" min="0" class="zv-input text-end"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderForm.estimatedOverheadCostPerUnit') }}</label>
              <input v-model="form.estimated_overhead_cost_per_unit" type="number" step="0.0001" min="0" class="zv-input text-end"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderForm.plannedStartDate') }}</label>
              <input v-model="form.planned_start_date" type="date" class="zv-input"/>
            </div>
          </div>
          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.manufacturing.jobOrderForm.notes') }}</label>
            <textarea v-model="form.notes" rows="1" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.job-orders.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('accounting.manufacturing.jobOrderForm.creating') : t('accounting.manufacturing.jobOrderForm.createJobOrder') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  products:    { type: Array, default: () => [] },
  templates:   { type: Array, default: () => [] },
  locations:   { type: Array, default: () => [] },
  salesOrders: { type: Array, default: () => [] },
})

const materialMode = ref('template')

const uoms = computed(() => {
  const seen = new Map()
  props.products.forEach(p => { if (p.primary_uom_id) seen.set(p.primary_uom_id, { id: p.primary_uom_id, code: p.uom_code ?? '—' }) })
  return Array.from(seen.values())
})

const form = useForm({
  product_id: '',
  sales_order_id: '',
  quantity_to_produce: '',
  job_order_template_id: '',
  lines: [],
  raw_material_location_id: '',
  output_location_id: '',
  estimated_labor_cost_per_unit: '',
  estimated_overhead_cost_per_unit: '',
  planned_start_date: '',
  notes: '',
})

// A template made for a specific product is only offered for that product; templates
// with no product are generic and always available.
const visibleTemplates = computed(() =>
  props.templates.filter(tpl => !tpl.product_id || tpl.product_id === form.product_id)
)

watch(() => form.product_id, () => {
  if (form.job_order_template_id && !visibleTemplates.value.some(tpl => tpl.id === form.job_order_template_id)) {
    form.job_order_template_id = ''
  }
})

const selectedTemplate = computed(() => props.templates.find(tpl => tpl.id === form.job_order_template_id))

const scaledTemplateLines = computed(() => {
  const tpl = selectedTemplate.value
  if (!tpl?.lines) return []
  const qty = Number(form.quantity_to_produce) || 0
  return tpl.lines.map(l => ({ ...l, scaledQuantity: Number(l.quantity_per_unit) * qty }))
})

// Switching modes clears whichever payload the other mode would have sent —
// only one of job_order_template_id / lines is ever submitted.
watch(materialMode, (mode) => {
  if (mode === 'manual') {
    form.job_order_template_id = ''
    if (form.lines.length === 0) form.lines = [{ component_product_id: '', quantity: '', uom_id: '' }]
  } else {
    form.lines = []
  }
})

function addLine() {
  form.lines.push({ component_product_id: '', quantity: '', uom_id: '' })
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function submit() {
  if (materialMode.value === 'manual' && form.lines.length === 0) {
    form.lines = [{ component_product_id: '', quantity: '', uom_id: '' }]
  }
  form.post(route('accounting.job-orders.store'))
}
</script>

<style>
.jo-source-tiles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
@media (max-width: 700px) { .jo-source-tiles { grid-template-columns: 1fr; } }
.jo-source-tile {
  text-align: start; padding: 1.1rem; border-radius: var(--zv-radius-md, 10px);
  border: 1.5px solid var(--zv-border, #e2e8f0); background: var(--zv-bg-card, #fff);
  cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.jo-source-tile:hover { box-shadow: var(--zv-shadow-sm); }
.jo-source-tile-label { font-weight: 700; font-size: 0.9rem; margin-bottom: 0.25rem; color: var(--zv-text-primary, #0f172a); }
.jo-source-tile-desc { font-size: 0.78rem; color: var(--zv-text-muted, #94a3b8); line-height: 1.4; }
.jo-source-tile--selected { border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.12); }
.jo-source-tile--selected .jo-source-tile-label { color: #059669; }

.jo-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.jo-preview-hint { padding: 0.6rem 1rem; margin: 0; border-top: 1px solid var(--zv-border, #e2e8f0); }
.jo-th-qty { width: 14rem; }
.jo-th-actions { width: 3rem; text-align: center; }
.jo-flow-arrow { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--zv-text-muted, #94a3b8); padding-top: 1.25rem; }
</style>
