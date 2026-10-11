<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/BomForm.vue

  Create and Edit share one page (Rule 2) — mode by props.bom?.id.

  BOM Draft/Active/Superseded fix (VeroCFO_Manufacturing_
  Flexibility_Design_v1_0.md, Section 1): a Draft version is fully
  editable; once Active or Superseded, every field is locked
  (isLocked below) and the Save button is replaced with "Create New
  Version" — BillOfMaterialService::update() rejects a save on a
  non-Draft version server-side too, this is just the same rule
  shown before the user ever tries. product_id is always shown
  read-only in edit mode — it never changes across versions.

  Three sections, each carrying its own accent color:
    1. Product & Batch (blue) — what this BOM produces and its batch size
    2. Routing (amber) — ordered production steps (Process + default
       Machine + Labor Category + hours per batch), VeroCFO_
       Manufacturing_Costing_Redesign_v1_0.md Section 5. Feeds standard
       labor cost (via each step's Labor Category rate) and standard
       overhead cost (via each step's Machine's own linked Overhead
       Pool(s), Section 4.4) — replaces the old two flat hour fields
       entirely (dropped, migration 000264).
    3. Material Components (green) — the actual bill, a proper
       repeater table rather than a bare list

  @layout    AppLayout
  @route     accounting.bom.create / accounting.bom.edit
  @props     bom (edit only), manufacturedProducts, rawMaterials, uoms,
             processes, machines, laborCategories
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.bomForm.billOfMaterials'), href: route('accounting.bom.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.bomForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.bomForm.editBillOfMaterials') : t('accounting.manufacturing.bomForm.newBillOfMaterials')"
    >
      <template #subtitle>
        <template v-if="isEdit">
          <span class="bom-version-chip">{{ t('accounting.manufacturing.bomForm.version') }} {{ bom.version }}</span>
          <StatusBadge
            :status="bom.status"
            :overrides="{ superseded: { label: t('common.superseded'), badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' } }"
            class="ms-2"
          />
        </template>
        <template v-else>{{ t('accounting.manufacturing.bomForm.defineWhatGoesIntoProducingOne') }}</template>
      </template>
    </PageHeader>

    <p v-if="isLocked" class="zv-banner zv-banner-info mb-4">
      {{ t('accounting.manufacturing.bomForm.thisVersionIsLockedBanner', { status: t('common.' + bom.status) }) }}
    </p>

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#2563EB">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.bomForm.productBatch') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.bomForm.whatThisBOMProducesAndThe') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-2-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.bomForm.product') }}</label>
              <div v-if="isEdit" class="zv-readonly-field">{{ bom.product?.code }} — {{ bom.product?.name_en }}</div>
              <SelectBox v-model="form.product_id" :options="manufacturedProducts" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.bomForm.selectAManufacturedProduct')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.product_id }" />
              <p v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.bomForm.outputQuantity') }}</label>
              <input v-model="form.output_quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end" :disabled="isLocked"
                     :class="{ 'zv-input-error': form.errors.output_quantity }"/>
              <p v-if="form.errors.output_quantity" class="zv-field-error">{{ form.errors.output_quantity }}</p>
              <p class="field-hint">{{ t('accounting.manufacturing.bomForm.theBatchSizeEveryLineBelow') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.bomForm.effectiveDate') }}</label>
              <input v-model="form.effective_date" type="date" class="zv-input" :disabled="isLocked" :class="{ 'zv-input-error': form.errors.effective_date }"/>
              <p v-if="form.errors.effective_date" class="zv-field-error">{{ form.errors.effective_date }}</p>
              <p class="field-hint">{{ t('accounting.manufacturing.bomForm.effectiveDateHint') }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.manufacturing.bomForm.notes') }}</label>
            <textarea v-model="form.notes" rows="2" class="zv-input" :disabled="isLocked"></textarea>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#D97706">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.bomForm.routing') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.bomForm.orderedProductionStepsEachFeeding') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p class="text-sm opacity-70 mb-3">{{ t('accounting.manufacturing.bomForm.optionalLeaveEmptyIfThisProduct') }}</p>

          <div v-if="processes.length === 0 || machines.length === 0 || laborCategories.length === 0" class="text-sm opacity-70 mb-3">
            {{ t('accounting.manufacturing.bomForm.setUpProcessesMachinesAndLabor') }}
          </div>

          <div v-if="form.operations.length" class="zv-table-wrap bom-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th class="bom-th-seq">#</th>
                  <th>{{ t('accounting.manufacturing.bomForm.process') }}</th>
                  <th>{{ t('accounting.manufacturing.bomForm.defaultMachine') }}</th>
                  <th>{{ t('accounting.manufacturing.bomForm.laborCategory') }}</th>
                  <th class="bom-th-hours">{{ t('accounting.manufacturing.bomForm.laborHrsBatch') }}</th>
                  <th class="bom-th-hours">{{ t('accounting.manufacturing.bomForm.machineHrsBatch') }}</th>
                  <th class="bom-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(op, idx) in form.operations" :key="idx">
                  <td class="text-center opacity-60">{{ idx + 1 }}</td>
                  <td>
                    <SelectBox v-model="op.process_id" :options="processes" value-key="id" label-key="name"
                               :placeholder="t('accounting.manufacturing.bomForm.selectProcess')" :clearable="false" size="sm" :disabled="isLocked"
                               @change="op.default_machine_id = ''" />
                  </td>
                  <td>
                    <SelectBox v-model="op.default_machine_id" :options="machinesForProcess(op.process_id)" value-key="id" :label-fn="m => `${m.code} — ${m.name}`"
                               :placeholder="t('accounting.manufacturing.bomForm.selectMachine')" :clearable="false" size="sm" :disabled="isLocked || !op.process_id" />
                  </td>
                  <td>
                    <SelectBox v-model="op.labor_category_id" :options="laborCategories" value-key="id" label-key="name"
                               :placeholder="t('accounting.manufacturing.bomForm.selectCategory')" :clearable="false" size="sm" :disabled="isLocked" />
                  </td>
                  <td>
                    <input v-model="op.standard_labor_hours" type="number" step="0.0001" min="0" class="zv-input text-end" :disabled="isLocked"/>
                  </td>
                  <td>
                    <input v-model="op.standard_machine_hours" type="number" step="0.0001" min="0" class="zv-input text-end" :disabled="isLocked"/>
                  </td>
                  <td class="bom-th-actions">
                    <button v-if="!isLocked" type="button" class="zv-btn-remove-row" :title="t('accounting.manufacturing.bomForm.removeOperation')" @click="removeOperation(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!isLocked" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addOperation">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('accounting.manufacturing.bomForm.addOperation') }}
          </button>
          <p v-if="form.errors.operations" class="zv-field-error mt-2">{{ form.errors.operations }}</p>
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
            <div class="form-section-title">{{ t('accounting.manufacturing.bomForm.materialComponents') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.bomForm.rawMaterialsConsumedPerBatchAt') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap bom-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.manufacturing.bomForm.component') }}</th>
                  <th class="bom-th-qty">{{ t('accounting.manufacturing.bomForm.qtyBatch') }}</th>
                  <th>{{ t('accounting.manufacturing.bomForm.uOM') }}</th>
                  <th class="bom-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.component_product_id" :options="rawMaterials" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.manufacturing.bomForm.selectComponent')" :clearable="false" size="sm" :disabled="isLocked" />
                  </td>
                  <td>
                    <input v-model="line.quantity_per_batch" type="number" step="0.0001" min="0.0001" class="zv-input text-end" :disabled="isLocked"/>
                  </td>
                  <td>
                    <SelectBox v-model="line.uom_id" :options="uoms" value-key="id" :label-fn="u => u.code" :placeholder="'—'" size="sm" :disabled="isLocked" />
                  </td>
                  <td class="bom-th-actions">
                    <button v-if="!isLocked" type="button" class="zv-btn-remove-row" :title="t('accounting.manufacturing.bomForm.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!isLocked" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('accounting.manufacturing.bomForm.addComponent') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.bom.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <Link v-if="isLocked" :href="route('accounting.bom.create')" class="zv-btn zv-btn-primary">
            {{ t('accounting.manufacturing.bomForm.createNewVersion') }}
          </Link>
          <button v-else type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.bomForm.createBillOfMaterials')) }}
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
import StatusBadge from '@/Components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  bom:                  { type: Object, default: null },
  manufacturedProducts: { type: Array,  default: () => [] },
  rawMaterials:         { type: Array,  default: () => [] },
  uoms:                 { type: Array,  default: () => [] },
  processes:            { type: Array,  default: () => [] },
  machines:             { type: Array,  default: () => [] },
  laborCategories:      { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.bom?.id)

// Only a Draft version can be saved — Active/Superseded are locked
// read-only here, mirroring BillOfMaterialService::assertEditable().
const isLocked = computed(() => isEdit.value && props.bom?.status !== 'draft')

const form = useForm({
  product_id: props.bom?.product_id ?? '',
  output_quantity: props.bom?.output_quantity ?? 1,
  effective_date: props.bom?.effective_date?.toString().slice(0, 10) ?? todayLocal(),
  notes: props.bom?.notes ?? '',
  lines: props.bom?.lines?.map(l => ({
    component_product_id: l.component_product_id, quantity_per_batch: l.quantity_per_batch, uom_id: l.uom_id ?? '',
  })) ?? [{ component_product_id: '', quantity_per_batch: '', uom_id: '' }],
  operations: props.bom?.operations?.map(o => ({
    process_id: o.process_id, default_machine_id: o.default_machine_id, labor_category_id: o.labor_category_id,
    standard_labor_hours: o.standard_labor_hours, standard_machine_hours: o.standard_machine_hours,
  })) ?? [],
})

function addLine() {
  form.lines.push({ component_product_id: '', quantity_per_batch: '', uom_id: '' })
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function addOperation() {
  form.operations.push({ process_id: '', default_machine_id: '', labor_category_id: '', standard_labor_hours: '', standard_machine_hours: '' })
}

function removeOperation(idx) {
  form.operations.splice(idx, 1)
}

// A Machine's default_machine_id picker is scoped to whichever
// Process that same operation row already has selected — a Routing
// operation's machine must actually perform that process (Section
// 4.1: a Machine is tagged to exactly one Process). Falls back to
// every machine when no Process is chosen yet for that row.
function machinesForProcess(processId) {
  if (!processId) return props.machines
  return props.machines.filter(m => m.process_id === processId)
}

function submit() {
  if (isLocked.value) return
  isEdit.value
    ? form.put(route('accounting.bom.update', props.bom.id))
    : form.post(route('accounting.bom.store'))
}
</script>

<style>
.bom-version-chip { display: inline-flex; align-items: center; font-size: 0.8rem; font-weight: 700; color: var(--zv-text-secondary, #475569); background: var(--zv-subtle-bg, #f1f5f9); border-radius: 999px; padding: 0.15rem 0.7rem; }
.bom-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.bom-th-qty { width: 10rem; }
.bom-th-seq { width: 2.5rem; }
.bom-th-hours { width: 8rem; }
.bom-th-actions { width: 3rem; text-align: center; }
.zv-banner { padding: 0.65rem 1rem; border-radius: var(--zv-radius, 8px); font-size: 0.875rem; }
.zv-banner-info { background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; }
</style>
