<!--
══════════════════════════════════════════════════════════════════
  Settings/Currencies/Index.vue

  Two-tab page:
  Tab 1 — Currencies: list all system currencies with company
          activation toggle. Functional currency shown with lock.
  Tab 2 — Exchange Rates: select currency, enter rate + date,
          view full rate history per currency. Also supports bulk
          Excel/CSV import — upload, review a per-row status table
          (valid / duplicate / skipped / error), then confirm to
          commit only the valid rows. Columns are matched by header
          NAME on the backend (CurrencyService::parseRatesExcel()),
          not fixed position, so both the downloadable 3-column
          template and the fuller 5-column Export Excel file import
          correctly from the same parser.

  Design: premium two-panel layout.
  Currencies tab: grid of currency cards.
  Rates tab: rate entry form + history table side by side.
  Import review renders in a zv-modal-lg (global classes — no
  local CSS added for this feature). "Download Template" link
  sits next to Export/Import Excel — always visible, no reason
  to make anyone guess the column layout.

  @layout    AppLayout
  @route     settings.currencies.index
  @props     currencies: Currency[] with is_activated, is_functional,
             latest_rate
             latest_rates: ExchangeRate[] with currency
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.currencies.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.currencies.index.currenciesExchangeRates') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.currencies.index.activateCurrenciesForYourCompanyAnd') }}</p>
        </div>
      </div>

      <!-- ── Flash Messages ────────────────────────────────────── -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="zv-alert zv-alert-danger mb-4">
        {{ $page.props.flash.error }}
      </div>

      <!-- ── Tabs ──────────────────────────────────────────────── -->
      <div class="curr-tabs mb-5">
        <button type="button" class="curr-tab"
                :class="{ 'curr-tab--active': activeTab === 'currencies' }"
                @click="activeTab = 'currencies'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          {{ t('settings.currencies.index.currencies') }}
          <span class="curr-tab-count">{{ activatedCount }}</span>
        </button>
        <button type="button" class="curr-tab"
                :class="{ 'curr-tab--active': activeTab === 'rates' }"
                @click="activeTab = 'rates'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
          </svg>
          {{ t('settings.currencies.index.exchangeRates') }}
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 1 — CURRENCIES
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'currencies'">

        <!-- Functional Currency Banner -->
        <div v-if="functional" class="functional-banner mb-5">
          <div class="functional-banner-icon">
            {{ functional.symbol || functional.code }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="functional-banner-label">{{ t('settings.currencies.index.functionalCurrency') }}</div>
            <div class="functional-banner-name">
              <span class="font-mono font-bold">{{ functional.code }}</span>
              <span class="mx-2">—</span>
              <span>{{ functional.name_en }}</span>
              <span v-if="functional.name_ar" class="ms-2" style="color:#ffffff">{{ functional.name_ar }}</span>
            </div>
            <div class="functional-banner-note">
              {{ t('settings.currencies.index.thisIsYourCompanySBaseCurrency') }}
            </div>
          </div>
          <div class="functional-banner-rate">
            <div class="text-2xl font-bold font-mono" style="color:#000000">1.0000</div>
            <div class="text-s  mt-0.5">{{ t('settings.currencies.index.fixedRate') }}</div>
          </div>
        </div>

        <!-- Info banner -->
        <div class="zv-alert zv-alert-info mb-5 flex items-start gap-3">
          <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:#ffffff" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span class="text-m" style="color:#ffffff">
            {{ t('settings.currencies.index.activateTheForeignCurrenciesYourCompany') }}
          </span>
        </div>

        <!-- Currency Cards Grid — foreign currencies only -->
        <div class="curr-grid">
          <div v-for="currency in currencies" :key="currency.id"
               class="curr-card"
               :class="{
                 'curr-card--active':   currency.is_company_active,
                 'curr-card--inactive': currency.is_activated && !currency.is_company_active,
               }">

            <!-- Card Header -->
            <div class="curr-card-header">
              <div class="curr-symbol">{{ currency.symbol || currency.code }}</div>
              <div class="flex-1 min-w-0">
                <div class="curr-code">{{ currency.code }}</div>
                <div class="curr-name">{{ currency.name_en }}</div>
                <div v-if="currency.name_ar" class="curr-name-ar">{{ currency.name_ar }}</div>
              </div>
              <div class="flex flex-col items-end gap-1">
                <span v-if="currency.is_company_active" class="zv-badge zv-badge-green">
                  <span class="zv-status-dot zv-status-dot-green"></span>{{ t('common.active') }}
                </span>
                <span v-else-if="currency.is_activated" class="zv-badge zv-badge-danger">
                  {{ t('common.inactive') }}
                </span>
                <span v-else class="zv-badge zv-badge-gray">
                  {{ t('settings.currencies.index.notActivated') }}
                </span>
              </div>
            </div>

            <!-- Latest Rate -->
            <div class="curr-card-rate">
              <template v-if="currency.latest_rate">
                <span class="curr-rate-val zv-num-blue">{{ currency.latest_rate.rate }}</span>
                <span class="curr-rate-label">{{ formatDate(currency.latest_rate.rate_date) }}</span>
              </template>
              <template v-else>
                <span class="curr-rate-val" style="color:var(--zv-text-muted)">—</span>
                <span class="curr-rate-label">{{ t('settings.currencies.index.noRateEnteredYet') }}</span>
              </template>
            </div>

            <!-- Actions -->
            <div class="curr-card-footer">
              <button type="button"
                      class="zv-btn zv-btn-sm w-full"
                      :class="currency.is_company_active ? 'zv-btn-danger' : 'zv-btn-primary'"
                      @click="toggleCurrency(currency)">
                {{ currency.is_company_active ? t('common.deactivate') : t('common.activate') }}
              </button>
            </div>

          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 2 — EXCHANGE RATES
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'rates'">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

          <!-- ── Rate Entry Form ─────────────────────────────── -->
          <div class="lg:col-span-1">
            <div class="zv-card">
              <div class="zv-card-header">
                <h2 class="zv-card-title">{{ t('settings.currencies.index.addExchangeRate') }}</h2>
              </div>
              <div class="zv-card-body">
                <form @submit.prevent="submitRate">

                  <!-- Currency selector -->
                  <div class="mb-4">
                    <label class="zv-label zv-label-required">{{ t('settings.currencies.index.currency') }}</label>
                    <select v-model="rateForm.currency_id" class="zv-select"
                            @change="onCurrencyChange">
                      <option :value="null">— {{ t('settings.currencies.index.selectCurrency') }} —</option>
                      <option v-for="c in activeCurrencies" :key="c.id" :value="c.id">
                        {{ c.code }} — {{ c.name_en }}
                      </option>
                    </select>
                    <p v-if="rateErrors.currency_id" class="zv-field-error">{{ rateErrors.currency_id }}</p>
                  </div>

                  <!-- Rate -->
                  <div class="mb-4">
                    <label class="zv-label zv-label-required">
                      {{ t('settings.currencies.index.rate1ForeignFunctional') }}
                    </label>
                    <div class="zv-input-group">
                      <input v-model="rateForm.rate"
                             type="number" step="0.000001" min="0.000001"
                             class="zv-input zv-input-has-suffix text-end font-mono"
                             :class="{ 'zv-input-error': rateErrors.rate }"
                             placeholder="0.000000" />
                      <span class="zv-input-suffix text-xs font-semibold">
                        {{ functionalCurrencyCode }}
                      </span>
                    </div>
                    <p v-if="rateErrors.rate" class="zv-field-error">{{ rateErrors.rate }}</p>
                    <p class="field-hint">
                      {{ t('settings.currencies.index.eGIf1USD50') }}
                    </p>
                  </div>

                  <!-- Effective Date -->
                  <div class="mb-5">
                    <label class="zv-label zv-label-required">{{ t('settings.currencies.index.effectiveDate') }}</label>
                    <input v-model="rateForm.rate_date"
                           type="date"
                           class="zv-input"
                           :class="{ 'zv-input-error': rateErrors.rate_date }" />
                    <p v-if="rateErrors.rate_date" class="zv-field-error">{{ rateErrors.rate_date }}</p>
                  </div>

                  <button type="submit"
                          :disabled="submitting || !rateForm.currency_id || !rateForm.rate || !rateForm.rate_date"
                          class="zv-btn zv-btn-primary w-full">
                    <div v-if="submitting" class="zv-spinner w-4 h-4 me-2"></div>
                    {{ submitting ? t('common.saving') : t('settings.currencies.index.saveRate') }}
                  </button>

                </form>
              </div>
            </div>
          </div>

          <!-- ── Rate History ────────────────────────────────── -->
          <div class="lg:col-span-2">
            <div class="zv-card">
              <div class="zv-card-header">
                <h2 class="zv-card-title">
                  {{ t('settings.currencies.index.rateHistory') }}
                  <span v-if="selectedCurrencyCode" class="zv-badge zv-badge-blue ms-2">
                    {{ selectedCurrencyCode }}
                  </span>
                  <span v-else class="zv-badge zv-badge-gray ms-2">{{ t('settings.currencies.index.allCurrenciesLatestRate') }}</span>
                </h2>
                <div class="ms-auto flex items-center gap-2">
                  <select v-model="historyFilter" class="zv-select" style="width:auto;min-width:160px"
                          @change="onCurrencyFilterChange">
                    <option value="">{{ t('settings.currencies.index.allCurrencies') }}</option>
                    <option v-for="c in activeCurrencies" :key="c.id" :value="c.id">
                      {{ c.code }} — {{ c.name_en }}
                    </option>
                  </select>
                  <a :href="route('settings.currencies.export')"
                     class="zv-btn zv-btn-secondary zv-btn-sm" target="_blank">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    {{ t('settings.currencies.index.exportExcel') }}
                  </a>
                  <a :href="route('settings.currencies.rates.template')"
                     class="zv-btn zv-btn-ghost zv-btn-sm" target="_blank">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    {{ t('settings.currencies.index.downloadTemplate') }}
                  </a>
                  <label class="zv-btn zv-btn-secondary zv-btn-sm" style="cursor:pointer">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    {{ t('settings.currencies.index.importExcel') }}
                    <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onImportFileChange"/>
                  </label>
                </div>
              </div>

              <!-- Date filters — only shown for specific currency -->
              <div v-if="isSpecificCurrency" class="zv-filter-bar" style="border-top:1px solid var(--zv-border);border-radius:0;border-left:none;border-right:none">
                <div class="flex items-center gap-3 flex-wrap">
                  <span class="text-xs font-medium" style="color:var(--zv-text-muted)">{{ t('settings.currencies.index.dateRange') }}</span>
                  <div class="flex items-center gap-2">
                    <label class="zv-label mb-0 text-xs">{{ t('settings.currencies.index.from') }}</label>
                    <input v-model="dateFrom" type="date" class="zv-input" style="width:150px"
                           @change="onDateFilterChange" />
                  </div>
                  <div class="flex items-center gap-2">
                    <label class="zv-label mb-0 text-xs">{{ t('settings.currencies.index.to') }}</label>
                    <input v-model="dateTo" type="date" class="zv-input" style="width:150px"
                           @change="onDateFilterChange" />
                  </div>
                  <button v-if="dateFrom || dateTo" type="button"
                          @click="dateFrom = ''; dateTo = ''; onDateFilterChange()"
                          class="zv-btn zv-btn-ghost zv-btn-sm" style="color:var(--zv-text-muted)">
                    {{ t('settings.currencies.index.clear') }}
                  </button>
                  <span v-if="historyLoading" class="zv-spinner w-4 h-4"></span>
                </div>
              </div>

              <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
                <table class="zv-table">
                  <thead>
                    <tr>
                      <th>{{ t('settings.currencies.index.currency') }}</th>
                      <th class="text-center">{{ t('common.rate') }}</th>
                      <th>{{ t('settings.currencies.index.effectiveDate') }}</th>
                      <th>{{ t('settings.currencies.index.enteredBy') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="rate in displayedRates" :key="rate.id">
                      <td>
                        <span class="font-mono font-semibold text-xs" style="color:var(--zv-blue-text)">
                          {{ rate.currency_code }}
                        </span>
                        <span class="text-xs ms-2" style="color:var(--zv-text-secondary)">
                          {{ rate.currency_name_en }}
                        </span>
                      </td>
                      <td class="text-center font-mono zv-num-blue font-semibold text-sm">
                        {{ rate.rate }}
                      </td>
                      <td class="text-sm" style="color:var(--zv-text-secondary)">
                        {{ formatDate(rate.rate_date) }}
                      </td>
                      <td class="text-sm" style="color:var(--zv-text-muted)">
                        {{ rate.entered_by_name ?? '—' }}
                      </td>
                    </tr>

                    <tr v-if="displayedRates.length === 0">
                      <td colspan="4">
                        <div class="zv-empty py-8">
                          <svg class="w-8 h-8 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                          </svg>
                          <div class="zv-empty-title">{{ t('settings.currencies.index.noRatesEnteredYet') }}</div>
                          <div class="zv-empty-sub">{{ t('settings.currencies.index.addTheFirstRateUsingThe') }}</div>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>

    <!-- ══════════════════════════════════════════════════════
         IMPORT EXCHANGE RATES — PREVIEW & CONFIRM MODAL
    ══════════════════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition name="scale">
        <div v-if="showImportModal" class="zv-modal-backdrop" @click.self="closeImportModal">
          <div class="zv-modal zv-modal-lg">
            <div class="zv-modal-header">
              <h3 class="zv-modal-title">{{ t('settings.currencies.index.importExchangeRates') }}</h3>
              <button type="button" @click="closeImportModal" class="zv-modal-close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <div class="zv-modal-body">
              <p v-if="importError" class="zv-field-error mb-3">{{ importError }}</p>

              <p class="field-hint mb-3">
                {{ t('settings.currencies.index.anyColumnOrderWorksAsLong') }}
                <a :href="route('settings.currencies.rates.template')" target="_blank" class="font-semibold" style="color:var(--zv-blue-text)">{{ t('settings.currencies.index.downloadTheTemplate') }}</a>.
              </p>

              <div v-if="importRows.length" class="zv-table-wrap" style="max-height:360px;overflow-y:auto">
                <table class="zv-table">
                  <thead>
                    <tr>
                      <th>{{ t('settings.currencies.index.currency') }}</th>
                      <th class="text-end">{{ t('common.rate') }}</th>
                      <th>{{ t('settings.currencies.index.effectiveDate') }}</th>
                      <th>{{ t('common.status') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in importRows" :key="idx">
                      <td class="font-semibold">{{ row.currency_code || '—' }}</td>
                      <td class="text-end font-mono">{{ row.rate ?? '—' }}</td>
                      <td>{{ row.rate_date ? formatDate(row.rate_date) : '—' }}</td>
                      <td>
                        <span v-if="row.status === 'valid'" class="zv-badge zv-badge-green">{{ t('settings.currencies.index.readyToImport') }}</span>
                        <span v-else-if="row.status === 'duplicate'" class="zv-badge zv-badge-amber">{{ t('settings.currencies.index.duplicateWillBeSkipped') }}</span>
                        <span v-else-if="row.status === 'skipped'" class="zv-badge zv-badge-gray">{{ row.message }}</span>
                        <span v-else class="zv-badge zv-badge-danger">{{ row.message }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p v-if="importRows.length" class="field-hint mt-3">
                {{ validImportRowsCount }} {{ t('settings.currencies.index.validRowSWillBeImported') }}
              </p>
            </div>
            <div class="zv-modal-footer">
              <button type="button" @click="closeImportModal" class="zv-btn zv-btn-ghost">
                {{ t('common.cancel') }}
              </button>
              <button type="button"
                      :disabled="!validImportRowsCount || importing"
                      @click="confirmImport"
                      class="zv-btn zv-btn-primary">
                <div v-if="importing" class="zv-spinner w-4 h-4 me-2"></div>
                <span v-if="importing">{{ t('settings.currencies.index.importing') }}</span>
                <span v-else>{{ t('common.import') }} {{ validImportRowsCount }} {{ t('settings.currencies.index.rowS') }}</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref, computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  functional:           Object,
  currencies:           Array,
  latest_per_currency:  Array,  // one row per currency — "All currencies" view
})

// ── Tabs ──────────────────────────────────────────────────────────
const activeTab = ref('currencies')

// ── Currencies Tab ────────────────────────────────────────────────
const activatedCount = computed(() =>
  (props.currencies ?? []).filter(c => c.is_company_active).length
)

const functionalCurrencyCode = computed(() =>
  props.functional?.code ?? 'EGP'
)

function toggleCurrency(currency) {
  router.post(route('settings.currencies.toggle', currency.id), {}, {
    preserveScroll: true,
  })
}

function formatDate(date) {
  if (!date) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
  }).format(new Date(date))
}

// ── Exchange Rates Tab ────────────────────────────────────────────
const activeCurrencies = computed(() =>
  (props.currencies ?? []).filter(c => c.is_company_active)
)

const rateForm = reactive({
  currency_id: null,
  rate:        '',
  rate_date:   todayLocal(),
})

const rateErrors     = reactive({})
const submitting     = ref(false)

// History filter state
const historyFilter  = ref('')   // '' = all currencies, number id = specific
const dateFrom       = ref('')
const dateTo         = ref('')
const historyRows    = ref([])
const historyLoading = ref(false)

const isSpecificCurrency = computed(() => !!historyFilter.value)

const selectedCurrencyCode = computed(() =>
  activeCurrencies.value.find(c => c.id == historyFilter.value)?.code ?? ''
)

// All currencies → latest_per_currency prop (one row per currency)
// Specific currency → historyRows loaded via AJAX (all rates for that currency)
const displayedRates = computed(() => {
  if (!historyFilter.value) return props.latest_per_currency ?? []
  return historyRows.value
})

// Round 7: a slow reply for an earlier currency must never overwrite the table
// of the currency chosen afterwards — only the newest request may write.
let historyTicket = 0
async function loadHistory() {
  const ticket = ++historyTicket
  if (!historyFilter.value) { historyRows.value = []; historyLoading.value = false; return }
  historyLoading.value = true
  try {
    const params = new URLSearchParams({ currency_id: historyFilter.value })
    if (dateFrom.value) params.append('date_from', dateFrom.value)
    if (dateTo.value)   params.append('date_to',   dateTo.value)
    const res = await window.axios.get(route('settings.currencies.history') + '?' + params)
    if (ticket !== historyTicket) return
    historyRows.value = res.data
  } catch { if (ticket === historyTicket) historyRows.value = [] }
  finally { if (ticket === historyTicket) historyLoading.value = false }
}

function onCurrencyFilterChange() {
  dateFrom.value    = ''
  dateTo.value      = ''
  historyRows.value = []
  if (historyFilter.value) loadHistory()
}

function onDateFilterChange() {
  if (historyFilter.value) loadHistory()
}

function submitRate() {
  submitting.value = true
  Object.keys(rateErrors).forEach(k => delete rateErrors[k])   // clear stale messages
  router.post(route('settings.currencies.rate'), rateForm, {
    preserveScroll: true,
    onSuccess: (page) => {
      // The server refused (e.g. a rate already exists for that date): keep what was typed.
      if (page?.props?.flash?.error) return
      rateForm.rate     = ''
      rateForm.rate_date = todayLocal()
      Object.keys(rateErrors).forEach(k => delete rateErrors[k])
      if (historyFilter.value == rateForm.currency_id) loadHistory()
    },
    onError:  (errors) => { Object.assign(rateErrors, errors) },
    onFinish: () => { submitting.value = false },
  })
}

// ── Exchange Rates — Excel Import ──────────────────────────────────
//
// Same two-step "parse then confirm" pattern as Leasing's cheque
// import (ContractForm.vue): upload → server parses and classifies
// every row → user reviews the table → confirm commits only the
// valid rows. Uses fetch() with an explicit CSRF header rather than
// window.axios, matching ContractForm.vue's onExcelUpload — axios
// isn't set up with the CSRF meta tag reliably for every page and
// this avoids relying on that.
const showImportModal   = ref(false)
const importRows        = ref([])
const importError       = ref('')
const importing         = ref(false)

const validImportRowsCount = computed(() =>
  importRows.value.filter(r => r.status === 'valid').length
)

async function onImportFileChange(e) {
  const file = e.target.files[0]
  if (!file) return

  importError.value = ''
  importRows.value  = []
  showImportModal.value = true

  const formData = new FormData()
  formData.append('file', file)

  try {
    const res = await fetch(route('settings.currencies.rates.import-preview'), {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
    })
    if (!res.ok) {
      if (res.status === 419) {
        throw new Error('Your session has expired — please refresh the page and try again.')
      }
      if (res.status === 422) {
        // Validation-style error with a specific, already-readable
        // message from the backend (e.g. missing required columns) —
        // show it as-is rather than the generic upload-failed fallback.
        const errData = await res.json().catch(() => null)
        throw new Error(errData?.message || 'Upload failed')
      }
      throw new Error('Upload failed')
    }
    const data = await res.json()
    importRows.value = data.rows ?? []

    if (importRows.value.length === 0) {
      importError.value = t('settings.currencies.index.noReadableRowsWereFoundIn')
    }
  } catch (err) {
    importError.value = err.message === 'Upload failed' || !err.message
      ? t('settings.currencies.index.couldNotReadThisFilePlease')
      : err.message
  } finally {
    e.target.value = ''
  }
}

function closeImportModal() {
  showImportModal.value = false
  importRows.value  = []
  importError.value = ''
}

function confirmImport() {
  const rows = importRows.value
    .filter(r => r.status === 'valid')
    .map(r => ({ currency_id: r.currency_id, rate: r.rate, rate_date: r.rate_date }))

  if (rows.length === 0) return

  importing.value = true
  router.post(route('settings.currencies.rates.import'), { rows }, {
    preserveScroll: true,
    onSuccess: () => {
      closeImportModal()
      if (historyFilter.value === '') return // "all currencies" view refreshes from shared props automatically
      loadHistory()
    },
    onError: () => {
      importError.value = t('settings.currencies.index.importFailedPleaseTryAgain')
    },
    onFinish: () => { importing.value = false },
  })
}
</script>

<style scoped>
/* ── Functional Currency Banner ─────────────────────────────── */
.functional-banner {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  padding: 1.25rem 1.5rem;
  background: linear-gradient(135deg, #0F2554 0%, #1E4DB7 100%);
  border-radius: var(--zv-radius-lg);
  color: #fff;
  box-shadow: var(--zv-shadow);
}
.functional-banner-icon {
  width: 52px; height: 52px;
  border-radius: var(--zv-radius-md);
  background: rgba(255,255,255,0.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.125rem;
  font-weight: 800;
  flex-shrink: 0;
  letter-spacing: -0.03em;
}
.functional-banner-label {
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  opacity: 1;
  margin-bottom: 0.25rem;
}
.functional-banner-name {
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;
}
.functional-banner-note {
  font-size: 0.8rem;
  opacity: 1;
  margin-top: 0.375rem;
  max-width: 480px;
  line-height: 1.5;
}
.functional-banner-rate {
  margin-inline-start: auto;
  text-align: end;
  flex-shrink: 0;
}

/* ── Tabs ───────────────────────────────────────────────────── */
.curr-tabs {
  display: flex;
  gap: 0.25rem;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.25rem;
  width: fit-content;
}
.curr-tab {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1.25rem;
  border-radius: var(--zv-radius);
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--zv-text-secondary);
  background: transparent;
  border: none;
  cursor: pointer;
  transition: var(--zv-transition);
}
.curr-tab:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }
.curr-tab--active { background: var(--zv-nav-bg); color: #fff; }
.curr-tab-count {
  background: rgba(255,255,255,0.2);
  color: inherit;
  font-size: 0.6875rem;
  font-weight: 700;
  padding: 0.125rem 0.4rem;
  border-radius: 9999px;
}
.curr-tab--active .curr-tab-count { background: rgba(255,255,255,0.25); }

/* ── Currency Cards Grid ────────────────────────────────────── */
.curr-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.curr-card {
  background: var(--zv-bg-card);
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  transition: all 0.15s ease;
  box-shadow: var(--zv-shadow-sm);
  display: flex;
  flex-direction: column;
}
.curr-card:hover { box-shadow: var(--zv-shadow); transform: translateY(-1px); }
.curr-card--active { border-color: var(--zv-green-border); }
.curr-card--inactive { opacity: 0.6; }

.curr-card-header {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--zv-border);
}

.curr-symbol {
  width: 40px; height: 40px;
  border-radius: var(--zv-radius-md);
  background: var(--zv-nav-bg);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.875rem;
  font-weight: 800;
  flex-shrink: 0;
  letter-spacing: -0.03em;
}
.curr-card--active .curr-symbol   { background: var(--zv-green); }
.curr-card--functional .curr-symbol { background: #0F2554; }

.curr-code {
  font-size: 1rem;
  font-weight: 700;
  color: var(--zv-text-primary);
  line-height: 1;
}
.curr-name {
  font-size: 0.75rem;
  color: var(--zv-text-secondary);
  margin-top: 0.25rem;
}
.curr-name-ar {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  direction: rtl;
}

.curr-card-rate {
  padding: 0.75rem 1rem;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}
.curr-rate-val {
  font-size: 1.0625rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--zv-num);
}
.curr-rate-label {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
}

.curr-card-footer {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--zv-border);
  background: var(--zv-bg-surface);
}

.field-hint {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  margin-top: 0.25rem;
  font-style: italic;
}
</style>