<!--
══════════════════════════════════════════════════════════════════
  Accounting/DeliveryNotes/Form.vue

  Create + Edit — only editable while status = draft. A POSTED
  delivery opens this same page in READ-ONLY mode instead (Mahmoud:
  there was previously no way at all to see what was delivered once
  posted) — all inputs disabled, no Save button, journal entries
  shown instead.

  Picking a Sales Order fetches only its physical-goods lines
  (Product::has_stock) that still have something left to deliver —
  a pure service SO shows nothing here at all (design doc Section 10).
  Price is always the SO's own agreed price — not editable here
  (confirmed: terms are settled before the SO is issued).

  Shipping From is per LINE, not header-level (Mahmoud: the same
  delivery can ship different lines of different products — or even
  the same product — from different physical locations). Two rules
  enforced here, mirrored server-side in DeliveryNoteService:
    - the same (SO line, location) pair cannot appear twice — more
      from the same place is a bigger quantity on the SAME line, not
      a second line;
    - quantity can never exceed what's left on the SO line;
    - insufficient stock at the chosen location blocks the delivery
      outright (confirmed by Mahmoud) — the live "Available" figure
      shown per line is there so the user finds this out before
      saving, not only after a failed submit.

  @layout    AppLayout
  @route     accounting.delivery-notes.create / .edit
  @props     deliveryNote, customers, locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.deliveryNotes.form.deliveryNotes'), href: route('accounting.delivery-notes.index') }, { label: pageLabel }]"
      :title="pageTitle"
      :subtitle="isPosted ? t('accounting.deliveryNotes.form.postedInventoryCOGSMovedAndRevenue') : t('accounting.deliveryNotes.form.oncePostedThisMovesInventoryCOGS')"
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 17h6l3-3.5V9a1 1 0 00-1-1h-4V5a1 1 0 00-1-1H4a1 1 0 00-1 1v11a1 1 0 001 1h2"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.deliveryNotes.form.deliveryInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.deliveryNotes.form.whichSalesOrderThisDeliveryShips') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.customer') }}</label>
              <SelectBox v-model="form.partner_id" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.deliveryNotes.form.selectCustomer')" :clearable="false" :disabled="isEdit || isReadOnly" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.deliveryNotes.form.salesOrder') }}</label>
              <SelectBox v-model="form.sales_order_id" :options="eligibleSalesOrders" value-key="id" :label-fn="so => so.so_code" :placeholder="t('accounting.deliveryNotes.form.selectSalesOrder')" :clearable="false" :disabled="isEdit || isReadOnly || !form.partner_id" @change="onSoChange" :class="{ 'zv-input-error': form.errors.sales_order_id }" />
              <p v-if="form.errors.sales_order_id" class="zv-field-error">{{ form.errors.sales_order_id }}</p>
              <p v-if="form.partner_id && !eligibleSalesOrders.length && !isReadOnly" class="field-hint">{{ t('accounting.deliveryNotes.form.thisCustomerHasNoActiveSales') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.deliveryNotes.form.deliveryDate') }}</label>
              <input v-model="form.delivery_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.delivery_date }" :disabled="isReadOnly"/>
              <p v-if="form.errors.delivery_date" class="zv-field-error">{{ form.errors.delivery_date }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Lines -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.deliveryNotes.form.deliveryLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.deliveryNotes.form.onlyPhysicalGoodsSalesOrderLines') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="!form.sales_order_id" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.deliveryNotes.form.selectASalesOrderAboveTo') }}
          </div>
          <div v-else-if="!soLines.length && !isReadOnly" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.deliveryNotes.form.everyPhysicalGoodsLineOnThis') }}
          </div>

          <div v-else class="zv-table-wrap">
            <table class="zv-table dn-lines-table">
              <thead>
                <tr>
                  <th class="dn-th-text">{{ t('accounting.deliveryNotes.form.sOLine') }}</th>
                  <th class="dn-th-text">{{ t('accounting.deliveryNotes.form.shippingFrom') }}</th>
                  <th class="zv-table-num">{{ t('common.available') }}</th>
                  <th class="zv-table-num">{{ t('accounting.deliveryNotes.form.ordered') }}</th>
                  <th class="zv-table-num">{{ t('accounting.deliveryNotes.form.deliveredSoFar') }}</th>
                  <th class="zv-table-num">{{ t('accounting.deliveryNotes.form.remaining') }}</th>
                  <th class="zv-table-num">{{ t('accounting.deliveryNotes.form.deliverNow') }}</th>
                  <th v-if="!isReadOnly" class="dn-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.sales_order_line_id" :options="soLines" value-key="id" :label-fn="sl => sl.description" :placeholder="t('accounting.deliveryNotes.form.selectLine')" :clearable="false" :disabled="isReadOnly" size="sm" @change="onSoLineChange(line, idx)" />
                  </td>
                  <td>
                    <SelectBox v-model="line.location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :placeholder="t('accounting.deliveryNotes.form.selectLocation')" :clearable="false" :disabled="isReadOnly" size="sm" @change="onLocationChange(line, idx)" />
                    <p v-if="pairErrors[idx]" class="zv-field-error">{{ pairErrors[idx] }}</p>
                  </td>
                  <td class="dn-td-num">
                    <span v-if="availableStock[idx] !== undefined">{{ availableStock[idx] }}</span>
                    <span v-else class="opacity-40">—</span>
                  </td>
                  <td class="dn-td-num">{{ selectedSoLine(line)?.quantity ?? '—' }}</td>
                  <td class="dn-td-num">{{ selectedSoLine(line)?.delivered_quantity ?? '—' }}</td>
                  <td class="dn-td-num">{{ selectedSoLine(line)?.remaining_quantity ?? '—' }}</td>
                  <td class="dn-td-num">
                    <input v-model="line.quantity" type="number" step="0.0001" min="0.0001" :max="selectedSoLine(line)?.remaining_quantity" class="zv-input text-center" style="width:8rem;" :disabled="isReadOnly"/>
                    <p v-if="qtyErrors[idx]" class="zv-field-error">{{ qtyErrors[idx] }}</p>
                  </td>
                  <td v-if="!isReadOnly" class="dn-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.deliveryNotes.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!isReadOnly && form.sales_order_id && soLines.length" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.deliveryNotes.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.deliveryNotes.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input" :disabled="isReadOnly"></textarea>
          </div>
        </div>
      </div>

      <!-- Posted: show the resulting journal entries instead of a Save action -->
      <div v-if="isPosted" class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.deliveryNotes.form.journalEntries') }}</label>
            <div class="dn-je-list">
              <div v-if="deliveryNote.inventory_journal_entry" class="dn-je-row">
                <span>{{ t('accounting.deliveryNotes.form.inventoryCOGS') }}</span>
                <span class="zv-code">{{ deliveryNote.inventory_journal_entry.entry_number }}</span>
              </div>
              <div v-if="deliveryNote.revenue_journal_entry" class="dn-je-row">
                <span>{{ t('accounting.deliveryNotes.form.revenueRecognized') }}</span>
                <span class="zv-code">{{ deliveryNote.revenue_journal_entry.entry_number }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.delivery-notes.index')" class="zv-btn zv-btn-ghost">{{ isReadOnly ? t('accounting.deliveryNotes.form.close') : t('common.cancel') }}</Link>

          <!-- Posted → the reopen action. Cancelled is also locked but
               NOT reachable via Set to Draft — the server only allows
               reopening something that was actually posted
               (DeliveryNoteService::setToDraft()). -->
          <button
            v-if="isPosted"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.deliveryNotes.form.setToDraft') }}</button>

          <!-- Editable draft only (fresh create, or reopened via Set
               to Draft — indistinguishable and treated the same) →
               the delete action. Gated on isReadOnly, NOT isPosted,
               so a locked CANCELLED delivery correctly gets neither
               this nor Save — just Close. -->
          <button
            v-if="isEdit && !isReadOnly"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('common.deleting') : t('common.delete') }}</button>

          <button v-if="!isReadOnly" type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.deliveryNotes.form.saveAsDraft')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sub as moneySub } from '@/Utils/money'
