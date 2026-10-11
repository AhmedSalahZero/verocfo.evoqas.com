<!--
══════════════════════════════════════════════════════════════════
  Accounting/FixedAssets/Index.vue

  Fixed Asset Register — Fixed Assets module, Phase 1. Assets are
  only ever CREATED via Goods Receipt Note posting (see
  FixedAssetService::receiveFromGrnLine()) — there is no "create"
  button here, only:
    - Edit modal: the asset's own fields (name, serial number,
      useful life, residual value, depreciation start date, location,
      notes) — never cost_basis directly, that only ever changes via
      a traced cost line.
    - History modal: read-only cost lines + depreciation entries.
    - Mark In Service: locks the asset from further capitalization.
    - Run Depreciation: bulk-posts one month for every eligible
      in-service asset company-wide.
    - Post / Reverse single depreciation entry from the history modal.
    - Audit H17: "Manage" opens the asset's own page — sell or scrap,
      impair, move (FixedAssets/Manage.vue). Net book value now also
      deducts any impairment.

  @layout    AppLayout
  @route     accounting.fixed-assets.index
  @props     fixedAssets (paginator), filters, products, inventoryLocations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.fixedAssets.index.fixedAssetRegister')"
      :subtitle="t('accounting.fixedAssets.index.everyIndividualAssetItsOwn')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m9-14h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M9 7h.01M15 7h.01"/>'
    >
      <template #actions>
        <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openRunDepreciation">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          {{ t('accounting.fixedAssets.index.runDepreciation') }}
        </button>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="fixedAssets.total"
        results-label="asset"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.fixedAssets.index.searchByAssetCodeNameOr')" @input="debouncedSearch" />
        <SelectBox v-model="statusFilter" :options="[{ value: 'capitalizing', label: t('accounting.fixedAssets.index.capitalizing') }, { value: 'in_service', label: t('accounting.fixedAssets.index.inService') }, { value: 'disposed', label: t('accounting.fixedAssets.index.disposed') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
        <SelectBox v-model="productFilter" :options="products" value-key="id" :label-fn="p => p.name_en" :placeholder="t('accounting.fixedAssets.index.allProducts')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="fixedAssets.data.length === 0"
        :title="hasActiveFilters ? t('accounting.fixedAssets.index.noAssetsMatchThisFilter') : t('accounting.fixedAssets.index.noFixedAssetsYet')"
        :message="hasActiveFilters ? t('accounting.fixedAssets.index.tryADifferentStatusOrClear') : t('accounting.fixedAssets.index.assetsAppearHereAutomaticallyWhen')"
        icon-path="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.fixedAssets.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.fixedAssets.index.assetCode') }}</th>
              <th>{{ t('accounting.fixedAssets.index.name') }}</th>
              <th>{{ t('accounting.fixedAssets.index.category') }}</th>
              <th class="zv-table-num">{{ t('accounting.fixedAssets.index.costBasis') }}</th>
              <th class="zv-table-num">{{ t('accounting.fixedAssets.index.accumDepreciation') }}</th>
              <th class="zv-table-num">{{ t('accounting.fixedAssets.index.netBookValue') }}</th>
              <th>{{ t('accounting.fixedAssets.index.depreciationStart') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in fixedAssets.data" :key="a.id">
              <td><span class="zv-code">{{ a.asset_code }}</span></td>
              <td><div class="zv-cell-clamp">{{ a.name }}</div></td>
              <td><span class="zv-code">{{ a.fixed_asset_account?.code }}</span><span class="zv-account-name">{{ a.fixed_asset_account?.name_en }}</span></td>
              <td class="zv-table-num">{{ fmt(a.cost_basis) }}</td>
              <td class="zv-table-num">{{ fmt(a.accumulated_depreciation) }}</td>
              <td class="zv-table-num fw-600">{{ fmt(a.cost_basis - a.accumulated_depreciation - (a.accumulated_impairment || 0)) }}</td>
              <td>{{ a.depreciation_start_date }}</td>
              <td class="text-center"><StatusBadge :status="a.status" :overrides="ASSET_STATUS_BADGES" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button type="button" class="zv-action-btn" :title="t('common.view')" @click="openDetail(a)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </button>
                  <button v-if="a.status === 'capitalizing'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="confirmMarkInService(a)">{{ t('accounting.fixedAssets.index.markInService') }}</button>
                  <!-- Audit H17: sell / scrap / impair / move -->
                  <Link :href="route('accounting.fixed-assets.manage', a.id)" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('accounting.fixedAssetsManage.manage') }}</Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="fixedAssets" />
    </div>

    <!-- ══════════════════════════════════════════════════════
         DETAIL MODAL — edit fields + cost lines + depreciation
         history, all in one place since an asset's whole story
         lives across these three things together.
    ══════════════════════════════════════════════════════ -->
    <div v-if="detailTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
      <div class="zv-modal rounded-lg w-full max-w-3xl fa-modal">
        <div class="p-6 pb-0">
          <h2 class="text-lg font-medium zv-text-primary mb-1">{{ detailTarget.asset_code }} — {{ detailForm.name || detailTarget.name }}</h2>
          <p class="text-xs zv-text-muted mb-4">
            {{ detailTarget.product?.code }} — {{ detailTarget.product?.name_en }} · {{ detailTarget.currency?.code }}
          </p>
        </div>

        <div class="fa-modal-scroll px-6">

          <!-- Editable fields -->
          <div class="fa-edit-grid mb-5">
            <div>
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssets.index.name') }}</label>
              <input v-model="detailForm.name" type="text" class="zv-input w-full"/>
              <p v-if="detailErrors.name" class="zv-field-error">{{ detailErrors.name }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('accounting.fixedAssets.index.serialNumber') }}</label>
              <input v-model="detailForm.serial_number" type="text" class="zv-input w-full"/>
            </div>
            <div>
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssets.index.usefulLifeMonths') }}</label>
              <input v-model="detailForm.useful_life_months" type="number" min="1" class="zv-input w-full"/>
              <p v-if="detailErrors.useful_life_months" class="zv-field-error">{{ detailErrors.useful_life_months }}</p>
            </div>
            <div>
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssets.index.residualValue') }}</label>
              <input v-model="detailForm.residual_value" type="number" min="0" step="0.01" class="zv-input w-full"/>
              <p v-if="detailErrors.residual_value" class="zv-field-error">{{ detailErrors.residual_value }}</p>
            </div>
            <div>
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssets.index.depreciationStartDate') }}</label>
              <input v-model="detailForm.depreciation_start_date" type="date" class="zv-input w-full"/>
              <p v-if="detailErrors.depreciation_start_date" class="zv-field-error">{{ detailErrors.depreciation_start_date }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('accounting.fixedAssets.index.location') }}</label>
              <SelectBox v-model="detailForm.inventory_location_id" :options="inventoryLocations" value-key="id" :label-fn="loc => loc.name_en" :placeholder="'—'" class="w-full" />
            </div>
            <div class="fa-edit-span-2">
              <label class="zv-label">{{ t('accounting.fixedAssets.index.notes') }}</label>
              <textarea v-model="detailForm.notes" class="zv-input w-full" rows="2"></textarea>
            </div>
          </div>

          <div class="flex justify-between items-center mb-2">
            <div class="fa-mini-summary">
              <span>{{ t('accounting.fixedAssets.index.costBasis') }}: <strong>{{ fmt(detailTarget.cost_basis) }}</strong></span>
              <span>{{ t('accounting.fixedAssets.index.netBookValue') }}: <strong>{{ fmt(detailTarget.cost_basis - detailTarget.accumulated_depreciation) }}</strong></span>
            </div>
            <button v-if="detailTarget.status === 'capitalizing'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="confirmMarkInService(detailTarget)">{{ t('accounting.fixedAssets.index.markInService') }}</button>
          </div>

          <!-- Cost lines -->
          <div class="fa-section-title mt-4 mb-2">{{ t('accounting.fixedAssets.index.costLines') }}</div>
          <div class="zv-table-wrap mb-4">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.fixedAssets.index.type') }}</th>
                  <th class="zv-table-num">{{ t('accounting.fixedAssets.index.amount') }}</th>
                  <th class="text-center">{{ t('common.status') }}</th>
                  <th>{{ t('accounting.fixedAssets.index.notes') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="cl in detailTarget.cost_lines" :key="cl.id">
                  <td><span class="zv-tag">{{ t('accounting.fixedAssets.index.costType_' + cl.cost_type) }}</span></td>
                  <td class="zv-table-num">{{ fmt(cl.amount) }}</td>
                  <td class="text-center">
                    <span class="zv-badge" :class="cl.is_provisional ? 'zv-badge-amber' : 'zv-badge-green'">
                      {{ cl.is_provisional ? t('accounting.fixedAssets.index.provisional') : t('accounting.fixedAssets.index.confirmed') }}
                    </span>
                  </td>
                  <td class="zv-text-muted">{{ cl.description || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Depreciation history -->
          <div class="fa-section-title mt-4 mb-2">{{ t('accounting.fixedAssets.index.depreciationHistory') }}</div>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.fixedAssets.index.period') }}</th>
                  <th class="zv-table-num">{{ t('accounting.fixedAssets.index.amount') }}</th>
                  <th class="text-center">{{ t('common.status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="de in detailTarget.depreciation_entries" :key="de.id" :class="{ 'fa-reversed-row': de.status === 'reversed' }">
                  <td>{{ de.period_date }}</td>
                  <td class="zv-table-num">{{ fmt(de.amount) }}</td>
                  <td class="text-center">
                    <span class="zv-badge" :class="de.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">
                      {{ de.status === 'posted' ? t('accounting.fixedAssets.index.posted') : t('accounting.fixedAssets.index.reversed') }}
                    </span>
                  </td>
                  <td>
                    <button v-if="de.status === 'posted'" type="button" class="zv-btn zv-btn-danger-ghost zv-btn-sm" @click="confirmReverseDepreciation(de)">{{ t('accounting.fixedAssets.index.reverse') }}</button>
                  </td>
                </tr>
                <tr v-if="!detailTarget.depreciation_entries?.length">
                  <td colspan="4" class="zv-text-muted text-center">{{ t('accounting.fixedAssets.index.noDepreciationPostedYet') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>

        <div class="flex justify-end gap-2 p-6 pt-4 fa-modal-footer">
          <button type="button" class="zv-btn zv-btn-secondary" @click="detailTarget = null">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" @click="submitDetail">{{ t('common.confirm') }}</button>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         RUN DEPRECIATION MODAL — bulk posts one month for every
         eligible in-service asset company-wide.
    ══════════════════════════════════════════════════════ -->
    <div v-if="runDepTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
      <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
        <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('accounting.fixedAssets.index.runMonthlyDepreciation') }}</h2>
        <p class="text-xs zv-text-muted mb-4">{{ t('accounting.fixedAssets.index.postsOneMonthForEveryInService') }}</p>
        <div class="field-group mb-4">
          <label class="zv-label zv-label-required">{{ t('accounting.fixedAssets.index.period') }}</label>
          <input v-model="runDepForm.for_date" type="date" class="zv-input w-full"/>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="zv-btn zv-btn-secondary" @click="runDepTarget = null">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" @click="submitRunDepreciation">{{ t('accounting.fixedAssets.index.runDepreciation') }}</button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  fixedAssets: { type: Object, required: true },
  filters:     { type: Object, default: () => ({}) },
  products:    { type: Array, default: () => [] },
  inventoryLocations: { type: Array, default: () => [] },
})

const ASSET_STATUS_BADGES = {
  capitalizing: { label: 'Capitalizing', badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
  in_service:   { label: 'In Service',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  disposed:     { label: 'Disposed',     badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray' },
}

// ── Filters ────────────────────────────────────────────────────────
const searchTerm    = ref(props.filters?.search ?? '')
const statusFilter   = ref(props.filters?.status ?? '')
const productFilter  = ref(props.filters?.product_id ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!statusFilter.value || !!productFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.fixed-assets.index'), {
    search: searchTerm.value || undefined,
    status: statusFilter.value || undefined,
    product_id: productFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  statusFilter.value = ''
  productFilter.value = ''
  applyFilters()
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Detail modal (edit + cost lines + depreciation history) ────────
const detailTarget = ref(null)
const detailForm = ref({})
const detailErrors = ref({})

function openDetail(asset) {
  detailErrors.value = {}
  fetch(route('accounting.fixed-assets.show', asset.id), { headers: { Accept: 'application/json' } })
    .then(r => r.json())
    .then(data => {
      detailTarget.value = data
      detailForm.value = {
        name:                     data.name,
        serial_number:            data.serial_number,
        useful_life_months:       data.useful_life_months,
        residual_value:           data.residual_value,
        depreciation_start_date:  data.depreciation_start_date,
        inventory_location_id:    data.inventory_location_id,
        notes:                    data.notes,
      }
    })
}

function submitDetail() {
  router.put(route('accounting.fixed-assets.update', detailTarget.value.id), detailForm.value, {
    preserveScroll: true,
    onError: (errors) => { detailErrors.value = errors },
    onSuccess: () => { detailTarget.value = null },
  })
}

function confirmMarkInService(asset) {
  if (! confirm(t('accounting.fixedAssets.index.markInServiceConfirm'))) return
  router.patch(route('accounting.fixed-assets.mark-in-service', asset.id), {}, {
    preserveScroll: true,
    onSuccess: () => { if (detailTarget.value) detailTarget.value = null },
  })
}

function confirmReverseDepreciation(entry) {
  if (! confirm(t('accounting.fixedAssets.index.reverseDepreciationConfirm'))) return
  router.delete(route('accounting.fixed-assets.reverse-depreciation', entry.id), {
    preserveScroll: true,
    onSuccess: () => { if (detailTarget.value) openDetail(detailTarget.value) },
  })
}

// ── Run Depreciation modal ──────────────────────────────────────────
const runDepTarget = ref(null)
const runDepForm = ref({ for_date: todayLocal() })

function openRunDepreciation() {
  runDepForm.value = { for_date: todayLocal() }
  runDepTarget.value = true
}

function submitRunDepreciation() {
  router.post(route('accounting.fixed-assets.run-depreciation'), runDepForm.value, {
    preserveScroll: true,
    onSuccess: () => { runDepTarget.value = null },
  })
}
</script>

<style>
.fw-600 { font-weight: 600; }

.fa-modal { display: flex; flex-direction: column; max-height: calc(100vh - 48px); }
.fa-modal-scroll { overflow-y: auto; flex: 1 1 auto; }
.fa-modal-footer { flex-shrink: 0; border-top: 1px solid var(--zv-border); }

.fa-edit-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px 16px; }
.fa-edit-span-2 { grid-column: span 2; }
.fa-section-title { font-size: 13.5px; font-weight: 700; color: var(--zv-text-primary); }
.fa-mini-summary { display: flex; gap: 18px; font-size: 13px; color: var(--zv-text-muted); }
.fa-reversed-row { opacity: 0.5; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }

@media (max-width: 700px) {
  .fa-edit-grid { grid-template-columns: 1fr; }
  .fa-edit-span-2 { grid-column: span 1; }
}
</style>
