<!--
══════════════════════════════════════════════════════════════════
  Settings/Taxes/Index.vue

  Two sections:
  1. Company Default Rates — VAT % and Debit WHT %
  2. Overrides Repeater   — specific rates per category or product

  Override logic (shown as info banner):
    Product rate → Category rate → Company default

  @layout    AppLayout
  @route     settings.taxes.index
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page" style="max-width:860px">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.taxes.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.taxes.index.taxSettings') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.taxes.index.vATAndWithholdingTaxRatesApplied') }}</p>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="zv-alert zv-alert-danger mb-4">{{ $page.props.flash.error }}</div>

      <!-- ── Info Banner ───────────────────────────────────────── -->
      <div class="zv-alert zv-alert-info mb-5 flex items-start gap-3">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--zv-blue-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div class="text-sm" style="color:var(--zv-blue-text)">
          <strong>{{ t('settings.taxes.index.rateResolutionPriority') }}</strong>
          {{ t('settings.taxes.index.productSpecificRateCategoryRateCompany') }}
          {{ t('settings.taxes.index.creditWHTIsEnteredDirectlyOn') }}
          {{ t('settings.taxes.index.corporateIncomeTaxIsHandledAt') }}
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           SECTION 1 — COMPANY DEFAULT RATES
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card mb-5">
        <div class="zv-card-header">
          <div class="form-section-icon me-3" style="background:var(--zv-nav-bg)">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
          </div>
          <h2 class="zv-card-title">{{ t('settings.taxes.index.companyDefaultRates') }}</h2>
          <div class="ms-auto text-xs" style="color:var(--zv-text-muted)">
            {{ t('settings.taxes.index.appliedToAllTransactionsUnlessOverridden') }}
          </div>
        </div>
        <div class="zv-card-body">
          <form @submit.prevent="saveSettings">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

              <!-- VAT Rate -->
              <div class="tax-rate-card">
                <div class="tax-rate-header">
                  <div class="tax-rate-icon" style="background:#1D4ED8">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z"/>
                    </svg>
                  </div>
                  <div>
                    <div class="tax-rate-title">{{ t('settings.taxes.index.vATRate') }}</div>
                    <div class="tax-rate-subtitle">{{ t('settings.taxes.index.text') }}</div>
                  </div>
                </div>
                <div class="tax-rate-input-wrap">
                  <input v-model="settingsForm.vat_rate"
                         type="number" step="0.01" min="0" max="100"
                         class="zv-input font-mono text-end text-2xl font-bold"
                         style="font-size:1.5rem" />
                  <span class="tax-rate-pct">%</span>
                </div>
                <p class="text-xs mt-2" style="color:var(--zv-text-muted)">
                  {{ t('settings.taxes.index.egyptianStandardVAT14ZeroRated') }}
                </p>
              </div>

              <!-- Debit WHT Rate -->
              <div class="tax-rate-card">
                <div class="tax-rate-header">
                  <div class="tax-rate-icon" style="background:#B45309">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                  </div>
                  <div>
                    <div class="tax-rate-title">{{ t('settings.taxes.index.debitWHTRate') }}</div>
                    <div class="tax-rate-subtitle">{{ t('settings.taxes.index.text2') }}</div>
                  </div>
                </div>
                <div class="tax-rate-input-wrap">
                  <input v-model="settingsForm.debit_wht_rate"
                         type="number" step="0.01" min="0" max="100"
                         class="zv-input font-mono text-end text-2xl font-bold"
                         style="font-size:1.5rem" />
                  <span class="tax-rate-pct">%</span>
                </div>
                <p class="text-xs mt-2" style="color:var(--zv-text-muted)">
                  {{ t('settings.taxes.index.withheldByCustomersFromPaymentsPosts') }}
                </p>
              </div>

            </div>

            <div class="flex justify-end mt-4">
              <button type="submit" :disabled="savingSettings" class="zv-btn zv-btn-primary">
                <div v-if="savingSettings" class="zv-spinner w-4 h-4 me-2"></div>
                {{ savingSettings ? t('common.saving') : t('settings.taxes.index.saveDefaultRates') }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           SECTION 2 — OVERRIDES REPEATER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card">
        <div class="zv-card-header">
          <div class="form-section-icon me-3" style="background:#047857">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
          </div>
          <h2 class="zv-card-title">{{ t('settings.taxes.index.specificRateOverrides') }}</h2>
          <div class="ms-auto text-xs" style="color:var(--zv-text-muted)">
            {{ t('settings.taxes.index.overrideDefaultRatesForSpecificProducts') }}
          </div>
        </div>

        <!-- Add override form -->
        <div class="p-4 border-b" style="border-color:var(--zv-border);background:var(--zv-bg-surface)">
          <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-start">

            <!-- Category -->
            <div>
              <label class="zv-label">{{ t('settings.taxes.index.category') }}</label>
              <select v-model="overrideForm.category_id" class="zv-select"
                      @change="overrideForm.product_id = null">
                <option :value="null">— {{ t('settings.taxes.index.allCategories') }} —</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                  {{ cat.level === 2 ? '↳ ' : '' }}{{ cat.name_en }}
                </option>
              </select>
            </div>

            <!-- Product (filtered by category if selected) -->
            <div>
              <label class="zv-label">{{ t('settings.taxes.index.productItem') }}</label>
              <select v-model="overrideForm.product_id" class="zv-select"
                      :disabled="!overrideForm.category_id && filteredProducts.length === 0">
                <option :value="null">— {{ t('settings.taxes.index.allInCategory') }} —</option>
                <option v-for="p in filteredProducts" :key="p.id" :value="p.id">
                  {{ p.code }} — {{ p.name_en }}
                </option>
              </select>
            </div>

            <!-- VAT Rate -->
            <div>
              <label class="zv-label">{{ t('settings.taxes.index.vATRate2') }}</label>
              <div class="zv-input-group">
                <input v-model="overrideForm.vat_rate" type="number" step="0.01"
                       min="0" max="100" class="zv-input font-mono text-end"
                       placeholder="—" />
                <span class="zv-input-suffix">%</span>
              </div>
            </div>

            <!-- Debit WHT Rate -->
            <div>
              <label class="zv-label">{{ t('settings.taxes.index.debitWHTRate2') }}</label>
              <div class="zv-input-group">
                <input v-model="overrideForm.debit_wht_rate" type="number" step="0.01"
                       min="0" max="100" class="zv-input font-mono text-end"
                       placeholder="—" />
                <span class="zv-input-suffix">%</span>
              </div>
            </div>

          </div>

          <div class="flex items-center justify-between mt-3">
            <p class="text-xs" style="color:var(--zv-text-muted)">
              {{ t('settings.taxes.index.leaveARateBlankToInherit') }}
            </p>
            <button type="button" @click="saveOverride"
                    :disabled="!canSaveOverride || savingOverride"
                    class="zv-btn zv-btn-primary zv-btn-sm">
              <div v-if="savingOverride" class="zv-spinner w-3.5 h-3.5 me-1.5"></div>
              {{ t('settings.taxes.index.addOverride') }}
            </button>
          </div>
        </div>

        <!-- Overrides table -->
        <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('settings.taxes.index.category') }}</th>
                <th>{{ t('settings.taxes.index.product') }}</th>
                <th class="text-center">{{ t('settings.taxes.index.vATRate') }}</th>
                <th class="text-center">{{ t('settings.taxes.index.debitWHT') }}</th>
                <th class="text-center">{{ t('settings.taxes.index.level') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="overrides.length === 0">
                <td colspan="6">
                  <div class="zv-empty py-8">
                    <div class="zv-empty-title">{{ t('settings.taxes.index.noOverridesYet') }}</div>
                    <div class="zv-empty-sub">{{ t('settings.taxes.index.allTransactionsUseTheCompanyDefault') }}</div>
                  </div>
                </td>
              </tr>
              <tr v-for="ov in overrides" :key="ov.id">
                <td class="text-sm">
                  {{ ov.category?.name_en ?? (ov.product ? ov.product.category?.name_en : '—') }}
                </td>
                <td>
                  <span v-if="ov.product" class="text-sm">
                    <span class="font-mono text-xs me-1" style="color:var(--zv-blue-text)">{{ ov.product?.code }}</span>
                    {{ ov.product?.name_en }}
                  </span>
                  <span v-else class="text-xs" style="color:var(--zv-text-muted)">
                    {{ t('settings.taxes.index.allProductsInCategory') }}
                  </span>
                </td>
                <td class="text-center">
                  <span v-if="ov.vat_rate !== null"
                        class="zv-badge zv-badge-blue font-mono">
                    {{ ov.vat_rate }}%
                  </span>
                  <span v-else class="text-xs" style="color:var(--zv-text-muted)">{{ t('settings.taxes.index.inherit') }}</span>
                </td>
                <td class="text-center">
                  <span v-if="ov.debit_wht_rate !== null"
                        class="zv-badge zv-badge-amber font-mono">
                    {{ ov.debit_wht_rate }}%
                  </span>
                  <span v-else class="text-xs" style="color:var(--zv-text-muted)">{{ t('settings.taxes.index.inherit') }}</span>
                </td>
                <td class="text-center">
                  <span class="zv-badge text-xs"
                        :class="ov.product_id ? 'zv-badge-green' : 'zv-badge-blue'">
                    {{ ov.product_id ? t('settings.taxes.index.product') : t('settings.taxes.index.category') }}
                  </span>
                </td>
                <td>
                  <button type="button" @click="deleteOverride(ov.id)"
                          class="zv-btn zv-btn-danger zv-btn-sm">
                    {{ t('settings.taxes.index.remove') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  settings:   Object,
  overrides:  Array,
  categories: Array,
  products:   Array,
})

// ── Default rates form ────────────────────────────────────────────
const settingsForm = reactive({
  vat_rate:       props.settings?.vat_rate       ?? 0,
  debit_wht_rate: props.settings?.debit_wht_rate ?? 0,
})
const savingSettings = ref(false)

function saveSettings() {
  savingSettings.value = true
  router.post(route('settings.taxes.settings'), settingsForm, {
    preserveScroll: true,
    onFinish: () => { savingSettings.value = false },
  })
}

// ── Override form ─────────────────────────────────────────────────
const overrideForm = reactive({
  category_id:    null,
  product_id:     null,
  vat_rate:       '',
  debit_wht_rate: '',
})
const savingOverride = ref(false)

// Filter products by selected category
const filteredProducts = computed(() => {
  if (!overrideForm.category_id) return props.products ?? []
  return (props.products ?? []).filter(p => p.category_id == overrideForm.category_id)
})

// Can save if at least a category is selected and at least one rate is set
const canSaveOverride = computed(() => {
  const hasTarget = !!overrideForm.category_id || !!overrideForm.product_id
  const hasRate   = overrideForm.vat_rate !== '' || overrideForm.debit_wht_rate !== ''
  return hasTarget && hasRate
})

function saveOverride() {
  savingOverride.value = true
  // The server accepts a category OR a product, never both. A product-level
  // override must therefore be sent without the category used only to filter the list.
  const payload = { ...overrideForm, category_id: overrideForm.product_id ? null : overrideForm.category_id }
  router.post(route('settings.taxes.overrides.save'), payload, {
    preserveScroll: true,
    onSuccess: (page) => {
      if (page?.props?.flash?.error || Object.keys(page?.props?.errors ?? {}).length) return
      Object.assign(overrideForm, { category_id: null, product_id: null, vat_rate: '', debit_wht_rate: '' })
    },
    onFinish: () => { savingOverride.value = false },
  })
}

function deleteOverride(id) {
  router.delete(route('settings.taxes.overrides.delete', id), { preserveScroll: true })
}
</script>

<style scoped>
.tax-rate-card {
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  padding: 1.25rem;
}
.tax-rate-header {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  margin-bottom: 1rem;
}
.tax-rate-icon {
  width: 40px; height: 40px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.tax-rate-title {
  font-weight: 700;
  font-size: 0.9375rem;
  color: var(--zv-text-primary);
}
.tax-rate-subtitle {
  font-size: 0.75rem;
  color: var(--zv-text-muted);
  direction: rtl;
  text-align: start;
}
.tax-rate-input-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.tax-rate-pct {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--zv-text-secondary);
  flex-shrink: 0;
}
.form-section-icon {
  width: 32px; height: 32px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
</style>
