<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/BuySellCurrencyForm.vue

  Create + Edit — a transaction is only editable while status =
  draft (see BuySellCurrencyService header). A posted transaction is
  read-only here; its only action is "Set to Draft" (reopens it,
  automatically reversing the journal entry — a real accounting
  reversal, never a delete), same lifecycle shape already proven on
  Customer Invoice / Customer Payment / Supplier Payment.

  Each side (From / To) is independently either a Bank account or a
  Safe (a 131xxx Chart of Accounts sub-account) — four leg
  combinations, same convention as Internal Money Transfer. Unlike
  Internal Money Transfer, the two sides are always different
  currencies — this is a conversion, not a same-currency move.

  System reference rates (one per leg) are auto-pulled live from the
  server for the chosen currency + transaction date, and shown
  editable — an override always wins if the person's own source
  differs. The realized FX gain/loss preview below recomputes
  client-side from whatever rates are currently in the form, purely
  for a live preview; the server always recomputes and posts the
  authoritative figures on post().

  @layout    AppLayout
  @route     fi.buy-sell-currencies.create / .edit
  @props     model, bankAccounts, safeAccounts, currencies,
             functionalCurrencyId, mode
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.treasury.buySellCurrencyForm.buySellCurrency'), href: route('fi.buy-sell-currencies.index') }, { label: isEdit ? model.transaction_code : t('financialInstitutions.treasury.buySellCurrencyForm.new') }]"
      :title="isEdit ? model.transaction_code : t('financialInstitutions.treasury.buySellCurrencyForm.newBuySellCurrency')"
      :subtitle="t('financialInstitutions.treasury.buySellCurrencyForm.aCurrencyConversionIsARealization')"
    />

    <!-- Status context banner — same pattern already proven on Customer Invoice. -->
    <div v-if="isEdit && model.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      {{ t('financialInstitutions.treasury.buySellCurrencyForm.thisTransactionIsPostedAndRead') }}
    </div>

    <form @submit.prevent="submit">

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Transaction Details ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v12m0 0l4-4m-4 4l-4-4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.buySellCurrencyForm.transactionDetails') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.buySellCurrencyForm.whichCurrencyIsSoldWhichIs') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.transactionDate') }}</label>
              <input v-model="form.transaction_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.transaction_date }" @change="refreshBothRates"/>
              <p v-if="form.errors.transaction_date" class="zv-field-error">{{ form.errors.transaction_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.currencyToSell') }}</label>
              <select v-model="form.currency_to_sell_id" class="zv-select" :class="{ 'zv-input-error': form.errors.currency_to_sell_id }" @change="onSellCurrencyChange">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectCurrency') }}</option>
                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
              </select>
              <p v-if="form.errors.currency_to_sell_id" class="zv-field-error">{{ form.errors.currency_to_sell_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.amountToSell') }}</label>
              <input v-model="form.currency_to_sell_amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.currency_to_sell_amount }" placeholder="0.00" @input="recalcBuyAmount"/>
              <p v-if="form.errors.currency_to_sell_amount" class="zv-field-error">{{ form.errors.currency_to_sell_amount }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.currencyToBuy') }}</label>
              <select v-model="form.currency_to_buy_id" class="zv-select" :class="{ 'zv-input-error': form.errors.currency_to_buy_id }" @change="onBuyCurrencyChange">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectCurrency') }}</option>
                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
              </select>
              <p v-if="sameCurrencyError" class="zv-field-error">{{ sameCurrencyError }}</p>
              <p v-else-if="form.errors.currency_to_buy_id" class="zv-field-error">{{ form.errors.currency_to_buy_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.dealRate') }}</label>
              <input
                v-model="form.deal_rate" type="text" inputmode="decimal" autocomplete="off"
                class="zv-input text-end" :class="{ 'zv-input-error': form.errors.deal_rate }"
                :placeholder="t('financialInstitutions.treasury.buySellCurrencyForm.eG4830Or1')"
                @input="onDealRateInput" @blur="onDealRateBlur"
              />
              <p class="field-hint">
                {{ t('financialInstitutions.treasury.buySellCurrencyForm.theBankSNegotiatedRateCurrencyTo') }}
                {{ t('financialInstitutions.treasury.buySellCurrencyForm.typeAPlainNumberOrA') }}
              </p>
              <p v-if="impliedDealRate && !dealRateTouched" class="field-hint bsc-implied-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.suggestedFromTodaySSystemRates') }} {{ fmtRate(impliedDealRate) }}</p>
              <p v-if="form.errors.deal_rate" class="zv-field-error">{{ form.errors.deal_rate }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.amountToBuy') }}</label>
              <input v-model="form.currency_to_buy_amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.currency_to_buy_amount }" placeholder="0.00"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.autoCalculatedFromAmountToSell') }}</p>
              <p v-if="form.errors.currency_to_buy_amount" class="zv-field-error">{{ form.errors.currency_to_buy_amount }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ From (Sell) ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#DC2626">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4m0 0l6-6m-6 6l6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.buySellCurrencyForm.from') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.buySellCurrencyForm.whereTheSoldCurrencyIsLeaving') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <OptionTiles
            :model-value="form.from_leg_type" variant="pill" class="mb-4"
            :options="[{ value: 'bank', label: t('financialInstitutions.treasury.buySellCurrencyForm.bankAccount') }, { value: 'safe', label: t('financialInstitutions.treasury.buySellCurrencyForm.safe') }]"
            @update:model-value="(v) => setLegType('from', v)"
          />

          <div class="field-grid field-grid-3">
            <div class="field-group" v-if="form.from_leg_type === 'bank'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.bankAccount') }}</label>
              <select v-model="form.from_bank_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.from_bank_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectAccount') }}</option>
                <option v-for="a in fromBankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
              <p v-if="fromBankAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.noBankAccountInTheCurrency') }}</p>
              <p v-if="form.errors.from_bank_account_id" class="zv-field-error">{{ form.errors.from_bank_account_id }}</p>
            </div>
            <div class="field-group" v-else>
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.safe') }}</label>
              <select v-model="form.from_safe_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.from_safe_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectSafe') }}</option>
                <option v-for="s in fromSafeAccounts" :key="s.id" :value="s.id">{{ s.name_en }}</option>
              </select>
              <p v-if="fromSafeAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.noSafeInTheCurrencyTo') }}</p>
              <p v-if="form.errors.from_safe_account_id" class="zv-field-error">{{ form.errors.from_safe_account_id }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ To (Buy) ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.buySellCurrencyForm.to') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.buySellCurrencyForm.whereTheBoughtCurrencyIsLanding') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <OptionTiles
            :model-value="form.to_leg_type" variant="pill" class="mb-4"
            :options="[{ value: 'bank', label: t('financialInstitutions.treasury.buySellCurrencyForm.bankAccount') }, { value: 'safe', label: t('financialInstitutions.treasury.buySellCurrencyForm.safe') }]"
            @update:model-value="(v) => setLegType('to', v)"
          />

          <div class="field-grid field-grid-3">
            <div class="field-group" v-if="form.to_leg_type === 'bank'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.bankAccount') }}</label>
              <select v-model="form.to_bank_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.to_bank_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectAccount') }}</option>
                <option v-for="a in toBankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
              <p v-if="toBankAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.noBankAccountInTheCurrency2') }}</p>
              <p v-if="form.errors.to_bank_account_id" class="zv-field-error">{{ form.errors.to_bank_account_id }}</p>
            </div>
            <div class="field-group" v-else>
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.safe') }}</label>
              <select v-model="form.to_safe_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.to_safe_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.buySellCurrencyForm.selectSafe') }}</option>
                <option v-for="s in toSafeAccounts" :key="s.id" :value="s.id">{{ s.name_en }}</option>
              </select>
              <p v-if="toSafeAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.noSafeInTheCurrencyTo2') }}</p>
              <p v-if="form.errors.to_safe_account_id" class="zv-field-error">{{ form.errors.to_safe_account_id }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Valuation & FX Gain/Loss Preview ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.buySellCurrencyForm.referenceRateValuation') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.buySellCurrencyForm.autoPulledFromTheSystemRate') }}</div>
          </div>
          <a :href="route('settings.currencies.index')" target="_blank" class="bsc-manage-rates-link">{{ t('financialInstitutions.treasury.buySellCurrencyForm.manageExchangeRates') }} ↗</a>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.sellReferenceRate') }}</label>
              <div class="bsc-rate-row">
                <input v-model="form.sell_reference_rate" type="number" step="0.0000000001" min="0" class="zv-input text-end"/>
                <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :disabled="!form.currency_to_sell_id || loadingSellRate" @click="() => refreshRate('sell')">
                  {{ loadingSellRate ? t('financialInstitutions.treasury.buySellCurrencyForm.text') : t('financialInstitutions.treasury.buySellCurrencyForm.refresh') }}
                </button>
              </div>
              <p class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.functionalCurrencyValuePer1Unit') }} {{ sellCurrencyCode }}.</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.buySellCurrencyForm.buyReferenceRate') }}</label>
              <div class="bsc-rate-row">
                <input v-model="form.buy_reference_rate" type="number" step="0.0000000001" min="0" class="zv-input text-end"/>
                <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" :disabled="!form.currency_to_buy_id || loadingBuyRate" @click="() => refreshRate('buy')">
                  {{ loadingBuyRate ? t('financialInstitutions.treasury.buySellCurrencyForm.text') : t('financialInstitutions.treasury.buySellCurrencyForm.refresh') }}
                </button>
              </div>
              <p class="field-hint">{{ t('financialInstitutions.treasury.buySellCurrencyForm.functionalCurrencyValuePer1Unit') }} {{ buyCurrencyCode }}.</p>
            </div>
          </div>

          <!-- Audit M28: a rate that differs from the system rate needs a reason (and may not be more than 5% away). -->
          <div class="field-group mt-4">
            <label class="zv-label">Reason for changing a rate</label>
            <input v-model="rateReason" type="text" maxlength="200" class="zv-input" placeholder="Only if you changed a reference rate above — e.g. bank's closing rate that day"/>
            <p class="field-hint">Leave empty when you keep the system rates. A changed rate must stay within 5% of the system rate.</p>
          </div>

          <div class="bsc-preview mt-4">
            <div class="bsc-preview-row">
              <span>{{ t('financialInstitutions.treasury.buySellCurrencyForm.fairValueGivenUpSellLeg') }}</span>
              <strong>{{ fmt(sellFairValuePreview) }}</strong>
            </div>
            <div class="bsc-preview-row">
              <span>{{ t('financialInstitutions.treasury.buySellCurrencyForm.fairValueReceivedBuyLeg') }}</span>
              <strong>{{ fmt(buyFairValuePreview) }}</strong>
            </div>
            <div class="bsc-preview-row bsc-preview-total">
              <span>{{ plugPreview >= 0 ? t('financialInstitutions.treasury.buySellCurrencyForm.realizedFXGain431') : t('financialInstitutions.treasury.buySellCurrencyForm.realizedFXLoss571') }}</span>
              <strong :class="plugPreview > 0 ? 'bsc-gain' : (plugPreview < 0 ? 'bsc-loss' : '')">{{ fmt(Math.abs(plugPreview)) }}</strong>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Posted journal entry (edit mode, posted) ══════════════════ -->
      <div v-if="isEdit && model.status === 'posted' && model.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('financialInstitutions.treasury.buySellCurrencyForm.postedJournalEntry') }}</div>
          <div class="form-section-sub">{{ model.journal_entry.entry_number }}</div>
        </div>
        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead><tr><th>{{ t('financialInstitutions.treasury.buySellCurrencyForm.account') }}</th><th class="bsc-th-num">{{ t('financialInstitutions.treasury.buySellCurrencyForm.debit') }}</th><th class="bsc-th-num">{{ t('financialInstitutions.treasury.buySellCurrencyForm.credit') }}</th></tr></thead>
            <tbody>
              <tr v-for="line in model.journal_entry.lines" :key="line.id">
                <td>{{ line.chart_of_account?.code }} — {{ line.chart_of_account?.name_en }}</td>
                <td class="zv-table-num">{{ fmt(line.debit) }}</td>
                <td class="zv-table-num">{{ fmt(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.treasury.buySellCurrencyForm.comment') }}</label>
            <textarea v-model="form.comment" class="zv-input" rows="3"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('fi.buy-sell-currencies.index')" class="zv-btn zv-btn-ghost">{{ t('financialInstitutions.treasury.buySellCurrencyForm.close') }}</Link>

          <!-- Posted → the reopen action -->
          <button
            v-if="isEdit && model.status === 'posted'"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('financialInstitutions.treasury.buySellCurrencyForm.setToDraft') }}</button>

          <!-- Draft → Save + Post (covers both a brand-new draft and a reopened one) -->
          <template v-if="!isEdit || model.status === 'draft'">
            <button type="submit" :disabled="form.processing || !!sameCurrencyError" class="zv-btn zv-btn-secondary">
              {{ form.processing ? t('common.saving') : t('financialInstitutions.treasury.buySellCurrencyForm.saveAsDraft') }}
            </button>
            <button v-if="isEdit" type="button" :disabled="posting || !!sameCurrencyError" class="zv-btn zv-btn-primary" @click="confirmPost">
              {{ posting ? t('financialInstitutions.treasury.buySellCurrencyForm.posting') : t('financialInstitutions.treasury.buySellCurrencyForm.postTransaction') }}
            </button>
          </template>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { todayLocal } from '@/Utils/dates'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
  model:                 { type: Object, default: null },
  bankAccounts:          { type: Array,  default: () => [] },
  safeAccounts:           { type: Array,  default: () => [] },
  currencies:             { type: Array,  default: () => [] },
  functionalCurrencyId:   { type: [Number, String], default: null },
})

const isEdit = computed(() => !!props.model?.id)

// A posted transaction is read-only until reopened via "Set to
// Draft" — same rule already proven on Customer Invoice/Customer
// Payment/Supplier Payment. A draft (new or reopened) is fully
// editable.
const isReadOnly = computed(() => isEdit.value && props.model.status !== 'draft')

const form = useForm(isEdit.value ? {
  transaction_date:         props.model.transaction_date?.toString().slice(0, 10),
  from_leg_type:            props.model.from_leg_type,
  from_bank_account_id:     props.model.from_bank_account_id ?? '',
  from_safe_account_id:     props.model.from_safe_account_id ?? '',
  to_leg_type:              props.model.to_leg_type,
  to_bank_account_id:       props.model.to_bank_account_id ?? '',
  to_safe_account_id:       props.model.to_safe_account_id ?? '',
  currency_to_sell_id:      props.model.currency_to_sell_id,
  currency_to_sell_amount:  props.model.currency_to_sell_amount,
  currency_to_buy_id:       props.model.currency_to_buy_id,
  currency_to_buy_amount:   props.model.currency_to_buy_amount,
  deal_rate:                props.model.deal_rate,
  sell_reference_rate:      props.model.sell_reference_rate ?? '',
  buy_reference_rate:       props.model.buy_reference_rate ?? '',
  comment:                  props.model.comment ?? '',
} : {
  transaction_date:         todayLocal(),
  from_leg_type:            'bank',
  from_bank_account_id:     '',
  from_safe_account_id:     '',
  to_leg_type:              'bank',
  to_bank_account_id:       '',
  to_safe_account_id:       '',
  currency_to_sell_id:      props.functionalCurrencyId ?? '',
  currency_to_sell_amount:  '',
  currency_to_buy_id:       '',
  currency_to_buy_amount:   '',
  deal_rate:                '',
  sell_reference_rate:      '',
  buy_reference_rate:       '',
  comment:                  '',
})

const sellCurrencyCode = computed(() => currencies_value().find(c => c.id == form.currency_to_sell_id)?.code ?? '')
const buyCurrencyCode  = computed(() => currencies_value().find(c => c.id == form.currency_to_buy_id)?.code ?? '')
function currencies_value() { return props.currencies ?? [] }

const sameCurrencyError = computed(() => {
  if (form.currency_to_sell_id && form.currency_to_buy_id && String(form.currency_to_sell_id) === String(form.currency_to_buy_id)) {
    return t('financialInstitutions.treasury.buySellCurrencyForm.currencyToSellAndCurrencyTo')
  }
  return ''
})

function setLegType(side, type) {
  form[`${side}_leg_type`] = type
  form[`${side}_bank_account_id`] = ''
  form[`${side}_safe_account_id`] = ''
}

const fromBankAccounts = computed(() => (props.bankAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_to_sell_id)))
const toBankAccounts   = computed(() => (props.bankAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_to_buy_id)))
const fromSafeAccounts = computed(() => (props.safeAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_to_sell_id)))
const toSafeAccounts   = computed(() => (props.safeAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_to_buy_id)))

