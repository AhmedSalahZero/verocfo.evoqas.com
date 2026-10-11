<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierBills/Form.vue

  Create + Edit — a Bill is only editable while status = draft (see
  SupplierBillService header — posting is one-way, same convention
  as GoodsReceiptNotes/Form.vue). Posting and cancelling both happen
  from the Index page.

  bill_type ('goods' | 'expense') is chosen once via the tile toggle
  at the top and drives which line table renders below — see
  SupplierBillLine's own header for why a line is exactly one of
  three shapes:

    GOODS      — picked from a live list of this supplier's posted
                 GRN lines still open to bill (fetched from
                 eligible-grn-lines). Unit price is the ACTUAL
                 invoice price — it can differ from the GRN's own
                 cost, and that difference (variance) splits between
                 the product's inventory account and its COGS
                 account via the per-line % control (default 100%
                 to inventory).
    EXPENSE    — each line is either a fresh expense (any postable
                 account) or settles an existing open Accrual for
                 this supplier (fetched from
                 accruals.eligible-for-partner) — the toggle per line
                 controls which picker shows.

  Currency: exchange_rate only appears once a non-functional
  currency is chosen, auto-filled from the latest rate on file
  (still overridable) — see SupplierBillController::latestExchangeRate.

  @layout    AppLayout
  @route     accounting.supplier-bills.create / .edit
  @props     supplierBill, suppliers, purchaseOrders, currencies,
             functionalCurrencyId, expenseAccounts, products,
             productVatRates, costCenters, projects, paymentTerms,
             companyDefaultVatRate
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.supplierBills.form.supplierBills'), href: route('accounting.supplier-bills.index') }, { label: isEdit ? supplierBill.bill_code : t('accounting.supplierBills.form.new') }]"
      :title="isEdit ? supplierBill.bill_code : t('accounting.supplierBills.form.newSupplierBill')"
      :subtitle="t('accounting.supplierBills.form.theSupplierSRealInvoiceOncePosted')"
    />

    <!-- Status context banner — same pattern already proven on Customer Invoice. -->
    <!-- Audit C7: bill created from Opening Balances -->
    <div v-if="isEdit && supplierBill.is_opening_balance" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.supplierBills.form.openingBalanceNotice') }} {{ supplierBill.internal_notes }}
    </div>
    <div v-if="isEdit && supplierBill.status === 'posted' && !supplierBill.is_opening_balance" class="zv-alert zv-alert-info mb-4" >
      <span v-if="!periodClosed">{{ t('accounting.supplierBills.form.thisBillIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.supplierBills.form.thisBillIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && supplierBill.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.supplierBills.form.thisBillWasCancelledItCannot') }}
    </div>

    <form @submit.prevent="submit">

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierBills.form.billInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierBills.form.whatKindOfBillThisIs') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <OptionTiles
            :model-value="form.bill_type" variant="card" class="mb-4" :disabled="isEdit"
            :options="[
              { value: 'goods', label: t('accounting.supplierBills.form.goodsBill'), description: t('accounting.supplierBills.form.matchesGoodsReceiptNoteLinesClears') },
              { value: 'expense', label: t('accounting.supplierBills.form.expenseBill'), description: t('accounting.supplierBills.form.freshExpenseOrSettlesAnExisting') },
              { value: 'prepaid', label: t('accounting.supplierBills.form.prepaidExpenseBill'), description: t('accounting.supplierBills.form.spreadsARealInvoiceSExpense') },
            ]"
            @update:model-value="setBillType"
          />

          <div class="field-grid field-grid-6-3-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierBills.form.supplier') }}</label>
              <SelectBox
                v-model="form.partner_id"
                :options="supplierOptions"
                :placeholder="t('accounting.supplierBills.form.selectSupplier')"
                :clearable="false"
                :error="!!form.errors.partner_id"
                @change="onPartnerChange"
              />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group" v-if="form.bill_type === 'goods'">
              <label class="zv-label">{{ t('accounting.supplierBills.form.purchaseOrder') }}</label>
              <SelectBox
                v-model="form.purchase_order_id"
                :options="poOptions"
                :placeholder="t('accounting.supplierBills.form.anyNotTiedToOnePO')"
                @change="onPoChange"
              />
              <p class="field-hint">{{ t('accounting.supplierBills.form.convenienceFilterOnlyMatchingHappensPer') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierBills.form.supplierInvoice') }}</label>
              <input v-model="form.supplier_invoice_number" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.supplier_invoice_number }"/>
              <p v-if="form.errors.supplier_invoice_number" class="zv-field-error">{{ form.errors.supplier_invoice_number }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-2-2-8 mt-4">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierBills.form.supplierInvoiceDate') }}</label>
              <input v-model="form.supplier_invoice_date" type="date" :max="today" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.supplierBills.form.theDatePrintedOnTheSupplierS') }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierBills.form.postingDate') }}</label>
              <input v-model="form.bill_date" type="date" :max="today" class="zv-input" :class="{ 'zv-input-error': form.errors.bill_date }"/>
              <p class="field-hint">{{ t('accounting.supplierBills.form.theDateThisBillAffectsYour') }}</p>
              <p v-if="form.errors.bill_date" class="zv-field-error">{{ form.errors.bill_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierBills.form.paymentTerm') }}</label>
              <SelectBox
                v-model="form.payment_term_id"
                :options="paymentTermOptions"
                :placeholder="t('accounting.supplierBills.form.none')"
              />
            </div>
          </div>

          <div class="field-grid field-grid-2-narrow mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierBills.form.currency') }}</label>
              <SelectBox
                v-model="form.currency_id"
                :options="currencyOptions"
                :placeholder="t('accounting.supplierBills.form.selectCurrency')"
                :clearable="false"
                :error="!!form.errors.currency_id"
                @change="onCurrencyChange"
              />
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>

            <div class="field-group" v-if="isForeignCurrency">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierBills.form.exchangeRate') }}</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001" min="0.000001" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.exchange_rate }"/>
              <p v-if="!form.exchange_rate" class="field-hint" style="color:#f59e0b;">{{ t('accounting.supplierBills.form.noRateOnFile') }}</p>
              <p v-else class="field-hint">{{ t('accounting.supplierBills.form.autoFilledFromTheLatestRate') }}</p>
              <p v-if="form.errors.exchange_rate" class="zv-field-error">{{ form.errors.exchange_rate }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ── GOODS LINES ─────────────────────────────────────────── -->
      <div class="form-section mb-5" v-if="form.bill_type === 'goods'">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierBills.form.goodsLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierBills.form.pickFromThisSupplierSReceivedBut') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p v-if="form.partner_id && eligibleGrnLines.length === 0" class="field-hint mb-3">
            {{ t('accounting.supplierBills.form.noOpenGoodsReceiptNoteLines') }}
          </p>

          <div class="zv-table-wrap sb-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th class="sb-th-grn-line">{{ t('accounting.supplierBills.form.gRNLine') }}</th>
                  <th class="sb-th-qty">{{ t('accounting.supplierBills.form.quantity') }}</th>
                  <th class="sb-th-cost">{{ t('accounting.supplierBills.form.gRNCost') }}</th>
                  <th class="sb-th-amount">{{ t('accounting.supplierBills.form.billUnitPrice') }}</th>
                  <th class="sb-th-vat">{{ t('accounting.supplierBills.form.vAT') }}</th>
                  <th class="sb-th-pct">
                    {{ t('accounting.supplierBills.form.wHT') }}
                    <span class="sb-th-help" :title="t('accounting.supplierBills.form.withholdingTaxCalculatedOnThisLineS')">?</span>
                  </th>
                  <th class="sb-th-pct">
                    {{ t('accounting.supplierBills.form.varianceToInventory') }}
                    <span class="sb-th-help" :title="t('accounting.supplierBills.form.whenTheBillPriceDiffersFrom')">?</span>
                  </th>
                  <th class="sb-th-total">{{ t('accounting.supplierBills.form.lineTotal') }}</th>
                  <th class="sb-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox
                      v-model="line.goods_receipt_note_line_id"
                      :options="grnLineOptions(line)"
                      :placeholder="t('accounting.supplierBills.form.selectGRNLine')"
                      :clearable="false"
                      size="sm"
                      @change="onGrnLineChange(line)"
                    />
                  </td>
                  <td><input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end"/></td>
                  <td class="zv-table-num">{{ fmt(line._grnUnitCost) }}</td>
                  <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end"/></td>
                  <td>
                    <input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/>
                    <label class="sb-deductible-toggle" :title="t('accounting.supplierBills.form.uncheckIfThisVatIsNotRecoverable')">
                      <input type="checkbox" v-model="line.is_deductible"/> {{ t('accounting.supplierBills.form.deductible') }}
                    </label>
                  </td>
                  <td><input v-model="line.withholding_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/></td>
                  <td><input v-model="line.variance_inventory_pct" type="number" step="1" min="0" max="100" class="zv-input text-end"/></td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="sb-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.supplierBills.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" :disabled="!form.partner_id" @click="addGoodsLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.supplierBills.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <!-- ── EXPENSE LINES ───────────────────────────────────────── -->
      <div class="form-section mb-5" v-else-if="form.bill_type === 'expense'">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierBills.form.expenseLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierBills.form.eachLineEitherPostsFreshOr') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap sb-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.supplierBills.form.kind') }}</th>
                  <th>{{ t('accounting.supplierBills.form.accountAccrual') }}</th>
                  <th>{{ t('accounting.supplierBills.form.description') }}</th>
                  <th class="sb-th-amount">{{ t('common.amount') }}</th>
                  <th class="sb-th-vat">{{ t('accounting.supplierBills.form.vAT') }}</th>
                  <th class="sb-th-pct">
                    {{ t('accounting.supplierBills.form.wHT') }}
                    <span class="sb-th-help" :title="t('accounting.supplierBills.form.withholdingTaxCalculatedOnThisLineS2')">?</span>
                  </th>
                  <th>{{ t('accounting.supplierBills.form.project') }}</th>
                  <th class="sb-th-total">{{ t('accounting.supplierBills.form.lineTotal') }}</th>
                  <th class="sb-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox
                      v-model="line._kind"
                      :options="lineKindOptions"
                      :clearable="false"
                      size="sm"
                      @change="onLineKindChange(line)"
                    />
                  </td>
                  <td>
                    <SelectBox
                      v-if="line._kind === 'fresh'"
                      v-model="line.chart_of_account_id"
                      :options="expenseAccountOptions"
                      :placeholder="t('accounting.supplierBills.form.selectAccount')"
                      :clearable="false"
                      size="sm"
                    />
                    <SelectBox
                      v-else
                      v-model="line.accrual_id"
                      :options="accrualOptions"
                      :placeholder="t('accounting.supplierBills.form.selectAccrual')"
                      :clearable="false"
                      size="sm"
                      @change="onAccrualChange(line)"
                    />
                  </td>
                  <td><input v-model="line.description" type="text" class="zv-input"/></td>
                  <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end"/></td>
                  <td>
                    <input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/>
                    <label class="sb-deductible-toggle" :title="t('accounting.supplierBills.form.uncheckIfThisVatIsNotRecoverable')">
                      <input type="checkbox" v-model="line.is_deductible"/> {{ t('accounting.supplierBills.form.deductible') }}
                    </label>
                  </td>
                  <td><input v-model="line.withholding_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/></td>
                  <td>
                    <SelectBox
                      v-model="line.project_id"
                      :options="projectOptions"
                      :placeholder="t('accounting.supplierBills.form.none')"
                      size="sm"
                    />
                  </td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="sb-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.supplierBills.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addExpenseLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.supplierBills.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <!-- ── PREPAID EXPENSE LINES ───────────────────────────────────
           Identical shape to a fresh Expense line — same account
           picker, same VAT/WHT — plus one extra field, Amortization
           Months. No accrual toggle here: a prepayment is always a
           fresh cost, never a settlement of something already accrued.
           chart_of_account_id is where the AMORTIZATION lands each
           period, not where the bill itself posts (that's always 136
           Prepaid Expenses, resolved server-side) — see
           SupplierBillService::postPrepaidExpenseLine(). -->
      <div class="form-section mb-5" v-else-if="form.bill_type === 'prepaid'">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierBills.form.prepaidLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierBills.form.eachLineAmortizesEvenlyOverIts') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap sb-lines-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.supplierBills.form.expenseAccount') }}</th>
                  <th>{{ t('accounting.supplierBills.form.description') }}</th>
                  <th class="sb-th-amount">{{ t('common.amount') }}</th>
                  <th class="sb-th-months">
                    {{ t('accounting.supplierBills.form.amortizationMonths') }}
                    <span class="sb-th-help" :title="t('accounting.supplierBills.form.amortizationStartsFromThisBillSOwn')">?</span>
                  </th>
                  <th class="sb-th-vat">{{ t('accounting.supplierBills.form.vAT') }}</th>
                  <th class="sb-th-pct">
                    {{ t('accounting.supplierBills.form.wHT') }}
                    <span class="sb-th-help" :title="t('accounting.supplierBills.form.withholdingTaxCalculatedOnThisLineS2')">?</span>
                  </th>
                  <th>{{ t('accounting.supplierBills.form.project') }}</th>
                  <th class="sb-th-total">{{ t('accounting.supplierBills.form.lineTotal') }}</th>
                  <th class="sb-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox
                      v-model="line.chart_of_account_id"
                      :options="expenseAccountOptions"
                      :placeholder="t('accounting.supplierBills.form.selectAccount')"
                      :clearable="false"
                      size="sm"
                    />
                  </td>
                  <td><input v-model="line.description" type="text" class="zv-input"/></td>
                  <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end"/></td>
                  <td><input v-model="line.amortization_months" type="number" step="1" min="1" max="60" class="zv-input text-end"/></td>
                  <td>
                    <input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/>
                    <label class="sb-deductible-toggle" :title="t('accounting.supplierBills.form.uncheckIfThisVatIsNotRecoverable')">
                      <input type="checkbox" v-model="line.is_deductible"/> {{ t('accounting.supplierBills.form.deductible') }}
                    </label>
                  </td>
                  <td><input v-model="line.withholding_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end"/></td>
                  <td>
                    <SelectBox
                      v-model="line.project_id"
                      :options="projectOptions"
                      :placeholder="t('accounting.supplierBills.form.none')"
                      size="sm"
                    />
                  </td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="sb-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.supplierBills.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addPrepaidLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.supplierBills.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="ci-totals-notes-row">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierBills.form.internalNotes') }}</label>
              <textarea v-model="form.internal_notes" rows="3" class="zv-textarea"></textarea>
            </div>
            <div class="sb-totals">
              <div><span>{{ t('accounting.supplierBills.form.subtotal') }}</span><strong>{{ fmt(subtotal) }} {{ selectedCurrencyCode }}</strong></div>
              <div><span>{{ t('accounting.supplierBills.form.vAT2') }}</span><strong>{{ fmt(vatTotal) }} {{ selectedCurrencyCode }}</strong></div>
              <div class="sb-totals-grand"><span>{{ t('accounting.supplierBills.form.billTotal') }}</span><strong>{{ fmt(grandTotal) }} {{ selectedCurrencyCode }}</strong></div>
              <div v-if="withholdingAmount > 0" class="sb-totals-wht"><span>{{ t('accounting.supplierBills.form.withholdingTaxWithheld') }}</span><strong>− {{ fmt(withholdingAmount) }} {{ selectedCurrencyCode }}</strong></div>
              <div v-if="withholdingAmount > 0" class="sb-totals-net"><span>{{ t('accounting.supplierBills.form.netPayableToSupplier') }}</span><strong>{{ fmt(grandTotal - withholdingAmount) }} {{ selectedCurrencyCode }}</strong></div>
            </div>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Prepaid Amortization Schedule (edit mode, posted prepaid bill) ══════════════════
           One row per line, one "Post Next Amortization" click per
           period — mirrors the Lease "Post Wear-Down" pattern exactly.
           No pre-built 12-row schedule exists to display; this table
           shows what HAS been posted (amortizationEntries, oldest
           first) plus a progress readout and the button for the next
           one. See PrepaidAmortizationService. -->
      <div v-if="isEdit && supplierBill.bill_type === 'prepaid' && supplierBill.status === 'posted'" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.supplierBills.form.amortizationSchedule') }}</div>
          <div class="form-section-sub">{{ t('accounting.supplierBills.form.postEachMonthSAmortizationWhenIt') }}</div>
        </div>
        <div class="form-section-body" style="display:flex; flex-direction:column; gap:1.5rem;">
          <div v-for="line in supplierBill.lines" :key="line.id">
            <div class="flex items-center justify-between mb-2">
              <div>
                <strong>{{ line.description }}</strong>
                <span class="field-hint">
                  {{ (line.amortization_entries || []).filter(e => e.status === 'posted').length }} / {{ totalPeriodsForLine(line) }}
                  {{ t('accounting.supplierBills.form.periodsPosted') }}
                </span>
              </div>
              <button
                type="button" class="zv-btn zv-btn-secondary zv-btn-sm"
                :disabled="postingLineId === line.id || isLineFullyAmortized(line)"
                @click="confirmPostAmortization(line)"
              >{{ postingLineId === line.id ? t('common.saving') : t('accounting.supplierBills.form.postNextAmortization') }}</button>
            </div>

            <table v-if="(line.amortization_entries || []).length" class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.supplierBills.form.period') }}</th>
                  <th>{{ t('accounting.supplierBills.form.date') }}</th>
                  <th class="sb-th-qty">{{ t('common.amount') }}</th>
                  <th>{{ t('accounting.supplierBills.form.status') }}</th>
                  <th class="sb-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in line.amortization_entries" :key="entry.id">
                  <td>{{ entry.period_number }}</td>
                  <td>{{ entry.entry_date }}</td>
                  <td class="zv-table-num">{{ fmt(entry.amount) }}</td>
                  <td>
                    <span class="zv-badge" :class="entry.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">
                      {{ entry.status === 'posted' ? t('accounting.supplierBills.form.posted') : t('accounting.supplierBills.form.reversed') }}
                    </span>
                  </td>
                  <td class="sb-th-actions">
                    <button
                      v-if="entry.status === 'posted'"
                      type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="reversingEntryId === entry.id"
                      @click="confirmReverseAmortization(entry)"
                    >{{ reversingEntryId === entry.id ? t('common.saving') : t('accounting.supplierBills.form.reverse') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-else class="field-hint">{{ t('accounting.supplierBills.form.noAmortizationPostedYet') }}</p>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Linked Payments (edit mode, posted or reopened) ══════════════════
           Same split as Customer Invoice's "Linked Collections": a link
           that shares the payment's own original journal entry can't be
           unlinked here (would mean reversing the whole payment) — only
           Set to Draft on the payment itself does that. A link with its
           own separate journal entry (a later "Apply") can be unlinked
           right here. -->
      <div v-if="isEdit && supplierBill.allocations?.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.supplierBills.form.linkedPayments') }}</div>
          <div class="form-section-sub">{{ t('accounting.supplierBills.form.moneyAlreadyMatchedAgainstThisBill') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.supplierBills.form.payment') }}</th><th class="zv-table-num">{{ t('accounting.supplierBills.form.appliedAmount') }}</th><th class="ci-linked-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="alloc in supplierBill.allocations" :key="alloc.id">
                <td><span class="zv-code">{{ alloc.supplier_payment?.payment_code }}</span></td>
                <td class="zv-table-num">{{ fmt(alloc.allocated_amount) }}</td>
                <td class="ci-linked-actions">
                  <button
                    v-if="alloc.journal_entry_id !== alloc.supplier_payment?.journal_entry_id"
                    type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="unallocating === alloc.id"
                    @click="confirmUnallocate(alloc)"
                  >{{ unallocating === alloc.id ? t('accounting.supplierBills.form.removing') : t('accounting.supplierBills.form.unlink') }}</button>
                  <span v-else class="field-hint">{{ t('accounting.supplierBills.form.partOfThatPaymentSOriginalPosting') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════ Other Unapplied Payments — link from here ══════════════════
           Payable-side mirror of Customer Invoice's "Other Unapplied
           Collections" — this bill's own page can pull a payment onto
           itself, not only the other way around. Same allocate() action
           Supplier Payment's own "Apply" button already calls. Never
           moves cash — purely which bill the already-paid money is
           credited against. -->
      <div v-if="isEdit && supplierBill.status === 'posted' && unappliedPayments.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.supplierBills.form.otherUnappliedPayments') }}</div>
          <div class="form-section-sub">{{ t('accounting.supplierBills.form.thisSupplierSOtherPaymentsThatStill') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.supplierBills.form.payment') }}</th><th>{{ t('accounting.supplierBills.form.date') }}</th><th class="zv-table-num">{{ t('accounting.supplierBills.form.unapplied') }}</th><th class="zv-table-num">{{ t('accounting.supplierBills.form.amountToLink') }}</th><th class="ci-linked-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="pmt in unappliedPayments" :key="pmt.id">
                <td><span class="zv-code">{{ pmt.payment_code }}</span></td>
                <td>{{ pmt.payment_date }}</td>
                <td class="zv-table-num">{{ fmt(pmt.unallocated_amount) }} {{ pmt.pay_currency?.code }}</td>
                <td class="zv-table-num">
                  <input v-model="linkAmounts[pmt.id]" type="number" step="0.01" min="0" :max="pmt.unallocated_amount" class="zv-input text-end" style="width: 8rem;" placeholder="0.00"/>
                </td>
                <td class="ci-linked-actions">
                  <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="linking === pmt.id" @click="submitLink(pmt)">
                    {{ linking === pmt.id ? t('accounting.supplierBills.form.linking') : t('accounting.supplierBills.form.link') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="linkError" class="zv-field-error mt-2">{{ linkError }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.supplier-bills.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.supplierBills.form.close') }}</Link>

          <!-- Posted, period open → the reopen action -->
          <button
            v-if="isEdit && supplierBill.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.supplierBills.form.setToDraft') }}</button>

          <!-- Draft → Save (covers both a brand-new draft and a reopened one) -->
          <button
            v-if="!isEdit || supplierBill.status === 'draft'"
            type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.supplierBills.form.saveAsDraft')) }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, lineSubtotal as moneyLineSubtotal, lineVat as moneyLineVat } from '@/Utils/money'
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  supplierBill:         { type: Object, default: null },
  suppliers:            { type: Array,  default: () => [] },
  purchaseOrders:       { type: Array,  default: () => [] },
  currencies:           { type: Array,  default: () => [] },
  functionalCurrencyId: { type: [Number, String], default: null },
  expenseAccounts:      { type: Array,  default: () => [] },
  products:             { type: Array,  default: () => [] },
  productVatRates:      { type: Object, default: () => ({}) },
  costCenters:          { type: Array,  default: () => [] },
  projects:             { type: Array,  default: () => [] },
  paymentTerms:         { type: Array,  default: () => [] },
  companyDefaultVatRate: { type: [Number, String], default: 0 },
  periodClosed:          { type: Boolean, default: false },
})

const isEdit = computed(() => !!props.supplierBill?.id)
const today = todayLocal()

// A posted bill is read-only until reopened via "Set to Draft" — same
// rule already proven on Customer Invoice/Collection/Supplier Payment.
// A draft (whether brand-new or reopened) is always fully editable.
const isReadOnly = computed(() => isEdit.value && props.supplierBill.status !== 'draft')

function blankGoodsLine() {
  return { goods_receipt_note_line_id: '', product_id: '', description: '', quantity: '', unit_price: '', vat_rate: '0', is_deductible: true, withholding_tax_rate: '0', variance_inventory_pct: 100, _grnUnitCost: 0 }
}
function blankExpenseLine() {
  return { _kind: 'fresh', chart_of_account_id: '', accrual_id: '', product_id: '', description: '', quantity: 1, unit_price: '', vat_rate: String(props.companyDefaultVatRate ?? 0), is_deductible: true, withholding_tax_rate: '0', cost_center_id: '', project_id: '', sales_order_id: '' }
}
function blankPrepaidLine() {
  return { chart_of_account_id: '', description: '', quantity: 1, unit_price: '', vat_rate: String(props.companyDefaultVatRate ?? 0), is_deductible: true, withholding_tax_rate: '0', cost_center_id: '', project_id: '', sales_order_id: '', amortization_months: '' }
}

function mapExistingLine(l) {
  if (l.goods_receipt_note_line_id) {
    return { ...blankGoodsLine(), goods_receipt_note_line_id: l.goods_receipt_note_line_id, product_id: l.product_id, description: l.description, quantity: l.quantity, unit_price: l.unit_price, vat_rate: l.vat_rate, is_deductible: l.is_deductible ?? true, withholding_tax_rate: l.withholding_tax_rate ?? '0', variance_inventory_pct: l.variance_inventory_pct, _grnUnitCost: l.goods_receipt_note_line?.unit_cost ?? 0 }
  }
  if (props.supplierBill?.bill_type === 'prepaid') {
    return { ...blankPrepaidLine(), chart_of_account_id: l.chart_of_account_id ?? '', description: l.description, quantity: l.quantity, unit_price: l.unit_price, vat_rate: l.vat_rate, is_deductible: l.is_deductible ?? true, withholding_tax_rate: l.withholding_tax_rate ?? '0', cost_center_id: l.cost_center_id ?? '', project_id: l.project_id ?? '', sales_order_id: l.sales_order_id ?? '', amortization_months: l.amortization_months ?? '' }
  }
  return { ...blankExpenseLine(), _kind: l.accrual_id ? 'accrual' : 'fresh', chart_of_account_id: l.chart_of_account_id ?? '', accrual_id: l.accrual_id ?? '', description: l.description, quantity: l.quantity, unit_price: l.unit_price, vat_rate: l.vat_rate, is_deductible: l.is_deductible ?? true, withholding_tax_rate: l.withholding_tax_rate ?? '0', cost_center_id: l.cost_center_id ?? '', project_id: l.project_id ?? '', sales_order_id: l.sales_order_id ?? '' }
}

const form = useForm({
  bill_type:               props.supplierBill?.bill_type ?? 'goods',
  partner_id:              props.supplierBill?.partner_id ?? '',
  purchase_order_id:       props.supplierBill?.purchase_order_id ?? '',
  supplier_invoice_number: props.supplierBill?.supplier_invoice_number ?? '',
  supplier_invoice_date:   props.supplierBill?.supplier_invoice_date?.toString().slice(0, 10) ?? '',
  bill_date:               props.supplierBill?.bill_date?.toString().slice(0, 10) ?? todayLocal(),
  currency_id:             props.supplierBill?.currency_id ?? (props.functionalCurrencyId ?? ''),
  exchange_rate:           props.supplierBill?.exchange_rate ?? '1',
  payment_term_id:         props.supplierBill?.payment_term_id ?? '',
  internal_notes:          props.supplierBill?.internal_notes ?? '',
  lines: props.supplierBill?.lines?.map(mapExistingLine)
    ?? [props.supplierBill?.bill_type === 'expense' ? blankExpenseLine() : props.supplierBill?.bill_type === 'prepaid' ? blankPrepaidLine() : blankGoodsLine()],
})

const isForeignCurrency = computed(() =>
  !!form.currency_id && String(form.currency_id) !== String(props.functionalCurrencyId)
)

const selectedCurrencyCode = computed(() =>
  props.currencies.find(c => c.id === Number(form.currency_id))?.code ?? ''
)

// Fix (July 2026): the Purchase Order dropdown was showing every open
// PO regardless of supplier — a real bug, since a Supplier Bill can
// only ever match GRN lines from ITS OWN supplier's purchase orders
// anyway. PurchaseOrder already carries partner_id, so this is a
// plain client-side filter — no new endpoint needed.
//
// Fix (same root cause as the Customer Invoice Sales Order bug): the
// controller's purchaseOrders list only includes POs still open to
// bill (billing_status != fully_billed) — but a bill being viewed in
// Edit/View may be exactly what made its own PO fully billed. Without
// this fallback the dropdown would silently render blank, just like
// the Sales Order did. Seeded from the bill's own loaded relation
// (props.supplierBill.purchase_order — snake_case, per Inertia's
// relation serialization), not props.purchaseOrders.
const availablePurchaseOrders = computed(() => {
  const list = (props.purchaseOrders ?? []).filter(po => String(po.partner_id) === String(form.partner_id))
  if (isEdit.value && props.supplierBill.purchase_order && !list.some(po => po.id === props.supplierBill.purchase_order.id)) {
    return [...list, props.supplierBill.purchase_order]
  }
  return list
})

// True when any line already holds something the user typed or picked —
// switching Bill Type throws all lines away, so we ask first.
function linesHaveData() {
  return form.lines.some(l =>
    l.goods_receipt_note_line_id || l.chart_of_account_id || l.accrual_id ||
    (l.description && String(l.description).trim() !== '') || Number(l.unit_price) > 0
  )
}

function setBillType(type) {
  if (isEdit.value) return
  if (type === form.bill_type) return
  if (linesHaveData() && ! confirm(t('accounting.supplierBills.form.changeBillTypeConfirm'))) return
  form.bill_type = type
  form.lines = [type === 'goods' ? blankGoodsLine() : type === 'prepaid' ? blankPrepaidLine() : blankExpenseLine()]
}

// ── Currency / exchange rate ────────────────────────────────────

// Only the answer to the LATEST request may set the rate: a slow reply for
// the currency picked a moment ago used to overwrite the newer currency's
// rate (EUR ended up with the USD rate). A currency with no rate on file
// now leaves the box EMPTY (the server then looks the rate up or refuses
// with a clear message) instead of silently using 1.
let rateReq = 0
let autoRate = null            // the rate WE filled in (so a hand-typed rate is never overwritten)

async function refreshRate() {
  const myReq = ++rateReq
  if (! isForeignCurrency.value) { form.exchange_rate = '1'; autoRate = null; return }
  form.exchange_rate = ''
  autoRate = null
  try {
    const response = await fetch(
      route('accounting.supplier-bills.latest-exchange-rate', { currency_id: form.currency_id, date: form.bill_date }),
      { headers: { Accept: 'application/json' } }
    )
    const data = await response.json()
    if (myReq !== rateReq) return
    form.exchange_rate = data.rate ?? ''
    autoRate = data.rate ?? null
  } catch (e) {
    if (myReq === rateReq) form.exchange_rate = ''
  }
}

async function onCurrencyChange() {
  await refreshRate()
}

// A different bill date can have a different rate — refresh it, unless the
// user typed their own rate.
watch(() => form.bill_date, () => {
  if (isReadOnly.value || ! isForeignCurrency.value) return
  if (! form.exchange_rate || String(form.exchange_rate) === String(autoRate)) refreshRate()
})

// ── Goods lines — supplier / PO drive the eligible GRN line list ──

const eligibleGrnLines = ref([])

// Only the answer to the LATEST request may fill the list (a slow reply for
// the supplier picked a moment ago used to replace the current one).
let grnReq = 0

async function fetchEligibleGrnLines(prune = false) {
  const myReq = ++grnReq
  eligibleGrnLines.value = []
  if (! form.partner_id) { if (prune) pruneGrnSelections(); return }
  const response = await fetch(
    route('accounting.supplier-bills.eligible-grn-lines', { partner_id: form.partner_id, purchase_order_id: form.purchase_order_id || undefined }),
    { headers: { Accept: 'application/json' } }
  )
  const data = await response.json()
  if (myReq !== grnReq) return
  eligibleGrnLines.value = data.lines ?? []
  mergeSelectedGrnLines()
  if (prune) pruneGrnSelections()
}

// After the supplier or Purchase Order changes, a line may still hold a
// receipt line that is no longer on offer. The dropdown then LOOKS empty but
// the hidden value was still saved — so such lines are reset here.
function pruneGrnSelections() {
  const ids = new Set(eligibleGrnLines.value.map(g => g.id))
  form.lines = form.lines.map(l =>
    (l.goods_receipt_note_line_id && ! ids.has(Number(l.goods_receipt_note_line_id))) ? blankGoodsLine() : l
  )
}

// Fix: same root cause and same fix pattern as availablePurchaseOrders
// above. eligible-grn-lines only returns lines with remaining billable
// quantity > 0 — but a Bill's OWN already-billed GRN line has remaining
// = 0 (billed by this very bill), so it drops out of the fetched list
// and the dropdown renders blank for a line that's already correctly
// selected. Reconstructs a display-shape entry from this bill's own
// loaded relation (props.supplierBill.lines[].goods_receipt_note_line
// — snake_case, per Inertia's relation serialization) for any selected
// line missing from the fetched list, so the dropdown always shows
// what's actually saved. remaining_billable_quantity is set to this
// line's own committed quantity rather than the GRN line's true
// remaining (0) — the true "remaining" figure is meaningless for a line
// that's already fully claimed by this exact bill.
function mergeSelectedGrnLines() {
  if (! isEdit.value) return
  const known = new Set(eligibleGrnLines.value.map(g => g.id))

  ;(props.supplierBill?.lines ?? []).forEach(l => {
    const grnLineId = l.goods_receipt_note_line_id
    if (! grnLineId || known.has(grnLineId)) return

    const grnLine = l.goods_receipt_note_line
    if (! grnLine) return

    eligibleGrnLines.value.push({
      id: grnLineId,
      grn_code: grnLine.goods_receipt_note?.grn_code ?? '',
      product_id: grnLine.product_id,
      product_name: grnLine.product?.name_en ?? '',
      unit_cost: grnLine.unit_cost,
      quantity: grnLine.quantity,
      billed_quantity: grnLine.billed_quantity,
      remaining_billable_quantity: l.quantity,
      uom_id: grnLine.uom_id,
    })
    known.add(grnLineId)
  })
}

// The payment term follows the supplier — unless the user picked a term
// themselves (then it is left alone).
let autoTermId = null

function onPartnerChange() {
  const supplier = (props.suppliers ?? []).find(s => s.id == form.partner_id)
  if (supplier && (!form.payment_term_id || String(form.payment_term_id) === String(autoTermId))) {
    form.payment_term_id = supplier.payment_term_id ?? ''
    autoTermId = supplier.payment_term_id ?? null
  }
  // The previously picked PO may belong to a different supplier now —
  // clear it rather than silently keep an impossible combination.
  if (form.purchase_order_id && !availablePurchaseOrders.value.some(po => po.id === Number(form.purchase_order_id))) {
    form.purchase_order_id = ''
  }
  // Lines tied to the PREVIOUS supplier's documents are cleared at once.
  form.lines = form.lines.map(l => {
    if (l.goods_receipt_note_line_id) return blankGoodsLine()
    if (l.accrual_id) return { ...l, accrual_id: '', description: '', unit_price: '' }
    return l
  })
  if (form.bill_type === 'goods') fetchEligibleGrnLines(true)
  if (form.bill_type === 'expense') fetchEligibleAccruals()
}

function onPoChange() {
  fetchEligibleGrnLines(true)
}

if (form.partner_id && form.bill_type === 'goods') fetchEligibleGrnLines()

function addGoodsLine() {
  form.lines.push(blankGoodsLine())
}

function onGrnLineChange(line) {
  const gl = eligibleGrnLines.value.find(g => g.id === Number(line.goods_receipt_note_line_id))
  if (! gl) return
  line.product_id = gl.product_id
  line.description = `${gl.grn_code} — ${gl.product_name}`
  line.quantity = gl.remaining_billable_quantity
  line.unit_price = gl.unit_cost
  line._grnUnitCost = gl.unit_cost
  line.vat_rate = props.productVatRates?.[gl.product_id] ?? '0'
}

// Fix (July 2026): a GRN line picked in one row used to still show up
// in every other row's dropdown too, letting the same line be billed
// twice on one bill. Each row now only offers lines not already
// claimed by a DIFFERENT row on this same form (the row's own current
// selection stays visible so it doesn't disappear from itself).
// ── Option lists for SelectBox ───────────────────────────────────
// SelectBox takes {value, label} rather than reading a <select>'s
// <option> children, so each list is shaped once here instead of
// being rebuilt in the template. Labels are exactly the strings the
// old <option>s rendered, so nothing a user reads changes — and the
// panel's filter matches against that same label, which is why the
// code and the name are kept in one string rather than split across
// label/sub: someone filtering for "1502" and someone filtering for
// "Freight" both have to hit.
const supplierOptions = computed(() =>
  props.suppliers.map(x => ({ value: x.id, label: `${x.code} — ${x.name_en}` }))
)
const poOptions = computed(() =>
  availablePurchaseOrders.value.map(x => ({ value: x.id, label: x.po_code }))
)
const paymentTermOptions = computed(() =>
  props.paymentTerms.map(x => ({ value: x.id, label: x.name_en }))
)
const currencyOptions = computed(() =>
  props.currencies.map(x => ({ value: x.id, label: x.code }))
)
const expenseAccountOptions = computed(() =>
  props.expenseAccounts.map(x => ({ value: x.id, label: `${x.code} — ${x.name_en}` }))
)
const projectOptions = computed(() =>
  props.projects.map(x => ({ value: x.id, label: x.contract_code }))
)
const accrualOptions = computed(() =>
  eligibleAccruals.value.map(x => ({
    value: x.id,
    label: `${x.accrual_code} — ${x.description} (${t('accounting.supplierBills.form.remaining')} ${fmt(x.remaining_amount)})`,
  }))
)
const lineKindOptions = computed(() => ([
  { value: 'fresh',   label: t('accounting.supplierBills.form.freshExpense') },
  { value: 'accrual', label: t('accounting.supplierBills.form.settleAccrual') },
]))

// Per-line, because which GRN lines are still available depends on
// what the other lines have already claimed — see availableGrnLinesFor.
function grnLineOptions(currentLine) {
  return availableGrnLinesFor(currentLine).map(gl => ({
    value: gl.id,
    label: `${gl.grn_code} — ${gl.product_name} (${t('accounting.supplierBills.form.max')} ${gl.remaining_billable_quantity})`,
  }))
}

function availableGrnLinesFor(currentLine) {
  const pickedElsewhere = new Set(
    form.lines
      .filter(l => l !== currentLine && l.goods_receipt_note_line_id)
      .map(l => Number(l.goods_receipt_note_line_id))
  )
  return eligibleGrnLines.value.filter(gl => !pickedElsewhere.has(gl.id))
}

// ── Expense lines — accrual picker ──────────────────────────────

const eligibleAccruals = ref([])

let accrualReq = 0   // latest request wins (see fetchEligibleGrnLines)

async function fetchEligibleAccruals() {
  const myReq = ++accrualReq
  eligibleAccruals.value = []
  if (! form.partner_id) return
  const response = await fetch(
    route('accounting.accruals.eligible-for-partner', { partner_id: form.partner_id }),
    { headers: { Accept: 'application/json' } }
  )
  const data = await response.json()
  if (myReq !== accrualReq) return
  eligibleAccruals.value = data.accruals ?? []
}

if (form.partner_id && form.bill_type === 'expense') fetchEligibleAccruals()

function addExpenseLine() {
  form.lines.push(blankExpenseLine())
}

function addPrepaidLine() {
  form.lines.push(blankPrepaidLine())
}

function onLineKindChange(line) {
  if (line._kind === 'fresh') { line.accrual_id = ''; }
  else { line.chart_of_account_id = ''; if (! eligibleAccruals.value.length) fetchEligibleAccruals(); }
}

function onAccrualChange(line) {
  const ac = eligibleAccruals.value.find(a => a.id === Number(line.accrual_id))
  if (! ac) return
  line.description = ac.description
  line.unit_price = ac.remaining_amount
  line.vat_rate = '0'
}

// ── Shared ───────────────────────────────────────────────────────

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

// Exact decimal maths that follows the server's rounding (Utils/money.js),
// so the preview equals the saved bill to the cent. Values are returned as
// Numbers for display; every sum is done on exact decimals first.
function lineSub(line) {
  const qty = line.quantity || (form.bill_type === 'expense' || form.bill_type === 'prepaid' ? 1 : 0)
  return moneyLineSubtotal(qty, line.unit_price || 0)
}
function lineSubtotal(line) { return Number(lineSub(line)) }
function lineVat(line) { return Number(moneyLineVat(lineSub(line), line.vat_rate || 0)) }
function lineTotal(line) { return Number(sumMoney([lineSub(line), moneyLineVat(lineSub(line), line.vat_rate || 0)])) }

const subtotal = computed(() => Number(sumMoney(form.lines.map(l => lineSub(l)))))
const vatTotal = computed(() => Number(sumMoney(form.lines.map(l => moneyLineVat(lineSub(l), l.vat_rate || 0)))))
const grandTotal = computed(() => Number(sumMoney([subtotal.value, vatTotal.value])))
// Withholding Tax (July 2026, moved to line level) — each line's own
// rate applies to that line's own subtotal, before VAT; the total
// shown is just the sum, same relationship vatTotal has to lineVat().
function lineWithholding(line) {
  return Number(moneyLineVat(lineSub(line), line.withholding_tax_rate || 0))
}
const withholdingAmount = computed(() => Number(sumMoney(form.lines.map(l => moneyLineVat(lineSub(l), l.withholding_tax_rate || 0)))))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.supplier-bills.update', props.supplierBill.id))
    : form.post(route('accounting.supplier-bills.store'))
}

// ── Prepaid Amortization Schedule ───────────────────────────────
// Client-side mirror of PrepaidAmortizationService::totalPeriods() —
// display-only (the server is always the source of truth for what
// actually posts); keeps this readout in sync with the confirmed
// mid-month convention without a round trip just to show a count.
function totalPeriodsForLine(line) {
  const months = Number(line.amortization_months || 0)
  const day = Number((props.supplierBill?.bill_date || '').toString().slice(8, 10))
  return day >= 16 ? months + 1 : months
}
function isLineFullyAmortized(line) {
  const posted = (line.amortization_entries || []).filter(e => e.status === 'posted').length
  return posted >= totalPeriodsForLine(line)
}

const postingLineId = ref(null)
function confirmPostAmortization(line) {
  if (! confirm(t('accounting.supplierBills.form.postThisPeriodSAmortizationNow'))) return
  postingLineId.value = line.id
  router.post(route('accounting.supplier-bill-lines.post-amortization', line.id), {}, {
    preserveScroll: true,
    onFinish: () => { postingLineId.value = null },
  })
}

const reversingEntryId = ref(null)
function confirmReverseAmortization(entry) {
  if (! confirm(t('accounting.supplierBills.form.reverseThisAmortizationPeriodThisCreates'))) return
  reversingEntryId.value = entry.id
  router.post(route('accounting.prepaid-amortization-entries.reverse', entry.id), {}, {
    preserveScroll: true,
    onFinish: () => { reversingEntryId.value = null },
  })
}

// ── Set to Draft ───────────────────────────────────────────────────

function confirmSetToDraft() {
  if (! confirm(t('accounting.supplierBills.form.reopenThisBillForEditingThis'))) return

  // Same fix already proven on Customer Invoice: the server redirects
  // back to this exact edit URL, and Inertia landing on a page it's
  // already displaying updates props in place instead of re-running
  // this component's setup — forcing a full reload here guarantees
  // everything (GRN lines, linked payments) re-fetches correctly
  // against the now-reversed state. Only reload when the response
  // genuinely carries no error — a rejected request (e.g. this bill
  // is linked to a payment's original posting settling other bills
  // too) still redirects back "successfully" in Inertia's eyes, and a
  // forced reload right after would wipe the one-time flash error
  // before it was ever seen.
  // Audit H5: a written reason is required — and a second person must approve.
  const reason = window.prompt(t('accounting.reopen.reasonPromptApproval'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.supplier-bills.set-to-draft', props.supplierBill.id), { reason: reason.trim() }, {
    preserveScroll: true, // Audit H5: stays posted until a second person approves
  })
}

// ── Linked Payments — Unlink ──────────────────────────────────────

const unallocating = ref(null)
function confirmUnallocate(alloc) {
  if (! confirm(t('accounting.supplierBills.form.unlinkThisPaymentFromThisBill'))) return
  unallocating.value = alloc.id
  router.delete(route('accounting.supplier-payment-allocations.unallocate', alloc.id), {
    preserveScroll: true,
    onFinish: () => { unallocating.value = null },
  })
}

// ── Other Unapplied Payments — link from here ─────────────────────

const unappliedPayments = ref([])
const linkAmounts = ref({})
const linking = ref(null)
const linkError = ref('')

async function fetchUnappliedPayments() {
  if (! props.supplierBill?.partner_id) return
  const res = await fetch(route('accounting.supplier-bills.unapplied-payments', { partner_id: props.supplierBill.partner_id }), {
    headers: { Accept: 'application/json' }, cache: 'no-store',
  })
  const data = await res.json()
  unappliedPayments.value = data.payments ?? []
}

if (isEdit.value && props.supplierBill.status === 'posted') {
  fetchUnappliedPayments()
}

function submitLink(pmt) {
  const amount = linkAmounts.value[pmt.id]
  if (! amount || Number(amount) <= 0) return

  linkError.value = ''
  linking.value = pmt.id

  fetch(route('accounting.supplier-payments.allocate', pmt.id), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ supplier_bill_id: props.supplierBill.id, amount }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { linkError.value = data.message || t('accounting.supplierBills.form.somethingWentWrongPleaseTryAgain'); return }
      router.reload({ preserveScroll: true })
    })
    .catch(() => { linkError.value = t('accounting.supplierBills.form.somethingWentWrongPleaseTryAgain') })
    .finally(() => { linking.value = null })
}
</script>

<style>
.sb-lines-wrap { border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius, 8px); }
.sb-th-grn-line { min-width: 15rem; }
.sb-th-qty { width: 5.5rem; text-align: end; }
.sb-th-cost { width: 6.5rem; text-align: end; }
.sb-th-amount { width: 7rem; text-align: end; }
.sb-th-months { width: 5.5rem; text-align: end; white-space: normal; line-height: 1.2; }
.sb-th-vat { width: 6rem; text-align: end; }
.sb-th-pct { width: 5rem; text-align: end; white-space: normal; line-height: 1.2; }
.sb-th-total { width: 7.5rem; text-align: end; }
.sb-deductible-toggle {
  display: flex; align-items: center; justify-content: flex-end; gap: 4px;
  margin-top: 4px; font-size: 10.5px; color: var(--zv-text-muted); cursor: pointer; white-space: nowrap;
}
.sb-deductible-toggle input[type="checkbox"] { width: 12px; height: 12px; margin: 0; }
.sb-th-help {
  display: inline-flex; align-items: center; justify-content: center;
  width: 1rem; height: 1rem; border-radius: 50%;
  background: var(--zv-bg-surface); color: var(--zv-text-muted);
  font-size: 0.625rem; font-weight: 700; cursor: help; margin-inline-start: 0.25rem;
}
.sb-th-actions { width: 3rem; text-align: center; }
.sb-totals { display: flex; flex-direction: column; gap: .5rem; align-items: flex-end; flex-shrink: 0; padding-top: 1.6rem; }
.sb-totals > div { display: flex; gap: 1.5rem; font-size: .9rem; min-width: 16rem; justify-content: space-between; }
.sb-totals-grand { padding-top: .5rem; border-top: 1px solid var(--zv-border, #e2e8f0); font-size: 1.05rem; }
.sb-totals-wht { color: var(--zv-danger, #ef4444); }
.sb-totals-net { padding-top: .5rem; border-top: 1px solid var(--zv-border, #e2e8f0); font-size: 1.05rem; font-weight: 700; }

.ci-linked-actions { width: 18rem; text-align: end; }
.ci-linked-actions .field-hint { text-align: end; display: block; margin: 0; }

/* Fix: Notes now sits beside the totals instead of stacked underneath
   — uses the wide open space next to it rather than sitting narrow. */
.ci-totals-notes-row { display: flex; gap: 2rem; align-items: flex-start; }
.ci-totals-notes-row .field-group { flex: 1; }

@media (max-width: 900px) {
  .ci-totals-notes-row { flex-direction: column; }
  .sb-totals { align-items: stretch; padding-top: 0; }
  .sb-totals > div { min-width: 0; }
}
</style>