<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Leasing/ContractForm.vue

  Create + Edit for Leasing Contracts.
  Shows treatment selector only for Operational contracts.
  Capital contracts always use IFRS 16 — no treatment choice.
  Treatment is locked once status goes active (Rule 29.1).

  Changes in this revision (confirmed with Mahmoud):
    - NEW: entry_type field ('new' | 'existing'), labeled "Contract
      Type" on the form. NOT the same thing as the pre-existing
      `contract_type` field (Operational/Capital) — that field's
      visible label was renamed from "Contract Type" to "Lease
      Classification" to remove the collision; its underlying values
      and behavior are completely unchanged.
    - Section 1 header row restructured to 4 columns: Contract Type
      (new/existing) / Leasing Company / Contract Reference / Asset
      Description.
    - Renamed monthly_payment → installment_amount, added
      installment_interval (monthly/quarterly/semi_annual/annual).
      Row is now 4 columns: Installment Interval / Installment
      Amount / Duration (Months) / Borrowing Rate.
    - NEW: bank_liability_account_id field ("Bank Liability
      Account"). Row is now 4 columns: Start Date / End Date
      (computed, read-only, from Start Date + Duration — replaces
      the old separate "Contract ends on" alert banner) / Bank
      Liability Account / Bank Account for Payments.
      ⚠ NEEDS BACKEND: `liabilityAccounts` prop must be added by the
      controller (same pattern as the existing `expenseAccounts`
      prop) — not yet wired up server-side as of this revision.
    - Cheque Schedule: added Beginning Balance (after Payment Date)
      and End Balance (after Interest Amount). End Balance
      auto-calculates as Beginning − Principal but stays editable
      for manual override. Next row's Beginning Balance auto-carries
      from the previous row's End Balance.
    - First Payment Date field REMOVED entirely (confirmed with
      Mahmoud — redundant once the cheque schedule is entered or
      uploaded directly; nothing left for it to represent). Backend
      column made nullable via migration rather than dropped, in
      case existing contracts already have a value in it.
    - BUG FIX: Currency / Exchange Rate / Total Contract Value row
      had an inverted grid-class ternary (field-grid-2 applied when
      3 fields were showing, field-grid-3 when only 2 were) —
      corrected.

  ═══════════════════════════════════════════════════════════════
  ACCOUNTING ENGINE TODO — READ BEFORE BUILDING LEASE POSTING LOGIC
  ═══════════════════════════════════════════════════════════════
  Confirmed business rule: entry_type drives whether this contract
  triggers a posting at all.

    - entry_type = 'existing' → NO action. The lease already existed
      before the company started using Vero CFO — same principle as
      Opening Balances and LG/LC Issuance's "Opening Issuance" mode.
      Pure record-keeping only.

    - entry_type = 'new' → a REAL event happening now. On save (or
      on activation — confirm which trigger with Mahmoud when this
      gets built), the system must auto-post the initial lease
      liability recognition:
          Dr  [Right-of-Use Asset account — Treatment B/Capital only]
          Cr  bank_liability_account_id (this form's new field)
          Amount: total_contract_value (Capital/Treatment B) — for
          Treatment A (Direct Expense), confirm with Mahmoud whether
          any liability posting applies at all, since that treatment
          deliberately keeps the asset off the Balance Sheet.

  Since the Accounting Engine's General Ledger doesn't exist yet,
  follow the exact same PENDING-GL-POSTING pattern already built for
  LG Issuance (`lg_issuance_movements`) and reserved for Purchase
  Orders — do NOT invent a different mechanism. Create an equivalent
  `leasing_contract_movements` table, write a staged
  'pending_gl_posting' row for the liability recognition when
  entry_type = 'new', and leave it there until the real GL sweep
  routine exists (Accounting Engine Phase 4).

  @layout    AppLayout
  @route     fi.leasing-contracts.create / fi.leasing-contracts.edit
  @props     contract, leasingCompanies, bankAccounts, expenseAccounts,
             liabilityAccounts (NEW — needs backend), currencies,
             companyBaseCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ══════════════════════════════════════════════════════
                 PAGE HEADER
            ══════════════════════════════════════════════════════ -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('fi.leasing-contracts.index')">{{ t('financialInstitutions.leasing.contractForm.leasingContracts') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.leasing.contractForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">
                        {{ isEdit ? t('financialInstitutions.leasing.contractForm.editLeasingContract') : t('financialInstitutions.leasing.contractForm.newLeasingContract') }}
                    </h1>
                </div>
            </div>

            <!-- Error banner -->
            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span>{{ t('financialInstitutions.leasing.contractForm.pleaseFixTheErrorsBelowBefore') }}</span>
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════════════════════════════════════
                     SECTION 1 — CONTRACT DETAILS
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.leasing.contractForm.contractDetails') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.leasing.contractForm.leasingCompanyAssetAndContractType') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <div class="field-grid field-grid-4 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.contractType') }}</label>
                                <select v-model="form.entry_type" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.entry_type }">
                                    <option value="new">{{ t('financialInstitutions.leasing.contractForm.new') }}</option>
                                    <option value="existing">{{ t('financialInstitutions.leasing.contractForm.existing') }}</option>
                                </select>
                                <p v-if="form.errors.entry_type" class="zv-field-error">{{ form.errors.entry_type }}</p>
                            </div>

                            <!-- Fix (July 2026 — Lease Amortization Engine): only relevant when
                                 entry_type = 'existing'. Same principle as Opening Balances
                                 elsewhere — user enters where things stand today, the schedule
                                 builds forward from that instead of recalculating from scratch. -->
                            <template v-if="form.entry_type === 'existing'">
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.balanceOwedToday') }}</label>
                                    <input v-model="form.opening_balance_amount" type="number" step="0.01" class="zv-input"
                                           :class="{ 'zv-input-error': form.errors.opening_balance_amount }"/>
                                    <p v-if="form.errors.opening_balance_amount" class="zv-field-error">{{ form.errors.opening_balance_amount }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.asOfDate') }}</label>
                                    <input v-model="form.opening_balance_as_of_date" type="date" class="zv-input"
                                           :class="{ 'zv-input-error': form.errors.opening_balance_as_of_date }"/>
                                    <p v-if="form.errors.opening_balance_as_of_date" class="zv-field-error">{{ form.errors.opening_balance_as_of_date }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.remainingPayments') }}</label>
                                    <input v-model="form.opening_remaining_installments" type="number" class="zv-input"
                                           :class="{ 'zv-input-error': form.errors.opening_remaining_installments }"/>
                                    <p v-if="form.errors.opening_remaining_installments" class="zv-field-error">{{ form.errors.opening_remaining_installments }}</p>
                                </div>
                            </template>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.leasingCompany') }}</label>
                                <select v-model="form.leasing_company_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.leasing_company_id }">
                                    <option value="">{{ t('financialInstitutions.leasing.contractForm.selectLeasingCompany') }}</option>
                                    <option v-for="co in leasingCompanies" :key="co.id" :value="co.id">{{ co.name_en }}</option>
                                </select>
                                <p v-if="form.errors.leasing_company_id" class="zv-field-error">{{ form.errors.leasing_company_id }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.leasing.contractForm.contractReference') }}</label>
                                <input v-model="form.contract_reference" type="text" class="zv-input font-mono"
                                       :placeholder="t('financialInstitutions.leasing.contractForm.leasingCompanyContractNumber')"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.assetDescription') }}</label>
                                <input v-model="form.asset_description" type="text" class="zv-input"
                                       :class="{ 'zv-input-error': form.errors.asset_description }"
                                       :placeholder="t('financialInstitutions.leasing.contractForm.eGToyotaHiluxFleet5')"/>
                                <p v-if="form.errors.asset_description" class="zv-field-error">{{ form.errors.asset_description }}</p>
                            </div>
                        </div>

                        <!-- Lease Classification — RENAMED label from "Contract Type" to avoid
                             colliding with the new New/Existing field above, which now owns
                             that label. Underlying field/values (form.contract_type =
                             operational/capital) are completely unchanged. -->
                        <div class="field-group mb-5">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.leaseClassification') }}</label>
                            <OptionTiles
                                :model-value="form.contract_type" variant="card"
                                :options="[
                                    { value: 'operational', label: t('financialInstitutions.leasing.contractForm.operationalLease'), description: t('financialInstitutions.leasing.contractForm.paymentsAsExpenseOrROUYour') },
                                    { value: 'capital', label: t('financialInstitutions.leasing.contractForm.capitalLease'), description: t('financialInstitutions.leasing.contractForm.financeLeaseIFRS16AlwaysApplies') },
                                ]"
                                @update:model-value="(v) => { form.contract_type = v; form.accounting_treatment = v === 'capital' ? 'rou_b' : '' }"
                            />
                        </div>

                        <!-- Treatment — only for Operational -->
                        <div v-if="form.contract_type === 'operational'" class="field-group mb-5">
                            <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.accountingTreatment') }}</label>
                            <OptionTiles
                                :model-value="form.accounting_treatment" variant="card" :disabled="isLocked"
                                :options="[
                                    { value: 'expense_a', label: t('financialInstitutions.leasing.contractForm.treatmentADirectExpense'), description: t('financialInstitutions.leasing.contractForm.eachPaymentPostsToExpenseAccount') },
                                    { value: 'rou_b', label: t('financialInstitutions.leasing.contractForm.treatmentBROUIFRS16'), description: t('financialInstitutions.leasing.contractForm.rightOfUseAssetLeaseLiability') },
                                ]"
                                @update:model-value="(v) => form.accounting_treatment = v"
                            />
                            <p v-if="isLocked" class="field-hint contract-warn">{{ t('financialInstitutions.leasing.contractForm.treatmentIsLockedOnActiveContracts') }}</p>

                            <!-- Expense account — only for Treatment A -->
                            <div v-if="form.accounting_treatment === 'expense_a'" class="field-group mt-4">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.expenseAccount') }}</label>
                                <select v-model="form.expense_account_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.expense_account_id }">
                                    <option value="">{{ t('financialInstitutions.leasing.contractForm.selectExpenseAccount52Or54') }}</option>
                                    <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name_en }}</option>
                                </select>
                                <p v-if="form.errors.expense_account_id" class="zv-field-error">{{ form.errors.expense_account_id }}</p>
                            </div>
                        </div>

                        <!-- Capital Lease info banner -->
                        <div v-if="form.contract_type === 'capital'" class="zv-alert zv-alert-info">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-sm">{{ t('financialInstitutions.leasing.contractForm.capitalLeasesAlwaysUseIFRS16') }}</span>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     SECTION 2 — FINANCIAL TERMS
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#b8860b">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.leasing.contractForm.financialTerms') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.leasing.contractForm.currencyContractValuePaymentsAndSchedule') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <!-- Currency + Exchange Rate + Total Contract Value — one line.
                             BUG FIX: this ternary was inverted (grid-2 when 3 fields
                             show, grid-3 when 2 show) — corrected to match field count. -->
                        <div class="field-grid" :class="showExchangeRate ? 'field-grid-3' : 'field-grid-2'" style="margin-bottom: 1.25rem;">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.currency') }}</label>
                                <select v-model="form.currency_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.currency_id }"
                                        @change="onCurrencyChange">
                                    <option value="">{{ t('financialInstitutions.leasing.contractForm.select') }}</option>
                                    <option v-for="c in currencies" :key="c.id" :value="c.id">
                                        {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.leasing.contractForm.functional') + ')' : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
                            </div>

                            <div v-if="showExchangeRate" class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.exchangeRate') }}</label>
                                <input v-model="form.exchange_rate" type="number" step="0.000001" min="0"
                                       class="zv-input text-end" placeholder="1.000000"/>
                                <p class="field-hint">{{ t('financialInstitutions.leasing.contractForm.rateToFunctionalCurrencyUsedFor') }}</p>
                            </div>

                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.totalContractValue') }}</label>
                                <div class="zv-input-group">
                                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                                    <input v-model="form.total_contract_value" type="number" step="0.01" min="0"
                                           class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                                </div>
                            </div>
                        </div>

                        <div class="field-grid field-grid-4 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.installmentInterval') }}</label>
                                <select v-model="form.installment_interval" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.installment_interval }">
                                    <option value="monthly">{{ t('financialInstitutions.leasing.contractForm.monthly') }}</option>
                                    <option value="quarterly">{{ t('financialInstitutions.leasing.contractForm.quarterly') }}</option>
                                    <option value="semi_annual">{{ t('financialInstitutions.leasing.contractForm.semiAnnual') }}</option>
                                    <option value="annual">{{ t('financialInstitutions.leasing.contractForm.annual') }}</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.installmentAmount') }}</label>
                                <div class="zv-input-group">
                                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                                    <input v-model="form.installment_amount" type="number" step="0.01" min="0"
                                           class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                                </div>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.durationMonths') }}</label>
                                <input v-model="form.duration_months" type="number" min="1"
                                       class="zv-input text-end" placeholder="36"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.borrowingRatePa') }}</label>
                                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" max="100"
                                       class="zv-input text-end" placeholder="12.0000"/>
                                <p class="field-hint">{{ t('financialInstitutions.leasing.contractForm.usedToCalculatePresentValueFor') }}</p>
                            </div>
                        </div>

                        <div class="field-grid field-grid-4 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
                                <input v-model="form.start_date" type="date" class="zv-input"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('common.endDate') }}</label>
                                <div class="zv-readonly-field">{{ calculatedEndDate ?? '—' }}</div>
                                <p class="field-hint">{{ t('financialInstitutions.leasing.contractForm.computedFromStartDateDuration') }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.bankLiabilityAccount') }}</label>
                                <select v-model="form.bank_liability_account_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.bank_liability_account_id }">
                                    <option value="">{{ t('financialInstitutions.leasing.contractForm.selectLiabilityAccount') }}</option>
                                    <option v-for="acc in liabilityAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name_en }}</option>
                                </select>
                                <p v-if="form.errors.bank_liability_account_id" class="zv-field-error">{{ form.errors.bank_liability_account_id }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.leasing.contractForm.bankAccountForPayments') }}</label>
                                <select v-model="form.bank_account_id" class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.bank_account_id }"
                                        :disabled="!form.currency_id">
                                    <option value="">{{ form.currency_id ? t('financialInstitutions.leasing.contractForm.selectBankAccount') : t('financialInstitutions.leasing.contractForm.selectACurrencyFirst') }}</option>
                                    <option v-for="acc in filteredBankAccounts" :key="acc.id" :value="acc.id">
                                        {{ acc.bank?.name_en }} — {{ acc.account_name }}
                                    </option>
                                </select>
                                <p v-if="form.errors.bank_account_id" class="zv-field-error">{{ form.errors.bank_account_id }}</p>
                                <p v-if="form.currency_id && filteredBankAccounts.length === 0" class="zv-field-error">
                                    {{ t('financialInstitutions.leasing.contractForm.noBankAccountsInThisCurrency') }}
                                </p>
                                <p class="field-hint">{{ t('financialInstitutions.leasing.contractForm.onlyAccountsMatchingTheContractCurrency') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     SECTION 3 — CHEQUE SCHEDULE
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#1490A8">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="form-section-title">{{ t('financialInstitutions.leasing.contractForm.chequeSchedule') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.leasing.contractForm.theRealChequesHandedToThe') }}</div>
                        </div>
                        <label class="cheque-upload-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            {{ t('financialInstitutions.leasing.contractForm.uploadExcel') }}
                            <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onExcelUpload"/>
                        </label>
                    </div>

                    <div class="form-section-body">
                        <p v-if="uploadError" class="zv-field-error mb-3">{{ uploadError }}</p>

                        <div class="zv-table-wrap">
                            <table class="zv-table">
                                <thead>
                                    <tr>
                                        <th style="width:13%">{{ t('financialInstitutions.leasing.contractForm.paymentDate') }}</th>
                                        <th style="width:13%" class="text-end">{{ t('financialInstitutions.leasing.contractForm.beginningBalance') }}</th>
                                        <th style="width:13%">{{ t('financialInstitutions.leasing.contractForm.chequeNumber') }}</th>
                                        <th style="width:13%" class="text-end">{{ t('financialInstitutions.leasing.contractForm.chequeAmount') }}</th>
                                        <th style="width:13%" class="text-end">{{ t('financialInstitutions.leasing.contractForm.principalAmount') }}</th>
                                        <th style="width:13%" class="text-end">{{ t('financialInstitutions.leasing.contractForm.interestAmount') }}</th>
                                        <th style="width:13%" class="text-end">{{ t('financialInstitutions.leasing.contractForm.endBalance') }}</th>
                                        <th style="width:9%"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in form.cheques" :key="idx">
                                        <td><input v-model="row.payment_date" type="date" class="zv-input so-cell-input"/></td>
                                        <td><input v-model="row.beginning_balance" type="number" step="0.01" class="zv-input so-cell-input text-end"/></td>
                                        <td><input v-model="row.cheque_number" type="text" class="zv-input so-cell-input font-mono" :placeholder="t('financialInstitutions.leasing.contractForm.eG0012345')"/></td>
                                        <td><input v-model="row.cheque_amount" type="number" step="0.01" min="0" class="zv-input so-cell-input text-end" @input="calcEndBalance(idx)"/></td>
                                        <td><input v-model="row.principal_amount" type="number" step="0.01" min="0" class="zv-input so-cell-input text-end"/></td>
                                        <td><input v-model="row.interest_amount" type="number" step="0.01" min="0" class="zv-input so-cell-input text-end"/></td>
                                        <td><input v-model="row.end_balance" type="number" step="0.01" class="zv-input so-cell-input text-end" :title="t('financialInstitutions.leasing.contractForm.beginningBalanceMinusPrincipalOverrideIf')"/></td>
                                        <td>
                                            <button type="button" class="so-remove-btn" @click="removeChequeRow(idx)" :title="t('financialInstitutions.leasing.contractForm.removeRow')">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <!-- Server errors for cheque rows (e.g. a missing cheque number).
                                 Shown here, row-numbered, because the cells themselves have no
                                 error slot — without this a rejected row would fail silently. -->
                            <ul v-if="chequeErrors.length" class="zv-field-error mt-2" style="list-style:none; padding:0;">
                                <li v-for="e in chequeErrors" :key="e.key">{{ e.text }}</li>
                            </ul>
                        </div>

                        <div class="so-lines-footer">
                            <button type="button" class="od-add-row-btn" @click="addChequeRow">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                                {{ t('financialInstitutions.leasing.contractForm.addCheque') }}
                            </button>
                            <div v-if="chequeTotal > 0" class="so-total-row">
                                <span class="so-total-lbl">{{ t('financialInstitutions.leasing.contractForm.total') }}</span>
                                <span class="so-total-val">{{ fmt(chequeTotal) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     FORM ACTIONS
                ══════════════════════════════════════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route('fi.leasing-contracts.index')" class="zv-btn zv-btn-ghost">
                            {{ t('common.cancel') }}
                        </Link>
                        <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.leasing.contractForm.createContract')) }}
                        </button>
                    </div>
                </div>

            </form>
        </div>
</template>

<script setup>
import { sum as sumMoney } from '@/Utils/money'
import { computed, ref } from 'vue'
import { addMonthsClamped } from '@/Utils/dates'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
    contract:              Object,
    leasingCompanies:      Array,
    bankAccounts:          Array,
    expenseAccounts:       Array,
    liabilityAccounts:     Array,  // NEW — needs backend support, see form header comment
    currencies:            Array,
    companyBaseCurrencyId: Number,
})

const isEdit = computed(() => !!props.contract?.id)
const isLocked = computed(() => isEdit.value && props.contract?.status !== 'draft')

const form = useForm({
    entry_type:              props.contract?.entry_type              ?? 'new', // NEW/Existing — see header comment on accounting behavior
    opening_balance_amount:         props.contract?.opening_balance_amount         ?? '',
    opening_balance_as_of_date:     props.contract?.opening_balance_as_of_date     ?? '',
    opening_remaining_installments: props.contract?.opening_remaining_installments ?? '',
    leasing_company_id:      props.contract?.leasing_company_id      ?? '',
    contract_reference:      props.contract?.contract_reference      ?? '',
    contract_type:           props.contract?.contract_type           ?? 'operational', // unchanged — Operational/Capital classification, now labeled "Lease Classification" in the UI
    asset_description:       props.contract?.asset_description       ?? '',
    currency_id:             props.contract?.currency_id             ?? props.companyBaseCurrencyId ?? '',
    exchange_rate:           props.contract?.exchange_rate           ?? '1',
    accounting_treatment:    props.contract?.accounting_treatment    ?? '',
    expense_account_id:      props.contract?.expense_account_id      ?? '',
    total_contract_value:    props.contract?.total_contract_value    ?? '',
    installment_interval:    props.contract?.installment_interval    ?? 'monthly',
    installment_amount:      props.contract?.installment_amount      ?? props.contract?.monthly_payment ?? '', // falls back to the old column name for existing records until the rename migration runs
    duration_months:         props.contract?.duration_months         ?? '',
    borrowing_rate:          props.contract?.borrowing_rate          ?? '',
    start_date:              props.contract?.start_date?.toString().slice(0, 10)         ?? '',
    bank_liability_account_id: props.contract?.bank_liability_account_id ?? '',
    bank_account_id:         props.contract?.bank_account_id         ?? '',
    notes:                   props.contract?.notes                   ?? '',
    cheques: props.contract?.cheques?.map(c => ({
        payment_date:       c.payment_date?.toString().slice(0, 10) ?? '',
        beginning_balance:  c.beginning_balance  ?? '',
        cheque_number:      c.cheque_number      ?? '',
        cheque_amount:      c.cheque_amount      ?? '',
        principal_amount:   c.principal_amount   ?? '',
        interest_amount:    c.interest_amount    ?? '',
        end_balance:        c.end_balance        ?? '',
    })) ?? [{ payment_date: '', beginning_balance: '', cheque_number: '', cheque_amount: '', principal_amount: '', interest_amount: '', end_balance: '' }],
})

// Currency differs from functional → show exchange rate field
const showExchangeRate = computed(() =>
    !!form.currency_id && !!props.companyBaseCurrencyId && form.currency_id != props.companyBaseCurrencyId
)

const selectedCurrencyCode = computed(() => {
    const c = (props.currencies ?? []).find(c => c.id == form.currency_id)
    return c?.code ?? 'CCY'
})

function onCurrencyChange() {
    // Reset exchange rate to 1 when switching to the functional currency
    if (!showExchangeRate.value) form.exchange_rate = '1'
    // Clear bank account if it no longer matches the new currency
    const acc = (props.bankAccounts ?? []).find(a => a.id == form.bank_account_id)
    if (acc && acc.currency_id != form.currency_id) form.bank_account_id = ''
}

// Bank accounts filtered to the selected contract currency
const filteredBankAccounts = computed(() => {
    if (!form.currency_id) return []
    return (props.bankAccounts ?? []).filter(a => a.currency_id == form.currency_id)
})

const calculatedEndDate = computed(() => {
    if (!form.start_date || !form.duration_months) return null
    // addMonthsClamped keeps 31 Jan + 1 month on 28 Feb (not 3 Mar).
    const d = addMonthsClamped(form.start_date, parseInt(form.duration_months))
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
})

// ── Cheque repeater ──────────────────────────────────────────────

const chequeTotal = computed(() =>
    Number(sumMoney(form.cheques.map(r => r.cheque_amount))) // Audit L6
)

function addChequeRow() {
    // Beginning Balance auto-carries from the previous row's End Balance —
    // saves re-typing the running balance on every new row.
    const prevEndBalance = form.cheques.length > 0
        ? form.cheques[form.cheques.length - 1].end_balance
        : ''
    form.cheques.push({ payment_date: '', beginning_balance: prevEndBalance, cheque_number: '', cheque_amount: '', principal_amount: '', interest_amount: '', end_balance: '' })
}

function removeChequeRow(idx) {
    form.cheques.splice(idx, 1)
}

// End Balance = Beginning Balance − Principal Amount. Auto-computed as a
// starting point on Cheque Amount entry, but left as a real editable
// field (not read-only) — Mahmoud may need to override it for cases
// where the schedule doesn't reconcile exactly (rounding, early
// settlement adjustments, etc.).
function calcEndBalance(idx) {
    const row = form.cheques[idx]
    const beginning = Number(row.beginning_balance || 0)
    const principal = Number(row.principal_amount || 0)
    if (row.beginning_balance !== '') {
        row.end_balance = (beginning - principal).toFixed(2)
    }
}

const uploadError = ref('')

async function onExcelUpload(e) {
    const file = e.target.files[0]
    if (!file) return
    uploadError.value = ''

    const formData = new FormData()
    formData.append('file', file)

    try {
        // BUG FIX: this request was missing the CSRF token — same
        // mistake, same fix as MtlScheduleIndex.vue's schedule
        // import. Every other POST/DELETE fetch() in the app
        // attaches it (see ContractAssignmentOdIndex.vue); without
        // it, Laravel correctly rejects the request (419) as
        // untrusted, unrelated to the uploaded file itself — the old
        // catch-all below showed the same misleading "could not read
        // this file" message for that and every other failure.
        const res = await fetch(route('fi.leasing-contracts.import-cheques'), {
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
            throw new Error('Upload failed')
        }
        const data = await res.json()

        // Strip the default blank row before appending imported rows
        form.cheques = form.cheques.filter(r => r.payment_date || r.cheque_number)
        form.cheques.push(...data.rows)

        if (form.cheques.length === 0) {
            form.cheques.push({ payment_date: '', beginning_balance: '', cheque_number: '', cheque_amount: '', principal_amount: '', interest_amount: '', end_balance: '' })
        }
    } catch (err) {
        uploadError.value = err.message === 'Upload failed' || !err.message
            ? t('financialInstitutions.leasing.contractForm.couldNotReadThisFilePlease')
            : t(err.message)
    } finally {
        e.target.value = ''
    }
}

function fmt(val) {
    return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Only a completely empty row is left out of what is SENT. A half-filled
// row (e.g. a date and amount but no cheque number) is sent as it is so the
// server can say exactly what is missing; before, it was dropped silently.
// form.transform changes only the outgoing data — the rows on screen stay
// untouched, so nothing is lost if the server rejects the save.
function chequeRowHasData(r) {
    return !!(r.payment_date || r.cheque_number || r.cheque_amount || r.principal_amount || r.interest_amount)
}
form.transform(data => ({ ...data, cheques: data.cheques.filter(chequeRowHasData) }))

// Row-numbered messages for any server error on the cheque rows.
const chequeErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('cheques'))
        .map(([key, text]) => {
            const m = key.match(/^cheques\.(\d+)\./)
            return { key, text: m ? `${t('financialInstitutions.leasing.contractForm.chequeRow')} ${Number(m[1]) + 1}: ${text}` : text }
        })
)

function submit() {
    isEdit.value
        ? form.put(route('fi.leasing-contracts.update', props.contract.id))
        : form.post(route('fi.leasing-contracts.store'))
}
</script>

<style>
.contract-warn { color: #f59e0b; font-style: normal; }

/* ── Self-contained grid fallback — same defensive fix applied to
     LgIssuanceForm.vue and the Purchase Order form, in case app.css
     hasn't loaded yet on a direct page visit. ─────────────────────── */
.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
.field-grid-2 { grid-template-columns: repeat(2, 1fr); }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-grid-4 { grid-template-columns: repeat(4, 1fr); }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.zv-readonly-field {
    padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
    background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 600; min-height: 38px;
}

.cheque-upload-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
    background: var(--zv-bg-surface); color: #1490A8; font-size: 12.5px; font-weight: 600;
    cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.cheque-upload-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.06); }
.hidden { display: none; }

/* Reused from SalesOrder line items table */
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

@media (max-width: 1100px) {
    .field-grid-4 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 900px) {
    .field-grid-2, .field-grid-3, .field-grid-4 { grid-template-columns: 1fr; }
}
</style>