// A previously picked account may not exist under the newly chosen
// currency — clear rather than silently keep an impossible
// combination, same convention as Internal Money Transfer.
function onSellCurrencyChange() {
  form.from_bank_account_id = ''
  form.from_safe_account_id = ''
  resetDealRateForNewPair()
  refreshRate('sell')
}
function onBuyCurrencyChange() {
  form.to_bank_account_id = ''
  form.to_safe_account_id = ''
  resetDealRateForNewPair()
  refreshRate('buy')
}

// Buy Amount auto-calculates from Sell Amount × Deal Rate — same
// "auto-calculated Buy Amount" convention as the source CashVero
// form. The person can still override it by typing over the result.
function recalcBuyAmount() {
  const sell = Number(form.currency_to_sell_amount || 0)
  const rate = Number(form.deal_rate || 0)
  if (sell > 0 && rate > 0) {
    form.currency_to_buy_amount = (sell * rate).toFixed(2)
  }
}

// ── Reference rate auto-pull ─────────────────────────────────────

const loadingSellRate = ref(false)
const loadingBuyRate  = ref(false)

function refreshRate(side) {
  const currencyId = side === 'sell' ? form.currency_to_sell_id : form.currency_to_buy_id
  if (! currencyId || ! form.transaction_date) return

  const loading = side === 'sell' ? loadingSellRate : loadingBuyRate
  loading.value = true

  axios.get(route('fi.buy-sell-currencies.reference-rate'), {
    params: { currency_id: currencyId, date: form.transaction_date },
  }).then(res => {
    if (res.data?.rate) {
      form[`${side}_reference_rate`] = res.data.rate
    }
  }).finally(() => {
    loading.value = false
  })
}

