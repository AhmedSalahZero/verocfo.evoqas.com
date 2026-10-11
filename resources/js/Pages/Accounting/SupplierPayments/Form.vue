<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierPayments/Form.vue

  Supplier Payment — payable-side mirror of Customer Collection's
  Form.vue, confirmed by Mahmoud to follow the exact same design.
  Every account role is reversed (Dr AP or 144 Advances / Cr
  [source]) but the shape is identical by design.

  POSTS IMMEDIATELY ON SAVE — no real draft stage (same as Customer
  Collection). 'draft' is only ever reached by reopening a posted
  payment via "Set to Draft".

  Money Type (tile selector) — only THREE values, confirmed by
  Mahmoud, not four like Customer Collection: Cash Payment / Outgoing
  Transfer / Payable Cheque. Outgoing Transfer and Payable Cheque
  share the same source picker (a Payable Cheque's source account is
  fixed at issue time, unlike a Receivable cheque's destination,
  which is genuinely unknown until later).

  Payment Type (tile selector, enabled once a supplier is picked)
  drives what appears below the Money Type block: Settlement shows
  every open bill (filtered by Bill Currency) with an editable
  Settlement Amount per row; Down Payment shows a Project/PO tag
  instead. Both post the exact same journal entry shape.

  @layout    AppLayout
  @route     accounting.supplier-payments.create
  @props     suppliers, projects, purchaseOrders, currencies, functionalCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.supplierPayments.form.supplierPayment'), href: route('accounting.supplier-payments.index') }, { label: isEdit ? supplierPayment.payment_code : t('accounting.supplierPayments.form.new') }]"
      :title="isEdit ? supplierPayment.payment_code : t('accounting.supplierPayments.form.newSupplierPayment')"
      :subtitle="t('accounting.supplierPayments.form.recordsMoneyPaidToASupplier')"
    />

    <!-- Status context banner — always visible on an existing record so
         it's never ambiguous why fields are locked or what to do next. -->
    <div v-if="isEdit && supplierPayment.status === 'posted'" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.supplierPayments.form.thisPaymentIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.supplierPayments.form.thisPaymentIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && supplierPayment.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.supplierPayments.form.thisPaymentWasCancelledItCannot') }}
    </div>
    <div v-else-if="isEdit && supplierPayment.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.supplierPayments.form.thisPaymentWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">

      <!-- Fallback banner: catches ANY validation error, even on a field
           this form doesn't show individual red text for. -->
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        <div>
          <div>{{ t('accounting.supplierPayments.form.pleaseFixTheFollowingBeforeSaving') }}</div>
          <ul style="margin: 4px 0 0 18px; list-style: disc;">
            <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
          </ul>
        </div>
      </div>

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <!-- ══════════════════ Payment Details ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierPayments.form.moneyPaid') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierPayments.form.whoWasPaidInWhatCurrency') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3-3-6">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.paymentDate') }}</label>
              <input v-model="form.payment_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.payment_date }"/>
              <p v-if="form.errors.payment_date" class="zv-field-error">{{ form.errors.payment_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierPayments.form.partnerType') }}</label>
              <input type="text" class="zv-input" value="Supplier" disabled/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
              <SelectBox v-model="form.partner_id" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.supplierPayments.form.selectSupplier')" :clearable="false" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-4 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.billCurrency') }}</label>
              <SelectBox v-model="form.bill_currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" :placeholder="t('accounting.supplierPayments.form.noneGeneralOnly')" :disabled="!form.partner_id" @change="onBillCurrencyChange" />
              <p class="field-hint">{{ t('accounting.supplierPayments.form.filtersWhichOpenBillsAreEligible') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.payCurrency') }}</label>
              <SelectBox v-model="form.pay_currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" @change="onPayCurrencyChange" :class="{ 'zv-input-error': form.errors.pay_currency_id }" />
              <p v-if="form.errors.pay_currency_id" class="zv-field-error">{{ form.errors.pay_currency_id }}</p>
            </div>
            <div class="field-group" v-if="isCrossCurrency">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.billPayRate') }}</label>
              <input v-model="form.bill_to_pay_exchange_rate" type="number" step="0.000001" min="0" class="zv-input text-end"/>
              <p class="field-hint">{{ t('accounting.supplierPayments.form.usedOnlyToMatchSettlementRows') }}</p>
            </div>
          </div>

          <!-- Money Type tiles — only THREE, confirmed by Mahmoud -->
          <div class="field-group mt-4">
            <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.moneyType') }}</label>
            <OptionTiles
              :model-value="form.money_type" variant="icon" :columns="3"
              :options="[
                { value: 'cash_payment', label: t('accounting.supplierPayments.form.cashPayment'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z\'/>' },
                { value: 'outgoing_transfer', label: t('accounting.supplierPayments.form.outgoingTransfer'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M16 17h-12m0 0l4-4m-4 4l4 4m4-14h12m0 0l-4-4m4 4l-4 4\'/>' },
                { value: 'payable_cheque', label: t('accounting.supplierPayments.form.payableCheque'), icon: '<path stroke-linecap=\'round\' stroke-linejoin=\'round\' d=\'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01\'/>' },
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
            <div class="form-section-sub">{{ t('accounting.supplierPayments.form.exactlyWhereThisMoneyLeftFrom') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Outgoing Transfer / Payable Cheque — share the same source picker
               (confirmed different from Customer Collection: a Payable Cheque's
               source account is fixed at issue time, not deferred). -->
          <template v-if="form.money_type === 'outgoing_transfer' || form.money_type === 'payable_cheque'">
            <div class="field-grid field-grid-4-8">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.accountType') }}</label>
                <SelectBox v-model="form.source_type" :options="[{ value: 'bank_account', label: t('accounting.supplierPayments.form.currentAccount') }, { value: 'credit_facility', label: t('accounting.supplierPayments.form.overdraftFacility') }]" @change="form.source_bank_account_id = ''; form.source_credit_facility_id = ''" :class="{ 'zv-input-error': form.errors.source_type }" />
                <p v-if="form.errors.source_type" class="zv-field-error">{{ form.errors.source_type }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.accountNumber') }}</label>
                <SelectBox v-model="form.source_bank_account_id" :options="bankAccountSources" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.supplierPayments.form.selectAccount')" :clearable="false" v-if="form.source_type === 'bank_account'" :class="{ 'zv-input-error': form.errors.source_bank_account_id }" />
                <SelectBox v-model="form.source_credit_facility_id" :options="creditFacilitySources" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.supplierPayments.form.selectFacility')" :clearable="false" v-else :class="{ 'zv-input-error': form.errors.source_credit_facility_id }" />
                <p v-if="form.errors.source_bank_account_id" class="zv-field-error">{{ form.errors.source_bank_account_id }}</p>
                <p v-if="form.errors.source_credit_facility_id" class="zv-field-error">{{ form.errors.source_credit_facility_id }}</p>
              </div>
            </div>
            <div class="field-grid field-grid-2-2-8 mt-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }} [{{ payCurrencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
                <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
              </div>
              <div class="field-group" v-if="isCrossCurrency">
                <label class="zv-label">{{ t('accounting.supplierPayments.form.amountInBillCurrency') }}</label>
                <input :value="fmt(amountInBillCurrency)" type="text" class="zv-input text-end cc-readonly" readonly/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.supplierPayments.form.referenceNumber') }}</label>
                <input v-model="form.reference_number" type="text" class="zv-input" :placeholder="t('accounting.supplierPayments.form.transferRef')"/>
              </div>
            </div>

            <!-- Payable Cheque adds its own number/due date, on top of the source above -->
            <template v-if="form.money_type === 'payable_cheque'">
              <div class="field-grid field-grid-2-2-8 mt-4">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.chequeNumber') }}</label>
                  <input v-model="form.cheque.cheque_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.cheque_number'] }"/>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.dueDate') }}</label>
                  <input v-model="form.cheque.due_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors['cheque.due_date'] }"/>
                </div>
              </div>
              <div class="zv-alert zv-alert-info mt-4" style="color:#ffffff">
                {{ t('accounting.supplierPayments.form.issuingAChequeSettlesTheBill') }}
              </div>
            </template>
          </template>

          <!-- Cash Payment -->
          <template v-else-if="form.money_type === 'cash_payment'">
            <div class="field-grid field-grid-6-3-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.payingBranch') }}</label>
                <SelectBox v-model="form.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="s => s.name_en" :placeholder="t('accounting.supplierPayments.form.selectSafe')" :clearable="false" :class="{ 'zv-input-error': form.errors.safe_account_id }" />
                <p v-if="form.errors.safe_account_id" class="zv-field-error">{{ form.errors.safe_account_id }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.paidAmount') }} [{{ payCurrencyCode }}]</label>
                <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.supplierPayments.form.receiptNumber') }}</label>
                <input v-model="form.receipt_number" type="text" class="zv-input"/>
              </div>
            </div>
            <div class="field-group mt-4" v-if="isCrossCurrency">
              <label class="zv-label">{{ t('accounting.supplierPayments.form.amountInBillCurrency') }}</label>
              <input :value="fmt(amountInBillCurrency)" type="text" class="zv-input text-end cc-readonly" style="max-width: 220px;" readonly/>
            </div>
          </template>
        </div>
      </div>

      <!-- ══════════════════ Payment Type ══════════════════ -->
      <div class="form-section mb-5" v-if="form.partner_id && form.money_type">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.supplierPayments.form.paymentType') }}</label>
            <OptionTiles
              :model-value="form.payment_type" variant="card"
              :options="[
                { value: 'settlement', label: t('accounting.supplierPayments.form.settlement'), description: t('accounting.supplierPayments.form.matchThisPaymentAgainstOpenBills') },
                { value: 'down_payment', label: t('accounting.supplierPayments.form.downPayment'), description: t('accounting.supplierPayments.form.tagItToAProjectPO') },
              ]"
              @update:model-value="(v) => form.payment_type = v"
            />
          </div>
        </div>
      </div>

      <!-- ══════════════════ Settlement Information ══════════════════ -->
      <div class="form-section mb-5" v-if="form.payment_type === 'settlement' && form.partner_id">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierPayments.form.settlementInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierPayments.form.enterASettlementAmountOnWhichever') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <!-- Only while locked to a single bill (arrived via the "Pay"
               button). "Show all open bills" drops the lock and
               re-fetches, in case this isn't the one they meant to pay
               after all. -->
          <div v-if="lockedBillId" class="zv-alert zv-alert-info mb-3">
            <span>{{ t('accounting.supplierPayments.form.payingBillOnlyPrefix') }} <strong>{{ pre?.bill_code }}</strong> {{ t('accounting.supplierPayments.form.payingBillOnlySuffix') }}</span>
            <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" style="margin-inline-start: 12px;" @click="showAllOpenBills">
              {{ t('accounting.supplierPayments.form.showAllOpenBills') }}
            </button>
          </div>

          <div v-if="openBillsLoading" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('accounting.supplierPayments.form.loadingOpenBills') }}</div>
          <div v-else-if="!openBills.length" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.supplierPayments.form.thisSupplierHasNoOpenBills') }}
          </div>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.supplierPayments.form.purchaseOrder') }}</th>
                  <th>{{ t('accounting.supplierPayments.form.billNumber') }}</th>
                  <th>{{ t('accounting.supplierPayments.form.billDate') }}</th>
                  <th class="zv-table-num">{{ t('accounting.supplierPayments.form.billAmount') }}</th>
                  <th class="zv-table-num">{{ t('accounting.supplierPayments.form.paid') }}</th>
                  <th class="zv-table-num">{{ t('accounting.supplierPayments.form.netBalance') }}</th>
                  <th class="zv-table-num">{{ t('accounting.supplierPayments.form.settlementAmount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="bill in openBills" :key="bill.id">
                  <td>{{ bill.purchase_order?.po_code ?? '—' }}</td>
                  <td><span class="zv-code">{{ bill.bill_code }}</span></td>
                  <td>{{ bill.bill_date }}</td>
                  <td class="zv-table-num">{{ fmt(bill.bill_value) }}</td>
                  <td class="zv-table-num">{{ fmt(bill.amount_paid) }}</td>
                  <td class="zv-table-num">{{ fmt(bill.amount_outstanding) }}</td>
                  <td class="zv-table-num">
                    <input
                      v-model="settlementAmounts[bill.id]"
                      type="number" step="0.01" min="0" :max="bill.amount_outstanding_native"
                      class="zv-input text-end" :class="{ 'zv-input-error': exceedsOutstanding(bill) }"
                      style="width: 9rem;" placeholder="0.00"
                    />
                    <p class="field-hint">{{ t('accounting.supplierPayments.form.fullSettlementHint', { amount: fmt(bill.amount_outstanding_native) }) }}</p>
                    <p v-if="exceedsOutstanding(bill)" class="zv-field-error">{{ t('accounting.supplierPayments.form.exceedsNetBalance') }}</p>
                    <p v-if="fxEstimate(bill)" class="field-hint cc-fx-hint" :class="{ 'cc-fx-loss': fxEstimate(bill) < 0 }">
                      {{ fxEstimate(bill) > 0 ? t('accounting.supplierPayments.form.estFxGain') : t('accounting.supplierPayments.form.estFxLoss') }}: {{ fmt(Math.abs(fxEstimate(bill))) }}
                    </p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="cc-settlement-summary mt-4">
            <div><span>{{ t('accounting.supplierPayments.form.totalSettled') }}</span><strong>{{ fmt(totalSettled) }}</strong></div>
            <div :class="{ 'cc-summary-warn': remainderAmount < 0 }"><span>{{ t('accounting.supplierPayments.form.remainingBecomesUnappliedCredit') }}</span><strong>{{ fmt(remainderAmount) }}</strong></div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Down Payment ══════════════════ -->
      <div class="form-section mb-5" v-if="form.payment_type === 'down_payment' && form.partner_id">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierPayments.form.chooseProjectForDownPayment') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierPayments.form.tagThisPaymentApplyItAgainst') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierPayments.form.project') }}</label>
              <SelectBox v-model="form.down_payment_project_id" :options="projects" value-key="id" :label-fn="p => `${p.name_en} (${p.contract_code})`" :placeholder="t('accounting.supplierPayments.form.generalDownPayment')" />
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierPayments.form.purchaseOrder') }}</label>
              <SelectBox v-model="form.down_payment_purchase_order_id" :options="purchaseOrders" value-key="id" :label-fn="po => po.po_code" :placeholder="t('accounting.supplierPayments.form.none')" />
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierPayments.form.unappliedAmount') }} [{{ payCurrencyCode }}]</label>
              <input :value="fmt(form.amount || 0)" type="text" class="zv-input text-end cc-readonly" readonly/>
              <p class="field-hint">{{ t('accounting.supplierPayments.form.theFullAmountNothingIsApplied') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Comment ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.supplierPayments.form.comment') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Applied To Bills (edit mode only) ══════════════════
           Only shown when there's something genuinely actionable — an
           application made SEPARATELY from the payment's own posting
           (its own distinct journal entry). A payment can settle several
           bills at once (multiple rows here is normal). -->
      <div v-if="isEdit && separatelyAppliedAllocations.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.supplierPayments.form.appliedToBills') }}</div>
          <div class="form-section-sub">{{ t('accounting.supplierPayments.form.appliedSeparatelyFromThisPaymentSOwn') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.supplierPayments.form.bill') }}</th><th class="zv-table-num">{{ t('accounting.supplierPayments.form.appliedAmount') }}</th><th class="cp-th-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="alloc in separatelyAppliedAllocations" :key="alloc.id">
                <td><span class="zv-code">{{ alloc.supplier_bill?.bill_code }}</span></td>
                <td class="zv-table-num">{{ fmt(alloc.allocated_amount) }}</td>
                <td class="cp-th-actions">
                  <button
                    type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="unallocating === alloc.id"
                    @click="confirmUnallocate(alloc)"
                  >{{ unallocating === alloc.id ? t('accounting.supplierPayments.form.removing') : t('accounting.supplierPayments.form.unapply') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && supplierPayment.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.supplierPayments.form.journalEntry') }} — {{ supplierPayment.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.supplierPayments.form.account') }}</th><th class="zv-table-num">{{ t('accounting.supplierPayments.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.supplierPayments.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in supplierPayment.journal_entry.lines" :key="line.id">
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
          <Link :href="route('accounting.supplier-payments.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.supplierPayments.form.close') }}</Link>

          <!-- Posted, period open → the reopen action -->
          <button
            v-if="isEdit && supplierPayment.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.supplierPayments.form.setToDraft') }}</button>

          <!-- Draft (only ever reached via Set to Draft) → the actual delete
               action. Never shown alongside Set to Draft. -->
          <button
            v-if="isEdit && supplierPayment.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('accounting.supplierPayments.form.deleting') : t('common.delete') }}</button>

          <!-- Draft (fresh create, or a reopened one being edited) → Save -->
          <button
            v-if="!isEdit || supplierPayment.status === 'draft'"
            type="submit" :disabled="form.processing || !canSubmit" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('accounting.supplierPayments.form.saveRePost') : t('accounting.supplierPayments.form.save')) }}</button>
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
  suppliers:             { type: Array, default: () => [] },
  projects:              { type: Array, default: () => [] },
  purchaseOrders:        { type: Array, default: () => [] },
  currencies:            { type: Array, default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  supplierPayment:       { type: Object, default: null },
  periodClosed:          { type: Boolean, default: false },
  // Set only when arriving via the "Pay" button on a specific Supplier
  // Bill (see SupplierPaymentController::create()). Drives pre-filling
  // Supplier + Bill Currency below, and narrows Settlement Information
  // down to just this one bill.
  preselectedBill:       { type: Object, default: null },
})

// Rule 2 — Create and Edit share one Vue page. Mode is detected by
// whether props.supplierPayment exists (edit) or not (create).
const isEdit = computed(() => !!props.supplierPayment?.id)

// A payment is only ever editable while it's a draft — and the ONLY
// way it becomes a draft is via setToDraft() reopening a posted one.
const isReadOnly = computed(() => isEdit.value && props.supplierPayment.status !== 'draft')

const p = props.supplierPayment

// Only applies on a fresh create (never on an existing payment being
// edited/reopened) — arrives via the "Pay" button on one bill.
const pre = ! isEdit.value ? props.preselectedBill : null

const form = useForm({
  partner_id: p?.partner_id ?? pre?.partner_id ?? '',
  payment_type: p?.payment_type ?? 'settlement',
  payment_date: p?.payment_date ?? todayLocal(),
  money_type: p?.money_type ?? 'outgoing_transfer',
  reference_number: p?.reference_number ?? '',

  pay_currency_id: p?.pay_currency_id ?? props.functionalCurrencyId ?? '',
  bill_currency_id: p?.bill_currency_id ?? pre?.currency_id ?? props.functionalCurrencyId ?? '',
  bill_to_pay_exchange_rate: p?.bill_to_pay_exchange_rate ?? '1',
  exchange_rate: p?.exchange_rate ?? '1',
  amount: p?.amount ?? '',

  source_type: p?.source_type ?? 'bank_account',
  source_bank_account_id: p?.source_bank_account_id ?? '',
  source_credit_facility_id: p?.source_credit_facility_id ?? '',

  safe_account_id: p?.safe_account_id ?? '',
  receipt_number: p?.receipt_number ?? '',

  cheque: {
    cheque_number: p?.cheque?.cheque_number ?? '',
    due_date: p?.cheque?.due_date ?? '',
  },

  down_payment_project_id: p?.down_payment_project_id ?? '',
  down_payment_purchase_order_id: p?.down_payment_purchase_order_id ?? '',

  settlements: [],
  internal_notes: p?.internal_notes ?? '',
})

function setMoneyType(type) {
  if (isReadOnly.value) return
  form.money_type = type
}

const moneyTypeBlockTitle = computed(() => {
  const map = {
    cash_payment: t('accounting.supplierPayments.form.cashInformation'),
    outgoing_transfer: t('accounting.supplierPayments.form.outgoingTransferInformation'),
    payable_cheque: t('accounting.supplierPayments.form.payableChequeInformation'),
  }
  return map[form.money_type] ?? ''
})

const payCurrencyCode = computed(() => props.currencies.find(c => c.id == form.pay_currency_id)?.code ?? '')
const isCrossCurrency = computed(() => !!form.bill_currency_id && !!form.pay_currency_id && String(form.bill_currency_id) !== String(form.pay_currency_id))

watch(isCrossCurrency, (cross) => {
  if (! cross) form.bill_to_pay_exchange_rate = '1'
})

function onPayCurrencyChange() {
  if (! isCrossCurrency.value) form.bill_to_pay_exchange_rate = '1'
}

// Amount paid, expressed in Bill Currency terms — informational,
// matches the settlement rows' own currency for easy visual comparison.
// Does NOT drive the GL posting (that always uses pay-currency amount
// x exchange_rate to functional — see SupplierPaymentService).
const amountInBillCurrency = computed(() => {
  const amt = parseFloat(form.amount) || 0
  const rate = parseFloat(form.bill_to_pay_exchange_rate) || 1
  return amt * rate
})

// ── Sources (Current Account or Overdraft Facility) ──────────────

const allSources = ref([])
async function fetchSources() {
  const res = await fetch(route('accounting.supplier-payments.sources'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  allSources.value = data.sources ?? []
}
fetchSources()

const bankAccountSources = computed(() => allSources.value.filter(d => d.type === 'bank_account'))
const creditFacilitySources = computed(() => allSources.value.filter(d => d.type === 'credit_facility'))

// ── Safe accounts (131xxx) ────────────────────────────────────────

const safeAccounts = ref([])
async function fetchSafeAccounts() {
  const res = await fetch(route('accounting.supplier-payments.safe-accounts'), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  safeAccounts.value = data.accounts ?? []
}
fetchSafeAccounts()

// ── Open bills (Settlement Information) ───────────────────────────

const openBills = ref([])
const openBillsLoading = ref(false)
const settlementAmounts = reactive({})

// While set, Settlement Information is narrowed to this one bill only
// (see fetchOpenBills below). Cleared the moment the person changes
// the Supplier themselves — at that point they've moved off the bill
// they arrived from, so it no longer makes sense to keep filtering.
const lockedBillId = ref(pre?.id ?? null)

async function fetchOpenBills() {
  if (! form.partner_id) { openBills.value = []; return }
  openBillsLoading.value = true
  const res = await fetch(route('accounting.supplier-payments.open-bills', {
    partner_id: form.partner_id,
    bill_currency_id: form.bill_currency_id || undefined,
    bill_id: lockedBillId.value || undefined,
  }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  openBills.value = data.bills ?? []
  openBillsLoading.value = false
}

// Amounts typed against the PREVIOUS supplier's bills must not survive a
// change of supplier or currency (see CustomerPayments/Form.vue).
function clearAllocations() {
  Object.keys(settlementAmounts).forEach(k => delete settlementAmounts[k])
}

function onPartnerChange() {
  clearAllocations()
  lockedBillId.value = null
  fetchOpenBills()
}

function onBillCurrencyChange() {
  clearAllocations()
  fetchOpenBills()
}

function showAllOpenBills() {
  lockedBillId.value = null
  fetchOpenBills()
}

// Edit mode (a reopened draft) starts with partner_id already filled in —
// unlike Create, where the person triggers this by picking a supplier.
//
// A fresh create arriving via "Pay" (preselectedBill) also starts with
// partner_id already filled in — same reasoning applies.
if (form.partner_id && (isEdit.value || pre)) {
  fetchOpenBills()
}

// Safety net: if this page's props ever change status WITHOUT a full
// reload, re-fetch rather than silently keep showing stale figures —
// same fix already proven on the Customer Collection side.
watch(() => props.supplierPayment?.status, (status, previous) => {
  if (status && previous && status !== previous && form.partner_id) {
    fetchOpenBills()
  }
})

const totalSettled = computed(() =>
  Object.values(settlementAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0)
)

// Live warning per bill row — the definitive check still happens
// server-side (SupplierPaymentService::record() throws a clear
// DomainException if this is ever bypassed).
function exceedsOutstanding(bill) {
  const typed = parseFloat(settlementAmounts[bill.id]) || 0
  return typed > parseFloat(bill.amount_outstanding_native || 0)
}

const hasOverAllocatedRow = computed(() => openBills.value.some(exceedsOutstanding))

// Estimated FX Gain (positive) / Loss (negative) this row would
// generate, purely for the person's information before they save —
// the actual figure is computed and posted server-side, using the
// exact same math (see SupplierPaymentService::postPaymentTransaction()
// header comment — sign convention matches: positive = gain).
function fxEstimate(bill) {
  const settlement = parseFloat(settlementAmounts[bill.id]) || 0
  if (! settlement || ! bill.exchange_rate) return 0
  const billToPayRate = parseFloat(form.bill_to_pay_exchange_rate) || 1
  const payToFunctionalRate = parseFloat(form.exchange_rate) || 1
  const paymentRateToFunctional = billToPayRate * payToFunctionalRate
  const apClear = settlement * parseFloat(bill.exchange_rate)
  const paymentValue = settlement * paymentRateToFunctional
  const diff = apClear - paymentValue
  return Math.abs(diff) < 0.005 ? 0 : diff
}

// Remaining, expressed in Bill-Currency terms — purely informational,
// the real functional-currency check happens server-side.
const remainderAmount = computed(() => amountInBillCurrency.value - totalSettled.value)

// ── Submit ─────────────────────────────────────────────────────────

const canSubmit = computed(() => !!form.partner_id && !!form.money_type && !!form.payment_type && !!form.amount && !hasOverAllocatedRow.value)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  form.settlements = Object.entries(settlementAmounts)
    .filter(([, amount]) => parseFloat(amount) > 0)
    .map(([supplier_bill_id, settlement_amount]) => ({ supplier_bill_id: Number(supplier_bill_id), settlement_amount }))

  // Blanking of the unused destination/source and cheque fields is done on
  // the OUTGOING copy only (form.transform). Mutating the form itself used to
  // wipe the user's cheque / bank choices on screen when the server rejected
  // the save and the user then switched Money Type back.
  form.transform(data => {
    const d = { ...data }
    if (d.money_type !== 'outgoing_transfer' && d.money_type !== 'payable_cheque') {
      d.source_type = null; d.source_bank_account_id = ''; d.source_credit_facility_id = ''
    }
    if (d.money_type !== 'payable_cheque') d.cheque = { cheque_number: null, due_date: null }
    return d
  })

  if (isEdit.value) {
    form.put(route('accounting.supplier-payments.update', props.supplierPayment.id))
  } else {
    form.post(route('accounting.supplier-payments.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.supplierPayments.form.reopenThisPaymentForEditingThis'))) return

  // Same fix as Customer Collection: the server redirects back to this
  // EXACT same edit URL after reopening, and Inertia landing on a page
  // it's already displaying updates props in place instead of re-running
  // this component's setup code — forcing a full reload here guarantees
  // Settlement Information re-fetches correctly.
  // Fix (real bug, found via Customer Invoice testing — same latent
  // bug here too): onSuccess used to fire, and reload unconditionally,
  // even when the server correctly REJECTED this (e.g. period closed).
  // A rejected request still redirects back successfully in Inertia's
  // eyes, so onSuccess fired anyway; the forced reload right after
  // then wiped out the one-time flash error before it was ever seen —
  // looking exactly like "nothing happened," no visible reason why.
  // Only reload when the response genuinely carries no error.
  // Audit H5: a written reason is required and recorded in the audit trail.
  const reason = window.prompt(t('accounting.reopen.reasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.supplier-payments.set-to-draft', props.supplierPayment.id), { reason: reason.trim() }, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.supplierPayments.form.deleteThisPaymentThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.supplier-payments.destroy', props.supplierPayment.id), {
    onFinish: () => { deleting.value = false },
  })
}

// Only allocations with their OWN journal entry (a later, separate
// "Apply" action) — not ones that are just a debit line inside the
// payment's own original posting.
const separatelyAppliedAllocations = computed(() =>
  (props.supplierPayment?.allocations ?? []).filter(
    alloc => alloc.journal_entry_id !== props.supplierPayment.journal_entry_id
  )
)

const unallocating = ref(null)
function confirmUnallocate(alloc) {
  if (! confirm(t('accounting.supplierPayments.form.removeThisApplicationFrom') + ' ' + alloc.supplier_bill?.bill_code + '?')) return
  unallocating.value = alloc.id
  router.delete(route('accounting.supplier-payment-allocations.unallocate', alloc.id), {
    preserveScroll: true,
    onFinish: () => { unallocating.value = null },
  })
}
</script>

<style>
/* Money Type and Payment Type now use the shared <OptionTiles>
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
