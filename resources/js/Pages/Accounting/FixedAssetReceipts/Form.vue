<!--
══════════════════════════════════════════════════════════════════
  Accounting/FixedAssetReceipts/Form.vue

  Create + Edit + View — mirrors GoodsReceiptNotes/Form.vue's own
  lifecycle exactly (draft → posted-locked-view → Set to Draft to
  reopen), since this creates the exact same GoodsReceiptNote record
  under the hood. Simplified for Fixed Assets specifically:
    - No Project / Sales Order column — irrelevant to owned equipment.
    - No UOM picker — every Fixed Asset Type already has a fixed
      UOM ("UNIT", auto-resolved when the type was created); this form
      just carries it along silently rather than asking again.
    - Quantity must be a whole number — each unit becomes one
      individually depreciated asset (FixedAssetService::
      receiveFromGrnLine()), so "2.5" has no meaning here.
    - Product picker only ever lists Fixed Asset Types (the server
      independently rejects anything else regardless — defense in
      depth, not this form's decision to make).

  @layout    AppLayout
  @route     accounting.fixed-asset-receipts.create / .edit
  @props     goodsReceiptNote, grnPolicy, suppliers, purchaseOrders,
             inventoryLocations, fixedAssetTypes
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.fixedAssetReceipts.form.fixedAssetReceipts'), href: route('accounting.fixed-asset-receipts.index') }, { label: pageLabel }]"
      :title="pageTitle"
      :subtitle="isPosted ? t('accounting.fixedAssetReceipts.form.postedAssetsRegisteredAndJournal') : t('accounting.fixedAssetReceipts.form.registersEachUnitAsItsOwn')"
    />

    <form @submit.prevent="submit">
      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#4338CA">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.fixedAssetReceipts.form.receiptInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.fixedAssetReceipts.form.whereThisEquipmentCameFromAnd') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-4-6-2">
            <div class="field-group">
              <label class="zv-label" :class="{ 'zv-label-required': poRequired }">{{ t('accounting.fixedAssetReceipts.form.purchaseOrder') }}</label>
              <SelectBox v-model="form.purchase_order_id" :options="purchaseOrders" value-key="id" :label-fn="po => po.po_code" :placeholder="poRequired ? t('accounting.fixedAssetReceipts.form.selectAPurchaseOrder') : t('accounting.fixedAssetReceipts.form.adHocNoPurchaseOrder')" @change="onPoChange" :class="{ 'zv-input-error': form.errors.purchase_order_id }" />
              <p v-if="form.errors.purchase_order_id" class="zv-field-error">{{ form.errors.purchase_order_id }}</p>
              <p v-if="!poRequired" class="field-hint">{{ t('accounting.fixedAssetReceipts.form.thisCompanyAllowsAdHocReceipts') }}</p>
              <p v-if="poRequired && purchaseOrders.length === 0" class="field-hint">{{ t('accounting.fixedAssetReceipts.form.noOpenPurchaseOrdersWithFixedAsset') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetReceipts.form.supplier') }}</label>
              <SelectBox v-model="form.partner_id" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.fixedAssetReceipts.form.selectSupplier')" :clearable="false" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetReceipts.form.location') }}</label>
              <SelectBox v-model="form.inventory_location_id" :options="inventoryLocations" value-key="id" :label-fn="l => `${l.code} — ${l.name_en}`" :placeholder="t('accounting.fixedAssetReceipts.form.selectLocation')" :clearable="false" :class="{ 'zv-input-error': form.errors.inventory_location_id }" />
              <p v-if="form.errors.inventory_location_id" class="zv-field-error">{{ form.errors.inventory_location_id }}</p>
              <p class="field-hint">{{ t('accounting.fixedAssetReceipts.form.eachAssetStartsAtThisLocation') }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-4-8 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetReceipts.form.receivedDate') }}</label>
              <input v-model="form.received_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.received_date }"/>
              <p v-if="form.errors.received_date" class="zv-field-error">{{ form.errors.received_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.fixedAssetReceipts.form.supplierReference') }}</label>
              <input v-model="form.supplier_reference" type="text" class="zv-input" :placeholder="t('accounting.fixedAssetReceipts.form.supplierDeliveryNoteInvoice')"/>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.fixedAssetReceipts.form.lines') }}</div>
            <div class="form-section-sub">{{ t('accounting.fixedAssetReceipts.form.eachUnitBecomesItsOwn') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap far-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.fixedAssetReceipts.form.pOLine') }}</th>
                  <th>{{ t('accounting.fixedAssetReceipts.form.fixedAssetType') }}</th>
                  <th class="far-th-qty">{{ t('accounting.fixedAssetReceipts.form.quantity') }}</th>
                  <th class="far-th-qty">{{ t('accounting.fixedAssetReceipts.form.unitCost') }}</th>
                  <th class="far-th-qty">{{ t('accounting.fixedAssetReceipts.form.lineTotal') }}</th>
                  <th class="far-th-actions" v-if="!isReadOnly"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.purchase_order_line_id" :options="availablePoLinesFor(line)" value-key="id" :label-fn="pl => `${pl.product_name} — ${t('accounting.fixedAssetReceipts.form.max')} ${pl.max_receivable_quantity}`" :placeholder="t('accounting.fixedAssetReceipts.form.adHocNotOnPO')" v-if="form.purchase_order_id" size="sm" @change="onPoLineChange(line)" />
                    <span v-else class="zv-tag zv-tag-gray">{{ t('accounting.fixedAssetReceipts.form.adHoc') }}</span>
                  </td>
                  <td>
                    <SelectBox v-model="line.product_id" :options="fixedAssetTypes" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.fixedAssetReceipts.form.selectFixedAssetType')" :clearable="false" :disabled="!!line.purchase_order_line_id" size="sm" @change="onProductChange(line)" />
                  </td>
                  <td>
                    <input v-model="line.quantity" type="number" step="1" min="1" class="zv-input text-end"/>
                  </td>
                  <td>
                    <input v-model="line.unit_cost" type="number" step="0.01" min="0" class="zv-input text-end"/>
                  </td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="far-th-actions" v-if="!isReadOnly">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.fixedAssetReceipts.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!isReadOnly" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('accounting.fixedAssetReceipts.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>

          <div class="far-total-row">
            <span>{{ t('accounting.fixedAssetReceipts.form.receiptTotal') }}</span>
            <strong>{{ fmt(receiptTotal) }}</strong>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.fixedAssetReceipts.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>
      </fieldset>

      <!-- Posted: show the resulting journal entry instead of a Save action -->
      <div v-if="isPosted && goodsReceiptNote.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.fixedAssetReceipts.form.journalEntry') }} — {{ goodsReceiptNote.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.fixedAssetReceipts.form.account') }}</th><th class="far-th-qty">{{ t('accounting.fixedAssetReceipts.form.debit') }}</th><th class="far-th-qty">{{ t('accounting.fixedAssetReceipts.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in goodsReceiptNote.journal_entry.lines" :key="line.id">
                <td><span class="zv-code">{{ line.chart_of_account?.code }}</span><span class="zv-account-name">{{ line.chart_of_account?.name_en }}</span></td>
                <td class="zv-table-num">{{ fmt(line.debit) }}</td>
                <td class="zv-table-num">{{ fmt(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Posted: link straight to the Fixed Asset Register, filtered to this receipt's product -->
      <div v-if="isPosted" class="zv-alert zv-alert-info mb-5">
        {{ t('accounting.fixedAssetReceipts.form.everyUnitOnThisReceiptIsNow') }}
        <Link :href="route('accounting.fixed-assets.index')" class="zv-text-blue font-medium">{{ t('accounting.fixedAssetReceipts.form.viewInTheFixedAssetRegister') }}</Link>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.fixed-asset-receipts.index')" class="zv-btn zv-btn-ghost">{{ isReadOnly ? t('accounting.fixedAssetReceipts.form.close') : t('common.cancel') }}</Link>

          <button v-if="isPosted" type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.fixedAssetReceipts.form.setToDraft') }}</button>

          <button v-if="isEdit && !isReadOnly" type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete">
            {{ deleting ? t('common.deleting') : t('common.delete') }}
          </button>

          <button v-if="!isReadOnly" type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.fixedAssetReceipts.form.saveAsDraft')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  goodsReceiptNote:   { type: Object, default: null },
  grnPolicy:          { type: String, default: 'po_required' },
  suppliers:          { type: Array,  default: () => [] },
  purchaseOrders:     { type: Array,  default: () => [] },
  inventoryLocations: { type: Array,  default: () => [] },
  fixedAssetTypes:    { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.goodsReceiptNote?.id)
const isPosted = computed(() => props.goodsReceiptNote?.status === 'posted')
const isReadOnly = computed(() => isEdit.value && props.goodsReceiptNote.status !== 'draft')

const pageLabel = computed(() => isReadOnly.value ? t('common.view') : (isEdit.value ? t('common.edit') : t('accounting.fixedAssetReceipts.form.new')))
const pageTitle = computed(() => isReadOnly.value
  ? t('accounting.fixedAssetReceipts.form.fixedAssetReceipt') + ' ' + props.goodsReceiptNote.grn_code
  : (isEdit.value ? t('accounting.fixedAssetReceipts.form.editFixedAssetReceipt') : t('accounting.fixedAssetReceipts.form.newFixedAssetReceipt')))

const poRequired = computed(() => props.grnPolicy !== 'allow_ad_hoc')

function blankLine() {
  return { purchase_order_line_id: '', product_id: '', quantity: '', uom_id: '', unit_cost: '' }
}

const form = useForm({
  purchase_order_id:     props.goodsReceiptNote?.purchase_order_id ?? '',
  partner_id:            props.goodsReceiptNote?.partner_id ?? '',
  inventory_location_id: props.goodsReceiptNote?.inventory_location_id ?? '',
  received_date:         props.goodsReceiptNote?.received_date?.toString().slice(0, 10) ?? todayLocal(),
  supplier_reference:    props.goodsReceiptNote?.supplier_reference ?? '',
  internal_notes:        props.goodsReceiptNote?.internal_notes ?? '',
  lines: props.goodsReceiptNote?.lines?.map(l => ({
    purchase_order_line_id: l.purchase_order_line_id ?? '',
    product_id: l.product_id, quantity: l.quantity, uom_id: l.uom_id ?? '', unit_cost: l.unit_cost,
  })) ?? [blankLine()],
})

function onPoChange() {
  const po = props.purchaseOrders.find(p => p.id === Number(form.purchase_order_id))
  if (po) form.partner_id = po.partner_id
  fetchEligiblePoLines()
}

const eligiblePoLines = ref([])

async function fetchEligiblePoLines() {
  eligiblePoLines.value = []
  if (! form.purchase_order_id) return

  const response = await fetch(
    route('accounting.fixed-asset-receipts.eligible-po-lines', { purchase_order_id: form.purchase_order_id }),
    { headers: { Accept: 'application/json' } }
  )
  const data = await response.json()
  eligiblePoLines.value = data.lines ?? []
}

if (form.purchase_order_id) fetchEligiblePoLines()

// Same double-pick guard as the general Goods Receipt Note form —
// a PO line already selected on one row disappears from every
// other row's dropdown (but stays visible in its own).
function availablePoLinesFor(currentLine) {
  const pickedElsewhere = new Set(
    form.lines
      .filter(l => l !== currentLine && l.purchase_order_line_id)
      .map(l => Number(l.purchase_order_line_id))
  )
  return eligiblePoLines.value.filter(pl => ! pickedElsewhere.has(pl.id))
}

function onPoLineChange(line) {
  if (! line.purchase_order_line_id) return
  const pl = eligiblePoLines.value.find(p => p.id === Number(line.purchase_order_line_id))
  if (! pl) return
  line.product_id = pl.product_id
  line.uom_id = pl.uom_id ?? ''
  line.unit_cost = pl.unit_price
  line.quantity = pl.max_receivable_quantity
}

// No UOM picker shown to the user — silently carried from the picked
// Fixed Asset Type's own primary_uom_id (always "UNIT").
function onProductChange(line) {
  const type = props.fixedAssetTypes.find(p => p.id === Number(line.product_id))
  if (type) line.uom_id = type.primary_uom_id
}

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function lineTotal(line) {
  return Number(line.quantity || 0) * Number(line.unit_cost || 0)
}

const receiptTotal = computed(() => form.lines.reduce((sum, l) => sum + lineTotal(l), 0))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.fixed-asset-receipts.update', props.goodsReceiptNote.id))
    : form.post(route('accounting.fixed-asset-receipts.store'))
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.fixedAssetReceipts.form.reopenThisFixedAssetReceiptFor'))) return
  router.patch(route('accounting.fixed-asset-receipts.set-to-draft', props.goodsReceiptNote.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.fixedAssetReceipts.form.deleteThisFixedAssetReceiptThis'))) return
  deleting.value = true
  router.delete(route('accounting.fixed-asset-receipts.destroy', props.goodsReceiptNote.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>

<style>
.far-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.far-th-qty { width: 8rem; text-align: end; }
.far-th-actions { width: 3rem; text-align: center; }
.far-total-row { display: flex; justify-content: flex-end; gap: 1rem; margin-top: .75rem; font-size: .95rem; padding-top: .75rem; border-top: 1px solid var(--zv-border, #e2e8f0); }
</style>