function refreshBothRates() {
  refreshRate('sell')
  refreshRate('buy')
}

// Auto-pull both rates once on first load of a brand-new draft, once
// currency + date are already known (functional currency preselected
// as Sell by default).
if (! isEdit.value && form.currency_to_sell_id) {
  refreshRate('sell')
}

// ── Deal Rate — auto-suggest from reference rates, expression entry ─
//
// The implied "fair" cross rate between the two currencies, purely
// from today's system reference rates (sell / buy) — what the deal
// rate would be if the bank charged no spread at all. Shown as a
// suggestion and auto-filled into Deal Rate whenever the person
// hasn't typed their own value yet; the moment they touch the field,
// auto-fill stops until they pick a different currency pair (a new
// pair makes the old deal rate meaningless anyway).
const dealRateTouched = ref(isEdit.value)

const impliedDealRate = computed(() => {
  const sell = Number(form.sell_reference_rate || 0)
  const buy  = Number(form.buy_reference_rate || 0)
  return (sell > 0 && buy > 0) ? sell / buy : null
})

watch(impliedDealRate, (val) => {
  if (dealRateTouched.value || val === null) return
  form.deal_rate = trimRateDecimals(val)
  recalcBuyAmount()
})

function onDealRateInput() {
  dealRateTouched.value = true
  recalcBuyAmount()
}

