<!--
══════════════════════════════════════════════════════════════════
  Settings/Products/Index.vue

  Products & Services master catalogue list.
  Filterable by search, category, nature, status.
  Shows product code, name, type badges, UOM, category, status.

  @layout    AppLayout
  @route     settings.products.index
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.products.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.products.index.productsServices') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.products.index.masterCatalogueOfAllItemsYour') }}</p>
        </div>
        <Link :href="route('settings.products.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.products.index.newProduct') }}
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
            <input v-model="form.search" type="text" :placeholder="t('settings.products.index.searchByCodeNameBarcode')" @input="applyFilters" />
          </div>

          <select v-model="form.category_id" class="zv-select" style="width:180px" @change="applyFilters">
            <option value="">{{ t('settings.products.index.allCategories') }}</option>
            <template v-for="cat in categories" :key="cat.id">
              <option :value="cat.id">{{ cat.name_en }}</option>
              <option v-for="sub in cat.children" :key="sub.id" :value="sub.id">
                &nbsp;&nbsp;↳ {{ sub.name_en }}
              </option>
            </template>
          </select>

          <select v-model="form.nature" class="zv-select" style="width:150px" @change="applyFilters">
            <option value="">{{ t('settings.products.index.allTypes') }}</option>
            <option value="tradable">{{ t('settings.products.index.tradable') }}</option>
            <option value="manufactured">{{ t('settings.products.index.manufactured') }}</option>
            <option value="service">{{ t('settings.products.index.service') }}</option>
          </select>

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
              <th>{{ t('settings.products.index.code') }}</th>
              <th>{{ t('settings.products.index.productService') }}</th>
              <th>{{ t('settings.products.index.category') }}</th>
              <th>{{ t('settings.products.index.types') }}</th>
              <th>{{ t('settings.products.index.nature') }}</th>
              <th>{{ t('settings.products.index.uOM') }}</th>
              <th>{{ t('settings.products.index.stock') }}</th>
              <th>{{ t('common.status') }}</th>
              <th style="width:140px">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="products.data.length === 0">
              <td colspan="9">
                <div class="zv-empty py-10">
                  <svg class="w-10 h-10 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                  </svg>
                  <div class="zv-empty-title">{{ t('settings.products.index.noProductsFound') }}</div>
                  <div class="zv-empty-sub">{{ t('settings.products.index.createYourFirstProductOrAdjust') }}</div>
                </div>
              </td>
            </tr>
            <tr v-for="product in products.data" :key="product.id"
                :class="{ 'opacity-50': !product.is_active }">

              <td class="font-mono text-xs font-semibold" style="color:var(--zv-blue-text)">
                {{ product.code }}
              </td>

              <td>
                <div class="font-medium" style="color:var(--zv-text-primary)">{{ product.name_en }}</div>
                <div v-if="product.name_ar" class="text-xs" style="color:var(--zv-text-muted);direction:rtl">{{ product.name_ar }}</div>
              </td>

              <td class="text-sm" style="color:var(--zv-text-secondary)">
                {{ product.category?.name_en }}
              </td>

              <td>
                <div class="flex gap-1 flex-wrap">
                  <span v-for="ut in (product.usage_types ?? [])" :key="ut"
                        class="zv-badge text-xs"
                        :class="usageTypeBadge(ut)">
                    {{ t(ut) }}
                  </span>
                </div>
              </td>

              <td>
                <span v-if="product.nature" class="zv-badge text-xs" :class="natureBadge(product.nature)">
                  {{ t(product.nature) }}
                </span>
                <span v-else class="text-xs" style="color:var(--zv-text-muted)">—</span>
              </td>

              <td>
                <div class="font-mono text-sm font-semibold" style="color:var(--zv-text-primary)">
                  {{ product.primary_uom?.code ?? '—' }}
                </div>
                <div v-if="product.uom_lines_count > 1"
                     class="text-xs mt-0.5" style="color:var(--zv-text-muted)">
                  +{{ product.uom_lines_count - 1 }} {{ t('settings.products.index.alt') }}
                </div>
              </td>

              <td>
                <span v-if="product.has_stock" class="zv-badge zv-badge-green text-xs">{{ t('common.yes') }}</span>
                <span v-else class="zv-badge zv-badge-gray text-xs">{{ t('common.no') }}</span>
              </td>

              <td>
                <span class="zv-badge" :class="product.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="product.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ product.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>

              <td>
                <div class="flex gap-1.5">
                  <Link :href="route('settings.products.edit', product.id)"
                        class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('common.edit') }}</Link>
                  <button type="button" @click="toggle(product)"
                          class="zv-btn zv-btn-sm"
                          :class="product.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                    {{ product.is_active ? t('common.deactivate') : t('common.activate') }}
                  </button>
                </div>
              </td>

            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <Pagination :paginator="products" />

    </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  products:   Object,
  categories: Array,
  filters:    Object,
})

const form = reactive({
  search:      props.filters?.search      ?? '',
  category_id: props.filters?.category_id ?? '',
  nature:      props.filters?.nature      ?? '',
  status:      props.filters?.status      ?? '',
})

function applyFilters() {
  router.get(route('settings.products.index'), form, { preserveScroll: true, replace: true })
}

function toggle(product) {
  router.patch(route('settings.products.toggle', product.id), {}, { preserveScroll: true })
}

function usageTypeBadge(type) {
  return { saleable: 'zv-badge-blue', raw_material: 'zv-badge-amber', consumable: 'zv-badge-green' }[type] ?? 'zv-badge-gray'
}

function natureBadge(nature) {
  return { tradable: 'zv-badge-blue', manufactured: 'zv-badge-navy', service: 'zv-badge-teal' }[nature] ?? 'zv-badge-gray'
}
</script>