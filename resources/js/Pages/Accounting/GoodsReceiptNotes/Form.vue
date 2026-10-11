<!--
══════════════════════════════════════════════════════════════════
  Accounting/GoodsReceiptNotes/Form.vue

  Create + Edit + View — a GRN is only ever editable while status =
  draft. Posting happens from the Index page. Once posted, this same
  page opens as a locked, read-only VIEW instead — every field wrapped
  in a single <fieldset disabled>, matching how Customer Payment's
  Form.vue does it (CustomerPaymentService::setToDraft() /
  CustomerPaymentController is the reference this was built from).
  From that locked view: "Set to Draft" reopens it for editing —
  which then shows "Delete" and "Save" instead, exactly like Customer
  Payment. Cancelling happens from the Index page, same split
  PurchaseOrderController uses for activate/close.

  grnPolicy ('po_required' | 'allow_ad_hoc') is read from the
  company and controls whether the Purchase Order field is required
  — this mirrors the same rule the server enforces independently in
  GoodsReceiptNoteRequest and GoodsReceiptNoteService (defense in
  depth, not this form's decision to make).

  When a Purchase Order is selected, eligible PO lines (remaining
  receivable quantity > 0, per tolerance) are fetched live and
  offered as a picker for each line — picking one locks that line's
  product and pre-fills the ordered unit price, but quantity and
  unit_cost stay editable (a receipt doesn't have to exactly match
  the order line, within tolerance — the server is the real check).
  Ad-hoc lines (no purchase_order_line_id) are always available via
  "Add Ad-Hoc Line" — gated by grnPolicy the same way the PO field
  itself is, since an ad-hoc GRN's lines are inherently all ad-hoc,
  but a PO-based GRN could still, on an ad-hoc-allowed company, add
  an extra line with no PO backing (e.g. a small substitute item the
  supplier threw in) — the server tolerates this: it simply skips the
  PO roll-up for that one line.

  @layout    AppLayout
  @route     accounting.goods-receipt-notes.create / .edit
  @props     goodsReceiptNote, grnPolicy, suppliers, purchaseOrders,
             inventoryLocations, products, uoms
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.goodsReceiptNotes.form.goodsReceiptNotes'), href: route('accounting.goods-receipt-notes.index') }, { label: pageLabel }]"
      :title="pageTitle"
      :subtitle="isPosted ? t('accounting.goodsReceiptNotes.form.postedStockMovedAndGRNIJournal') : t('accounting.goodsReceiptNotes.form.receivesGoodsIntoStockAndOnce')"
    />

    <form @submit.prevent="submit">
      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">


      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.goodsReceiptNotes.form.receiptInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.goodsReceiptNotes.form.whereThisShipmentCameFromAnd') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-4-6-2">
            <div class="field-group">
              <label class="zv-label" :class="{ 'zv-label-required': poRequired }">{{ t('accounting.goodsReceiptNotes.form.purchaseOrder') }}</label>
              <SelectBox v-model="form.purchase_order_id" :options="purchaseOrders" value-key="id" :label-fn="po => po.po_code" :placeholder="poRequired ? t('accounting.goodsReceiptNotes.form.selectAPurchaseOrder') : t('accounting.goodsReceiptNotes.form.adHocNoPurchaseOrder')" @change="onPoChange" :class="{ 'zv-input-error': form.errors.purchase_order_id }" />
              <p v-if="form.errors.purchase_order_id" class="zv-field-error">{{ form.errors.purchase_order_id }}</p>
              <p v-if="!poRequired" class="field-hint">{{ t('accounting.goodsReceiptNotes.form.thisCompanyAllowsAdHocReceipts') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.goodsReceiptNotes.form.supplier') }}</label>
              <SelectBox v-model="form.partner_id" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.goodsReceiptNotes.form.selectSupplier')" :clearable="false" :disabled="!!form.purchase_order_id" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.goodsReceiptNotes.form.receivingLocation') }}</label>
              <SelectBox v-model="form.inventory_location_id" :options="inventoryLocations" value-key="id" :label-fn="l => `${l.code} — ${l.name_en}`" :placeholder="t('accounting.goodsReceiptNotes.form.selectLocation')" :clearable="false" :class="{ 'zv-input-error': form.errors.inventory_location_id }" />
              <p v-if="form.errors.inventory_location_id" class="zv-field-error">{{ form.errors.inventory_location_id }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-4-8 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.goodsReceiptNotes.form.receivedDate') }}</label>
              <input v-model="form.received_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.received_date }"/>
              <p v-if="form.errors.received_date" class="zv-field-error">{{ form.errors.received_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.goodsReceiptNotes.form.supplierReference') }}</label>
              <input v-model="form.supplier_reference" type="text" class="zv-input" :placeholder="t('accounting.goodsReceiptNotes.form.supplierDeliveryNoteInvoice')"/>
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
            <div class="form-section-title">{{ t('accounting.goodsReceiptNotes.form.lines') }}</div>
            <div class="form-section-sub">{{ t('accounting.goodsReceiptNotes.form.eachLineCanOptionallyBeTagged') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap grn-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.goodsReceiptNotes.form.pOLine') }}</th>
                  <th>{{ t('accounting.goodsReceiptNotes.form.product') }}</th>
                  <th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.quantity') }}</th>
                  <th>{{ t('accounting.goodsReceiptNotes.form.uOM') }}</th>
                  <th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.unitCost') }}</th>
                  <th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.lineTotal') }}</th>
                  <th>{{ t('accounting.goodsReceiptNotes.form.project') }}</th>
                  <th class="grn-th-actions" v-if="!isReadOnly"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.purchase_order_line_id" :options="availablePoLinesFor(line)" value-key="id" :label-fn="pl => `${pl.product_name} — ${t('accounting.goodsReceiptNotes.form.max')} ${pl.max_receivable_quantity}`" :placeholder="t('accounting.goodsReceiptNotes.form.adHocNotOnPO')" v-if="form.purchase_order_id" size="sm" @change="onPoLineChange(line)" />
                    <span v-else class="zv-tag zv-tag-gray">{{ t('accounting.goodsReceiptNotes.form.adHoc') }}</span>
                  </td>
                  <td>
                    <SelectBox v-model="line.product_id" :options="products" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="t('accounting.goodsReceiptNotes.form.selectProduct')" :clearable="false" :disabled="!!line.purchase_order_line_id" size="sm" />
                  </td>
                  <td>
                    <input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"/>
                  </td>
                  <td>
                    <SelectBox v-model="line.uom_id" :options="uoms" value-key="id" :label-fn="u => u.code" :placeholder="'—'" size="sm" />
                  </td>
                  <td>
                    <input v-model="line.unit_cost" type="number" step="0.01" min="0" class="zv-input text-end"/>
                  </td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td>
                    <SelectBox v-model="line.project_id" :options="projects" value-key="id" :label-fn="pr => pr.contract_code" :placeholder="t('accounting.goodsReceiptNotes.form.none')" size="sm" />
                  </td>
                  <td class="grn-th-actions" v-if="!isReadOnly">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.goodsReceiptNotes.form.removeLine')" @click="removeLine(idx)">
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
            {{ t('accounting.goodsReceiptNotes.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>

          <div class="grn-total-row">
            <span>{{ t('accounting.goodsReceiptNotes.form.gRNTotal') }}</span>
            <strong>{{ fmt(grnTotal) }}</strong>
          </div>
        </div>
      </div>

      <!-- Landing Cost — see VeroCFO_Landing_Cost_Design_v1_0.md.
           Picks are saved with the draft (nothing consumed yet) —
           the real attachment only happens when this GRN is posted.
           Once posted, this section switches to a plain read-only
           list of what actually got attached. -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#B45309">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4m16 0l-6-6m6 6l-6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.goodsReceiptNotes.form.landingCosts') }}</div>
            <div class="form-section-sub">{{ t('accounting.goodsReceiptNotes.form.landingCostsSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Posted — read-only list of what actually got attached -->
          <div v-if="isPosted">
            <div v-if="!goodsReceiptNote.landing_costs?.length" class="text-sm opacity-50">
              {{ t('accounting.goodsReceiptNotes.form.noLandingCostsAttached') }}
            </div>
            <table v-else class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.goodsReceiptNotes.form.landingCostCode') }}</th>
                  <th>{{ t('accounting.goodsReceiptNotes.form.supplier') }}</th>
                  <th>{{ t('accounting.goodsReceiptNotes.form.account') }}</th>
                  <th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="lc in goodsReceiptNote.landing_costs" :key="lc.id">
                  <td><span class="zv-code">{{ lc.landing_cost_code }}</span></td>
                  <td>{{ lc.partner?.name_en }}</td>
                  <td><span class="zv-code">{{ lc.chart_of_account?.code }}</span><span class="zv-account-name">{{ lc.chart_of_account?.name_en }}</span></td>
                  <td class="zv-table-num">{{ fmt(lc.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Draft — the picker -->
          <template v-else>
            <div v-if="!eligibleLandingCosts.length && !form.landing_cost_selections.length" class="text-sm opacity-50">
              {{ t('accounting.goodsReceiptNotes.form.noUnusedLandingCosts') }}
            </div>

            <div v-for="pickable in pickableLandingCosts" :key="pickable.id" class="lc-pick-row">
              <label class="lc-pick-checkbox">
                <input type="checkbox" :checked="isPicked(pickable.id)" @change="togglePick(pickable)" />
                <span>
                  <span class="zv-code">{{ pickable.landing_cost_code }}</span>
                  — {{ pickable.partner_name }} — {{ pickable.account_label }} —
                  <strong>{{ fmt(pickable.amount) }}</strong>
                </span>
              </label>

              <div v-if="isPicked(pickable.id)" class="lc-pick-details">
                <div class="field-group">
                  <label class="zv-label">{{ t('accounting.goodsReceiptNotes.form.allocationBase') }}</label>
                  <SelectBox v-model="selectionFor(pickable.id).allocation_base" :options="[{ value: 'value', label: t('accounting.goodsReceiptNotes.form.baseValue') }, { value: 'quantity', label: t('accounting.goodsReceiptNotes.form.baseQuantity') }, { value: 'weight', label: t('accounting.goodsReceiptNotes.form.baseWeight') }, { value: 'manual', label: t('accounting.goodsReceiptNotes.form.baseManual') }]" style="max-width:12rem;" />
                </div>

                <!-- Manual — one amount per GRN line, must sum to the Landing Cost's own amount -->
                <div v-if="selectionFor(pickable.id).allocation_base === 'manual'" class="lc-manual-lines">
                  <div v-for="(line, idx) in form.lines" :key="idx" class="lc-manual-line">
                    <span class="lc-manual-line-label">{{ productLabel(line) }}</span>
                    <input
                      type="number" step="0.01" min="0" class="zv-input text-end" style="width:8rem;"
                      v-model="selectionFor(pickable.id).manual_amounts[idx]"
                    />
                  </div>
                  <p class="field-hint">
                    {{ t('accounting.goodsReceiptNotes.form.manualSumHint') }}:
                    {{ fmt(manualSum(pickable.id)) }} / {{ fmt(pickable.amount) }}
                  </p>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.goodsReceiptNotes.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>
      </fieldset>

      <!-- Posted: show the resulting GRNI journal entry instead of a Save action -->
      <div v-if="isPosted && goodsReceiptNote.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.goodsReceiptNotes.form.journalEntry') }} — {{ goodsReceiptNote.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.goodsReceiptNotes.form.account') }}</th><th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.debit') }}</th><th class="grn-th-qty">{{ t('accounting.goodsReceiptNotes.form.credit') }}</th></tr>
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

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.goods-receipt-notes.index')" class="zv-btn zv-btn-ghost">{{ isReadOnly ? t('accounting.goodsReceiptNotes.form.close') : t('common.cancel') }}</Link>

          <!-- Posted → the reopen action. Cancelled is also locked
               but is NOT reachable via Set to Draft — the server
               only allows reopening something that was actually
               posted (GoodsReceiptNoteService::setToDraft()). -->
          <button
            v-if="isPosted"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.goodsReceiptNotes.form.setToDraft') }}</button>

          <!-- Editable draft only (fresh create, or reopened via Set
               to Draft — indistinguishable and treated the same) →
               the delete action. Gated on isReadOnly, NOT isPosted,
               so a locked CANCELLED GRN correctly gets neither this
               nor Save — just Close. -->
          <button
            v-if="isEdit && !isReadOnly"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('common.deleting') : t('common.delete') }}</button>

          <!-- Draft (fresh create, or a reopened one being edited) → Save -->
          <button v-if="!isReadOnly" type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.goodsReceiptNotes.form.saveAsDraft')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, mul as mulMoney } from '@/Utils/money'
import { computed, ref, watch } from 'vue'
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
  products:           { type: Array,  default: () => [] },
  uoms:               { type: Array,  default: () => [] },
  projects:           { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.goodsReceiptNote?.id)
const isPosted = computed(() => props.goodsReceiptNote?.status === 'posted')

// Locked whenever viewing anything that isn't currently a draft —
// covers posted AND cancelled alike (a cancelled GRN should never be
// editable either), mirroring CustomerPaymentService's own
// isReadOnly rule exactly.
const isReadOnly = computed(() => isEdit.value && props.goodsReceiptNote.status !== 'draft')

const pageLabel = computed(() => isReadOnly.value ? t('common.view') : (isEdit.value ? t('common.edit') : t('accounting.goodsReceiptNotes.form.new')))
const pageTitle = computed(() => isReadOnly.value
  ? t('accounting.goodsReceiptNotes.form.goodsReceiptNote') + ' ' + props.goodsReceiptNote.grn_code
  : (isEdit.value ? t('accounting.goodsReceiptNotes.form.editGoodsReceiptNote') : t('accounting.goodsReceiptNotes.form.newGoodsReceiptNote')))

const poRequired = computed(() => props.grnPolicy !== 'allow_ad_hoc')

// Landing Cost picks previously saved on this draft (see
// GrnLandingCostSelection) — manual_amounts is already keyed by
// position (0-indexed) exactly like this form writes it, so no
// translation is needed on the way in, only on the way to post()
// (done server-side — see GoodsReceiptNoteService::
// translateManualAmountsToLineIds()).
function initialLandingCostSelections() {
  return (props.goodsReceiptNote?.landing_cost_selections ?? []).map(s => ({
    landing_cost_id: s.landing_cost_id,
    allocation_base: s.allocation_base,
    manual_amounts: { ...(s.manual_amounts ?? {}) },
  }))
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
    product_id: l.product_id, quantity: l.quantity, uom_id: l.uom_id ?? '',
    unit_cost: l.unit_cost, project_id: l.project_id ?? '', sales_order_id: l.sales_order_id ?? '',
  })) ?? [{ purchase_order_line_id: '', product_id: '', quantity: '', uom_id: '', unit_cost: '', project_id: '', sales_order_id: '' }],
  landing_cost_selections: initialLandingCostSelections(),
})

// ── Landing Cost picker (draft only — see form-section above) ──────

const eligibleLandingCosts = ref([])

async function fetchEligibleLandingCosts() {
  const res = await fetch(route('accounting.goods-receipt-notes.eligible-landing-costs'), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  eligibleLandingCosts.value = data.landing_costs ?? []
}

if (! isReadOnly.value) fetchEligibleLandingCosts()

// The fetched "unused" list already includes anything this draft
// itself picked earlier (attachment only happens at post time — see
// LandingCostService::attach()), so no separate merge is needed.
const pickableLandingCosts = computed(() => eligibleLandingCosts.value)

function isPicked(landingCostId) {
  return form.landing_cost_selections.some(s => s.landing_cost_id === landingCostId)
}

function selectionFor(landingCostId) {
  return form.landing_cost_selections.find(s => s.landing_cost_id === landingCostId)
}

function togglePick(pickable) {
  if (isPicked(pickable.id)) {
    form.landing_cost_selections = form.landing_cost_selections.filter(s => s.landing_cost_id !== pickable.id)
  } else {
    form.landing_cost_selections.push({ landing_cost_id: pickable.id, allocation_base: 'value', manual_amounts: {} })
  }
}

function productLabel(line) {
  const p = props.products.find(p => p.id === Number(line.product_id))
  return p ? `${p.code} — ${p.name_en}` : t('accounting.goodsReceiptNotes.form.selectProduct')
}

function manualSum(landingCostId) {
  const sel = selectionFor(landingCostId)
  if (! sel) return 0
  return sumMoney(Object.values(sel.manual_amounts))
}

// Auto-fills Supplier from the selected PO — mirrors PurchaseOrderForm's
// own product/UOM auto-fill convention.
function onPoChange() {
  const po = props.purchaseOrders.find(p => p.id === Number(form.purchase_order_id))
  if (po) form.partner_id = po.partner_id
  // Lines picked from the previous order do not belong to the new one — drop them
  // (lines typed by hand without a PO link are kept).
  for (let i = form.lines.length - 1; i >= 0; i--) {
    if (form.lines[i].purchase_order_line_id) removeLine(i)
  }
  fetchEligiblePoLines()
}

const eligiblePoLines = ref([])
let poLinesReq = 0 // a slow reply for an earlier PO must not overwrite the current one

async function fetchEligiblePoLines() {
  eligiblePoLines.value = []
  const seq = ++poLinesReq
  if (! form.purchase_order_id) return

  const response = await fetch(
    route('accounting.goods-receipt-notes.eligible-po-lines', { purchase_order_id: form.purchase_order_id }),
    { headers: { Accept: 'application/json' } } // without this header Laravel/Inertia can redirect instead of returning JSON — burned once already on this exact endpoint shape
  )
  const data = await response.json()
  if (seq !== poLinesReq) return
  eligiblePoLines.value = data.lines ?? []
}

if (form.purchase_order_id) fetchEligiblePoLines()

// Bug fix (audit, Aug 2026, per Mahmoud): every row's dropdown used to
// list ALL eligible PO lines with no memory of what other rows on
// this same GRN had already picked — so the same PO line (e.g. the
// same product) could be selected two or three times over, each
// receiving up to its own max_receivable_quantity, letting the form
// silently accept receiving far more than the PO actually allows
// (post() would eventually reject it via the tolerance check, but
// only after the user had already filled in the whole form). Same
// class of bug already fixed once for Delivery Note's (product,
// location) pairs — same fix shape applied here: each row's own
// current selection stays visible in ITS OWN dropdown (so switching
// away and back still works), but disappears from every OTHER row's
// dropdown the moment it's picked.
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

function addLine() {
  form.lines.push({ purchase_order_line_id: '', product_id: '', quantity: '', uom_id: '', unit_cost: '', project_id: '', sales_order_id: '' })
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
  // Manual landing-cost amounts are keyed by line position — renumber them so each
  // amount stays with its own line.
  form.landing_cost_selections.forEach(sel => {
    const next = {}
    Object.keys(sel.manual_amounts ?? {}).forEach(k => {
      const n = Number(k)
      if (n === idx) return
      next[n > idx ? n - 1 : n] = sel.manual_amounts[k]
    })
    sel.manual_amounts = next
  })
}

function lineTotal(line) {
  return mulMoney(line.quantity || 0, line.unit_cost || 0, 2)
}

const grnTotal = computed(() => sumMoney(form.lines.map(l => lineTotal(l))))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.goods-receipt-notes.update', props.goodsReceiptNote.id))
    : form.post(route('accounting.goods-receipt-notes.store'))
}

// Mirrors CustomerPaymentService's Form.vue exactly — same two real
// bugs already found and fixed there, both avoided here from the
// start: (1) the server redirects back to this same edit URL after
// reopening, and Inertia landing on a page it's already showing
// updates props in place rather than re-running this component's
// setup code — so a plain in-place update would keep the screen
// showing pre-reversal figures until a manual reload. (2) onSuccess
// fires even when the server correctly REJECTED the request (a
// rejection is still a successful redirect in Inertia's eyes) — so
// the reload only happens when the response genuinely carries no
// flash error.
function confirmSetToDraft() {
  if (! confirm(t('accounting.goodsReceiptNotes.form.reopenThisGoodsReceiptNoteForEditing'))) return
  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.goods-receipt-notes.set-to-draft', props.goodsReceiptNote.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.goodsReceiptNotes.form.deleteThisGoodsReceiptNoteThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.goods-receipt-notes.destroy', props.goodsReceiptNote.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>

<style>
.grn-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.grn-th-qty { width: 8rem; text-align: end; }
.grn-th-actions { width: 3rem; text-align: center; }
.grn-total-row { display: flex; justify-content: flex-end; gap: 1rem; margin-top: .75rem; font-size: .95rem; padding-top: .75rem; border-top: 1px solid var(--zv-border, #e2e8f0); }
.lc-pick-row { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); padding: .75rem 1rem; margin-bottom: .75rem; }
.lc-pick-checkbox { display: flex; align-items: center; gap: .625rem; cursor: pointer; }
.lc-pick-details { margin-top: .75rem; padding-top: .75rem; border-top: 1px dashed var(--zv-border, #e2e8f0); }
.lc-manual-lines { margin-top: .75rem; display: flex; flex-direction: column; gap: .5rem; }
.lc-manual-line { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.lc-manual-line-label { font-size: .875rem; opacity: .8; }
</style>