// Currency pair changed — the old deal rate (typed or suggested) no
// longer means anything, so clear it and let the suggestion for the
// new pair take over again.
function resetDealRateForNewPair() {
  dealRateTouched.value = false
  form.deal_rate = ''
}

// Lets the person type a plain number ("48.30") or a quick
// calculation ("=1/50", "48.3*1.02", etc. — spreadsheet-style, "="
// prefix optional) and resolves it to a decimal the moment they
// leave the field. Only +, -, *, /, parentheses, and numbers are
// ever evaluated — no eval(), nothing beyond basic arithmetic.
function onDealRateBlur() {
  const raw = String(form.deal_rate ?? '').trim()
  if (! raw) return

  const result = evaluateArithmeticExpression(raw.startsWith('=') ? raw.slice(1) : raw)
  if (result !== null && result > 0) {
    form.deal_rate = trimRateDecimals(result)
  }
  recalcBuyAmount()
}

function evaluateArithmeticExpression(expr) {
  if (! /^[0-9+\-*/(). \t]+$/.test(expr)) return null

  let pos = 0
  const skip = () => { while (expr[pos] === ' ' || expr[pos] === '\t') pos++ }

  function parseNumber() {
    skip()
    const start = pos
    while (pos < expr.length && /[0-9.]/.test(expr[pos])) pos++
    if (pos === start) throw new Error('expected a number')
    return parseFloat(expr.slice(start, pos))
  }
  function parseFactor() {
    skip()
    if (expr[pos] === '(') {
      pos++
      const val = parseExpr()
      skip()
      if (expr[pos] !== ')') throw new Error('expected )')
      pos++
      return val
    }
    if (expr[pos] === '-') { pos++; return -parseFactor() }
    if (expr[pos] === '+') { pos++; return parseFactor() }
    return parseNumber()
  }
  function parseTerm() {
    let val = parseFactor()
    skip()
    while (expr[pos] === '*' || expr[pos] === '/') {
      const op = expr[pos]; pos++
      const rhs = parseFactor()
      val = op === '*' ? val * rhs : val / rhs
      skip()
    }
    return val
  }
  function parseExpr() {
    let val = parseTerm()
    skip()
    while (expr[pos] === '+' || expr[pos] === '-') {
      const op = expr[pos]; pos++
      const rhs = parseTerm()
      val = op === '+' ? val + rhs : val - rhs
      skip()
    }
    return val
  }

  try {
    const result = parseExpr()
    skip()
    if (pos !== expr.length || ! isFinite(result)) return null
    return result
  } catch {
    return null
  }
}

