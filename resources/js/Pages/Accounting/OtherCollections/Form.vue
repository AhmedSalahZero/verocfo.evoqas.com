<!--
══════════════════════════════════════════════════════════════════
  Accounting/OtherCollections/Form.vue

  Other Collection — see VeroCFO_Other_Collection_Design_v1_0.md.
  Money received from a Shareholder / Employee / Sister Company /
  Supplier (refund) / Other partner. Mirrors Customer Collection's
  Form.vue for the "where did the money land" half (Money Type tiles
  drive the same four blocks), but replaces Settlement/Down Payment
  with a single Credit Side section — the user picks the Chart of
  Accounts account to credit directly, since there is no AR to
  auto-resolve to here.

  POSTS IMMEDIATELY ON SAVE — no draft stage on create, same posture
  as Customer Collection. "Set to Draft" is the only way back to an
  editable state.

  @layout    AppLayout
  @route     accounting.other-collections.create / .edit
  @props     partnerTypes, banks, currencies, functionalCurrencyId,
             otherCollection, periodClosed
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.otherCollections.form.otherCollection'), href: route('accounting.other-collections.index') }, { label: isEdit ? otherCollection.collection_code : t('accounting.otherCollections.form.new') }]"
      :title="isEdit ? otherCollection.collection_code : t('accounting.otherCollections.form.newOtherCollection')"
      :subtitle="t('accounting.otherCollections.form.recordsMoneyReceivedFromANonCustomer')"
    />

    <div v-if="isEdit && otherCollection.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.otherCollections.form.thisCollectionIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.otherCollections.form.thisCollectionIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && otherCollection.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.otherCollections.form.thisCollectionWasCancelledItCannot') }}
    </div>
    <div v-else-if="isEdit && otherCollection.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.otherCollections.form.thisCollectionWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        <div>
          <div>{{ t('accounting.otherCollections.form.pleaseFixTheFollowingBeforeSaving') }}</div>
          <ul style="margin: 4px 0 0 18px; list-style: disc;">
            <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
          </ul>
        </div>
      </div>

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Money Received ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.otherCollections.form.moneyReceived') }}</div>
            <div class="form-section-sub">{{ t('accounting.otherCollections.form.whoPaidInWhatCurrencyAnd') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3-3-6">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.collectionDate') }}</label>
              <input v-model="form.collection_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.collection_date }"/>
              <p v-if="form.errors.collection_date" class="zv-field-error">{{ form.errors.collection_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.partnerType') }}</label>
              <SelectBox v-model="form.partner_type" :options="partnerTypes" :value-fn="pt => pt" :label-fn="pt => partnerTypeLabel(pt)" :placeholder="t('accounting.otherCollections.form.selectPartnerType')" :clearable="false" @change="onPartnerTypeChange" :class="{ 'zv-input-error': form.errors.partner_type }" />
              <p v-if="form.errors.partner_type" class="zv-field-error">{{ form.errors.partner_type }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.partnerName') }}</label>
              <SelectBox v-model="form.partner_id" :options="partners" value-key="id" :label-fn="p => `${p.code} — ${p.name_en}`" :placeholder="form.partner_type ? t('accounting.otherCollections.form.selectPartner') : t('accounting.otherCollections.form.selectPartnerTypeFirst')" :clearable="false" :disabled="!form.partner_type" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>
          </div>

          <!-- Advance Type (Employee only) + Currency + Credit Account —
               one row. Column count shifts: 3 columns when Advance Type
               is showing (Employee), 2 otherwise. Credit Account moved
               up from its own section so all three sit together. -->
          <div class="field-grid mt-4" :class="form.partner_type === 'employee' ? 'field-grid-3-3-6' : 'field-grid-3-3-6'">
            <div class="field-group" v-if="form.partner_type === 'employee'">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.advanceType') }}</label>
              <SelectBox v-model="form.employee_advance_type" :options="employeeAdvanceTypes" value-key="value" label-key="label" :placeholder="t('accounting.otherCollections.form.selectAdvanceType')" :clearable="false" @change="onEmployeeAdvanceTypeChange" :class="{ 'zv-input-error': form.errors.employee_advance_type }" />
              <p v-if="form.errors.employee_advance_type" class="zv-field-error">{{ form.errors.employee_advance_type }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.currency') }}</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :class="{ 'zv-input-error': form.errors.currency_id }" />
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.creditAccount') }}</label>
              <div v-if="!form.partner_type || !form.partner_id" class="text-sm opacity-50" style="padding: 8px 0;">
                {{ t('accounting.otherCollections.form.selectPartnerAndTypeFirst') }}
              </div>
              <div v-else-if="form.partner_type === 'employee' && !form.employee_advance_type" class="text-sm opacity-50" style="padding: 8px 0;">
                {{ t('accounting.otherCollections.form.selectAdvanceTypeFirst') }}
              </div>
              <template v-else>
                <SearchableSelect
                  v-model="form.credit_account_id"
                  v-model:label="creditAccountLabel"
                  :search-url="route('accounting.other-collections.credit-accounts')"
                  :placeholder="t('accounting.otherCollections.form.searchAccountCodeOrName')"
                  :min-chars="0"
                />
                <p v-if="form.errors.credit_account_id" class="zv-field-error">{{ form.errors.credit_account_id }}</p>
                <p v-else-if="suggestedAccountApplied" class="field-hint">{{ t('accounting.otherCollections.form.suggestedForThisPartnerType') }}</p>
              </template>
            </div>
          </div>

          <!-- Money Type tiles -->
          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.moneyType') }}</label>
            <OptionTiles
              :model-value="form.money_type" variant="icon" :columns="4"
              :options="[
                { value: 'bank_deposit', label: t('accounting.otherCollections.form.bankDeposit'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6\'/>' },
                { value: 'incoming_transfer', label: t('accounting.otherCollections.form.incomingTransfer'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4\'/>' },
                { value: 'cash_in_safe', label: t('accounting.otherCollections.form.cashInSafe'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z\'/>' },
                { value: 'cheque', label: t('accounting.otherCollections.form.cheque'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01\'/>' },
              ]"
              @update:model-value="setMoneyType"
            />
          </div>
        </div>
      </div>

      <!-- ══════════════════ Money Type block ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ moneyTypeBlockTitle }}</div>
            <div class="form-section-sub">{{ t('accounting.otherCollections.form.exactlyWhereThisMoneyLanded') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Bank Deposit / Incoming Transfer — shared block -->
          <template v-if="form.money_type === 'bank_deposit' || form.money_type === 'incoming_transfer'">
            <div class="field-grid field-grid-4-8">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.accountType') }}</label>
                <SelectBox v-model="form.destination_type" :options="[{ value: 'bank_account', label: t('accounting.otherCollections.form.currentAccount') }, { value: 'credit_facility', label: t('accounting.otherCollections.form.overdraftFacility') }]" @change="form.destination_bank_account_id = ''; form.destination_credit_facility_id = ''" :class="{ 'zv-input-error': form.errors.destination_type }" />
                <p v-if="form.errors.destination_type" class="zv-field-error">{{ form.errors.destination_type }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.accountNumber') }}</label>
                <SelectBox v-model="form.destination_bank_account_id" :options="bankAccountDestinations" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.otherCollections.form.selectAccount')" :clearable="false" v-if="form.destination_type === 'bank_account'" :class="{ 'zv-input-error': form.errors.destination_bank_account_id }" />
                <SelectBox v-model="form.destination_credit_facility_id" :options="creditFacilityDestinations" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.otherCollections.form.selectFacility')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.destination_credit_facility_id }" />
                <p v-if="form.errors.destination_bank_account_id" class="zv-field-error">{{ form.errors.destination_bank_account_id }}</p>
                <p v-if="form.errors.destination_credit_facility_id" class="zv-field-error">{{ form.errors.destination_credit_facility_id }}</p>
              </div>
            </div>
            <div class="field-grid field-grid-4-8 mt-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }} [{{ currencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
                <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.otherCollections.form.referenceNumber') }}</label>
                <input v-model="form.reference_number" type="text" class="zv-input" :placeholder="t('accounting.otherCollections.form.transferRef')"/>
              </div>
            </div>
          </template>

          <!-- Cash in Safe -->
          <template v-else-if="form.money_type === 'cash_in_safe'">
            <div class="field-grid field-grid-6-3-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.receivingBranch') }}</label>
                <SelectBox v-model="form.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.otherCollections.form.selectSafe')" :clearable="false" :class="{ 'zv-input-error': form.errors.safe_account_id }" />
                <p v-if="form.errors.safe_account_id" class="zv-field-error">{{ form.errors.safe_account_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.receivedAmount') }} [{{ currencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.otherCollections.form.receiptNumber') }}</label>
                <input v-model="form.receipt_number" type="text" class="zv-input"/>
              </div>
            </div>
          </template>

          <!-- Cheque -->
          <template v-else-if="form.money_type === 'cheque'">
            <div class="field-grid field-grid-6-2-2-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.selectDraweeBank') }}</label>
                <SelectBox v-model="form.cheque.drawee_bank_id" :options="banks" value-key="id" :label-fn="b => b.name_en" :placeholder="t('accounting.otherCollections.form.selectBank')" :clearable="false" :class="{ 'zv-input-error': form.errors['cheque.drawee_bank_id'] }" />
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.chequeAmount') }} [{{ currencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.dueDate') }}</label>
                <input v-model="form.cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.due_date'] }"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.otherCollections.form.chequeNumber') }}</label>
                <input v-model="form.cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.cheque_number'] }"/>
              </div>
            </div>
            <div class="field-grid field-grid-8-4 mt-4">
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.otherCollections.form.receivingSafe') }}</label>
                <SelectBox v-model="form.cheque.receiving_safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.otherCollections.form.none')" />
                <p class="field-hint">{{ t('accounting.otherCollections.form.tagOnlyDoesNotAffectAny') }}</p>
              </div>
            </div>
            <!-- M-31: further post-dated cheques in the same collection. The first cheque above takes whatever is left of the total. -->
            <div class="mt-4">
              <p class="zv-label">Additional cheques (optional)</p>
              <div v-for="(x, i) in form.cheque.extra" :key="i" class="field-grid field-grid-4 mt-2">
                <input v-model="x.cheque_number" type="text" class="zv-input" placeholder="Cheque number"/>
                <input v-model="x.due_date" type="date" class="zv-input"/>
                <input v-model="x.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" placeholder="Amount"/>
                <button type="button" class="zv-action-btn zv-action-btn-danger" @click="form.cheque.extra.splice(i, 1)">Remove</button>
              </div>
              <button type="button" class="zv-btn zv-btn-secondary mt-2" @click="form.cheque.extra.push({ cheque_number: '', due_date: '', amount: '' })">+ Add another cheque</button>
              <p class="field-hint">Egyptian practice: a customer often hands over several post-dated cheques at once. The total above is split — the first cheque takes the remainder after these.</p>
            </div>
            <div class="zv-alert zv-alert-info mt-4">
              {{ t('accounting.otherCollections.form.receivingAChequeSettlesThisCollection') }}
            </div>
          </template>
        </div>
      </div>

      <!-- ══════════════════ Comment ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.otherCollections.form.comment') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && otherCollection.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.otherCollections.form.journalEntry') }} — {{ otherCollection.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.otherCollections.form.account') }}</th><th class="zv-table-num">{{ t('accounting.otherCollections.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.otherCollections.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in otherCollection.journal_entry.lines" :key="line.id">
                <td><span class="zv-code">{{ line.chart_of_account?.code }}</span><span class="zv-account-name">{{ line.chart_of_account?.name_en }}</span></td>
                <td class="zv-table-num">{{ fmt(line.debit) }}</td>
                <td class="zv-table-num">{{ fmt(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.other-collections.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.otherCollections.form.close') }}</Link>

          <button
            v-if="isEdit && otherCollection.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.otherCollections.form.setToDraft') }}</button>

          <button
            v-if="isEdit && otherCollection.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('accounting.otherCollections.form.deleting') : t('common.delete') }}</button>

          <button
            v-if="!isEdit || otherCollection.status === 'draft'"
            type="submit" :disabled="form.processing || !canSubmit" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('accounting.otherCollections.form.saveRePost') : t('accounting.otherCollections.form.save')) }}</button>
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
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'
import SearchableSelect from '@/Components/SearchableSelect.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  partnerTypes:          { type: Array, default: () => [] },
  employeeAdvanceTypes:  { type: Array, default: () => [] },
  banks:                 { type: Array, default: () => [] },
  currencies:            { type: Array, default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  otherCollection:       { type: Object, default: null },
  periodClosed:          { type: Boolean, default: false },
  prefill:               { type: Object, default: () => ({}) },
})

// Rule 2 — Create and Edit share one Vue page.
const isEdit = computed(() => !!props.otherCollection?.id)
const isReadOnly = computed(() => isEdit.value && props.otherCollection.status !== 'draft')

const p = props.otherCollection

const form = useForm({
  partner_id: p?.partner_id ?? props.prefill?.partner_id ?? '',
  partner_type: p?.partner_type ?? props.prefill?.partner_type ?? '',
  employee_advance_type: p?.employee_advance_type ?? props.prefill?.employee_advance_type ?? '',
  collection_date: p?.collection_date ?? todayLocal(),
  money_type: p?.money_type ?? 'bank_deposit',
  reference_number: p?.reference_number ?? '',

  currency_id: p?.currency_id ?? props.functionalCurrencyId ?? '',
  exchange_rate: p?.exchange_rate ?? '1',
  amount: p?.amount ?? '',

  destination_type: p?.destination_type ?? 'bank_account',
  destination_bank_account_id: p?.destination_bank_account_id ?? '',
  destination_credit_facility_id: p?.destination_credit_facility_id ?? '',

  safe_account_id: p?.safe_account_id ?? '',
  receipt_number: p?.receipt_number ?? '',

  credit_account_id: p?.credit_account_id ?? '',

  cheque: {
    drawee_bank_id: p?.cheque?.drawee_bank_id ?? '',
    cheque_number: p?.cheque?.cheque_number ?? '',
    due_date: p?.cheque?.due_date ?? '',
    receiving_safe_account_id: p?.cheque?.receiving_safe_account_id ?? '',
    extra: (p?.cheques ?? []).filter(c => c.id !== p?.cheque?.id).map(c => ({ cheque_number: c.cheque_number, due_date: String(c.due_date).slice(0, 10), amount: c.cheque_amount })),
  },

  internal_notes: p?.internal_notes ?? '',
})

const creditAccountLabel = ref(p?.credit_account ? `${p.credit_account.code} — ${p.credit_account.name_en}` : '')
const suggestedAccountApplied = ref(false)

function setMoneyType(type) {
  if (isReadOnly.value) return
  form.money_type = type
}

const moneyTypeBlockTitle = computed(() => {
  const map = {
    bank_deposit: t('accounting.otherCollections.form.bankDeposit'),
    incoming_transfer: t('accounting.otherCollections.form.incomingTransfer'),
    cash_in_safe: t('accounting.otherCollections.form.cashInSafe'),
    cheque: t('accounting.otherCollections.form.cheque'),
  }
  return map[form.money_type] ?? ''
})

const currencyCode = computed(() => props.currencies.find(c => c.id == form.currency_id)?.code ?? '')

function partnerTypeLabel(type) {
  const map = {
    shareholder: t('accounting.otherCollections.form.shareholder'),
    employee: t('accounting.otherCollections.form.employee'),
    sister_company: t('accounting.otherCollections.form.sisterCompany'),
    supplier: t('accounting.otherCollections.form.supplier'),
    other: t('accounting.otherCollections.form.other'),
  }
  return map[type] ?? type
}

// ── Destinations (Current Account or Overdraft Facility) ─────────

const allDestinations = ref([])
async function fetchDestinations() {
  const res = await fetch(route('accounting.other-collections.destinations'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allDestinations.value = data.destinations ?? []
}
fetchDestinations()

const bankAccountDestinations = computed(() => allDestinations.value.filter(d => d.type === 'bank_account'))
const creditFacilityDestinations = computed(() => allDestinations.value.filter(d => d.type === 'credit_facility'))

// ── Safe accounts (131xxx) ────────────────────────────────────────

const safeAccounts = ref([])
async function fetchSafeAccounts() {
  const res = await fetch(route('accounting.other-collections.safe-accounts'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  safeAccounts.value = data.accounts ?? []
}
fetchSafeAccounts()

// ── Partners filtered by Partner Type ─────────────────────────────

const partners = ref(p?.partner ? [p.partner] : [])
// Only the answer to the LATEST request may fill the list — a slow reply for a
// previously chosen Partner Type must not overwrite the current one.
let partnersReq = 0
async function fetchPartners() {
  const myReq = ++partnersReq
  if (! form.partner_type) { partners.value = []; return }
  const res = await fetch(route('accounting.other-collections.partners', { partner_type: form.partner_type }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  if (myReq !== partnersReq) return
  partners.value = data.partners ?? []
}

function onPartnerTypeChange() {
  form.partner_id = ''
  form.employee_advance_type = ''
  form.credit_account_id = ''
  creditAccountLabel.value = ''
  fetchPartners()
  // Non-employee types resolve immediately — employee waits for the
  // Advance Type selector (see onEmployeeAdvanceTypeChange).
  if (form.partner_type !== 'employee') fetchDefaultCreditAccount()
}

function onEmployeeAdvanceTypeChange() {
  form.credit_account_id = ''
  creditAccountLabel.value = ''
  fetchDefaultCreditAccount()
}

function onPartnerChange() {
  // Nothing to fetch — the partner_type-driven suggestion already
  // covers the credit account; picking WHICH partner within that
  // type doesn't change the suggestion.
}

if (isEdit.value && form.partner_type) {
  fetchPartners()
}

// Arrived via a deep link (e.g. Advances & Settlements "Record
// Settlement") with partner_type/partner_id/employee_advance_type
// already set — resolve the partner list and default account.
if (!isEdit.value && props.prefill?.partner_type) {
  fetchPartners()
  fetchDefaultCreditAccount()
}

// ── Default credit account suggestion (user can always override) ──

async function fetchDefaultCreditAccount() {
  if (! form.partner_type || form.credit_account_id) return
  if (form.partner_type === 'employee' && ! form.employee_advance_type) return
  const res = await fetch(route('accounting.other-collections.default-credit-account', { partner_type: form.partner_type, employee_advance_type: form.employee_advance_type || undefined }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  if (data.account) {
    form.credit_account_id = data.account.id
    creditAccountLabel.value = `${data.account.code} — ${data.account.name_en}`
    suggestedAccountApplied.value = true
  }
}

// Clear the "suggested" hint the moment the user picks something
// themselves — SearchableSelect clears modelValue on typing, which
// is our signal here.
watch(() => form.credit_account_id, (val) => {
  if (! val) suggestedAccountApplied.value = false
})

// ── Submit ─────────────────────────────────────────────────────────

const canSubmit = computed(() => !!form.partner_id && !!form.partner_type && !!form.money_type && !!form.amount && !!form.credit_account_id && (form.partner_type !== 'employee' || !!form.employee_advance_type))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  // Blanking of the unused destination/source and cheque fields is done on
  // the OUTGOING copy only (form.transform). Mutating the form itself used to
  // wipe the user's cheque / bank choices on screen when the server rejected
  // the save and the user then switched Money Type back.
  form.transform(data => {
    const d = { ...data }
    if (d.money_type !== 'bank_deposit' && d.money_type !== 'incoming_transfer') {
      d.destination_type = null; d.destination_bank_account_id = ''; d.destination_credit_facility_id = ''
    }
    if (d.money_type !== 'cheque') {
      d.cheque = { drawee_bank_id: null, cheque_number: null, due_date: null, receiving_safe_account_id: null }
    }
    return d
  })

  if (isEdit.value) {
    form.put(route('accounting.other-collections.update', props.otherCollection.id))
  } else {
    form.post(route('accounting.other-collections.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.otherCollections.form.reopenThisCollectionForEditingThis'))) return

  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.other-collections.set-to-draft', props.otherCollection.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.otherCollections.form.deleteThisCollectionThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.other-collections.destroy', props.otherCollection.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>