import { computed, reactive, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  deliveryNote: { type: Object, default: null },
  customers:    { type: Array,  default: () => [] },
  locations:    { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.deliveryNote?.id)
const isPosted = computed(() => props.deliveryNote?.status === 'posted')

// Locked whenever viewing anything that isn't currently a draft —
// covers posted AND cancelled alike (a cancelled delivery should
// never be editable either). Same bug class already caught and
// fixed for Goods Receipt Note's own Form.vue — every field here
// used to check isPosted alone, which left a cancelled delivery
// showing as if it were still editable, Save button and all, right
// up until the server rejected it. Introduced properly here from
// the start.
const isReadOnly = computed(() => isEdit.value && props.deliveryNote.status !== 'draft')

const pageLabel = computed(() => isReadOnly.value ? t('common.view') : (isEdit.value ? t('common.edit') : t('accounting.deliveryNotes.form.new')))
const pageTitle = computed(() => isReadOnly.value ? t('accounting.deliveryNotes.form.deliveryNote') + ' ' + props.deliveryNote.delivery_code : (isEdit.value ? t('accounting.deliveryNotes.form.editDeliveryNote') : t('accounting.deliveryNotes.form.newDeliveryNote')))

function blankLine() {
  return { sales_order_line_id: '', location_id: '', description: '', quantity: '', uom_id: '' }
}

const form = useForm(isEdit.value ? {
  sales_order_id:    props.deliveryNote.sales_order_id,
  partner_id:        props.deliveryNote.partner_id,
  delivery_date:     props.deliveryNote.delivery_date?.toString().slice(0, 10),
  internal_notes:    props.deliveryNote.internal_notes ?? '',
  lines: (props.deliveryNote.lines ?? []).map(l => ({
    sales_order_line_id: l.sales_order_line_id,
    location_id: l.location_id,
    description: l.description,
    quantity: l.quantity,
    uom_id: l.uom_id,
  })),
} : {
  sales_order_id: '',
  partner_id: '',
  delivery_date: todayLocal(),
  internal_notes: '',
  lines: [],
})

const eligibleSalesOrders = ref([])
const soLines = ref([])
const availableStock = reactive({})
const pairErrors = reactive({})
const qtyErrors = reactive({})

// Guards: a slow reply for an earlier selection must not overwrite the current one.
let partnerReq = 0
let soReq = 0

async function onPartnerChange() {
  ++soReq
  form.sales_order_id = ''
  form.lines = []
  soLines.value = []
  eligibleSalesOrders.value = []
  clearRowState()
  const seq = ++partnerReq
  if (! form.partner_id) return
  const res = await fetch(route('accounting.delivery-notes.eligible-sales-orders', { partner_id: form.partner_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== partnerReq) return
  eligibleSalesOrders.value = data.orders ?? []
}

async function onSoChange() {
  form.lines = []
  clearRowState()
  const seq = ++soReq
  if (! form.sales_order_id) { soLines.value = []; return }
  const res = await fetch(route('accounting.delivery-notes.eligible-so-lines', { sales_order_id: form.sales_order_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== soReq) return
  soLines.value = data.lines ?? []
}

// Per-row helper state is keyed by row position, so it must be emptied when the
// rows are replaced and renumbered when a row is removed.
function clearRowState() {
  for (const o of [availableStock, pairErrors, qtyErrors]) Object.keys(o).forEach(k => delete o[k])
}

function shiftRowState(removedIdx) {
  for (const o of [availableStock, pairErrors, qtyErrors]) {
    const keys = Object.keys(o).map(Number).sort((a, b) => a - b)
    const kept = keys.filter(k => k !== removedIdx).map(k => [k > removedIdx ? k - 1 : k, o[k]])
    keys.forEach(k => delete o[k])
    kept.forEach(([k, v]) => { o[k] = v })
  }
}

const stockReq = new WeakMap() // row object -> latest stock request number

function selectedSoLine(line) {
  return soLines.value.find(sl => sl.id === Number(line.sales_order_line_id))
}

function onSoLineChange(line, idx) {
  const sl = selectedSoLine(line)
  if (! sl) return
  line.description = sl.description
  line.uom_id = sl.uom_id
  line.quantity = sl.remaining_quantity
  checkDuplicatePair()
  fetchAvailableStock(line, idx)
}

function onLocationChange(line, idx) {
  checkDuplicatePair()
  fetchAvailableStock(line, idx)
}

// Mahmoud: same (product, location) pair cannot appear twice on one
// Delivery Note — more from the same place is a bigger quantity on
// the SAME line, not a second line. Mirrored server-side in
// DeliveryNoteService::syncLines() — this is immediate feedback only.
function checkDuplicatePair() {
  Object.keys(pairErrors).forEach(k => delete pairErrors[k])
  const seen = {}
  form.lines.forEach((line, idx) => {
    if (! line.sales_order_line_id || ! line.location_id) return
    const key = line.sales_order_line_id + ':' + line.location_id
    if (seen[key] !== undefined) {
      pairErrors[idx] = t('accounting.deliveryNotes.form.thisProductIsAlreadyOnThis')
      pairErrors[seen[key]] = pairErrors[idx]
    }
    seen[key] = idx
  })
}

// Live "Available" figure — Mahmoud: show it as soon as a location is
// picked, so the user knows before saving, not only after a failed
// submit (insufficient stock at the location hard-blocks delivery).
async function fetchAvailableStock(line, idx) {
  delete availableStock[idx]
  const seq = (stockReq.get(line) ?? 0) + 1
  stockReq.set(line, seq)
  const sl = selectedSoLine(line)
  if (! sl || ! line.location_id) return

  const res = await fetch(route('accounting.delivery-notes.location-stock', {
    product_id: sl.product_id,
    location_id: line.location_id,
  }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  // The row may have moved (a line above it was removed) or been changed again meanwhile.
  if (stockReq.get(line) !== seq) return
  const now = form.lines.indexOf(line)
  if (now === -1) return
  availableStock[now] = data.quantity_on_hand
}

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
  shiftRowState(idx)
  checkDuplicatePair()
}

function submit() {
  checkDuplicatePair()
  if (Object.keys(pairErrors).length) return

  if (isEdit.value) {
    form.put(route('accounting.delivery-notes.update', props.deliveryNote.id))
  } else {
    form.post(route('accounting.delivery-notes.store'))
  }
}

// Fix (found from Mahmoud's screenshot, July 2026): eligibleSalesOrders()
// and eligibleSoLines() are deliberately filtered for the CREATE flow
// (active SOs only, lines with something left to deliver only) — a
// posted delivery's own SO may since be closed, and its own lines are
// by definition fully delivered, so re-fetching from those same
// endpoints for View/Edit left both dropdowns bound to a value with
// no matching <option>, rendering blank. Fixed: build both lists
// directly from the delivery's own already-loaded data instead of
// re-fetching anything.
if (isEdit.value) {
  if (props.deliveryNote.sales_order) {
    eligibleSalesOrders.value = [{ id: props.deliveryNote.sales_order_id, so_code: props.deliveryNote.sales_order.so_code }]
  }

  soLines.value = (props.deliveryNote.lines ?? []).map(l => ({
    id: l.sales_order_line_id,
    description: l.sales_order_line?.description ?? l.description,
    product_id: l.product_id,
    quantity: l.sales_order_line?.quantity ?? l.quantity,
    delivered_quantity: l.sales_order_line?.delivered_quantity ?? l.quantity,
    remaining_quantity: bcSubtractStrings(l.sales_order_line?.quantity ?? l.quantity, l.sales_order_line?.delivered_quantity ?? l.quantity),
    unit_price: l.sales_order_line?.unit_price ?? l.unit_price,
    uom_id: l.uom_id,
  }))

  form.lines.forEach((line, idx) => {
    if (line.location_id) fetchAvailableStock(line, idx)
  })
}

// Small helper — just for display math above, not authoritative (the
// real remaining-quantity guard lives server-side).
function bcSubtractStrings(a, b) {
  return moneySub(a || 0, b || 0, 4)
}

// Mirrors GoodsReceiptNoteService's Form.vue exactly — same two real
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
  if (! confirm(t('accounting.deliveryNotes.form.reopenThisDeliveryNoteForEditing'))) return
  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.delivery-notes.set-to-draft', props.deliveryNote.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.deliveryNotes.form.deleteThisDeliveryNoteThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.delivery-notes.destroy', props.deliveryNote.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>

<style>
/* Mahmoud: headers centered; numeric row values centered under them
   (same vertical as the header); non-numeric cells left-aligned (LTR). */
.dn-lines-table th { text-align: center; }
.dn-th-text { text-align: center; }
.dn-th-num, .dn-td-num { text-align: center; }
.dn-th-actions { width: 3rem; text-align: center; }
.dn-lines-table td:first-child,
.dn-lines-table td:nth-child(2) { text-align: left; }

.dn-je-list { display: flex; flex-direction: column; gap: 8px; }
.dn-je-row { display: flex; justify-content: space-between; padding: 8px 12px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; }
</style>