// Formats a rate to up to 10 decimal places, trimming trailing
// zeros but never dropping below 2 decimals — matches the deal_rate
// column's decimal(20,10) precision without showing a wall of zeros
// for a clean rate like 48.3.
function trimRateDecimals(num) {
  let str = Number(num).toFixed(10).replace(/0+$/, '').replace(/\.$/, '')
  if (! str.includes('.')) str += '.00'
  else if (str.split('.')[1].length < 2) str = Number(num).toFixed(2)
  return str
}

// ── Live FX gain/loss preview (client-side only — server always
// recomputes and posts the authoritative figures on post()) ────────

const sellFairValuePreview = computed(() => {
  const amt = Number(form.currency_to_sell_amount || 0)
  const rate = Number(form.sell_reference_rate || 0)
  return amt * rate
})
const buyFairValuePreview = computed(() => {
  const amt = Number(form.currency_to_buy_amount || 0)
  const rate = Number(form.buy_reference_rate || 0)
  return amt * rate
})
const plugPreview = computed(() => buyFairValuePreview.value - sellFairValuePreview.value)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Save (draft only) ────────────────────────────────────────────

// Was called in the template but never defined here (only in the Index page),
// which crashed the form as soon as a suggested rate appeared.
function fmtRate(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
}

function submit() {
  if (isEdit.value) {
    form.put(route('fi.buy-sell-currencies.update', props.model.id))
  } else {
    form.post(route('fi.buy-sell-currencies.store'))
  }
}

