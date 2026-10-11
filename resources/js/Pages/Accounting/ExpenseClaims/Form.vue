<!--
══════════════════════════════════════════════════════════════════
  Accounting/ExpenseClaims/Form.vue

  Create or edit a draft claim. Only drafts reach here — the Show page
  hides Edit once a claim is approved, and the service refuses it.

  Every receipt is its own line with its own expense account, because
  one trip produces a taxi fare, a hotel night and a meal that do not
  belong in the same account. Migration 000234 records why the account
  is picked each time rather than defaulted: employee reimbursements
  have no fixed account. Accounts 522 / 532 / 543 are sorted to the
  top of the picker as the natural choices, not forced ones.

  The running total is shown in the claim's own currency, which is
  what the employee is owed. The functional-currency figure that
  reaches the ledger is computed server-side per line and summed —
  see ExpenseClaimService's header on why that ordering matters.

  @layout    AppLayout
  @route     hr.expense-claims.create / hr.expense-claims.edit
  @props     claim (nullable), employees, expenseAccounts, costCenters, currencies
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="isEdit ? t('accounting.hr.expenseClaimForm.editExpenseClaim') : t('accounting.hr.expenseClaimForm.newExpenseClaim')"
      :subtitle="t('accounting.hr.expenseClaimForm.attachEveryReceiptAsItsOwnLine')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    />

    <!-- ══════════════════ The claim ══════════════════ -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#0F2554">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/>
          </svg>
        </div>
        <div>
          <div class="form-section-title">{{ t('accounting.hr.expenseClaimForm.whoIsClaiming') }}</div>
          <div class="form-section-sub">{{ t('accounting.hr.expenseClaimForm.employeeDateAndCurrency') }}</div>
        </div>
      </div>

      <div class="form-section-body">
      <div class="field-grid field-grid-3">
        <div class="field-group">
          <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimForm.employee') }}</label>
          <SelectBox
            v-model="form.partner_id"
            :options="employees"
            value-key="id"
            label-key="name_en"
            :placeholder="t('accounting.hr.expenseClaimForm.selectEmployee')"
            :clearable="false"
          />
          <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
        </div>

        <div class="field-group">
          <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimForm.claimDate') }}</label>
          <input v-model="form.claim_date" type="date" class="zv-input"/>
          <p v-if="form.errors.claim_date" class="zv-field-error">{{ form.errors.claim_date }}</p>
        </div>

        <div class="field-group">
          <label class="zv-label">{{ t('accounting.hr.expenseClaimForm.title') }}</label>
          <input
            v-model="form.title" type="text" class="zv-input"
            :placeholder="t('accounting.hr.expenseClaimForm.titlePlaceholder')"
          />
        </div>

        <div class="field-group">
          <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimForm.currency') }}</label>
          <SelectBox
            v-model="form.currency_id"
            :options="currencies"
            value-key="id"
            label-key="code"
            :clearable="false"
          />
          <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
        </div>

        <div class="field-group">
          <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimForm.exchangeRate') }}</label>
          <input v-model="form.exchange_rate" type="number" step="0.000001" min="0" class="zv-input"/>
          <p v-if="form.errors.exchange_rate" class="zv-field-error">{{ form.errors.exchange_rate }}</p>
        </div>
      </div>

      <div class="field-group mt-4">
        <label class="zv-label">{{ t('accounting.hr.expenseClaimForm.description') }}</label>
        <textarea v-model="form.description" rows="2" class="zv-textarea"></textarea>
      </div>
      </div>
    </div>

    <!-- ══════════════════ Receipts ══════════════════ -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#1D4ED8">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <div>
          <div class="form-section-title">{{ t('accounting.hr.expenseClaimForm.receipts') }}</div>
          <div class="form-section-sub">{{ t('accounting.hr.expenseClaimForm.oneLinePerReceipt') }}</div>
        </div>
        <button type="button" class="zv-btn zv-btn-sm zv-btn-ghost ecf-add" @click="addLine">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.expenseClaimForm.addReceipt') }}
        </button>
      </div>

      <div class="form-section-body">
      <p v-if="form.errors.lines" class="zv-field-error mb-2">{{ form.errors.lines }}</p>

      <EmptyState
        v-if="form.lines.length === 0"
        :title="t('accounting.hr.expenseClaimForm.noReceiptsYet')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th style="min-width: 200px">{{ t('accounting.hr.expenseClaimForm.expenseAccount') }}</th>
              <th style="min-width: 130px">{{ t('accounting.hr.expenseClaimForm.expenseDate') }}</th>
              <th style="min-width: 180px">{{ t('accounting.hr.expenseClaimForm.lineDescription') }}</th>
              <th style="min-width: 140px">{{ t('accounting.hr.expenseClaimForm.costCenter') }}</th>
              <th class="text-right" style="min-width: 120px">{{ t('accounting.hr.expenseClaimForm.lineAmount') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(line, i) in form.lines" :key="i">
              <td>
                <SelectBox
                  v-model="line.expense_account_id"
                  :options="expenseAccounts"
                  value-key="id"
                  :label-fn="a => `${a.code} — ${a.name_en}`"
                  :placeholder="t('accounting.hr.expenseClaimForm.selectAccount')"
                  :clearable="false"
                  size="sm"
                />
                <p v-if="form.errors[`lines.${i}.expense_account_id`]" class="zv-field-error">
                  {{ form.errors[`lines.${i}.expense_account_id`] }}
                </p>
              </td>
              <td>
                <input v-model="line.expense_date" type="date" class="zv-input zv-input-sm"/>
                <p v-if="form.errors[`lines.${i}.expense_date`]" class="zv-field-error">
                  {{ form.errors[`lines.${i}.expense_date`] }}
                </p>
              </td>
              <td>
                <input v-model="line.description" type="text" class="zv-input zv-input-sm"/>
                <p v-if="form.errors[`lines.${i}.description`]" class="zv-field-error">
                  {{ form.errors[`lines.${i}.description`] }}
                </p>
              </td>
              <td>
                <SelectBox
                  v-model="line.cost_center_id"
                  :options="costCenters"
                  value-key="id"
                  label-key="name_en"
                  :placeholder="t('accounting.hr.expenseClaimForm.none')"
                  size="sm"
                />
                <p v-if="form.errors[`lines.${i}.cost_center_id`]" class="zv-field-error">
                  {{ form.errors[`lines.${i}.cost_center_id`] }}
                </p>
              </td>
              <td>
                <input v-model="line.amount" type="number" step="0.01" min="0" class="zv-input zv-input-sm text-right"/>
                <p v-if="form.errors[`lines.${i}.amount`]" class="zv-field-error">
                  {{ form.errors[`lines.${i}.amount`] }}
                </p>
              </td>
              <td class="zv-col-actions">
                <button type="button" class="zv-action-btn" :title="t('accounting.hr.expenseClaimForm.removeLine')" @click="removeLine(i)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="text-right font-semibold">{{ t('accounting.hr.expenseClaimForm.total') }}</td>
              <td class="text-right font-mono font-semibold">{{ total }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      </div>
    </div>

    <div class="zv-card">
      <div class="zv-card-footer flex items-center justify-end gap-3">
        <Link :href="route('hr.expense-claims.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
        <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="submit">
          {{ t('common.save') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney } from '@/Utils/money'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  claim:           { type: Object, default: null },
  employees:       { type: Array,  default: () => [] },
  expenseAccounts: { type: Array,  default: () => [] },
  costCenters:     { type: Array,  default: () => [] },
  currencies:      { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.claim)

function blankLine() {
  return {
    expense_account_id: '',
    cost_center_id: '',
    expense_date: todayLocal(),
    description: '',
    amount: '',
  }
}

const form = useForm({
  partner_id:    props.claim?.partner_id ?? '',
  claim_date:    props.claim?.claim_date ?? todayLocal(),
  title:         props.claim?.title ?? '',
  description:   props.claim?.description ?? '',
  currency_id:   props.claim?.currency_id ?? (props.currencies[0]?.id ?? ''),
  exchange_rate: props.claim?.exchange_rate ?? 1,
  lines: props.claim?.lines?.length
    ? props.claim.lines.map((l) => ({
        expense_account_id: l.expense_account_id,
        cost_center_id: l.cost_center_id ?? '',
        expense_date: l.expense_date,
        description: l.description,
        amount: l.amount,
      }))
    : [blankLine()],
})

/** The claim's own currency — what the employee is owed. */
const total = computed(() =>
  sumMoney(form.lines.map(l => l.amount)) // Audit L6: exact decimal sum
)

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(index) {
  form.lines.splice(index, 1)
}

function submit() {
  form.transform((data) => ({
    ...data,
    lines: data.lines.map((l) => ({
      ...l,
      cost_center_id: l.cost_center_id === '' ? null : l.cost_center_id,
    })),
  }))

  if (isEdit.value) {
    form.put(route('hr.expense-claims.update', props.claim.id))
  } else {
    form.post(route('hr.expense-claims.store'))
  }
}
</script>

<style scoped>
/* Only what the shared system does not provide. The sections, the
   field grid and the footer are app-wide classes (form-section,
   field-grid-3, zv-card-footer) — this page used to hand-roll all
   three, which is why it did not look like any other form here. */

/* Pushes "Add receipt" to the end of the section header, which is a
   flex row of icon + titles. */
.ecf-add { margin-inline-start: auto; }
</style>
