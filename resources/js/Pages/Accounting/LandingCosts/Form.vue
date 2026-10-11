<!--
══════════════════════════════════════════════════════════════════
  Accounting/LandingCosts/Form.vue

  Create + Edit — only editable while status = draft. Posting,
  paying, and set-to-draft all happen from the Index page.

  Picking a Landing Cost Supplier auto-fills the Clearing Account
  from that supplier's own suggested default (landing_cost_account_id)
  — overridable, never enforced, same pattern as Other Collection /
  Other Payment.

  @layout    AppLayout
  @route     accounting.landing-costs.create / .edit
  @props     landingCost, landingCostSuppliers, clearingAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.landingCosts.form.landingCosts'), href: route('accounting.landing-costs.index') }, { label: isEdit ? t('common.edit') : t('accounting.landingCosts.form.new') }]"
      :title="isEdit ? t('accounting.landingCosts.form.editLandingCost') : t('accounting.landingCosts.form.newLandingCost')"
      :subtitle="t('accounting.landingCosts.form.subtitle')"
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#B45309">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4m16 0l-6-6m6 6l-6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.landingCosts.form.landingCostInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.landingCosts.form.landingCostInformationSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p v-if="!landingCostSuppliers.length" class="zv-alert zv-alert-warning mb-3">{{ t('accounting.landingCosts.form.noLandingCostSuppliers') }}</p>
          <p v-if="!clearingAccounts.length" class="zv-alert zv-alert-warning mb-3">{{ t('accounting.landingCosts.form.noClearingAccounts') }}</p>

          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.form.landingCostSupplier') }}</label>
              <SelectBox v-model="form.partner_id" :options="landingCostSuppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.landingCosts.form.selectSupplier')" :clearable="false" @change="onSupplierChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.form.clearingAccount') }}</label>
              <SelectBox v-model="form.chart_of_account_id" :options="clearingAccounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.landingCosts.form.selectAccount')" :clearable="false" :class="{ 'zv-input-error': form.errors.chart_of_account_id }" />
              <p v-if="form.errors.chart_of_account_id" class="zv-field-error">{{ form.errors.chart_of_account_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.form.costDate') }}</label>
              <input v-model="form.cost_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.cost_date }"/>
              <p v-if="form.errors.cost_date" class="zv-field-error">{{ form.errors.cost_date }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-4-8 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.landingCosts.form.amount') }}</label>
              <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input" :class="{ 'zv-input-error': form.errors.amount }"/>
              <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.landingCosts.form.reference') }}</label>
              <input v-model="form.reference" type="text" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.landingCosts.form.referenceHint') }}</p>
            </div>
          </div>

          <!-- M-32: currency, rate (blank = system rate) and recoverable VAT -->
          <div class="field-grid field-grid-3 mt-4">
            <div class="field-group">
              <label class="zv-label">Currency</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" placeholder="Main currency" />
              <p class="field-hint">Leave empty for the company's main currency.</p>
            </div>
            <div class="field-group" v-if="form.currency_id">
              <label class="zv-label">Exchange rate</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001" min="0" class="zv-input" placeholder="Blank = system rate"/>
            </div>
            <div class="field-group">
              <label class="zv-label">Recoverable VAT (customs / clearing)</label>
              <input v-model="form.vat_amount" type="number" step="0.01" min="0" class="zv-input"/>
              <p class="field-hint">Enter the cost above WITHOUT VAT. VAT entered here goes to VAT Input and is not added to the stock cost.</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.landingCosts.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.landing-costs.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.landingCosts.form.saveAsDraft')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  landingCost:          { type: Object, default: null },
  landingCostSuppliers: { type: Array,  default: () => [] },
  clearingAccounts:     { type: Array,  default: () => [] },
  currencies:           { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.landingCost?.id)

const form = useForm({
  partner_id:           props.landingCost?.partner_id ?? '',
  chart_of_account_id:  props.landingCost?.chart_of_account_id ?? '',
  cost_date:            props.landingCost?.cost_date?.toString().slice(0, 10) ?? todayLocal(),
  amount:               props.landingCost?.amount ?? '',
  currency_id:          props.landingCost?.currency_id ?? '',
  exchange_rate:        props.landingCost?.currency_id ? props.landingCost.exchange_rate : '',
  vat_amount:           props.landingCost?.vat_amount ?? 0,
  reference:            props.landingCost?.reference ?? '',
  internal_notes:       props.landingCost?.internal_notes ?? '',
})

// Suggested default only — never overrides a value the user already
// picked or changed themselves, same non-enforced pattern as Other
// Collection/Other Payment.
function onSupplierChange() {
  if (form.chart_of_account_id) return
  const supplier = props.landingCostSuppliers.find(s => s.id === Number(form.partner_id))
  if (supplier?.landing_cost_account_id) {
    form.chart_of_account_id = supplier.landing_cost_account_id
  }
}

function submit() {
  if (isEdit.value) {
    form.put(route('accounting.landing-costs.update', props.landingCost.id))
  } else {
    form.post(route('accounting.landing-costs.store'))
  }
}
</script>
