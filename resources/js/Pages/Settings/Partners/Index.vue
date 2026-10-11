<!--
══════════════════════════════════════════════════════════════════
  Settings/Partners/Index.vue

  Lists all partners for the current company.
  Filters: search (name/code/email/phone), type, status.

  Each row shows: Code, Name (EN + AR), Types as badges,
  Sector, primary contact, and status.

  Uses full Vero CFO design system (app.css):
  zv-page, zv-page-header, zv-table-wrap, zv-table,
  zv-filter-bar, zv-search-input, zv-badge, zv-btn,
  zv-pagination, zv-empty.

  @layout    AppLayout
  @route     settings.partners.index
  @props     partners: Paginator — Partner with businessSector
             filters: { search, type, is_active }
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.partners.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.partners.index.partners') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.partners.index.manageCustomersSuppliersEmployeesShareholdersAnd') }}</p>
        </div>
        <Link :href="route('settings.partners.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.partners.index.newPartner') }}
        </Link>
      </div>

      <!-- ── Table Card ───────────────────────────────────────── -->
      <div class="zv-table-wrap">

        <!-- Filter Bar -->
        <div class="zv-filter-bar">

          <!-- Search -->
          <div class="zv-search-input">
            <svg class="w-4 h-4 flex-shrink-0" style="color:var(--zv-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input v-model="filters.search" type="text"
                   :placeholder="t('settings.partners.index.searchByNameCodeEmail')"
                   @input="applyFilters" />
          </div>

          <!-- Type filter -->
          <select v-model="filters.type" @change="applyFilters" class="zv-select" style="width:auto; min-width:150px;">
            <option value="">{{ t('settings.partners.index.allTypes') }}</option>
            <option value="customer">{{ t('common.customer') }}</option>
            <option value="supplier">{{ t('settings.partners.index.supplier') }}</option>
            <option value="employee">{{ t('settings.partners.index.employee') }}</option>
            <option value="shareholder">{{ t('settings.partners.index.shareholder') }}</option>
            <option value="sister_company">{{ t('settings.partners.index.sisterCompany') }}</option>
            <option value="other">{{ t('settings.partners.index.other') }}</option>
          </select>

          <!-- Status filter -->
          <select v-model="filters.is_active" @change="applyFilters" class="zv-select" style="width:auto; min-width:130px;">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="1">{{ t('common.active') }}</option>
            <option value="0">{{ t('common.inactive') }}</option>
          </select>

          <!-- Results count -->
          <span class="ms-auto text-xs" style="color:var(--zv-text-muted)">
            {{ partners.total }} {{ t('settings.partners.index.partners2') }}
          </span>
        </div>

        <!-- Table -->
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('settings.partners.index.code') }}</th>
              <th>{{ t('common.name') }}</th>
              <th>{{ t('settings.partners.index.types') }}</th>
              <th>{{ t('settings.partners.index.sector') }}</th>
              <th>{{ t('settings.partners.index.contact') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="text-center">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="partner in partners.data" :key="partner.id">

              <!-- Code -->
              <td>
                <span class="font-mono text-xs font-medium" style="color:var(--zv-blue-text)">
                  {{ partner.code }}
                </span>
              </td>

              <!-- Name -->
              <td>
                <div class="font-medium" style="color:var(--zv-text-primary)">
                  {{ partner.name_en }}
                </div>
                <div v-if="partner.name_ar" class="text-xs mt-0.5" style="color:var(--zv-text-muted)">
                  {{ partner.name_ar }}
                </div>
              </td>

              <!-- Types -->
              <td>
                <div class="flex flex-wrap gap-1">
                  <span v-for="type in partner.types" :key="type"
                        class="zv-badge"
                        :class="typeBadgeClass(type)">
                    {{ t(typeLabel(type)) }}
                  </span>
                </div>
              </td>

              <!-- Sector -->
              <td>
                <span class="text-xs" style="color:var(--zv-text-secondary)">
                  {{ partner.business_sector?.name_en ?? '—' }}
                </span>
              </td>

              <!-- Primary Contact -->
              <td>
                <div class="text-xs" style="color:var(--zv-text-secondary)">
                  <div v-if="partner.email">{{ partner.email }}</div>
                  <div v-if="partner.phone" style="color:var(--zv-text-muted)">{{ partner.phone }}</div>
                  <span v-if="!partner.email && !partner.phone" style="color:var(--zv-text-muted)">—</span>
                </div>
              </td>

              <!-- Status -->
              <td class="text-center">
                <span class="zv-badge" :class="partner.is_active ? 'zv-badge-green' : 'zv-badge-danger'">
                  <span class="zv-status-dot" :class="partner.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ partner.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>

              <!-- Actions -->
              <td class="text-center">
                <div class="flex items-center justify-center gap-2">
                  <Link :href="route('settings.partners.edit', partner.id)"
                        class="zv-btn zv-btn-secondary zv-btn-sm">
                    {{ t('common.edit') }}
                  </Link>
                  <button type="button"
                          @click="toggle(partner)"
                          class="zv-btn zv-btn-sm"
                          :class="partner.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                    {{ partner.is_active ? t('common.deactivate') : t('common.activate') }}
                  </button>
                </div>
              </td>

            </tr>

            <!-- Empty state -->
            <tr v-if="partners.data.length === 0">
              <td colspan="7">
                <div class="zv-empty">
                  <svg class="w-10 h-10 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                  <div class="zv-empty-title">{{ t('settings.partners.index.noPartnersFound') }}</div>
                  <div class="zv-empty-sub">{{ t('settings.partners.index.tryAdjustingYourSearchOrFilters') }}</div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <Pagination :paginator="partners" />

      </div>
    </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  partners: Object,
  filters:  Object,
})

const filters = reactive({
  search:    props.filters?.search    ?? '',
  type:      props.filters?.type      ?? '',
  is_active: props.filters?.is_active ?? '',
})

function applyFilters() {
  router.get(route('settings.partners.index'), filters, {
    preserveState: true, replace: true,
  })
}

function toggle(partner) {
  router.patch(route('settings.partners.toggle', partner.id))
}

// ── Type helpers ──────────────────────────────────────────────────

const typeLabels = {
  customer:       'Customer',
  supplier:       'Supplier',
  employee:       'Employee',
  shareholder:    'Shareholder',
  sister_company: 'Sister Company',
  other:          'Other',
}

function typeLabel(type) { return typeLabels[type] ?? type }

function typeBadgeClass(type) {
  return {
    customer:       'zv-badge-blue',
    supplier:       'zv-badge-amber',
    employee:       'zv-badge-green',
    shareholder:    'zv-badge-navy',
    sister_company: 'zv-badge-gray',
    other:          'zv-badge-gray',
  }[type] ?? 'zv-badge-gray'
}
</script>
