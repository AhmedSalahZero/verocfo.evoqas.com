<!--
══════════════════════════════════════════════════════════════════
  Settings/PaymentTerms/Index.vue

  Lists all payment terms available to this company:
  system defaults (locked — padlock badge) and company-defined
  custom terms (editable + toggleable).

  Uses the Vero CFO design system (app.css) throughout:
  zv-page, zv-page-header, zv-card, zv-table-wrap, zv-table,
  zv-badge, zv-btn, zv-filter-bar, zv-search-input, zv-pagination.

  System terms show a locked badge and no actions.
  Custom terms show Edit and Activate/Deactivate actions.

  @layout    AppLayout
  @route     settings.payment-terms.index
  @props     terms: Paginator — PaymentTerm with lines loaded
             filters: { search }
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.paymentTerms.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.paymentTerms.index.paymentTerms') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.paymentTerms.index.defineCollectionAndPaymentSchedulesUsed') }}</p>
        </div>
        <Link :href="route('settings.payment-terms.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.paymentTerms.index.newTerm') }}
        </Link>
      </div>

      <!-- ── Table Card ───────────────────────────────────────── -->
      <div class="zv-table-wrap">

        <!-- Filter Bar -->
        <div class="zv-filter-bar">
          <div class="zv-search-input">
            <svg class="w-4 h-4 text-[var(--zv-text-muted)] flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input v-model="search" type="text"
                   :placeholder="t('settings.paymentTerms.index.searchTerms')"
                   @input="onSearch" />
          </div>
        </div>

        <!-- Table -->
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('settings.paymentTerms.index.code') }}</th>
              <th>{{ t('common.name') }}</th>
              <th>{{ t('settings.paymentTerms.index.schedule') }}</th>
              <th class="text-center">{{ t('common.type') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="text-center">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="term in terms.data" :key="term.id">

              <!-- Code -->
              <td>
                <span class="font-mono text-[var(--zv-blue-text)] font-medium text-xs">
                  {{ term.code }}
                </span>
              </td>

              <!-- Name -->
              <td>
                <div class="font-medium text-[var(--zv-text-primary)]">
                  {{ term.name_ar || term.name_en }}
                </div>
                <div v-if="term.name_ar" class="text-xs text-[var(--zv-text-muted)] mt-0.5">
                  {{ term.name_en }}
                </div>
              </td>

              <!-- Schedule lines -->
              <td>
                <div v-for="(line, i) in term.lines" :key="i"
                     class="text-xs text-[var(--zv-text-secondary)] leading-5">
                  <span class="font-semibold text-[var(--zv-num)]">{{ line.percentage }}%</span>
                  —
                  {{ line.due_days === 0 ? t('settings.paymentTerms.index.immediately') : line.due_days + ' ' + t('common.days') }}
                  —
                  <span class="text-[var(--zv-text-muted)]">{{ referenceLabel(line.reference_point) }}</span>
                  <span v-if="line.label" class="text-[var(--zv-blue-text)] ms-1">({{ line.label }})</span>
                </div>
              </td>

              <!-- Type -->
              <td class="text-center">
                <span v-if="term.is_system" class="zv-badge zv-badge-gray">
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                  {{ t('settings.paymentTerms.index.system') }}
                </span>
                <span v-else class="zv-badge zv-badge-blue">
                  {{ t('settings.paymentTerms.index.custom') }}
                </span>
              </td>

              <!-- Status -->
              <td class="text-center">
                <span :class="term.is_active ? 'zv-badge zv-badge-green' : 'zv-badge zv-badge-danger'">
                  <span class="zv-status-dot" :class="term.is_active ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                  {{ term.is_active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>

              <!-- Actions -->
              <td class="text-center">
                <div v-if="!term.is_system" class="flex items-center justify-center gap-2">
                  <Link :href="route('settings.payment-terms.edit', term.id)"
                        class="zv-btn zv-btn-secondary zv-btn-sm">
                    {{ t('common.edit') }}
                  </Link>
                  <button type="button"
                          @click="toggle(term)"
                          :class="term.is_active ? 'zv-btn zv-btn-sm zv-btn-danger' : 'zv-btn zv-btn-sm zv-btn-success'">
                    {{ term.is_active ? t('common.deactivate') : t('common.activate') }}
                  </button>
                </div>
                <span v-else class="text-xs text-[var(--zv-text-muted)]">—</span>
              </td>

            </tr>

            <!-- Empty state -->
            <tr v-if="terms.data.length === 0">
              <td colspan="6">
                <div class="zv-empty">
                  <svg class="w-10 h-10 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                  </svg>
                  <div class="zv-empty-title">{{ t('settings.paymentTerms.index.noPaymentTermsFound') }}</div>
                  <div class="zv-empty-sub">{{ t('settings.paymentTerms.index.tryAdjustingYourSearchOrFilters') }}</div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <Pagination :paginator="terms" />

      </div>
    </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  terms:   Object,
  filters: Object,
})

const search = ref(props.filters?.search ?? '')

const referenceLabels = {
  invoice_date:  'Invoice Date',
  so_date:       'SO Date',
  po_date:       'PO Date',
  contract_date: 'Contract Date',
}

function referenceLabel(key) {
  return referenceLabels[key] ?? key
}

function onSearch() {
  router.get(route('settings.payment-terms.index'), { search: search.value }, {
    preserveState: true, replace: true,
  })
}

function toggle(term) {
  router.patch(route('settings.payment-terms.toggle', term.id))
}
</script>