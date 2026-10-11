<!--
══════════════════════════════════════════════════════════════════
  Accounting/Inventory/StockTransferForm.vue

  Create-only form for an internal stock transfer. No edit mode —
  transfers, like every financial write in this app, are corrected
  by recording an offsetting entry, not by editing a posted one.

  No cost field on this form by design — a transfer never changes
  company-wide WAC (see StockLedgerService::transfer()), it only
  moves quantity between locations, so there's nothing to price.

  @layout    AppLayout
  @route     accounting.transfers.create
  @props     products, locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.inventory.stockTransferForm.internalTransfers'), href: route('accounting.transfers.index') }, { label: t('accounting.inventory.stockTransferForm.new') }]"
      :title="t('accounting.inventory.stockTransferForm.newStockTransfer')"
      :subtitle="t('accounting.inventory.stockTransferForm.moveStockFromOneLocationTo')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.inventory.stockTransferForm.transferDetails') }}</div>
            <div class="form-section-sub">{{ t('accounting.inventory.stockTransferForm.productSourceAndDestinationLocationAnd') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockTransferForm.product') }}</label>
              <SearchableSelect
                v-model="form.product_id"
                v-model:label="productLabel"
                :search-url="route('accounting.transfers.products.search')"
                :placeholder="t('accounting.inventory.stockTransferForm.searchByCodeOrName')"
                @update:modelValue="onProductChange"
              />
              <div v-if="form.errors.product_id" class="zv-field-error">{{ form.errors.product_id }}</div>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockTransferForm.quantity') }}</label>
              <div class="is-qty-with-uom">
                <input v-model="form.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"
                       :class="{ 'zv-input-error': form.errors.quantity }"/>
                <span v-if="uomCode" class="is-uom-suffix">{{ uomCode }}</span>
              </div>
              <div v-if="form.errors.quantity" class="zv-field-error">{{ form.errors.quantity }}</div>
            </div>
          </div>

          <div class="field-grid field-grid-3 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockTransferForm.fromLocation') }}</label>
              <SelectBox v-model="form.from_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :disabled-fn="l => l.id === form.to_location_id" :placeholder="t('accounting.inventory.stockTransferForm.select')" :clearable="false" @change="onFromLocationChange" :class="{ 'zv-input-error': form.errors.from_location_id }" />
              <p v-if="form.product_id && form.from_location_id" class="field-hint">
                {{ t('accounting.inventory.stockTransferForm.availableHere') }} <strong>{{ fmtQty(fromAvailable) }} {{ uomCode }}</strong>
              </p>
              <div v-if="form.errors.from_location_id" class="zv-field-error">{{ form.errors.from_location_id }}</div>
            </div>

            <div class="field-group">
              <div class="xfer-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
              </div>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.inventory.stockTransferForm.toLocation') }}</label>
              <SelectBox v-model="form.to_location_id" :options="locations" value-key="id" :label-fn="l => l.name_en" :disabled-fn="l => l.id === form.from_location_id" :placeholder="t('accounting.inventory.stockTransferForm.select')" :clearable="false" @change="onToLocationChange" :class="{ 'zv-input-error': form.errors.to_location_id }" />
              <p v-if="form.product_id && form.to_location_id" class="field-hint">
                {{ t('accounting.inventory.stockTransferForm.availableHere') }} <strong>{{ fmtQty(toAvailable) }} {{ uomCode }}</strong>
              </p>
              <div v-if="form.errors.to_location_id" class="zv-field-error">{{ form.errors.to_location_id }}</div>
            </div>
          </div>

          <div class="field-grid field-grid-2-3-7 mt-4">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.inventory.stockTransferForm.transferDate') }}</label>
              <input v-model="form.movement_date" type="date" class="zv-input"/>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.inventory.stockTransferForm.project') }}</label>
              <SelectBox v-model="form.project_id" :options="projects" value-key="id" :label-fn="p => `${p.contract_code} — ${p.name_en}`" :placeholder="t('accounting.inventory.stockTransferForm.noneGeneralStock')" @change="form.sales_order_id = ''" />
              <p class="field-hint">{{ t('accounting.inventory.stockTransferForm.projectTagHint') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.inventory.stockTransferForm.salesOrder') }}</label>
              <SelectBox v-model="form.sales_order_id" :options="salesOrders" value-key="id" :label-fn="so => `${so.so_code}${so.subject ? ' — ' + so.subject : ''}`" :placeholder="t('accounting.inventory.stockTransferForm.noneGeneralStock')" :disabled="!!form.project_id" />
              <p class="field-hint">{{ form.project_id ? t('accounting.inventory.stockTransferForm.salesOrderDisabledHint') : t('accounting.inventory.stockTransferForm.standaloneOnlyHint') }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.inventory.stockTransferForm.notes') }}</label>
            <textarea v-model="form.notes" rows="2" class="zv-input" :placeholder="t('accounting.inventory.stockTransferForm.optionalReasonForTheTransferReference')"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.transfers.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : t('accounting.inventory.stockTransferForm.recordTransfer') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SearchableSelect from '@/Components/SearchableSelect.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

defineProps({
  locations:   { type: Array, default: () => [] },
  projects:    { type: Array, default: () => [] },
  salesOrders: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = useForm({
  product_id: '',
  from_location_id: '',
  to_location_id: '',
  quantity: '',
  movement_date: today,
  project_id: '',
  sales_order_id: '',
  notes: '',
})
const productLabel = ref('')

// Point 3/4: one small endpoint gives both the product's own unit
// (shown beside Quantity) and — when a location is also known — that
// location's current available quantity (shown under From/To).
const uomCode      = ref('')
const fromAvailable = ref('0.0000')
const toAvailable   = ref('0.0000')

// Round 8: every lookup is stamped. A slow reply for an earlier product or
// location is dropped, so the unit and the "available here" figure always
// belong to what is selected now (they used to show another location's stock).
let uomTicket  = 0
const sideTicket = { from: 0, to: 0 }

async function fetchAvailability(locationId, target, side) {
  const ticket = ++sideTicket[side]
  const productId = form.product_id
  if (! productId || ! locationId) return
  try {
    const res = await window.axios.get(route('accounting.transfers.availability'), {
      params: { product_id: productId, location_id: locationId },
    })
    if (ticket !== sideTicket[side] || productId !== form.product_id) return
    uomCode.value = res.data.uom_code ?? ''
    target.value = res.data.quantity_on_hand ?? '0.0000'
  } catch {
    // Leave the figure at zero rather than show a stale one from another location.
    if (ticket === sideTicket[side]) target.value = '0.0000'
  }
}

async function onProductChange() {
  const ticket = ++uomTicket
  sideTicket.from++; sideTicket.to++          // drop any in-flight location lookups
  fromAvailable.value = '0.0000'
  toAvailable.value = '0.0000'
  if (! form.product_id) { uomCode.value = ''; return }
  const productId = form.product_id
  // Fetch the unit even before a location is picked (no location_id
  // means the endpoint just returns the UOM, no quantity).
  try {
    const res = await window.axios.get(route('accounting.transfers.availability'), { params: { product_id: productId } })
    if (ticket !== uomTicket) return
    uomCode.value = res.data.uom_code ?? ''
  } catch { if (ticket === uomTicket) uomCode.value = '' }
  if (ticket !== uomTicket) return
  if (form.from_location_id) fetchAvailability(form.from_location_id, fromAvailable, 'from')
  if (form.to_location_id) fetchAvailability(form.to_location_id, toAvailable, 'to')
}

function onFromLocationChange() {
  fromAvailable.value = '0.0000'
  fetchAvailability(form.from_location_id, fromAvailable, 'from')
}
function onToLocationChange() {
  toAvailable.value = '0.0000'
  fetchAvailability(form.to_location_id, toAvailable, 'to')
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}

function submit() {
  form.post(route('accounting.transfers.store'))
}
</script>

<style>
.xfer-arrow { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--zv-text-muted, #94a3b8); padding-top: 1.25rem; }
.is-qty-with-uom { display: flex; align-items: center; gap: 0.5rem; }
.is-qty-with-uom input { flex: 1; }
.is-uom-suffix {
  flex-shrink: 0; font-size: 0.75rem; font-weight: 600;
  color: var(--zv-text-muted); background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border); border-radius: var(--zv-radius, 0.5rem);
  padding: 0.4rem 0.6rem;
}
</style>
