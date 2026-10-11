<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/ProductionOrderShow.vue

  The first dedicated "Show" page in the app — everything else so
  far has been Index + Form only. A Production Order genuinely needs
  one: it's a job that lives for days or weeks, gets multiple
  postings against it (material, labor, overhead) from multiple
  visits, and needs its running WIP and variance visible at a
  glance — none of that fits an Index row or a one-shot Form.

  Sections:
    1. Header — order code, product, contextual action (Start /
       Complete), status
    2. Lifecycle stepper — Draft → In Progress → Completed → Closed,
       with Cancelled as a distinct end state when it applies
    3. KPI row — quantity, live WIP balance, standard unit cost
    4. Two columns:
       Left  — BOM reference (scaled to this order's quantity) +
               material consumption log, with a "Consume Material"
               action
       Right — labor/overhead cost log, with "Record Labor" /
               "Record Overhead" actions, and the Variance card
               (standard vs actual vs variance, color-coded —
               red = unfavorable/over standard, green = favorable)
    5. Complete action — its own confirmation modal, since posting
       Finished Goods at actual cost is a one-way action

  All four postings (consume material / record labor / record
  overhead / complete) share one Teleport-based modal, same pattern
  as LgIssuanceIndex.vue's action modals — one `modal.type` ref
  drives which fields render, one submitModal() dispatches to the
  right route.

  @layout    AppLayout
  @route     accounting.production.show
  @props     order, wipBalance, variance, overheadPools
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.productionOrderShow.productionOrders'), href: route('accounting.production.index') }, { label: order.order_code }]"
      :title="order.order_code"
    >
      <template #subtitle>
        <span class="font-semibold">{{ order.product?.name_en }}</span>
        <span class="mx-2" style="color:var(--zv-border-md)">·</span>
        <span>{{ t('accounting.manufacturing.productionOrderShow.bOMV') }}{{ order.bill_of_material?.version }}</span>
      </template>

      <template #actions>
        <button v-if="order.status === 'draft'" type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="start">
          {{ t('accounting.manufacturing.productionOrderShow.startProduction') }}
        </button>
        <template v-else-if="order.status === 'in_progress'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.manufacturing.productionOrderShow.setToDraft') }}</button>
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openModal('complete')">
            {{ t('accounting.manufacturing.productionOrderShow.completeOrder') }}
          </button>
        </template>
        <template v-else-if="order.status === 'completed'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.manufacturing.productionOrderShow.setToDraft') }}</button>
          <StatusBadge :status="order.status" :overrides="ORDER_STATUS_BADGES" />
        </template>
        <StatusBadge v-else :status="order.status" :overrides="ORDER_STATUS_BADGES" />
      </template>
    </PageHeader>

    <!-- ══════════════════════════════════════════════════════
         LIFECYCLE STEPPER
    ══════════════════════════════════════════════════════ -->
    <div class="zv-card po-stepper-card">
      <div v-if="order.status === 'cancelled'" class="po-cancelled-banner">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        {{ t('accounting.manufacturing.productionOrderShow.thisProductionOrderWasCancelled') }}
      </div>
      <div v-else class="po-stepper">
        <div v-for="(step, i) in steps" :key="step.key" class="po-step" :class="stepClass(i)">
          <div class="po-step-dot">
            <svg v-if="currentStepIndex > i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            <span v-else>{{ i + 1 }}</span>
          </div>
          <div class="po-step-label">{{ t(step.label) }}</div>
          <div v-if="i < steps.length - 1" class="po-step-line" :class="{ 'po-step-line-done': currentStepIndex > i }"></div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         KPI ROW
    ══════════════════════════════════════════════════════ -->
    <div class="po-kpi-grid">
      <KpiCard
        :label="t('accounting.manufacturing.productionOrderShow.quantityToProduce')"
        :value="fmtQty(order.quantity_to_produce)"
        tone="navy"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
      >
        <template #sub>{{ order.product?.name_en }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.manufacturing.productionOrderShow.wIPBalance')"
        :value="fmt(wipBalance)"
        tone="blue"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      >
        <template #sub>{{ t('accounting.manufacturing.productionOrderShow.materialLaborOverheadPostedSoFar') }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.manufacturing.productionOrderShow.standardUnitCost')"
        :value="fmt(standardUnitCost)"
        tone="gold"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-2m-2 5h10a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
      >
        <template #sub>{{ t('accounting.manufacturing.productionOrderShow.lockedInAtCreation') }}</template>
      </KpiCard>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TWO-COLUMN WORKING AREA
    ══════════════════════════════════════════════════════ -->
    <div class="po-columns">

      <!-- ── LEFT: BOM reference + material log ─────────────── -->
      <div class="po-col">
        <div class="zv-card mb-5">
          <div class="zv-card-header">{{ t('accounting.manufacturing.productionOrderShow.billOfMaterialsScaledToThis') }}</div>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('accounting.manufacturing.productionOrderShow.component') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderShow.requiredQty') }}</th></tr></thead>
              <tbody>
                <tr v-for="line in scaledBomLines" :key="line.id">
                  <td>{{ line.component_product?.name_en }}</td>
                  <td class="zv-table-num">{{ fmtQty(line.scaledQuantity) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-card-header flex items-center justify-between">
            <span>{{ t('accounting.manufacturing.productionOrderShow.materialConsumption') }}</span>
            <button v-if="order.status === 'in_progress'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('consume')">
              {{ t('accounting.manufacturing.productionOrderShow.consumeMaterial') }}
            </button>
          </div>

          <EmptyState v-if="!materialMovements.length" :title="t('accounting.manufacturing.productionOrderShow.noMaterialConsumedYet')" icon-path="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('accounting.manufacturing.productionOrderShow.date') }}</th><th>{{ t('accounting.manufacturing.productionOrderShow.component') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderShow.qty') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderShow.wACValue') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in materialMovements" :key="m.id">
                  <td>{{ fmtDate(m.movement_date) }}</td>
                  <td>{{ m.product?.name_en }}</td>
                  <td class="zv-table-num">{{ fmtQty(m.quantity) }}</td>
                  <td class="zv-table-num">{{ fmt(m.wac_total_value) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── RIGHT: cost log + variance ───────────────────────── -->
      <div class="po-col">
        <div class="zv-card mb-5">
          <div class="zv-card-header flex items-center justify-between">
            <span>{{ t('accounting.manufacturing.productionOrderShow.laborOverhead') }}</span>
            <div v-if="order.status === 'in_progress'" class="flex gap-2">
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('labor')">{{ t('accounting.manufacturing.productionOrderShow.recordLabor') }}</button>
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('overhead')">{{ t('accounting.manufacturing.productionOrderShow.recordOverhead') }}</button>
            </div>
          </div>

          <EmptyState v-if="!order.cost_entries?.length" :title="t('accounting.manufacturing.productionOrderShow.noLaborOrOverheadRecordedYet')" icon-path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('common.type') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderShow.baseQty') }}</th><th class="zv-table-num">{{ t('common.rate') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.productionOrderShow.actual') }}</th><th>{{ t('accounting.manufacturing.productionOrderShow.costCenter') }}</th></tr></thead>
              <tbody>
                <tr v-for="e in order.cost_entries" :key="e.id">
                  <td>
                    <span class="zv-tag" :class="e.entry_type === 'labor' ? '' : 'zv-tag-gray'">
                      {{ e.entry_type === 'labor' ? t('accounting.manufacturing.productionOrderShow.labor') : (e.overhead_pool?.name ?? t('accounting.manufacturing.productionOrderShow.overhead')) }}
                    </span>
                  </td>
                  <td class="zv-table-num">{{ fmtQty(e.base_quantity) }}</td>
                  <td class="zv-table-num">{{ fmt(e.rate_applied) }}</td>
                  <td class="zv-table-num">{{ fmt(e.actual_amount) }}</td>
                  <td>{{ e.cost_center?.name_en ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="variance" class="zv-card">
          <div class="zv-card-header">{{ t('accounting.manufacturing.productionOrderShow.varianceStandardVsActual') }}</div>
          <div class="po-variance-body">
            <div v-for="cat in varianceCategories" :key="cat.key" class="po-variance-row">
              <div class="po-variance-label">{{ t(cat.label) }}</div>
              <div class="po-variance-figures">
                <span class="po-variance-figure"><span class="po-variance-tag">{{ t('accounting.manufacturing.productionOrderShow.std') }}</span>{{ fmt(variance[cat.key].standard) }}</span>
                <span class="po-variance-figure"><span class="po-variance-tag">{{ t('accounting.manufacturing.productionOrderShow.actual') }}</span>{{ fmt(variance[cat.key].actual) }}</span>
                <span class="po-variance-figure po-variance-delta" :class="varianceClass(variance[cat.key].variance)">
                  {{ Number(variance[cat.key].variance) > 0 ? '+' : '' }}{{ fmt(variance[cat.key].variance) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ Action Modals ══════════════════════════ -->
    <Teleport to="body">
      <div v-if="modal.type" class="po-modal-overlay zv-acc" @click.self="closeModal">
        <div class="po-modal">

          <template v-if="modal.type === 'consume'">
            <h3 class="po-modal-title">{{ t('accounting.manufacturing.productionOrderShow.consumeMaterial') }}</h3>
            <p class="po-modal-hint">{{ t('accounting.manufacturing.productionOrderShow.issuedFrom') }} {{ order.raw_material_location?.name_en }}, {{ t('accounting.manufacturing.productionOrderShow.relievedAtCurrentWAC') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderShow.component') }}</label>
              <SelectBox v-model="modalForm.component_product_id" :options="order.bill_of_material?.lines" value-key="component_product_id" :label-fn="line => line.component_product?.name_en" :placeholder="t('accounting.manufacturing.productionOrderShow.select')" :clearable="false" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderShow.quantity') }}</label>
              <input v-model="modalForm.quantity" type="number" step="0.0001" min="0.0001" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'labor'">
            <h3 class="po-modal-title">{{ t('accounting.manufacturing.productionOrderShow.recordLaborCost') }}</h3>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderShow.hours') }}</label>
              <input v-model="modalForm.hours" type="number" step="0.0001" min="0.0001" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.manufacturing.productionOrderShow.costCalculatedFromRate') }}</p>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.costCenter') }}</label>
              <SelectBox v-model="modalForm.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.manufacturing.productionOrderShow.none')" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'overhead'">
            <h3 class="po-modal-title">{{ t('accounting.manufacturing.productionOrderShow.recordOverheadCost') }}</h3>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderShow.overheadPool') }}</label>
              <SelectBox v-model="modalForm.overhead_pool_id" :options="overheadPools" value-key="id" :label-fn="p => p.name" :placeholder="t('accounting.manufacturing.productionOrderShow.select')" :clearable="false" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.productionOrderShow.baseQuantity') }}</label>
              <input v-model="modalForm.base_quantity" type="number" step="0.0001" min="0.0001" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.manufacturing.productionOrderShow.costCalculatedFromRate') }}</p>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.costCenter') }}</label>
              <SelectBox v-model="modalForm.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.manufacturing.productionOrderShow.none')" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.productionOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'complete'">
            <h3 class="po-modal-title">{{ t('accounting.manufacturing.productionOrderShow.completeProductionOrder') }}</h3>
            <p class="po-modal-hint">
              {{ t('accounting.manufacturing.productionOrderShow.finishedGoodsWillBeReceivedAt') }}
              ({{ fmt(wipBalance) }} {{ t('accounting.manufacturing.productionOrderShow.total') }},
              {{ fmt(bcActualUnitCost) }} {{ t('accounting.manufacturing.productionOrderShow.perUnit') }}) — {{ t('accounting.manufacturing.productionOrderShow.canBeReopenedLaterViaSetToDraft') }}
            </p>
          </template>

          <div v-if="modalErrors.length" class="zv-field-error mb-3" role="alert">
            <p v-for="(msg, i) in modalErrors" :key="i">{{ msg }}</p>
          </div>

          <div class="po-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModal">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="modalSubmitting" @click="submitModal">
              {{ modalSubmitting ? t('common.saving') : t('common.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, div as divMoney } from '@/Utils/money'
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  order:         { type: Object, required: true },
  wipBalance:    { type: [String, Number], required: true },
  variance:      { type: Object, default: null },
  overheadPools: { type: Array,  default: () => [] },
  costCenters:   { type: Array,  default: () => [] },
})

// Same override map as ProductionOrderIndex.vue — StatusBadge's default
// map doesn't cover in_progress/completed.
const ORDER_STATUS_BADGES = {
  in_progress: { label: 'In Progress', badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue'  },
  completed:   { label: 'Completed',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

// ── Lifecycle stepper ────────────────────────────────────────────

const steps = [
  { key: 'draft',       label: 'Draft' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'completed',   label: 'Completed' },
  { key: 'closed',      label: 'Closed' },
]

const currentStepIndex = computed(() => steps.findIndex(s => s.key === props.order.status))

function stepClass(i) {
  if (i < currentStepIndex.value) return 'po-step-done'
  if (i === currentStepIndex.value) return 'po-step-current'
  return 'po-step-pending'
}

// ── BOM scaled to this order's quantity ──────────────────────────

const scaledBomLines = computed(() => {
  const bom = props.order.bill_of_material
  if (!bom?.lines) return []
  const factor = Number(props.order.quantity_to_produce) / Number(bom.output_quantity || 1)
  return bom.lines.map(l => ({ ...l, scaledQuantity: Number(l.quantity_per_batch) * factor }))
})

const materialMovements = computed(() =>
  (props.order.stock_movements ?? []).filter(m => m.movement_type === 'production_consumption')
)

const standardUnitCost = computed(() =>
  Number(props.order.standard_material_cost_per_unit || 0)
  + Number(props.order.standard_labor_cost_per_unit || 0)
  + Number(props.order.standard_overhead_cost_per_unit || 0)
)

const bcActualUnitCost = computed(() => {
  const qty = Number(props.order.quantity_to_produce || 0)
  return qty > 0 ? divMoney(props.wipBalance, qty, 4) : 0
})

const varianceCategories = [
  { key: 'material', label: 'Material' },
  { key: 'labor',    label: 'Labor' },
  { key: 'overhead', label: 'Overhead' },
]

function varianceClass(v) {
  const n = Number(v)
  if (n > 0) return 'po-variance-unfavorable'
  if (n < 0) return 'po-variance-favorable'
  return ''
}

// ── Simple actions ────────────────────────────────────────────────

function start() {
  router.patch(route('accounting.production.start', props.order.id), {}, { preserveScroll: true })
}

// 2026-09 — full reversal (Finished Goods receipt, every cost entry,
// every material consumption). Same confirm()-then-patch pattern as
// OtherPaymentService's Set to Draft button.
function confirmSetToDraft() {
  if (! confirm(t('accounting.manufacturing.productionOrderShow.reopenThisOrderForEditingThisWillReverse'))) return

  router.patch(route('accounting.production.set-to-draft', props.order.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

// ── Modal ─────────────────────────────────────────────────────────

const modal = ref({ type: null })
const modalForm = ref({})
const modalSubmitting = ref(false)
const modalErrors = ref([])

// Shared result handling for every action window: close it only when the server
// accepted the entry; otherwise keep it open, keep what was typed, and show why.
function modalOptions() {
  return {
    preserveScroll: true,
    onStart: () => { modalErrors.value = [] },
    onSuccess: (page) => {
      const refusal = page.props.flash?.error
      if (refusal) { modalErrors.value = [refusal]; return }
      closeModal()
    },
    onError: (errors) => { modalErrors.value = Object.values(errors) },
    onFinish: () => { modalSubmitting.value = false },
  }
}
const today = todayLocal()

function openModal(type) {
  modal.value = { type }
  modalForm.value = {
    component_product_id: '', quantity: '', notes: '',
    hours: '', entry_date: today,
    overhead_pool_id: '', base_quantity: '', cost_center_id: '',
  }
}

function closeModal() {
  modalErrors.value = []
  modal.value = { type: null }
}

function submitModal() {
  modalSubmitting.value = true

  if (modal.value.type === 'complete') {
    router.patch(route('accounting.production.complete', props.order.id), {}, {
      ...modalOptions(),
    })
    return
  }

  const routeName = {
    consume:  'accounting.production.consume-material',
    labor:    'accounting.production.record-labor',
    overhead: 'accounting.production.record-overhead',
  }[modal.value.type]

  router.post(route(routeName, props.order.id), modalForm.value, {
    ...modalOptions(),
  })
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
/* ── Stepper ─────────────────────────────────────────────────── */
.po-stepper-card { padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
.po-stepper { display: flex; align-items: flex-start; }
.po-step { flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; }
.po-step-dot {
  width: 2rem; height: 2rem; border-radius: 999px; display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; border: 2px solid var(--zv-border, #e2e8f0); background: var(--zv-bg-card, #fff);
  color: var(--zv-text-muted, #94a3b8); z-index: 1; margin-bottom: 0.5rem;
}
.po-step-label { font-size: 0.8rem; color: var(--zv-text-muted, #94a3b8); font-weight: 600; }
.po-step-line { position: absolute; top: 1rem; left: 50%; width: 100%; height: 2px; background: var(--zv-border, #e2e8f0); z-index: 0; }
.po-step-line-done { background: #059669; }
.po-step-current .po-step-dot { border-color: #2563EB; color: #2563EB; box-shadow: 0 0 0 4px rgba(37,99,235,0.12); }
.po-step-current .po-step-label { color: #2563EB; }
.po-step-done .po-step-dot { border-color: #059669; background: #059669; color: #fff; }
.po-step-done .po-step-label { color: var(--zv-text-primary, #0f172a); }
.po-cancelled-banner { display: flex; align-items: center; gap: 0.5rem; color: #DC2626; font-weight: 600; font-size: 0.9rem; }

/* ── KPIs ────────────────────────────────────────────────────── */
.po-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .po-kpi-grid { grid-template-columns: 1fr; } }

/* ── Two-column layout ──────────────────────────────────────── */
.po-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 1000px) { .po-columns { grid-template-columns: 1fr; } }
.po-col { display: flex; flex-direction: column; }

/* ── Variance card ──────────────────────────────────────────── */
.po-variance-body { padding: 0.25rem 1.25rem 1.25rem; }
.po-variance-row { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid var(--zv-border, #e2e8f0); }
.po-variance-row:last-child { border-bottom: none; }
.po-variance-label { font-weight: 600; font-size: 0.85rem; color: var(--zv-text-secondary, #475569); }
.po-variance-figures { display: flex; gap: 1rem; align-items: center; }
.po-variance-figure { font-size: 0.82rem; font-variant-numeric: tabular-nums; }
.po-variance-tag { font-size: 0.68rem; color: var(--zv-text-muted, #94a3b8); text-transform: uppercase; letter-spacing: 0.03em; margin-inline-end: 0.35rem; }
.po-variance-delta { font-weight: 700; min-width: 5rem; text-align: end; }
.po-variance-unfavorable { color: #DC2626; }
.po-variance-favorable { color: #059669; }

/* ── Action modals — same pattern as LgIssuanceIndex.vue ──────── */
.po-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; display: flex; align-items: center; justify-content: center; }
.po-modal { width: 440px; max-width: 92vw; background: var(--zv-bg-card, #fff); border-radius: var(--zv-radius-lg, 14px); border: 1px solid var(--zv-border, #e2e8f0); box-shadow: var(--zv-shadow-md); padding: 20px; }
.po-modal-title { font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--zv-text-primary, #0f172a); }
.po-modal-hint { font-size: 12.5px; color: var(--zv-text-muted, #94a3b8); margin-bottom: 14px; line-height: 1.5; }
.po-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
</style>
