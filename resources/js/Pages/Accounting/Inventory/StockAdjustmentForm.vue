<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockAdjustmentForm.vue

  Create-only form for a manual stock adjustment. No edit mode —
  same "correct by reversal, not by editing" rule as every other
  financial write in this app.

  Direction is an <OptionTiles variant="card"> choice (Stock In /
  Stock Out), same pattern as AgreementForm's Direct/Reverse
  Factoring tiles. Unit Cost only appears for Stock In — an 'in'
  adjustment behaves like a receipt and moves the company WAC (needs
  a cost); an 'out'
  adjustment relieves at the current WAC and needs no cost input
  (see StockLedgerService::adjust()).

  MFG-002 write-off fix (confirmed with Mahmoud, 2026-09) — for Stock
  Out, if the selected product has stock sitting in one or more Job
  Orders' own Finished Goods segments (jobOrderStockBalances prop),
  an optional "Job Order" picker appears: leave blank for an ordinary
  write-off, or pick a Job Order when it's specifically that
  customer's build being damaged/lost/scrapped before delivery.

  Audit M-18: every adjustment carries a reason category and, for a
  count difference, the stock-count sheet reference. Submitting it does
  NOT post — a second person approves it from the adjustments list.

  Notes is required, not optional — StockAdjustmentRequest enforces
  this: an adjustment overrides the system-calculated stock
  position, so the reason has to be on record.

  @layout    AppLayout
  @route     accounting.stock-adjustments.create
  @props     products, locations, jobOrderStockBalances
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.inventory.stockAdjustmentForm.stockAdjustments'), href: route('accounting.stock-adjustments.index') }, { label: t('accounting.inventory.stockAdjustmentForm.new') }]"
      :title="t('accounting.inventory.stockAdjustmentForm.newStockAdjustment')"
      :subtitle="t('accounting.inventory.stockAdjustmentForm.correctTheSystemStockPositionWhen')"
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
            <div class="form-section-title">{{ t('accounting.inventory.stockAdjustmentForm.whatHappened') }}</div>
            <div class="form-section-sub">{{ t('accounting.inventory.stockAdjustmentForm.directionOfTheCorrectionAndWhy') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <OptionTiles
            :model-value="form.direction" variant="card" class="mb-5"
            :options="[
              { value: 'in', label: t('accounting.inventory.stockAdjustmentForm.stockIn'), description: t('accounting.inventory.stockAdjustmentForm.extraStockFoundCountCameIn') },
              { value: 'out', label: t('accounting.inventory.stockAdjustmentForm.stockOut'), description: t('accounting.inventory.stockAdjustmentForm.stockMissingDamagedOrWrittenOff') },
            ]"
            @update:model-value="(v) => form.direction = v"
          />

          <div class="field-grid field-grid-8-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.product') }}</label>
              <SelectBox v-model="form.product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.inventory.stockAdjustmentForm.selectAProduct')" :clearable="false" :class="{ 'zv-input-error': form.errors.product_id }" />
              <div v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</div>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.location') }}</label>
              <SelectBox v-model="form.inventory_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.inventory.stockAdjustmentForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.inventory_location_id }" />
              <div v-if="form.errors.inventory_location_id" class="zv-field-error">{{ form.errors.inventory_location_id }}</div>
            </div>
          </div>

          <div v-if="form.direction === 'out' && eligibleJobOrders.length" class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.inventory.stockAdjustmentForm.jobOrder') }}</label>
            <SelectBox
              v-model="form.job_order_id"
              :options="eligibleJobOrders"
              value-key="job_order_id"
              :label-fn="j => `${j.job_code} — ${j.quantity_on_hand} on hand`"
              :placeholder="t('accounting.inventory.stockAdjustmentForm.ordinaryStockLeaveBlank')"
              :clearable="true"
            />
            <p class="field-hint">{{ t('accounting.inventory.stockAdjustmentForm.jobOrderHint') }}</p>
          </div>

          <div class="field-grid field-grid-3 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.quantity') }}</label>
              <input v-model="form.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                     :class="{ 'zv-input-error': form.errors.quantity }"/>
              <div v-if="form.errors.quantity" class="zv-field-error">{{ form.errors.quantity }}</div>
            </div>

            <div class="field-group" v-if="form.direction === 'in'">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.unitCost') }}</label>
              <input v-model="form.unit_cost" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                     :class="{ 'zv-input-error': form.errors.unit_cost }"/>
              <div v-if="form.errors.unit_cost" class="zv-field-error">{{ form.errors.unit_cost }}</div>
            </div>

            <div class="field-group" v-else>
              <label class="zv-label">{{ t('accounting.inventory.stockAdjustmentForm.unitCost') }}</label>
              <div class="zv-readonly-field">{{ form.job_order_id ? t('accounting.inventory.stockAdjustmentForm.usesThatJobOrdersOwnCost') : t('accounting.inventory.stockAdjustmentForm.usesCurrentWACAutomatically') }}</div>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.inventory.stockAdjustmentForm.adjustmentDate') }}</label>
              <input v-model="form.movement_date" type="date" class="zv-input"/>
            </div>
          </div>

          <div class="field-grid field-grid-2 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.reasonCategory') }}</label>
              <select v-model="form.reason_code" class="zv-input" :class="{ 'zv-input-error': form.errors.reason_code }">
                <option value="" disabled>{{ t('accounting.inventory.stockAdjustmentForm.select') }}</option>
                <option v-for="r in availableReasons" :key="r" :value="r">{{ t('accounting.inventory.stockAdjustmentForm.reasons.' + r) }}</option>
              </select>
              <div v-if="form.errors.reason_code" class="zv-field-error">{{ form.errors.reason_code }}</div>
            </div>

            <div class="field-group">
              <label class="zv-label" :class="{ 'zv-label-required': form.reason_code === 'count_difference' }">{{ t('accounting.inventory.stockAdjustmentForm.countReference') }}</label>
              <input v-model="form.count_reference" type="text" maxlength="60" class="zv-input" :class="{ 'zv-input-error': form.errors.count_reference }"/>
              <p class="field-hint">{{ t('accounting.inventory.stockAdjustmentForm.countReferenceHint') }}</p>
              <div v-if="form.errors.count_reference" class="zv-field-error">{{ form.errors.count_reference }}</div>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockAdjustmentForm.reason') }}</label>
            <textarea v-model="form.notes" rows="3" class="zv-input" :class="{ 'zv-input-error': form.errors.notes }"
                      :placeholder="t('accounting.inventory.stockAdjustmentForm.requiredEGPhysicalCount2026')"></textarea>
            <div v-if="form.errors.notes" class="zv-field-error">{{ form.errors.notes }}</div>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.stock-adjustments.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : t('accounting.inventory.stockAdjustmentForm.submitForApproval') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  products:  { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
  jobOrderStockBalances: { type: Array, default: () => [] },
  reasons:   { type: Array, default: () => [] },
})

