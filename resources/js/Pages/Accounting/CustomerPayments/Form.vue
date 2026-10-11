<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerPayments/Form.vue

  Customer Collection — see VeroCFO_Customer_Collection_Design_v1_0.md,
  the authoritative reference for every decision in this file. Built
  from six real reference screenshots (Bank Deposit, Incoming
  Transfer, Cash in Safe, Cheque, cross-currency, Down Payment).

  POSTS IMMEDIATELY ON SAVE — no draft stage (design doc: the
  screenshots this was built from show a single Save action, not a
  draft-then-post two-step). There is no Edit mode for this form.

  Money Type (tile selector) drives which block renders below it:
  Bank Deposit / Incoming Transfer share one block (destination =
  Current Account or Overdraft Facility — confirmed valid either way);
  Cash in Safe and Cheque each have their own.

  Collection Type (tile selector, enabled once a customer is picked)
  drives what appears below the Money Type block: Settlement shows
  every open invoice (filtered by Invoice Currency) with an editable
  Settlement Amount per row; Down Payment shows a Contract/SO tag
  instead. Per the design doc's core insight, both post the exact
  same journal entry — this only changes what's captured alongside it.

  @layout    AppLayout
  @route     accounting.customer-payments.create
  @props     customers, banks, projects, currencies, functionalCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.customerPayments.form.customerCollection'), href: route('accounting.customer-payments.index') }, { label: isEdit ? customerPayment.payment_code : t('accounting.customerPayments.form.new') }]"
      :title="isEdit ? customerPayment.payment_code : t('accounting.customerPayments.form.newCustomerCollection')"
      :subtitle="t('accounting.customerPayments.form.recordsMoneyReceivedFromACustomer')"
    />

    <!-- Status context banner — always visible on an existing record so
         it's never ambiguous why fields are locked or what to do next. -->
    <div v-if="isEdit && customerPayment.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.customerPayments.form.thisCollectionIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.customerPayments.form.thisCollectionIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && customerPayment.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.customerPayments.form.thisCollectionWasCancelledItCannot') }}
    </div>
    <div v-else-if="isEdit && customerPayment.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.customerPayments.form.thisCollectionWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">

      <!-- Fallback banner: catches ANY validation error, even on a field
           this form doesn't show individual red text for. Without this,
           a rejected save can look exactly like nothing happened —
           which is the bug that was just fixed for the destination
           fields below; this is the safety net for anything else. -->
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        <div>
          <div>{{ t('accounting.customerPayments.form.pleaseFixTheFollowingBeforeSaving') }}</div>
          <ul style="margin: 4px 0 0 18px; list-style: disc;">
            <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
          </ul>
        </div>
      </div>

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Collection Details ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.customerPayments.form.moneyReceived') }}</div>
            <div class="form-section-sub">{{ t('accounting.customerPayments.form.whoPaidInWhatCurrencyAnd') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3-3-6">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.receivingDate') }}</label>
              <input v-model="form.payment_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.payment_date }"/>
              <p v-if="form.errors.payment_date" class="zv-field-error">{{ form.errors.payment_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.customerPayments.form.partnerType') }}</label>
              <input type="text" class="zv-input" value="Customer" disabled/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
              <SelectBox v-model="form.partner_id" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.customerPayments.form.selectCustomer')" :clearable="false" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-4 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.invoiceCurrency') }}</label>
              <SelectBox v-model="form.invoice_currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :placeholder="t('accounting.customerPayments.form.noneGeneralOnly')" :disabled="!form.partner_id" @change="onInvoiceCurrencyChange" />
              <p class="field-hint">{{ t('accounting.customerPayments.form.filtersWhichOpenInvoicesAreEligible') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.receiveCurrency') }}</label>
              <SelectBox v-model="form.receive_currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" @change="onReceiveCurrencyChange" :class="{ 'zv-input-error': form.errors.receive_currency_id }" />
              <p v-if="form.errors.receive_currency_id" class="zv-field-error">{{ form.errors.receive_currency_id }}</p>
            </div>
            <div class="field-group" v-if="isCrossCurrency">
              <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.invoiceReceiveRate') }}</label>
              <input v-model="form.invoice_to_receive_exchange_rate" type="number" step="0.000001" min="0" class="zv-input text-end"/>
              <p class="field-hint">{{ t('accounting.customerPayments.form.usedOnlyToMatchSettlementRows') }}</p>
            </div>
          </div>

          <!-- Money Type tiles -->
          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.moneyType') }}</label>
            <OptionTiles
              :model-value="form.money_type" variant="icon" :columns="4"
              :options="[
                { value: 'bank_deposit', label: t('accounting.customerPayments.form.bankDeposit'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6\'/>' },
                { value: 'incoming_transfer', label: t('accounting.customerPayments.form.incomingTransfer'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4\'/>' },
                { value: 'cash_in_safe', label: t('accounting.customerPayments.form.cashInSafe'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z\'/>' },
                { value: 'cheque', label: t('accounting.customerPayments.form.cheque'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01\'/>' },
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
            <div class="form-section-sub">{{ t('accounting.customerPayments.form.exactlyWhereThisMoneyLanded') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Bank Deposit / Incoming Transfer — shared block -->
          <template v-if="form.money_type === 'bank_deposit' || form.money_type === 'incoming_transfer'">
            <div class="field-grid field-grid-4-8">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.accountType') }}</label>
                <SelectBox v-model="form.destination_type" :options="[{ value: 'bank_account', label: t('accounting.customerPayments.form.currentAccount') }, { value: 'credit_facility', label: t('accounting.customerPayments.form.overdraftFacility') }]" @change="form.destination_bank_account_id = ''; form.destination_credit_facility_id = ''" :class="{ 'zv-input-error': form.errors.destination_type }" />
                <p v-if="form.errors.destination_type" class="zv-field-error">{{ form.errors.destination_type }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.accountNumber') }}</label>
                <SelectBox v-model="form.destination_bank_account_id" :options="bankAccountDestinations" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.customerPayments.form.selectAccount')" :clearable="false" v-if="form.destination_type === 'bank_account'" :class="{ 'zv-input-error': form.errors.destination_bank_account_id }" />
                <SelectBox v-model="form.destination_credit_facility_id" :options="creditFacilityDestinations" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.customerPayments.form.selectFacility')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.destination_credit_facility_id }" />
                <p v-if="form.errors.destination_bank_account_id" class="zv-field-error">{{ form.errors.destination_bank_account_id }}</p>
                <p v-if="form.errors.destination_credit_facility_id" class="zv-field-error">{{ form.errors.destination_credit_facility_id }}</p>
              </div>
            </div>
            <div class="field-grid field-grid-2-2-8 mt-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }} [{{ receiveCurrencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
                <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
              </div>
              <div class="field-group" v-if="isCrossCurrency">
                <label class="zv-label">{{ t('accounting.customerPayments.form.amountInInvoiceCurrency') }}</label>
                <input :value="fmt(amountInInvoiceCurrency)" type="text" class="zv-input text-end cc-readonly" readonly/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.customerPayments.form.referenceNumber') }}</label>
                <input v-model="form.reference_number" type="text" class="zv-input" :placeholder="t('accounting.customerPayments.form.transferRef')"/>
              </div>
            </div>
          </template>

          <!-- Cash in Safe -->
          <template v-else-if="form.money_type === 'cash_in_safe'">
            <div class="field-grid field-grid-6-3-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.receivingBranch') }}</label>
                <SelectBox v-model="form.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.customerPayments.form.selectSafe')" :clearable="false" :class="{ 'zv-input-error': form.errors.safe_account_id }" />
                <p v-if="form.errors.safe_account_id" class="zv-field-error">{{ form.errors.safe_account_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.receivedAmount') }} [{{ receiveCurrencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.customerPayments.form.receiptNumber') }}</label>
                <input v-model="form.receipt_number" type="text" class="zv-input"/>
              </div>
            </div>
            <div class="field-group mt-4" v-if="isCrossCurrency">
              <label class="zv-label">{{ t('accounting.customerPayments.form.amountInInvoiceCurrency') }}</label>
              <input :value="fmt(amountInInvoiceCurrency)" type="text" class="zv-input text-end cc-readonly" style="max-width: 220px;" readonly/>
            </div>
          </template>

          <!-- Cheque -->
          <template v-else-if="form.money_type === 'cheque'">
            <div class="field-grid field-grid-6-2-2-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.selectDraweeBank') }}</label>
                <SelectBox v-model="form.cheque.drawee_bank_id" :options="banks" value-key="id" :label-fn="b => b.name_en" :placeholder="t('accounting.customerPayments.form.selectBank')" :clearable="false" :class="{ 'zv-input-error': form.errors['cheque.drawee_bank_id'] }" />
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.chequeAmount') }} [{{ receiveCurrencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.dueDate') }}</label>
                <input v-model="form.cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.due_date'] }"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.chequeNumber') }}</label>
                <input v-model="form.cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.cheque_number'] }"/>
              </div>
            </div>
            <div class="field-grid field-grid-8-4 mt-4">
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.customerPayments.form.receivingSafe') }}</label>
                <SelectBox v-model="form.cheque.receiving_safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.customerPayments.form.none')" />
                <p class="field-hint">{{ t('accounting.customerPayments.form.tagOnlyDoesNotAffectAny') }}</p>
              </div>
              <div class="field-group" v-if="isCrossCurrency">
                <label class="zv-label">{{ t('accounting.customerPayments.form.amountInInvoiceCurrency') }}</label>
                <input :value="fmt(amountInInvoiceCurrency)" type="text" class="zv-input text-end cc-readonly" readonly/>
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
              {{ t('accounting.customerPayments.form.receivingAChequeSettlesTheInvoice') }}
            </div>
          </template>
        </div>
      </div>

      <!-- ══════════════════ Collection Type ══════════════════ -->
      <div class="form-section mb-5" v-if="form.partner_id && form.money_type">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.customerPayments.form.collectionType') }}</label>
            <OptionTiles
              :model-value="form.collection_type" variant="card"
              :options="[
                { value: 'settlement', label: t('accounting.customerPayments.form.settlement'), description: t('accounting.customerPayments.form.matchThisReceiptAgainstOpenInvoices') },
                { value: 'down_payment', label: t('accounting.customerPayments.form.downPayment'), description: t('accounting.customerPayments.form.tagItToAContractSO') },
              ]"
              @update:model-value="(v) => form.collection_type = v"
            />
          </div>
        </div>
      </div>

      <!-- ══════════════════ Settlement Information ══════════════════ -->
      <div class="form-section mb-5" v-if="form.collection_type === 'settlement' && form.partner_id">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.customerPayments.form.settlementInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.customerPayments.form.enterASettlementAmountOnWhichever') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Only while locked to a single invoice (arrived via the
               "Collect" button). "Show all open invoices" drops the
               lock and re-fetches, in case this isn't the one they
               meant to collect after all. -->
          <div v-if="lockedInvoiceId" class="zv-alert zv-alert-info mb-3">
            <span>{{ t('accounting.customerPayments.form.collectingInvoiceOnlyPrefix') }} <strong>{{ pre?.invoice_code }}</strong> {{ t('accounting.customerPayments.form.collectingInvoiceOnlySuffix') }}</span>
            <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" style="margin-inline-start: 12px;" @click="showAllOpenInvoices">
              {{ t('accounting.customerPayments.form.showAllOpenInvoices') }}
            </button>
          </div>

          <div v-if="openInvoicesLoading" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('accounting.customerPayments.form.loadingOpenInvoices') }}</div>
          <div v-else-if="!openInvoices.length" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.customerPayments.form.thisCustomerHasNoOpenInvoices') }}
          </div>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.customerPayments.form.project') }}</th>
                  <th>{{ t('accounting.customerPayments.form.invoiceNumber') }}</th>
                  <th>{{ t('accounting.customerPayments.form.invoiceDate') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerPayments.form.invoiceAmount') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerPayments.form.collected') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerPayments.form.netBalance') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerPayments.form.settlementAmount') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerPayments.form.debitWht') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in openInvoices" :key="inv.id">
                  <td>{{ inv.sales_order?.so_code ?? '—' }}</td>
                  <td><span class="zv-code">{{ inv.invoice_code }}</span></td>
                  <td>{{ inv.invoice_date }}</td>
                  <td class="zv-table-num">{{ fmt(inv.invoice_value) }}</td>
                  <td class="zv-table-num">{{ fmt(inv.amount_paid) }}</td>
                  <td class="zv-table-num">{{ fmt(inv.amount_outstanding) }}</td>
                  <td class="zv-table-num">
                    <input
                      v-model="settlementAmounts[inv.id]"
                      type="number" step="0.01" min="0" :max="inv.amount_outstanding_native"
                      class="zv-input text-end" :class="{ 'zv-input-error': exceedsOutstanding(inv) }"
                      style="width: 9rem;" placeholder="0.00"
                    />
                    <p class="field-hint">{{ t('accounting.customerPayments.form.fullSettlementHint', { amount: fmt(inv.amount_outstanding_native) }) }}</p>
                    <p v-if="exceedsOutstanding(inv)" class="zv-field-error">{{ t('accounting.customerPayments.form.exceedsNetBalance') }}</p>
                    <p v-if="fxEstimate(inv)" class="field-hint cc-fx-hint" :class="{ 'cc-fx-loss': fxEstimate(inv) < 0 }">
                      {{ fxEstimate(inv) > 0 ? t('accounting.customerPayments.form.estFxGain') : t('accounting.customerPayments.form.estFxLoss') }}: {{ fmt(Math.abs(fxEstimate(inv))) }}
                    </p>
                  </td>
                  <td class="zv-table-num">
                    <!-- Optional — most settlements have no withholding.
                         Zero/blank generates no extra journal line at all
                         (see CustomerPaymentService). Only counted at
                         the invoice's own booked rate — see field-hint. -->
                    <input
                      v-model="whtAmounts[inv.id]"
                      type="number" step="0.01" min="0"
                      class="zv-input text-end"
                      style="width: 8rem;" placeholder="0.00"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="cc-settlement-summary mt-4">
            <div><span>{{ t('accounting.customerPayments.form.totalSettled') }}</span><strong>{{ fmt(totalSettled) }}</strong></div>
            <div v-if="totalWht > 0"><span>{{ t('accounting.customerPayments.form.totalDebitWht') }}</span><strong>{{ fmt(totalWht) }}</strong></div>
            <div :class="{ 'cc-summary-warn': remainderAmount < 0 }"><span>{{ t('accounting.customerPayments.form.remainingBecomesUnappliedCredit') }}</span><strong>{{ fmt(remainderAmount) }}</strong></div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Down Payment ══════════════════ -->
      <div class="form-section mb-5" v-if="form.collection_type === 'down_payment' && form.partner_id">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.customerPayments.form.chooseContractForDownPayment') }}</div>
            <div class="form-section-sub">{{ t('accounting.customerPayments.form.tagThisReceiptApplyItAgainst') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.customerPayments.form.contract') }}</label>
              <SelectBox v-model="form.down_payment_project_id" :options="projects" value-key="id" :label-fn="p => `${p.name_en} (${p.contract_code})`" :placeholder="t('accounting.customerPayments.form.generalDownPayment')" />
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.customerPayments.form.sONumber') }}</label>
              <input v-model="form.down_payment_sales_order_id" type="text" class="zv-input" :placeholder="t('accounting.customerPayments.form.standaloneSOIdOptional')"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.customerPayments.form.unappliedAmount') }} [{{ receiveCurrencyCode }}]</label>
              <input :value="fmt(form.amount || 0)" type="text" class="zv-input text-end cc-readonly" readonly/>
              <p class="field-hint">{{ t('accounting.customerPayments.form.theFullAmountNothingIsApplied') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Comment ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerPayments.form.comment') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Applied To Invoices (edit mode only) ══════════════════
           Fix: only shown when there's something genuinely actionable —
           an application made SEPARATELY from the collection's own
           posting (its own distinct journal entry), which needs its own
           Unapply button. A collection can settle several invoices at
           once (multiple rows here is normal and expected); what's
           filtered out is any row that's just part of the original
           posting — that number is already visible in the Settlement
           Information table above (its "Collected" column), and
           "Set to Draft" already undoes it, so showing it a second time
           here with no button attached was pure redundancy. -->
      <div v-if="isEdit && separatelyAppliedAllocations.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.customerPayments.form.appliedToInvoices') }}</div>
          <div class="form-section-sub">{{ t('accounting.customerPayments.form.appliedSeparatelyFromThisCollectionSOwn') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.customerPayments.form.invoice') }}</th><th class="zv-table-num">{{ t('accounting.customerPayments.form.appliedAmount') }}</th><th class="cp-th-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="alloc in separatelyAppliedAllocations" :key="alloc.id">
                <td><span class="zv-code">{{ alloc.customer_invoice?.invoice_code }}</span></td>
                <td class="zv-table-num">{{ fmt(alloc.allocated_amount) }}</td>
                <td class="cp-th-actions">
                  <button
                    type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="unallocating === alloc.id"
                    @click="confirmUnallocate(alloc)"
                  >{{ unallocating === alloc.id ? t('accounting.customerPayments.form.removing') : t('accounting.customerPayments.form.unapply') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && customerPayment.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.customerPayments.form.journalEntry') }} — {{ customerPayment.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.customerPayments.form.account') }}</th><th class="zv-table-num">{{ t('accounting.customerPayments.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.customerPayments.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in customerPayment.journal_entry.lines" :key="line.id">
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
          <Link :href="route('accounting.customer-payments.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.customerPayments.form.close') }}</Link>

          <!-- Posted, period open → the reopen action -->
          <button
            v-if="isEdit && customerPayment.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.customerPayments.form.setToDraft') }}</button>

          <!-- Draft (only ever reached via Set to Draft, which already
               reversed everything real) → the actual delete action.
               Never shown alongside Set to Draft — the two are
               deliberately sequential, not simultaneous. -->
          <button
            v-if="isEdit && customerPayment.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('accounting.customerPayments.form.deleting') : t('common.delete') }}</button>

          <!-- Draft (fresh create, or a reopened one being edited) → Save -->
          <button
            v-if="!isEdit || customerPayment.status === 'draft'"
            type="submit" :disabled="form.processing || !canSubmit" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('accounting.customerPayments.form.saveRePost') : t('accounting.customerPayments.form.save')) }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { todayLocal } from '@/Utils/dates'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  customers:             { type: Array, default: () => [] },
  banks:                 { type: Array, default: () => [] },
  projects:              { type: Array, default: () => [] },
  currencies:            { type: Array, default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  customerPayment:       { type: Object, default: null },
  periodClosed:          { type: Boolean, default: false },
  // Set only when arriving via the "Collect" button on a specific
  // Customer Invoice (see CustomerPaymentController::create()). Drives
  // pre-filling Customer + Invoice Currency below, and narrows
  // Settlement Information down to just this one invoice.
  preselectedInvoice:    { type: Object, default: null },
})

// Rule 2 — Create and Edit share one Vue page. Mode is detected by
// whether props.customerPayment exists (edit) or not (create).
const isEdit = computed(() => !!props.customerPayment?.id)

// A payment is only ever editable while it's a draft — and the ONLY
// way it becomes a draft is via setToDraft() reopening a posted one.
// Freshly created payments post immediately (no draft stage — design
// doc's original decision, unchanged); cancelled ones are a dead end.
const isReadOnly = computed(() => isEdit.value && props.customerPayment.status !== 'draft')

const p = props.customerPayment

// Only applies on a fresh create (never on an existing payment being
// edited/reopened) — arrives via the "Collect" button on one invoice.
const pre = ! isEdit.value ? props.preselectedInvoice : null

const form = useForm({
  partner_id: p?.partner_id ?? pre?.partner_id ?? '',
  collection_type: p?.collection_type ?? 'settlement',
  payment_date: p?.payment_date ?? todayLocal(),
  money_type: p?.money_type ?? 'bank_deposit',
  reference_number: p?.reference_number ?? '',

  receive_currency_id: p?.receive_currency_id ?? props.functionalCurrencyId ?? '',
  invoice_currency_id: p?.invoice_currency_id ?? pre?.currency_id ?? props.functionalCurrencyId ?? '',
  invoice_to_receive_exchange_rate: p?.invoice_to_receive_exchange_rate ?? '1',
  exchange_rate: p?.exchange_rate ?? '1',
  amount: p?.amount ?? '',

  destination_type: p?.destination_type ?? 'bank_account',
  destination_bank_account_id: p?.destination_bank_account_id ?? '',
  destination_credit_facility_id: p?.destination_credit_facility_id ?? '',

  safe_account_id: p?.safe_account_id ?? '',
  receipt_number: p?.receipt_number ?? '',

  cheque: {
    drawee_bank_id: p?.cheque?.drawee_bank_id ?? '',
    cheque_number: p?.cheque?.cheque_number ?? '',
    due_date: p?.cheque?.due_date ?? '',
    receiving_safe_account_id: p?.cheque?.receiving_safe_account_id ?? '',
    extra: (p?.cheques ?? []).filter(c => c.id !== p?.cheque?.id).map(c => ({ cheque_number: c.cheque_number, due_date: String(c.due_date).slice(0, 10), amount: c.cheque_amount })),
  },

  down_payment_project_id: p?.down_payment_project_id ?? '',
  down_payment_sales_order_id: p?.down_payment_sales_order_id ?? '',

  settlements: [],
  internal_notes: p?.internal_notes ?? '',
})

function setMoneyType(type) {
  if (isReadOnly.value) return
  form.money_type = type
}

const moneyTypeBlockTitle = computed(() => {
  const map = {
    bank_deposit: t('accounting.customerPayments.form.bankDepositInformation'),
    incoming_transfer: t('accounting.customerPayments.form.incomingTransferInformation'),
    cash_in_safe: t('accounting.customerPayments.form.cashInformation'),
    cheque: t('accounting.customerPayments.form.chequeInformation'),
  }
  return map[form.money_type] ?? ''
})

const receiveCurrencyCode = computed(() => props.currencies.find(c => c.id == form.receive_currency_id)?.code ?? '')
const isCrossCurrency = computed(() => !!form.invoice_currency_id && !!form.receive_currency_id && String(form.invoice_currency_id) !== String(form.receive_currency_id))

watch(isCrossCurrency, (cross) => {
  if (! cross) form.invoice_to_receive_exchange_rate = '1'
})

function onReceiveCurrencyChange() {
  if (! isCrossCurrency.value) form.invoice_to_receive_exchange_rate = '1'
}

// Amount received, expressed in Invoice Currency terms — informational,
// matches the settlement rows' own currency for easy visual comparison.
// Does NOT drive the GL posting (that always uses receive-currency
// amount x exchange_rate to functional — see CustomerPaymentService).
const amountInInvoiceCurrency = computed(() => {
  const amt = parseFloat(form.amount) || 0
  const rate = parseFloat(form.invoice_to_receive_exchange_rate) || 1
  return amt * rate
})

// ── Destinations (Current Account or Overdraft Facility) ─────────

const allDestinations = ref([])
async function fetchDestinations() {
  // cache: 'no-store' — without this, a browser can silently serve a
  // stale cached response for this exact GET URL instead of asking the
  // server again. Confirmed root cause of a real bug: after "Set to
  // Draft" reverses an invoice's Collected/Net Balance server-side, the
  // Settlement Information table could still show the OLD figures
  // because the browser never actually re-asked the server.
  const res = await fetch(route('accounting.customer-payments.destinations'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allDestinations.value = data.destinations ?? []
}
fetchDestinations()

const bankAccountDestinations = computed(() => allDestinations.value.filter(d => d.type === 'bank_account'))
const creditFacilityDestinations = computed(() => allDestinations.value.filter(d => d.type === 'credit_facility'))

// ── Safe accounts (131xxx) ────────────────────────────────────────

const safeAccounts = ref([])
async function fetchSafeAccounts() {
  const res = await fetch(route('accounting.customer-payments.safe-accounts'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  safeAccounts.value = data.accounts ?? []
}
fetchSafeAccounts()

// ── Open invoices (Settlement Information) ────────────────────────

const openInvoices = ref([])
const openInvoicesLoading = ref(false)
const settlementAmounts = reactive({})
const whtAmounts = reactive({})

// While set, Settlement Information is narrowed to this one invoice
// only (see fetchOpenInvoices below). Cleared the moment the person
// changes the Customer themselves — at that point they've moved off
// the invoice they arrived from, so it no longer makes sense to keep
// filtering to it.
const lockedInvoiceId = ref(pre?.id ?? null)

async function fetchOpenInvoices() {
  if (! form.partner_id) { openInvoices.value = []; return }
  openInvoicesLoading.value = true
  const res = await fetch(route('accounting.customer-payments.open-invoices', {
    partner_id: form.partner_id,
    invoice_currency_id: form.invoice_currency_id || undefined,
    invoice_id: lockedInvoiceId.value || undefined,
  }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  openInvoices.value = data.invoices ?? []
  openInvoicesLoading.value = false
}

// Amounts typed against the PREVIOUS customer's invoices must not survive a
// change of customer or currency — they stay in these maps (keyed by invoice id)
// and would otherwise still be added to the totals and sent on Save.
function clearAllocations() {
  Object.keys(settlementAmounts).forEach(k => delete settlementAmounts[k])
  Object.keys(whtAmounts).forEach(k => delete whtAmounts[k])
}

function onPartnerChange() {
  clearAllocations()
  lockedInvoiceId.value = null
  fetchOpenInvoices()
}

function onInvoiceCurrencyChange() {
  clearAllocations()
  fetchOpenInvoices()
}

function showAllOpenInvoices() {
  lockedInvoiceId.value = null
  fetchOpenInvoices()
}

// Edit mode (a reopened draft) starts with partner_id already filled in —
// unlike Create, where the person triggers this by picking a customer.
// Without this, the settlement table would just sit empty until they
// manually re-touched the Customer field.
//
// A fresh create arriving via "Collect" (preselectedInvoice) also starts
// with partner_id already filled in — same reasoning applies.
if (form.partner_id && (isEdit.value || pre)) {
  fetchOpenInvoices()
}

// Safety net for the class of bug just fixed above: if this page's
// props ever change status WITHOUT a full reload (Inertia landing here
// via some other same-URL navigation in the future), re-fetch rather
// than silently keep showing whatever was true before the change.
watch(() => props.customerPayment?.status, (status, previous) => {
  if (status && previous && status !== previous && form.partner_id) {
    fetchOpenInvoices()
  }
})

const totalSettled = computed(() =>
  Object.values(settlementAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0)
)

const totalWht = computed(() =>
  Object.values(whtAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0)
)

// Live warning per invoice row — the definitive check still happens
// server-side (CustomerPaymentService::record() throws a clear
// DomainException if this is ever bypassed), but the person shouldn't
// have to hit Save to find out they've over-typed a specific row.
// Settlement + WHT together are what actually clears against the
// invoice — see postPaymentTransaction().
function exceedsOutstanding(inv) {
  const settlement = parseFloat(settlementAmounts[inv.id]) || 0
  const wht = parseFloat(whtAmounts[inv.id]) || 0
  return (settlement + wht) > parseFloat(inv.amount_outstanding_native || 0)
}

const hasOverAllocatedRow = computed(() => openInvoices.value.some(exceedsOutstanding))

// Estimated FX Gain (positive) / Loss (negative) this row would
// generate, purely for the person's information before they save —
// the actual figure is computed and posted server-side, using the
// exact same math (see CustomerPaymentService::postPaymentTransaction()
// header comment). Zero whenever the collection rate matches the
// invoice's own original rate — which is every same-currency,
// same-rate collection, i.e. almost all of them.
function fxEstimate(inv) {
  const settlement = parseFloat(settlementAmounts[inv.id]) || 0
  if (! settlement || ! inv.exchange_rate) return 0
  const invoiceToReceiveRate = parseFloat(form.invoice_to_receive_exchange_rate) || 1
  const receiveToFunctionalRate = parseFloat(form.exchange_rate) || 1
  const collectionRateToFunctional = invoiceToReceiveRate * receiveToFunctionalRate
  const arClear = settlement * parseFloat(inv.exchange_rate)
  const collectionValue = settlement * collectionRateToFunctional
  const diff = collectionValue - arClear
  return Math.abs(diff) < 0.005 ? 0 : diff
}

// Remaining, expressed in Invoice-Currency terms (matches the
// settlement table's own currency) — purely informational, the real
// functional-currency check happens server-side.
const remainderAmount = computed(() => amountInInvoiceCurrency.value - totalSettled.value)

// ── Submit ─────────────────────────────────────────────────────────

const canSubmit = computed(() => !!form.partner_id && !!form.money_type && !!form.collection_type && !!form.amount && !hasOverAllocatedRow.value)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  form.settlements = Object.entries(settlementAmounts)
    .filter(([, amount]) => parseFloat(amount) > 0)
    .map(([customer_invoice_id, settlement_amount]) => ({
      customer_invoice_id: Number(customer_invoice_id),
      settlement_amount,
      wht_amount: whtAmounts[customer_invoice_id] || 0,
    }))

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
    form.put(route('accounting.customer-payments.update', props.customerPayment.id))
  } else {
    form.post(route('accounting.customer-payments.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.customerPayments.form.reopenThisCollectionForEditingThis'))) return

  // Fix (real bug, confirmed by testing): the server redirects back to
  // this EXACT same edit URL after reopening — and when Inertia lands
  // on a page it's already displaying, it updates props in place
  // instead of re-running this component's setup code. That means the
  // Settlement Information fetch (and everything else that only runs
  // once, at mount) never re-fires, so the screen kept showing
  // pre-reversal figures until a manual browser reload forced
  // everything to start fresh. Forcing that same full reload here,
  // automatically, the moment the server confirms success.
  // Fix (real bug, found via Customer Invoice testing — same latent
  // bug here too): onSuccess fired, and reloaded unconditionally,
  // even when the server correctly REJECTED this. A rejected request
  // still redirects back successfully in Inertia's eyes, so onSuccess
  // fired anyway; the forced reload right after then wiped out the
  // one-time flash error before it was ever seen. Only reload when
  // the response genuinely carries no error.
  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.customer-payments.set-to-draft', props.customerPayment.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.customerPayments.form.deleteThisCollectionThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.customer-payments.destroy', props.customerPayment.id), {
    onFinish: () => { deleting.value = false },
  })
}

// Only allocations with their OWN journal entry (a later, separate
// "Apply" action) — not ones that are just a credit line inside the
// collection's own original posting. A collection can settle several
// invoices at once; this correctly keeps every row that qualifies,
// not just the first one.
const separatelyAppliedAllocations = computed(() =>
  (props.customerPayment?.allocations ?? []).filter(
    alloc => alloc.journal_entry_id !== props.customerPayment.journal_entry_id
  )
)

const unallocating = ref(null)
function confirmUnallocate(alloc) {
  if (! confirm(t('accounting.customerPayments.form.removeThisApplicationFrom') + ' ' + alloc.customer_invoice?.invoice_code + '?')) return
  unallocating.value = alloc.id
  router.delete(route('accounting.customer-payment-allocations.unallocate', alloc.id), {
    preserveScroll: true,
    onFinish: () => { unallocating.value = null },
  })
}
</script>

<style>
/* Money Type and Collection Type now use the shared <OptionTiles>
   component (see Components/OptionTiles.vue) — no page-specific CSS
   needed here anymore. */

.cc-readonly { background: rgba(20,144,168,0.08); color: var(--zv-text-primary); font-weight: 600; border-color: rgba(20,144,168,0.25); }

.cc-settlement-summary {
  display: flex; align-items: center; justify-content: flex-end; gap: 32px;
  padding: 14px 4px 4px; border-top: 1px solid var(--zv-border);
}
.cc-settlement-summary > div { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--zv-text-secondary); }
.cc-settlement-summary strong { font-size: 14px; color: var(--zv-text-primary); }
.cc-summary-warn strong { color: #ef4444; }
.cc-fx-hint { margin-top: 2px; font-size: 11px; color: #16a34a; }
.cc-fx-hint.cc-fx-loss { color: #ef4444; }
</style>
