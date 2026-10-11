<!--
══════════════════════════════════════════════════════════════════
  Accounting/CashExpensePayments/Form.vue

  Cash Expense Payment — a direct expense payment with no Supplier
  Bill behind it (no PO, no GRN, no Bill to match). Same THREE Money
  Type values, same "posts immediately, Set to Draft is the only way
  back" lifecycle as Other Payment.

  MULTI-LINE (August 2026) — one payment (one payee, one date, one
  money type, one source account) carries MULTIPLE expense lines via
  form.lines, each FULLY FLEXIBLE: its own Expense Account, own Cost
  Center, own Amount, own VAT % (+ Deductible checkbox — unchecked
  folds that line's VAT into its own cost instead of VAT Input 137,
  same rule as Supplier Bill), own Withholding Tax % (always on the
  line's own subtotal, before VAT, unaffected by deductibility).
  Structural sibling of Supplier Bill's own line table. Every picker
  here — Expense Account, Cost Center, Currency — is a searchable
  SelectBox (Sept 2026); they used to be plain native selects.

  Partner is OPTIONAL — except for Money Type = Payable Cheque, where
  it becomes required (a physical cheque needs a named payee). When
  no Partner is linked, Payee Description carries a free-text label
  instead.

  "Allocating with Customer Contracts" stays at the WHOLE-PAYMENT
  level (one shared table, confirmed with Mahmoud), but each row now
  also picks WHICH line it's drawn from — e.g. a payment with a
  Transportation line and an Accommodation line lets the user
  allocate the Transportation portion to one customer contract and
  the Accommodation portion to another. Auto-preselected when there's
  only one line.

  @layout    AppLayout
  @route     accounting.cash-expense-payments.create / .edit
  @props     currencies, functionalCurrencyId, costCenters,
             expenseAccounts, cashExpensePayment, periodClosed
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.cashExpensePayments.form.cashExpensePayment'), href: route('accounting.cash-expense-payments.index') }, { label: isEdit ? cashExpensePayment.payment_code : t('accounting.cashExpensePayments.form.new') }]"
      :title="isEdit ? cashExpensePayment.payment_code : t('accounting.cashExpensePayments.form.newCashExpensePayment')"
      :subtitle="t('accounting.cashExpensePayments.form.recordsADirectExpensePaymentWith')"
    />

    <div v-if="isEdit && cashExpensePayment.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.cashExpensePayments.form.thisPaymentIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.cashExpensePayments.form.thisPaymentIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && cashExpensePayment.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.cashExpensePayments.form.thisPaymentWasCancelledItCannot') }}
    </div>
    <div v-else-if="isEdit && cashExpensePayment.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.cashExpensePayments.form.thisPaymentWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        <div>
          <div>{{ t('accounting.cashExpensePayments.form.pleaseFixTheFollowingBeforeSaving') }}</div>
          <ul style="margin: 4px 0 0 18px; list-style: disc;">
            <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
          </ul>
        </div>
      </div>

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Payee ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.cashExpensePayments.form.whatWasPaid') }}</div>
            <div class="form-section-sub">{{ t('accounting.cashExpensePayments.form.whoWasPaidWhen') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3-6-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.paymentDate') }}</label>
              <input v-model="form.payment_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.payment_date }"/>
              <p v-if="form.errors.payment_date" class="zv-field-error">{{ form.errors.payment_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.cashExpensePayments.form.partner') }}</label>
              <SearchableSelect
                v-model="form.partner_id"
                v-model:label="partnerLabel"
                :search-url="route('accounting.cash-expense-payments.partners')"
                :placeholder="t('accounting.cashExpensePayments.form.searchPartnerOptional')"
                :min-chars="0"
              />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
              <p v-if="form.money_type === 'payable_cheque'" class="field-hint">{{ t('accounting.cashExpensePayments.form.requiredForAPayableChequeA') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.currency') }}</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :clearable="false" :class="{ 'zv-input-error': form.errors.currency_id }" />
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label" :class="{ 'zv-label-required': !form.partner_id }">{{ t('accounting.cashExpensePayments.form.payeeDescription') }}</label>
            <input v-model="form.payee_description" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.payee_description }" :placeholder="t('accounting.cashExpensePayments.form.eGCourierFeeGovernmentStamp')"/>
            <p v-if="form.errors.payee_description" class="zv-field-error">{{ form.errors.payee_description }}</p>
            <p v-else-if="form.partner_id" class="field-hint">{{ t('accounting.cashExpensePayments.form.optionalAPartnerIsAlreadyLinked') }}</p>
          </div>

          <!-- Money Type tiles — only THREE values, same as Other Payment / Supplier Payment -->
          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.moneyType') }}</label>
            <OptionTiles
              :model-value="form.money_type" variant="icon" :columns="3"
              :options="[
                { value: 'cash_payment', label: t('accounting.cashExpensePayments.form.cashPayment'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z\'/>' },
                { value: 'outgoing_transfer', label: t('accounting.cashExpensePayments.form.outgoingTransfer'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M16 17l4-4m0 0l-4-4m4 4H4m12 6H4m0 0l4 4m-4-4l4-4\'/>' },
                { value: 'payable_cheque', label: t('accounting.cashExpensePayments.form.payableCheque'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01\'/>' },
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
            <div class="form-section-sub">{{ t('accounting.cashExpensePayments.form.exactlyWhereThisMoneyLeftFrom') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Outgoing Transfer / Payable Cheque — shared source picker -->
          <template v-if="form.money_type === 'outgoing_transfer' || form.money_type === 'payable_cheque'">
            <div class="field-grid field-grid-4-6-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.accountType') }}</label>
                <SelectBox v-model="form.source_type" :options="[{ value: 'bank_account', label: t('accounting.cashExpensePayments.form.currentAccount') }, { value: 'credit_facility', label: t('accounting.cashExpensePayments.form.overdraftFacility') }]" @change="form.source_bank_account_id = ''; form.source_credit_facility_id = ''" :class="{ 'zv-input-error': form.errors.source_type }" />
                <p v-if="form.errors.source_type" class="zv-field-error">{{ form.errors.source_type }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.accountNumber') }}</label>
                <SelectBox v-model="form.source_bank_account_id" :options="bankAccountSources" value-key="id" :label-fn="s => s.label" :placeholder="t('accounting.cashExpensePayments.form.selectAccount')" :clearable="false" v-if="form.source_type === 'bank_account'" :class="{ 'zv-input-error': form.errors.source_bank_account_id }" />
                <SelectBox v-model="form.source_credit_facility_id" :options="creditFacilitySources" value-key="id" :label-fn="s => s.label" :placeholder="t('accounting.cashExpensePayments.form.selectFacility')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.source_credit_facility_id }" />
                <p v-if="form.errors.source_bank_account_id" class="zv-field-error">{{ form.errors.source_bank_account_id }}</p>
                <p v-if="form.errors.source_credit_facility_id" class="zv-field-error">{{ form.errors.source_credit_facility_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.cashExpensePayments.form.referenceNumber') }}</label>
                <input v-model="form.reference_number" type="text" class="zv-input" :placeholder="t('accounting.cashExpensePayments.form.transferRef')"/>
              </div>
            </div>

            <template v-if="form.money_type === 'payable_cheque'">
              <div class="field-grid field-grid-2-2-8 mt-4">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.dueDate') }}</label>
                  <input v-model="form.cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.due_date'] }"/>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.chequeNumber') }}</label>
                  <input v-model="form.cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.cheque_number'] }"/>
                </div>
              </div>
              <div class="zv-alert zv-alert-info mt-4">
                {{ t('accounting.cashExpensePayments.form.issuingAChequeRecordsThisPayment') }}
              </div>
            </template>
          </template>

          <!-- Cash Payment -->
          <template v-else-if="form.money_type === 'cash_payment'">
            <div class="field-grid field-grid-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.cashExpensePayments.form.payingSafe') }}</label>
                <SelectBox v-model="form.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.cashExpensePayments.form.selectSafe')" :clearable="false" :class="{ 'zv-input-error': form.errors.safe_account_id }" />
                <p v-if="form.errors.safe_account_id" class="zv-field-error">{{ form.errors.safe_account_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.cashExpensePayments.form.receiptNumber') }}</label>
                <input v-model="form.receipt_number" type="text" class="zv-input"/>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- ══════════════════ Expense Lines ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#FFBF00">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-2m-2 5h10a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.cashExpensePayments.form.expenseLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.cashExpensePayments.form.whichExpensesMakeUpThisPayment') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap sb-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.cashExpensePayments.form.description') }}</th>
                  <th>{{ t('accounting.cashExpensePayments.form.expenseAccount') }}</th>
                  <th>{{ t('accounting.cashExpensePayments.form.costCenter') }}</th>
                  <th class="sb-th-qty">{{ t('common.amount') }}</th>
                  <th class="sb-th-vat">{{ t('accounting.cashExpensePayments.form.vatPercent') }}</th>
                  <th class="sb-th-vat">{{ t('accounting.cashExpensePayments.form.withholdingTaxPercent') }}</th>
                  <th class="sb-th-qty">{{ t('accounting.cashExpensePayments.form.netAmount') }}</th>
                  <th class="sb-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td><input v-model="line.description" type="text" class="zv-input so-cell-input" :placeholder="t('accounting.cashExpensePayments.form.egTaxiToAirport')"/></td>
                  <td>
                    <SelectBox v-model="line.expense_account_id" :options="expenseAccounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.cashExpensePayments.form.selectAccount')" :clearable="false" class="so-cell-input" size="sm" />
                  </td>
                  <td>
                    <SelectBox v-model="line.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => cc.code" :placeholder="t('accounting.cashExpensePayments.form.none')" class="so-cell-input" size="sm" />
                    <p v-if="lineRequiresCostCenter(line) && !line.cost_center_id" class="cep-line-warn">{{ t('accounting.cashExpensePayments.form.requiresCostCenter') }}</p>
                  </td>
                  <td><input v-model="line.subtotal" type="number" step="0.01" min="0.01" class="zv-input so-cell-input text-end"/></td>
                  <td class="cep-vat-cell">
                    <div class="cep-rate-row">
                      <input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input so-cell-input text-end cep-rate-input"/>
                      <label class="sb-deductible-toggle" :title="t('accounting.cashExpensePayments.form.uncheckIfThisVatIsNotRecoverable')">
                        <input type="checkbox" v-model="line.is_deductible"/> {{ t('accounting.cashExpensePayments.form.deductible') }}
                      </label>
                    </div>
                    <div class="cep-rate-amount-display">{{ fmt(lineVatAmount(line)) }}</div>
                  </td>
                  <td class="cep-vat-cell">
                    <input v-model="line.withholding_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input so-cell-input text-end cep-rate-input"/>
                    <div class="cep-rate-amount-display">{{ fmt(lineWhtAmount(line)) }}</div>
                  </td>
                  <td class="zv-table-num">{{ fmt(lineNetAmount(line)) }}</td>
                  <td class="sb-th-actions">
                    <button type="button" class="zv-btn-remove-row" :disabled="form.lines.length === 1" :title="t('accounting.cashExpensePayments.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="so-lines-footer">
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="addLine">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
              {{ t('accounting.cashExpensePayments.form.addLine') }}
            </button>
            <div class="cep-totals-row">
              <span class="so-total-lbl">{{ t('accounting.cashExpensePayments.form.amountBeforeTax') }}</span>
              <span class="so-total-val cep-totals-small">{{ fmt(totalSubtotal) }}</span>
              <span class="so-total-lbl">{{ t('accounting.cashExpensePayments.form.vatPercent').replace(' %','') }}</span>
              <span class="so-total-val cep-totals-small">{{ fmt(totalVat) }}</span>
              <span class="so-total-lbl">{{ t('accounting.cashExpensePayments.form.withholdingTaxPercent').replace(' %','') }}</span>
              <span class="so-total-val cep-totals-small">{{ fmt(totalWht) }}</span>
              <span class="so-total-lbl">{{ t('accounting.cashExpensePayments.form.netAmountToPay') }}</span>
              <span class="so-total-val">{{ fmt(totalNet) }} {{ currencyCode }}</span>
            </div>
          </div>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <!-- ══════════════════ Allocating with Customer Contracts ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#10b981">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m14-10h-2M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6v-3a1 1 0 011-1h2a1 1 0 011 1v3"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.cashExpensePayments.form.allocatingWithCustomerContracts') }}</div>
            <div class="form-section-sub">{{ t('accounting.cashExpensePayments.form.optionalTagsThisExpenseToOne') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table cep-alloc-table">
              <thead>
                <tr>
                  <th v-if="form.lines.length > 1" style="width:16%">{{ t('accounting.cashExpensePayments.form.expenseLine') }}</th>
                  <th style="width:18%">{{ t('accounting.cashExpensePayments.form.customer') }}</th>
                  <th style="width:22%">{{ t('accounting.cashExpensePayments.form.contractName') }}</th>
                  <th style="width:14%">{{ t('accounting.cashExpensePayments.form.contractCode') }}</th>
                  <th style="width:14%" class="text-end">{{ t('accounting.cashExpensePayments.form.contractAmount') }}</th>
                  <th style="width:14%" class="text-end">{{ t('accounting.cashExpensePayments.form.allocateAmount') }}</th>
                  <th style="width:6%"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in allocationRows" :key="row.key">
                  <td v-if="form.lines.length > 1">
                    <SelectBox v-model="row.line_index" :options="lineOptions" value-key="index" :label-fn="opt => opt.label" :placeholder="t('accounting.cashExpensePayments.form.selectExpenseLine')" :clearable="false" class="so-cell-input" size="sm" />
                  </td>
                  <td>
                    <SelectBox v-model="row.customer_id" :options="allocationCustomers" value-key="id" :label-fn="c => c.name_en" :placeholder="t('accounting.cashExpensePayments.form.selectCustomer')" :clearable="false" @change="onAllocationCustomerChange(idx)" class="so-cell-input" size="sm" />
                  </td>
                  <td>
                    <SelectBox v-model="row.project_id" :options="(allocationProjectsByCustomer[row.customer_id] ?? [])" value-key="id" :label-fn="p => p.name_en" :placeholder="row.customer_id ? t('accounting.cashExpensePayments.form.selectContract') : t('accounting.cashExpensePayments.form.selectCustomerFirst')" :clearable="false" :disabled="!row.customer_id" @change="onAllocationProjectChange(idx)" class="so-cell-input" size="sm" />
                  </td>
                  <td class="font-mono cep-alloc-readonly">{{ row.contract_code || '—' }}</td>
                  <td class="zv-table-num cep-alloc-readonly">{{ row.contract_value ? `${fmt(row.contract_value)} ${row.currency_code}` : '—' }}</td>
                  <td><input v-model="row.allocated_amount" type="number" step="0.01" min="0.01" class="zv-input so-cell-input text-end" placeholder="0.00"/></td>
                  <td>
                    <button type="button" class="so-remove-btn" @click="removeAllocationRow(idx)" :title="t('accounting.cashExpensePayments.form.removeRow')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="so-lines-footer">
            <button type="button" class="od-add-row-btn" @click="addAllocationRow">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('accounting.cashExpensePayments.form.addAllocation') }}
            </button>
            <div v-if="allocationRows.length" class="so-total-row">
              <span class="so-total-lbl">{{ t('accounting.cashExpensePayments.form.allocated') }}</span>
              <span class="so-total-val" :class="{ 'cep-alloc-over': allocationTotal > totalSubtotal + 0.01 }">{{ fmt(allocationTotal) }}</span>
              <span class="so-total-lbl">/ {{ fmt(totalSubtotal) }} {{ currencyCode }}</span>
            </div>
          </div>
          <p v-if="form.errors.allocations" class="zv-field-error mt-2">{{ form.errors.allocations }}</p>
          <p class="field-hint mt-2">{{ t('accounting.cashExpensePayments.form.doesNotHaveToAddUp') }}</p>
        </div>
      </div>

      <!-- ══════════════════ Comment ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.cashExpensePayments.form.comment') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && cashExpensePayment.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.cashExpensePayments.form.journalEntry') }} — {{ cashExpensePayment.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.cashExpensePayments.form.account') }}</th><th class="zv-table-num">{{ t('accounting.cashExpensePayments.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.cashExpensePayments.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in cashExpensePayment.journal_entry.lines" :key="line.id">
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
          <Link :href="route('accounting.cash-expense-payments.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.cashExpensePayments.form.close') }}</Link>

          <button
            v-if="isEdit && cashExpensePayment.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.cashExpensePayments.form.setToDraft') }}</button>

          <button
            v-if="isEdit && cashExpensePayment.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('accounting.cashExpensePayments.form.deleting') : t('common.delete') }}</button>

          <button
            v-if="!isEdit || cashExpensePayment.status === 'draft'"
            type="submit" :disabled="form.processing || !canSubmit" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('accounting.cashExpensePayments.form.saveRePost') : t('accounting.cashExpensePayments.form.save')) }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { sum as sumMoney, lineVat } from '@/Utils/money'
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
  currencies:            { type: Array, default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  costCenters:           { type: Array, default: () => [] },
  expenseAccounts:       { type: Array, default: () => [] },
  cashExpensePayment:    { type: Object, default: null },
  periodClosed:          { type: Boolean, default: false },
})

const isEdit = computed(() => !!props.cashExpensePayment?.id)
const isReadOnly = computed(() => isEdit.value && props.cashExpensePayment.status !== 'draft')

const p = props.cashExpensePayment

// ── Default VAT rate (company Tax Settings — editable, never
//    enforced) — applied to newly added lines only, never overrides a
//    saved value. No such default exists for Withholding Tax anywhere
//    in this system — always typed in fresh. ──────────────────────

const defaultVatRate = ref('0.00')
async function fetchDefaultVatRate() {
  const res = await fetch(route('accounting.cash-expense-payments.default-vat-rate'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  defaultVatRate.value = data.vat_rate ?? '0.00'
  if (! isEdit.value && form.lines.length === 1 && form.lines[0].vat_rate === '0.00') {
    form.lines[0].vat_rate = defaultVatRate.value
  }
}
fetchDefaultVatRate()

function blankLine() {
  return { description: '', expense_account_id: '', cost_center_id: '', subtotal: '', vat_rate: defaultVatRate.value, is_deductible: true, withholding_tax_rate: '0.00' }
}

function mapExistingLine(l) {
  return {
    description: l.description ?? '',
    expense_account_id: l.expense_account_id ?? '',
    cost_center_id: l.cost_center_id ?? '',
    subtotal: l.subtotal ?? '',
    vat_rate: l.vat_rate ?? '0.00',
    is_deductible: l.is_deductible ?? true,
    withholding_tax_rate: l.withholding_tax_rate ?? '0.00',
  }
}

const form = useForm({
  partner_id: p?.partner_id ?? '',
  payee_description: p?.payee_description ?? '',

  payment_date: p?.payment_date ?? todayLocal(),
  money_type: p?.money_type ?? 'outgoing_transfer',
  reference_number: p?.reference_number ?? '',

  currency_id: p?.currency_id ?? props.functionalCurrencyId ?? '',
  exchange_rate: p?.exchange_rate ?? '1',

  lines: p?.lines?.length ? p.lines.map(mapExistingLine) : [blankLine()],

  source_type: p?.source_type ?? 'bank_account',
  source_bank_account_id: p?.source_bank_account_id ?? '',
  source_credit_facility_id: p?.source_credit_facility_id ?? '',

  safe_account_id: p?.safe_account_id ?? '',
  receipt_number: p?.receipt_number ?? '',

  cheque: {
    cheque_number: p?.cheque?.cheque_number ?? '',
    due_date: p?.cheque?.due_date ?? '',
  },

  allocations: [],

  internal_notes: p?.internal_notes ?? '',
})

const partnerLabel = ref(p?.partner ? `${p.partner.code} — ${p.partner.name_en}` : '')

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(idx) {
  if (form.lines.length === 1) return
  form.lines.splice(idx, 1)
  // Allocation rows point at lines by position. Removing line `idx`:
  //  - rows that pointed AT it have no line any more -> cleared (or the only
  //    remaining line when just one is left);
  //  - rows that pointed at a LATER line must move down by one, otherwise
  //    they silently end up on a different expense line than the user chose.
  for (const row of allocationRows.value) {
    if (row.line_index === idx) row.line_index = form.lines.length === 1 ? 0 : null
    else if (row.line_index !== null && row.line_index > idx) row.line_index -= 1
  }
}

function lineRequiresCostCenter(line) {
  return !!props.expenseAccounts.find(a => a.id == line.expense_account_id)?.requires_cost_center
}

// Percent amounts use the same exact truncating maths as the server (bcmath),
// so the preview never differs from the saved figure by a cent.
function lineVatAmount(line) { return Number(lineVat(line.subtotal || 0, line.vat_rate || 0)) }
function lineWhtAmount(line) { return Number(lineVat(line.subtotal || 0, line.withholding_tax_rate || 0)) }
function lineNetAmount(line) { return Number(line.subtotal || 0) + lineVatAmount(line) - lineWhtAmount(line) }

const totalSubtotal = computed(() => form.lines.reduce((s, l) => s + Number(l.subtotal || 0), 0))
const totalVat      = computed(() => form.lines.reduce((s, l) => s + lineVatAmount(l), 0))
const totalWht       = computed(() => form.lines.reduce((s, l) => s + lineWhtAmount(l), 0))
const totalNet       = computed(() => form.lines.reduce((s, l) => s + lineNetAmount(l), 0))

function setMoneyType(type) {
  if (isReadOnly.value) return
  form.money_type = type
}

const moneyTypeBlockTitle = computed(() => {
  const map = {
    cash_payment: t('accounting.cashExpensePayments.form.cashInformation'),
    outgoing_transfer: t('accounting.cashExpensePayments.form.outgoingTransferInformation'),
    payable_cheque: t('accounting.cashExpensePayments.form.payableChequeInformation'),
  }
  return map[form.money_type] ?? ''
})

const currencyCode = computed(() => props.currencies.find(c => c.id == form.currency_id)?.code ?? '')

// ── Sources (Current Account or Overdraft Facility) ─────────

const allSources = ref([])
async function fetchSources() {
  const res = await fetch(route('accounting.cash-expense-payments.sources'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allSources.value = data.sources ?? []
}
fetchSources()

const bankAccountSources = computed(() => allSources.value.filter(s => s.type === 'bank_account'))
const creditFacilitySources = computed(() => allSources.value.filter(s => s.type === 'credit_facility'))

// ── Safe accounts (131xxx) ────────────────────────────────────────

const safeAccounts = ref([])
async function fetchSafeAccounts() {
  const res = await fetch(route('accounting.cash-expense-payments.safe-accounts'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  safeAccounts.value = data.accounts ?? []
}
fetchSafeAccounts()

// ── Allocating with Customer Contracts ────────────────────────────
// Reporting-only (see migration 000179) — never touches the journal
// entry, never required to add up to the full payment. Each row also
// carries line_index — which of form.lines it's drawn from — auto-
// preselected to 0 when there's only one line.

let allocationRowKey = 0
const allocationRows = ref(
  (p?.allocations ?? []).map((a) => ({
    key: allocationRowKey++,
    line_index: a.cash_expense_payment_line_id
      ? (p.lines ?? []).findIndex(l => l.id === a.cash_expense_payment_line_id)
      : (form.lines.length === 1 ? 0 : null),
    customer_id: a.project?.partner_id ?? '',
    project_id: a.project_id,
    contract_code: a.project?.contract_code ?? '',
    contract_value: a.project?.contract_value ?? '',
    currency_code: '', // resolved once that customer's project list loads
    allocated_amount: a.allocated_amount,
  }))
)

const lineOptions = computed(() => form.lines.map((l, idx) => ({
  index: idx,
  label: l.description || `${t('accounting.cashExpensePayments.form.expenseLine')} ${idx + 1}${l.expense_account_id ? ' — ' + (props.expenseAccounts.find(a => a.id == l.expense_account_id)?.name_en ?? '') : ''}`,
})))

const allocationCustomers = ref([])
async function fetchAllocationCustomers() {
  const res = await fetch(route('accounting.cash-expense-payments.customers'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allocationCustomers.value = data.customers ?? []
}
fetchAllocationCustomers()

const allocationProjectsByCustomer = ref({})
async function fetchProjectsForCustomer(customerId) {
  if (! customerId || allocationProjectsByCustomer.value[customerId]) return
  const res = await fetch(route('accounting.cash-expense-payments.projects-for-customer', customerId), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allocationProjectsByCustomer.value = { ...allocationProjectsByCustomer.value, [customerId]: data.projects ?? [] }
  for (const row of allocationRows.value) {
    if (row.customer_id === customerId && row.project_id) {
      const proj = data.projects.find(pr => pr.id === row.project_id)
      if (proj) row.currency_code = proj.currency?.code ?? ''
    }
  }
}

for (const row of allocationRows.value) {
  if (row.customer_id) fetchProjectsForCustomer(row.customer_id)
}

function addAllocationRow() {
  allocationRows.value.push({
    key: allocationRowKey++,
    line_index: form.lines.length === 1 ? 0 : null,
    customer_id: '', project_id: '', contract_code: '', contract_value: '', currency_code: '', allocated_amount: '',
  })
}

function removeAllocationRow(idx) {
  allocationRows.value.splice(idx, 1)
}

function onAllocationCustomerChange(idx) {
  const row = allocationRows.value[idx]
  row.project_id = ''
  row.contract_code = ''
  row.contract_value = ''
  row.currency_code = ''
  fetchProjectsForCustomer(row.customer_id)
}

function onAllocationProjectChange(idx) {
  const row = allocationRows.value[idx]
  const proj = (allocationProjectsByCustomer.value[row.customer_id] ?? []).find(p => p.id == row.project_id)
  row.contract_code = proj?.contract_code ?? ''
  row.contract_value = proj?.contract_value ?? ''
  row.currency_code = proj?.currency?.code ?? ''
}

const allocationTotal = computed(() =>
  Number(sumMoney(allocationRows.value.map(r => r.allocated_amount))) // Audit L6
)

// ── Submit ─────────────────────────────────────────────────────────

const canSubmit = computed(() =>
  form.lines.length > 0 &&
  form.lines.every(l => !!l.expense_account_id && !!l.subtotal) &&
  !!form.money_type &&
  (!!form.partner_id || !!form.payee_description)
)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  // Only rows with both a Project and an amount are real allocations —
  // an in-progress empty row (added but not yet filled in) is silently
  // dropped rather than rejected.
  form.allocations = allocationRows.value
    .filter(r => r.project_id && r.allocated_amount)
    .map(r => ({ line_index: r.line_index, project_id: r.project_id, allocated_amount: r.allocated_amount }))

  // Blanking of the unused destination/source and cheque fields is done on
  // the OUTGOING copy only (form.transform). Mutating the form itself used to
  // wipe the user's cheque / bank choices on screen when the server rejected
  // the save and the user then switched Money Type back.
  form.transform(data => {
    const d = { ...data }
    if (d.money_type === 'cash_payment') {
      d.source_type = null; d.source_bank_account_id = ''; d.source_credit_facility_id = ''
    }
    if (d.money_type !== 'payable_cheque') d.cheque = { cheque_number: null, due_date: null }
    return d
  })

  if (isEdit.value) {
    form.put(route('accounting.cash-expense-payments.update', props.cashExpensePayment.id))
  } else {
    form.post(route('accounting.cash-expense-payments.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.cashExpensePayments.form.reopenThisPaymentForEditingThis'))) return

  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.cash-expense-payments.set-to-draft', props.cashExpensePayment.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.cashExpensePayments.form.deleteThisPaymentThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.cash-expense-payments.destroy', props.cashExpensePayment.id), {
    onFinish: () => { deleting.value = false },
  })
}
</script>

<style>
.sb-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.sb-th-qty { width: 8rem; text-align: end; }
.sb-th-vat { width: 9.5rem; text-align: end; }
.sb-th-actions { width: 3rem; text-align: center; }

.sb-deductible-toggle {
  display: flex; align-items: center; gap: 3px;
  font-size: 10px; color: var(--zv-text-muted); cursor: pointer; white-space: nowrap; flex: none;
}
.sb-deductible-toggle input[type="checkbox"] { width: 12px; height: 12px; margin: 0; }

.cep-line-warn { font-size: 10px; color: #ef4444; margin: 2px 0 0; }

/* Allocating with Customer Contracts — reused from the Leasing cheque
   schedule repeater (ContractForm.vue) for visual consistency. */
.so-cell-input { padding: 6px 8px; font-size: 12.5px; min-height: unset; }
.so-remove-btn {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
    color: var(--zv-text-muted); transition: all 0.12s;
}
.so-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }
.so-lines-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--zv-border);
    flex-wrap: wrap; gap: 10px;
}
.od-add-row-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border: 1px dashed var(--zv-border);
    border-radius: var(--zv-radius); background: transparent;
    color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.od-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }
.so-total-row { display: flex; align-items: center; gap: 12px; }
.so-total-lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); }
.so-total-val { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--zv-text-primary); }

.cep-totals-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cep-totals-small { font-size: 14px; }

.cep-alloc-readonly { color: var(--zv-text-muted); }
.cep-alloc-over { color: #ef4444 !important; }

/* VAT % / WHT % — rate + Deductible checkbox on one row; the computed
   amount sits on its OWN row underneath, in plain (not boxed) text, so
   it always has room to display fully instead of being squeezed
   beside the rate input. */
.cep-vat-cell { min-width: 9.5rem; }
.cep-rate-row { display: flex; align-items: center; gap: 6px; }
.cep-rate-input { max-width: 60px; flex: none; }
.cep-rate-amount-display {
  margin-top: 4px; font-size: 11.5px; text-align: end;
  color: var(--zv-text-secondary); font-variant-numeric: tabular-nums;
}
</style>