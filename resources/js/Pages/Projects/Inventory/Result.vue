<!--
══════════════════════════════════════════════════════════════════
  Projects/Inventory/Result.vue

  The statement itself — one block per item tagged to the chosen
  Project or standalone Sales Order: Receipts (date/qty/unit cost/
  total cost/location), Total Received, Transfers (to/from site),
  and Remaining — matching the format from Project Module Structure
  v1.1 Section 6.5 exactly.

  @layout    AppLayout
  @route     projects.inventory.result
  @props     subject ({type,id,label}), statement ({items,summary}), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('projects.inventory.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ t('projects.inventory.result.backToProjectInventory') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">{{ t('projects.inventory.result.projectInventory') }} — {{ subject.label }}</h1>
        <p class="zv-page-subtitle">
          {{ statement.summary.total_items }} {{ t('projects.inventory.result.itemsTagged') }}
        </p>
      </div>
      <a :href="exportUrl" class="zv-btn zv-btn-secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        {{ t('projects.inventory.result.exportToExcel') }}
      </a>
    </div>

    <div class="zv-kpi-card pi-summary-card mb-4">
      <div class="zv-kpi-label">{{ t('projects.inventory.result.totalReceivedCost') }}</div>
      <div class="zv-kpi-value">{{ fmt(statement.summary.total_received_cost) }}</div>
    </div>

    <div v-if="statement.items.length === 0" class="zv-card">
      <div class="zv-empty">
        <div class="zv-empty-title">{{ t('projects.inventory.result.noTaggedInventory') }}</div>
      </div>
    </div>

    <div v-else class="pi-items">
      <div v-for="item in statement.items" :key="item.product_id" class="zv-card pi-item-card">
        <div class="pi-item-header">
          <span class="pi-item-code">{{ item.product_code }}</span>
          <span class="pi-item-name">{{ item.product_name_en }}</span>
        </div>

        <!-- Receipts -->
        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('projects.inventory.result.receiptDate') }}</th>
                <th class="pi-th-num">{{ t('projects.inventory.result.qty') }}</th>
                <th class="pi-th-num">{{ t('projects.inventory.result.unitCost') }}</th>
                <th class="pi-th-num">{{ t('projects.inventory.result.totalCost') }}</th>
                <th>{{ t('projects.inventory.result.location') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, idx) in item.receipts" :key="idx">
                <td class="pi-muted">{{ fmtDate(r.date) }}</td>
                <td class="pi-num">{{ fmtQty(r.quantity) }}</td>
                <td class="pi-num">{{ fmt(r.unit_cost) }}</td>
                <td class="pi-num">{{ fmt(r.total_cost) }}</td>
                <td>{{ r.location }}</td>
              </tr>
              <tr class="pi-total-row">
                <td>{{ t('projects.inventory.result.totalReceived') }}</td>
                <td class="pi-num">{{ fmtQty(item.total_received_qty) }}</td>
                <td></td>
                <td class="pi-num">{{ fmt(item.total_received_cost) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Transfers -->
        <div v-if="item.transfers.length > 0" class="pi-transfers">
          <div class="pi-transfers-title">{{ t('projects.inventory.result.transfers') }}</div>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <tbody>
                <tr v-for="(tr, idx) in item.transfers" :key="idx">
                  <td class="pi-muted">{{ fmtDate(tr.date) }}</td>
                  <td class="pi-num" :class="{ 'pi-negative': Number(tr.quantity) < 0 }">{{ fmtSignedQty(tr.quantity) }}</td>
                  <td>{{ tr.location }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Remaining -->
        <div class="pi-remaining">
          <span>{{ t('projects.inventory.result.remaining') }}:</span>
          <strong>{{ fmtQty(item.remaining_qty) }}</strong>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  subject:   { type: Object, required: true },
  statement: { type: Object, required: true },
  filters:   { type: Object, default: () => ({}) },
})

const exportUrl = computed(() => {
  const params = new URLSearchParams()
  Object.entries(props.filters).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      params.set(key, value)
    }
  })
  return route('projects.inventory.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtQty(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) }
function fmtSignedQty(val) {
  const n = Number(val || 0)
  const formatted = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
  return n < 0 ? `-${formatted}` : `+${formatted}`
}
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).split('/').reverse().join('-') : '—' }
</script>

<style>
.pi-summary-card { max-width: 320px; }
.pi-items { display: flex; flex-direction: column; gap: 1rem; }
.pi-item-card { padding-top: 1rem; }
.pi-item-header { display: flex; align-items: baseline; gap: 0.5rem; padding: 0 1rem 0.75rem; }
.pi-item-code { font-weight: 700; color: var(--zv-text-primary); }
.pi-item-name { color: var(--zv-text-secondary); }
.pi-th-num { text-align: end; }
.pi-num { text-align: end; font-variant-numeric: tabular-nums; }
.pi-muted { color: var(--zv-text-muted); }
.pi-total-row td { border-top: 1px solid var(--zv-border); font-weight: 700; }
.pi-negative { color: #ef4444; }
.pi-transfers { padding: 0.75rem 1rem 0; }
.pi-transfers-title { font-size: 12.5px; font-weight: 700; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 0.4rem; }
.pi-remaining { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0.75rem 1rem 1rem; font-size: 14px; }
.pi-remaining strong { color: #f59e0b; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