const today = todayLocal()

const form = useForm({
  direction: 'in',
  product_id: '',
  inventory_location_id: '',
  job_order_id: '',
  quantity: '',
  unit_cost: '',
  movement_date: today,
  notes: '',
  reason_code: '',
  count_reference: '',
})

// Audit M-18 — reasons that only make sense for stock going out are
// hidden when the direction is Stock In.
const OUT_ONLY = ['damaged', 'expired', 'lost_theft', 'obsolete']
const availableReasons = computed(() => props.reasons.filter(r => form.direction === 'out' || !OUT_ONLY.includes(r)))
watch(() => form.direction, () => {
  if (!availableReasons.value.includes(form.reason_code)) form.reason_code = ''
})

// Only Job Orders holding stock of the CURRENTLY SELECTED product —
// a Job Order building a different product is never relevant here.
const eligibleJobOrders = computed(() =>
  props.jobOrderStockBalances.filter(j => j.product_id === form.product_id)
)

// Switching product or direction away from a state where the
// previously-picked Job Order no longer applies clears the selection,
// so a stale job_order_id can never be silently submitted.
watch([() => form.product_id, () => form.direction], () => {
  if (!eligibleJobOrders.value.some(j => j.job_order_id === form.job_order_id)) {
    form.job_order_id = ''
  }
})

function submit() {
  form.post(route('accounting.stock-adjustments.store'))
}
</script>
