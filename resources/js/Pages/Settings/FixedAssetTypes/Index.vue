<!--
══════════════════════════════════════════════════════════════════
  Settings/FixedAssetTypes/Index.vue

  Fixed Asset Types master list — a dedicated, simplified page,
  separate from Products & Services (confirmed with Mahmoud: mixing
  "owned equipment, depreciated over time" into the same form/list as
  sold/inventory products confused the user, since UOM/barcode/
  selling-price columns don't apply to it at all).

  Under the hood these are still Product rows (nature = 'fixed_asset')
  — the whole PO → GRN → Bill → FixedAssetService pipeline is built
  around that — this page just presents them through their own,
  much simpler list: name, category account, useful life, residual
  value, status.

  @layout    AppLayout
  @route     settings.fixed-asset-types.index
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.fixedAssetTypes.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.fixedAssetTypes.index.fixedAssetTypes') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.fixedAssetTypes.index.theTemplatesUsedWhenPurchasing') }}</p>
        </div>
        <Link :href="route('settings.fixed-asset-types.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.fixedAssetTypes.index.newFixedAssetType') }}
        </Link>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="zv-alert zv-alert-danger mb-4">{{ $page.props.flash.error }}</div>

      <!-- Filters -->
      <div class="zv-card mb-4">
        <div class="zv-filter-bar">
          <div class="zv-search-input" style="flex:1;max-width:300px">
            <svg class="w-4 h-4" style="color:var(--zv-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input v-model="form.search" type="text" :placeholder="t('settings.fixedAssetTypes.index.searchByNameOrCode')" @input="applyFilters" />
          </div>

          <select v-model="form.status" class="zv-select" style="width:130px" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="inactive">{{ t('common.inactive') }}</option>
          </select>
        </div>
      </div>

      <!-- Table -->
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('settings.fixedAssetTypes.index.code') }}</th>
              <th>{{ t('settings.fixedAssetTypes.index.name') }}</th>
              <th>{{ t('settings.fixedAssetTypes.index.category') }}</th>
              <th class="fat-th-num">{{ t('settings.fixedAssetTypes.index.usefulLifeMonths') }}</th>
              <th class="fat-th-num">{{ t('settings.fixedAssetTypes.index.residualValue') }}</th>
              <th>{{ t('common.status') }}</th>
              <th style="width:140px">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="fixedAssetTypes.data.length === 0">
              <td colspan="7">
                <div class="zv-empty py-10">
                  <svg class="w-10 h-10 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5"/>
                  </svg>
                  <div class="zv-empty-title">{{ t('settings.fixedAssetTypes.index.noFixedAssetTypesYet') }}</div>
                  <div class="zv-empty-sub">{{ t('settings.fixedAssetTypes.index.createOneForEveryKindOf') }}</div>
                </div>
              </td>
            </tr>
            <tr v-for="type in fixedAssetTypes.data" :key="type.id" :class="{ 'opacity-50': !type.is_active }">
              <td class="font-mono text-xs font-semibold" style="color:var(--zv-blue-text)">{{ type.code }}</td>
              <td>
                <div class="font-medium" style="color:var(--zv-text-primary)">{{ type.name_en }}</div>
                <div v-if="type.name_ar" class="text-xs" style="color:var(--zv-text-muted);direction:rtl">{{ type.name_ar }}</div>
              </td>
              <td class="text-sm" style="color:var(--zv-text-secondary)">
                {{ type.fixed_asset_account?.code }} — {{ type.fixed_asset_account?.name_en }}
              </td>
              <td class="fat-th-num">{{ type.default_useful_life_months }}</td>
              <td class="fat-th-num">{{ fmt(type.default_residual_value) }}</td>
              <td>
                <span class="zv-badge" :class="type.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="type.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ type.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>
              <td>
                <div class="flex gap-1.5">
                  <Link :href="route('settings.fixed-asset-types.edit', type.id)" class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('common.edit') }}</Link>
                  <button type="button" @click="toggle(type)" class="zv-btn zv-btn-sm" :class="type.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                    {{ type.is_active ? t('common.deactivate') : t('common.activate') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <Pagination :paginator="fixedAssetTypes" />

    </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  fixedAssetTypes: Object,
  filters:         Object,
})

const form = reactive({
  search: props.filters?.search ?? '',
  status: props.filters?.status ?? '',
})

function applyFilters() {
  router.get(route('settings.fixed-asset-types.index'), form, { preserveScroll: true, replace: true })
}

function toggle(type) {
  router.patch(route('settings.fixed-asset-types.toggle', type.id), {}, { preserveScroll: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.fat-th-num { text-align: end; }
</style>