// ── Post ──────────────────────────────────────────────────────────

const posting = ref(false)
const rateReason = ref('')
function confirmPost() {
  if (! confirm(t('financialInstitutions.treasury.buySellCurrencyForm.postThisTransactionThisValuesBoth'))) return
  posting.value = true
  router.patch(route('fi.buy-sell-currencies.post', props.model.id), {
    sell_reference_rate: form.sell_reference_rate,
    buy_reference_rate:  form.buy_reference_rate,
    rate_override_reason: rateReason.value,
  }, {
    onFinish: () => { posting.value = false },
  })
}

// ── Set to Draft ──────────────────────────────────────────────────

function confirmSetToDraft() {
  if (! confirm(t('financialInstitutions.treasury.buySellCurrencyForm.reopenThisTransactionForEditingThis'))) return

  // Same fix already proven on Customer Invoice: force a full reload
  // on success so every prop (reference rates, journal entry) re-
  // fetches correctly against the now-reversed state, but only when
  // the response genuinely carries no error.
  router.patch(route('fi.buy-sell-currencies.set-to-draft', props.model.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}
</script>

<style>
.bsc-rate-row { display: flex; gap: .5rem; align-items: center; }
.bsc-rate-row input { flex: 1; }
.bsc-preview { background: var(--zv-bg-surface); border-radius: .5rem; padding: 1rem 1.25rem; }
.bsc-preview-row { display: flex; justify-content: space-between; padding: .375rem 0; font-size: .875rem; }
.bsc-preview-total { border-top: 1px solid var(--zv-border, #e5e7eb); margin-top: .375rem; padding-top: .625rem; font-size: .95rem; }
.bsc-gain { color: var(--zv-success, #059669); }
.bsc-loss { color: var(--zv-danger, #DC2626); }
.bsc-th-num { text-align: end; }

/* From/To leg picker now uses the shared <OptionTiles variant="pill">
   component (see Components/OptionTiles.vue) — no page-specific CSS
   needed here anymore. */

.bsc-manage-rates-link {
  margin-left: auto;
  font-size: 12.5px;
  font-weight: 600;
  color: #1490A8;
  white-space: nowrap;
  text-decoration: none;
}
.bsc-manage-rates-link:hover { text-decoration: underline; }

.bsc-implied-hint { color: #1490A8; font-style: italic; }
</style>
