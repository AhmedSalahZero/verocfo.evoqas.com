<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/JobOrderTemplateForm.vue

  Create and Edit share one page (Rule 2). Deliberately lighter than
  BomForm — no version, no effective date, no routing hours — a
  template here is a convenience starting point, not a governed
  document (see JobOrderTemplateService header). Lines are per unit
  — scaled by JobOrderService against a job's quantity_to_produce
  when the template is used.

  @layout    AppLayout
  @route     accounting.job-order-templates.create / accounting.job-order-templates.edit
  @props     template (edit only), products
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.jobOrderTemplateForm.jobOrderTemplates'), href: route('accounting.job-order-templates.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.jobOrderTemplateForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.jobOrderTemplateForm.editTemplate') : t('accounting.manufacturing.jobOrderTemplateForm.newJobOrderTemplate')"
      :subtitle="t('accounting.manufacturing.jobOrderTemplateForm.anOptionalStartingPointJobsCan')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderTemplateForm.identity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderTemplateForm.aNameForThisRecurringJob') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderTemplateForm.templateName') }}</label>
              <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }"
                     :placeholder="t('accounting.manufacturing.jobOrderTemplateForm.eGModelXRackStandard')"/>
              <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderTemplateForm.usualProduct') }}</label>
              <SelectBox v-model="form.product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderTemplateForm.notSpecified')" />
              <p class="field-hint">{{ t('accounting.manufacturing.jobOrderTemplateForm.optionalJustForReference') }}</p>
            </div>
          </div>

          <div class="field-group mt-4" v-if="isEdit">
              <label class="zv-label">{{ t('common.status') }}</label>
              <div class="zv-toggle-row">
                <button type="button" class="zv-toggle" :class="{ 'zv-toggle-on': form.is_active }" @click="form.is_active = !form.is_active">
                  <span class="zv-toggle-knob"></span>
                </button>
                <span>{{ form.is_active ? t('common.active') : t('common.inactive') }}</span>
              </div>
            </div>

          <div class="field-group mt-4">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderTemplateForm.notes') }}</label>
              <textarea v-model="form.notes" rows="2" class="zv-input"></textarea>
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
            <div class="form-section-title">{{ t('accounting.manufacturing.jobOrderTemplateForm.materialComponents') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.jobOrderTemplateForm.perUnitScaledAutomaticallyToWhatever') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap jot-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.manufacturing.jobOrderTemplateForm.component') }}</th>
                  <th class="jotf-th-qty">{{ t('accounting.manufacturing.jobOrderTemplateForm.qtyUnit') }}</th>
                  <th>{{ t('accounting.manufacturing.jobOrderTemplateForm.uOM') }}</th>
                  <th class="jotf-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.component_product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderTemplateForm.selectComponent')" :clearable="false" size="sm" />
                  </td>
                  <td>
                    <input v-model="line.quantity_per_unit" type="number" step="0.0001" min="0.0001" class="zv-input text-end"/>
                  </td>
                  <td>
                    <SelectBox v-model="line.uom_id" :options="uoms" value-key="id" :label-fn="u => u.code" :placeholder="'—'" size="sm" />
                  </td>
                  <td class="jotf-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.manufacturing.jobOrderTemplateForm.removeLine')" @click="removeLine(idx)">
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
            {{ t('accounting.manufacturing.jobOrderTemplateForm.addComponent') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.job-order-templates.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.jobOrderTemplateForm.createTemplate')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  template: { type: Object, default: null },
  products: { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.template?.id)

const uoms = computed(() => {
  const seen = new Map()
  props.products.forEach(p => { if (p.primary_uom_id) seen.set(p.primary_uom_id, { id: p.primary_uom_id, code: p.uom_code ?? '—' }) })
  return Array.from(seen.values())
})

const form = useForm({
  name: props.template?.name ?? '',
  product_id: props.template?.product_id ?? '',
  is_active: props.template?.is_active ?? true,
  notes: props.template?.notes ?? '',
  lines: props.template?.lines?.map(l => ({
    component_product_id: l.component_product_id, quantity_per_unit: l.quantity_per_unit, uom_id: l.uom_id ?? '',
  })) ?? [{ component_product_id: '', quantity_per_unit: '', uom_id: '' }],
})

function addLine() {
  form.lines.push({ component_product_id: '', quantity_per_unit: '', uom_id: '' })
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.job-order-templates.update', props.template.id))
    : form.post(route('accounting.job-order-templates.store'))
}
</script>

<style>
.jot-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.jotf-th-qty { width: 10rem; }
.jotf-th-actions { width: 3rem; text-align: center; }
</style>
