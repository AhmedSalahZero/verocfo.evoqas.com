<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/LcIssuanceForm.vue

  Create/Edit form for an individual LC issuance. One component, two
  layouts — switches on issuanceType ('facility' | 'cash_cover'), same
  spirit as LgIssuanceForm.vue (Rule 2: one page, mode-driven). Locked
  while posting_status = 'posted' (Set to Draft to unlock — see
  LetterOfCreditIssuanceService class header for the full correction
  philosophy); fully editable while draft, same as create.

  Edit-mode-only sections: History (Mark as Paid / Settlement, each
  independently undoable, most-recent-first), Expenses (independent
  CRUD, not part of the undo chain), and GL Postings (read-only).

  @layout    AppLayout
  @route     fi.lc-issuance.create / fi.lc-issuance.edit
  @props     issuance, issuanceType, banks, currencies, lcFacilities,
             bankAccounts, defaultLcTypes, initialSuppliers,
             expenseAccounts, periodClosed, canSetToDraft, undoableEvent
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.lc-issuance.index')" class="zv-text-blue">{{ t('financialInstitutions.treasury.lcIssuanceForm.letterOfCreditIssuance') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t(typeLabel) }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('financialInstitutions.treasury.lcIssuanceForm.editLCIssuance') : t(typeLabel) }}</h1>
        </div>

        <div v-if="parseFloat(form.lc_amount) > 0" class="lgi-summary-panel">
          <div class="lgi-summary-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCAmount') }}</div>
          <div class="lgi-summary-value">{{ selectedCurrencyCode }} {{ fmt(form.lc_amount) }}</div>
          <div class="lgi-summary-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.commission') }}: {{ fmt(computedCommissionAmount) }} · {{ t('financialInstitutions.treasury.lcIssuanceForm.duration') }}: {{ form.duration_days || '—' }} {{ t('common.days') }}</div>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        {{ t('financialInstitutions.treasury.lcIssuanceForm.pleaseFixTheErrorsBelowBefore') }}
      </div>

      <div v-if="isEdit && isLocked" class="zv-alert zv-alert-info mb-4">
        {{ t('financialInstitutions.treasury.lcIssuanceForm.thisLCHasAlreadyBeenIssued') }}
      </div>
      <div v-else-if="isEdit && !isLocked" class="zv-alert zv-alert-warning mb-4">
        {{ t('financialInstitutions.treasury.lcIssuanceForm.thisLCIsADraftIts') }}
      </div>

      <form @submit.prevent="submit" novalidate>

      <fieldset :disabled="isLocked" style="border: 0; padding: 0; margin: 0;">

        <!-- ══════════════════ Posting Mode ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-body">
            <div class="lgi-posting-toggle">
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.posting_mode === 'new_issuance' }" @click="form.posting_mode = 'new_issuance'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.newIssuance') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.happeningNowSystemPostsCashCover') }}</div>
              </button>
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.posting_mode === 'opening_issuance' }" @click="form.posting_mode = 'opening_issuance'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.openingIssuance') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.alreadyExistedBeforeVeroCFORecord') }}</div>
              </button>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 1 — LC Type ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.letterOfCreditType') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-3">

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.transactionName') }}</label>
                <input v-model="form.transaction_name" type="text" class="zv-input"/>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCCurrency') }}</label>
                <select v-model="form.currency_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
              </div>

              <template v-if="issuanceType === 'facility'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.bank') }}</label>
                  <select v-model="form.bank_id" class="zv-select" @change="onBankChangeForFacility">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCFacility') }}</label>
                  <select v-model="form.credit_facility_id" class="zv-select" :disabled="!form.bank_id" @change="onFacilityChange">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="f in facilitiesForBank" :key="f.id" :value="f.id">{{ f.name }}</option>
                  </select>
                  <p v-if="selectedFacility" class="field-hint">
                    {{ selectedFacility.lc_security_type === 'fully_secured' ? t('financialInstitutions.treasury.lcIssuanceForm.fullySecuredFacility') : t('financialInstitutions.treasury.lcIssuanceForm.unsecuredFacility') }}
                  </p>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCLimit') }}</label>
                  <div class="zv-readonly-field">{{ selectedFacility ? fmt(selectedFacility.facility_limit) : '—' }}</div>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.availableRoom') }}</label>
                  <div class="zv-readonly-field lgi-room-highlight">{{ roomSummary ? fmt(roomSummary.available_room) : '—' }}</div>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCType') }}</label>
                  <select v-model="form.facility_lc_type_id" class="zv-select" :disabled="!form.credit_facility_id" @change="onFacilityLcTypeChange">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="lt in selectedFacility?.lc_types ?? []" :key="lt.id" :value="lt.id">{{ t(lt.lc_type) }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCTypeOutstanding') }}</label>
                  <div class="zv-readonly-field">{{ lcTypeOutstanding }}</div>
                </div>
              </template>

              <template v-else-if="issuanceType === 'cash_cover'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.bank') }}</label>
                  <select v-model="form.bank_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCType') }}</label>
                  <select v-model="form.lc_type" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="type in defaultLcTypes" :key="type" :value="type">{{ t(type) }}</option>
                  </select>
                </div>
              </template>

              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCCode') }} <span class="field-hint">({{ t('financialInstitutions.treasury.lcIssuanceForm.bankSOwnReferenceNotGenerated') }})</span></label>
                <input v-model="form.lc_code" type="text" class="zv-input"/>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 2 — Supplier & Purchase Order ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#1490A8">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.supplierInformation') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.supplier') }}</label>
                <select v-model="form.supplier_partner_id" class="zv-select" @change="onSupplierChange">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.pleaseSelect') }}</option>
                  <option v-for="p in suppliers" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                </select>
              </div>
              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.purchaseOrder') }}</label>
                <select v-model="form.purchase_order_id" class="zv-select" :disabled="!form.supplier_partner_id">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.none') }}</option>
                  <option v-for="po in eligiblePurchaseOrders" :key="po.id" :value="po.id">{{ po.po_code }} — {{ po.subject }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 3 — LC Information ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#b8860b">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.letterOfCreditInformation') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-4">

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.issuanceDate') }}</label>
                <input v-model="form.issuance_date" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCDurationDays') }}</label>
                <input v-model="form.duration_days" type="number" min="1" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.dueDate') }}</label>
                <div class="zv-readonly-field">{{ computedDueDate || '—' }}</div>
                <p class="field-hint">{{ t('financialInstitutions.treasury.lcIssuanceForm.issuanceDateLCDuration') }}</p>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCAmount') }}</label>
                <input v-model="form.lc_amount" type="number" step="0.01" class="zv-input"/>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.exchangeRate') }}</label>
                <input v-model="form.exchange_rate" type="number" step="any" class="zv-input" :readonly="isFunctionalCurrency"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.amountInPaymentCurrency') }}</label>
                <div class="zv-readonly-field">{{ fmt(computedAmountInPaymentCurrency) }}</div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.cashCoverRate') }}</label>
                <input v-model="form.cash_cover_pct" type="number" step="0.01" class="zv-input" :readonly="issuanceType === 'cash_cover'"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.cashCoverAmount') }}</label>
                <div class="zv-readonly-field">{{ fmt(computedCashCoverAmount) }}</div>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCCashCoverCurrency') }}</label>
                <select v-model="form.cash_cover_currency_id" class="zv-select" :disabled="issuanceType === 'cash_cover'">
                  <option v-for="c in cashCoverCurrencyOptions" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
                <p class="field-hint">{{ t('financialInstitutions.treasury.lcIssuanceForm.limitedToTheCompanySMainFunctional') }}</p>
              </div>
              <div></div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCCommissionRate') }}</label>
                <input v-model="form.commission_rate_pct" type="number" step="0.0001" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.lCCommissionAmountOneTimeIn') }}</label>
                <div class="zv-readonly-field">{{ fmt(computedCommissionAmount) }}</div>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.minLCCommissionFee') }}</label>
                <input v-model="form.min_commission_fee_amount" type="number" step="0.01" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.issuanceFees') }}</label>
                <input v-model="form.issuance_fee_amount" type="number" step="0.01" class="zv-input"/>
              </div>

              <template v-if="issuanceType === 'facility'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.cashCoverFromAccount') }}</label>
                  <select v-model="form.cash_cover_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCashCover" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.feesCommissionAccount') }}</label>
                  <select v-model="form.fees_commission_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCommission" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
              </template>
              <template v-else-if="issuanceType === 'cash_cover'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.cashCoverAccount') }}</label>
                  <select v-model="form.cash_cover_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCashCover" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.commissionAccount') }}</label>
                  <select v-model="form.fees_commission_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCommission" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 4 — Financing ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#7c3aed">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a4 4 0 00-8 0v2m-2 0h12l-1 11H8L7 9z"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.financing') }}</div>
          </div>
          <div class="form-section-body">
            <div class="lgi-posting-toggle mb-3">
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.financed_by === 'bank' }" @click="form.financed_by = 'bank'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.financedByBank') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.bankPaysTheSupplierNowCompany') }}</div>
              </button>
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.financed_by === 'self' }" @click="form.financed_by = 'self'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.financedBySelf') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.companyPaysTheSupplierDirectlyNo') }}</div>
              </button>
            </div>
            <div v-if="form.financed_by === 'bank'" class="field-grid field-grid-3">
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.financingDurationDays') }}</label>
                <input v-model="form.financing_duration_days" type="number" class="zv-input"/>
                <p class="field-hint">{{ t('financialInstitutions.treasury.lcIssuanceForm.gracePeriodBeforeRepaymentIsDue') }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Comment ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-body">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.comment') }}</label>
              <textarea v-model="form.comment" class="zv-input" rows="3"></textarea>
            </div>
          </div>
        </div>

      </fieldset>

      <!-- ══════════════════ Payment Status (edit mode, paid only) ══════════════════ -->
      <div v-if="isEdit && issuance.status === 'paid'" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.paymentStatus') }}</div>
        </div>
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.paidOn') }}</label>
              <div class="zv-readonly-field">{{ issuance.paid_at }}</div>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.supplierBill') }}</label>
              <div class="zv-readonly-field">{{ issuance.supplier_bill?.bill_code ?? '—' }}</div>
            </div>
            <div v-if="issuance.financed_by === 'bank'" class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lcIssuanceForm.owedToBank') }}</label>
              <div class="zv-readonly-field lgi-room-highlight">{{ fmt(issuance.bank_debt_outstanding) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ History (edit mode only) ══════════════════ -->
      <div v-if="isEdit && issuance.history?.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.history') }}</div>
          <div class="form-section-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.markAsPaidAndEverySettlement') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceForm.event') }}</th><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.date') }}</th><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.details') }}</th>
                <th>{{ t('financialInstitutions.treasury.lcIssuanceForm.journalEntry') }}</th><th class="cp-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="h in issuance.history" :key="h.id" :class="{ 'lgi-history-undone': h.undone_at }">
                <td class="font-mono">{{ eventTypeLabel(h.event_type) }}</td>
                <td>{{ h.event_date }}</td>
                <td>{{ historyDetails(h) }}</td>
                <td>{{ h.journal_entry?.entry_number ?? '—' }}</td>
                <td class="cp-th-actions">
                  <span v-if="h.undone_at" class="lgi-undone-tag">{{ t('financialInstitutions.treasury.lcIssuanceForm.undone') }}</span>
                  <button
                    v-else-if="canUndo(h)"
                    type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="undoing === h.id"
                    @click="confirmUndo(h)"
                  >{{ undoing === h.id ? t('financialInstitutions.treasury.lcIssuanceForm.undoing') : t('financialInstitutions.treasury.lcIssuanceForm.undo') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════ Expenses (edit mode only, independent) ══════════════════ -->
      <div v-if="isEdit" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.expenses') }}</div>
          <div class="form-section-sub">{{ t('financialInstitutions.treasury.lcIssuanceForm.freightCourierBankAdminChargesAddable') }}</div>
        </div>
        <div class="form-section-body">
          <table v-if="issuance.expenses?.length" class="zv-table mb-3">
            <thead>
              <tr><th>{{ t('common.name') }}</th><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.date') }}</th><th class="cc-th-num">{{ t('common.amount') }}</th><th>{{ t('common.status') }}</th><th class="cp-th-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="e in issuance.expenses" :key="e.id">
                <td>{{ e.expense_name }}</td>
                <td>{{ e.expense_date }}</td>
                <td class="zv-table-num">{{ fmt(e.amount) }}</td>
                <td>{{ e.status === 'posted' ? t('financialInstitutions.treasury.lcIssuanceForm.posted') : t('financialInstitutions.treasury.lcIssuanceForm.reversed') }}</td>
                <td class="cp-th-actions">
                  <button v-if="e.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="editExpense(e)">{{ t('common.edit') }}</button>
                  <button v-if="e.status === 'posted'" type="button" class="zv-btn zv-btn-danger zv-btn-sm" @click="deleteExpense(e)">{{ t('common.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>

          <button v-if="!expenseForm.show" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openAddExpense">{{ t('financialInstitutions.treasury.lcIssuanceForm.addExpense') }}</button>
          <div v-else class="lgi-posting-option" style="cursor: default;">
            <div class="field-grid field-grid-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.expenseName') }}</label>
                <input v-model="expenseForm.expense_name" type="text" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.date') }}</label>
                <input v-model="expenseForm.expense_date" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }}</label>
                <input v-model="expenseForm.amount" type="number" step="0.01" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.currency') }}</label>
                <select v-model="expenseForm.currency_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.expenseAccount') }}</label>
                <select v-model="expenseForm.expense_account_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                  <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_en }}</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lcIssuanceForm.paidFromAccount') }}</label>
                <select v-model="expenseForm.paid_from_account_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.treasury.lcIssuanceForm.select') }}</option>
                  <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
                </select>
              </div>
            </div>
            <div v-if="expenseErrors.length" class="zv-field-error mt-2" role="alert">
              <p v-for="(msg, i) in expenseErrors" :key="i">{{ msg }}</p>
            </div>
            <div class="flex gap-2 mt-3">
              <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="expenseForm.processing" @click="submitExpense">
                {{ expenseForm.processing ? t('common.saving') : t('financialInstitutions.treasury.lcIssuanceForm.saveExpense') }}
              </button>
              <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="expenseForm.show = false">{{ t('common.cancel') }}</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ GL Postings (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && issuance.movements?.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('financialInstitutions.treasury.lcIssuanceForm.gLPostings') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.movement') }}</th><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.date') }}</th><th class="cc-th-num">{{ t('common.amount') }}</th><th>{{ t('common.status') }}</th><th>{{ t('financialInstitutions.treasury.lcIssuanceForm.journalEntry') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="m in issuance.movements" :key="m.id">
                <td>{{ m.description }}</td>
                <td>{{ m.movement_date }}</td>
                <td class="zv-table-num">{{ fmt(m.amount) }}</td>
                <td>{{ movementStatusLabel(m.status) }}</td>
                <td>{{ m.posted_journal_entry?.entry_number ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('fi.lc-issuance.index')" class="zv-btn zv-btn-ghost">{{ t('financialInstitutions.treasury.lcIssuanceForm.close') }}</Link>

          <button
            v-if="isEdit && isLocked && props.canSetToDraft && !props.periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('financialInstitutions.treasury.lcIssuanceForm.setToDraft') }}</button>

          <button
            v-if="isEdit && !isLocked"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('financialInstitutions.treasury.lcIssuanceForm.deleting') : t('common.delete') }}</button>

          <button
            v-if="!isEdit || !isLocked"
            type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : t('financialInstitutions.treasury.lcIssuanceForm.save') }}</button>
        </div>
      </div>
      </form>
    </div>
</template>

<script setup>
import { todayLocal, addDaysIso } from '@/Utils/dates'
import { mul as mulMoney, lineVat } from '@/Utils/money'
import { computed, ref, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

const props = defineProps({
  issuance:          Object,
  issuanceType:      { type: String, required: true },
  banks:             { type: Array, default: () => [] },
  currencies:        { type: Array, default: () => [] },
  lcFacilities:      { type: Array, default: () => [] },
  bankAccounts:      { type: Array, default: () => [] },
  defaultLcTypes:    { type: Array, default: () => [] },
  initialSuppliers:  { type: Array, default: () => [] },
  expenseAccounts:   { type: Array, default: () => [] },
  periodClosed:      { type: Boolean, default: false },
  canSetToDraft:     { type: Boolean, default: false },
  undoableEvent:     { type: Object, default: null },
  functionalCurrencyId: { type: [Number, String], default: null },
})

const isEdit = computed(() => !!props.issuance?.id)
const isLocked = computed(() => isEdit.value && props.issuance?.posting_status !== 'draft')

const selectedCurrencyCode = computed(() => props.currencies.find(c => c.id == form?.currency_id)?.code ?? 'CCY')

const typeLabel = computed(() => ({
  facility: 'LC Against LC Facility',
  cash_cover: 'LC 100% Cash Cover',
}[props.issuanceType]))

// ── Form ──────────────────────────────────────────────────────────
const i = props.issuance ?? {}
const form = useForm({
  issuance_type: props.issuanceType,
  posting_mode: i.posting_mode ?? 'new_issuance',
  credit_facility_id: i.credit_facility_id ?? '',
  facility_lc_type_id: i.facility_lc_type_id ?? '',
  lc_type: i.lc_type ?? '',
  transaction_name: i.transaction_name ?? '',
  bank_id: i.bank_id ?? '',
  currency_id: i.currency_id ?? '',
  lc_code: i.lc_code ?? '',
  supplier_partner_id: i.supplier_partner_id ?? '',
  purchase_order_id: i.purchase_order_id ?? '',
  issuance_date: i.issuance_date ?? '',
  duration_days: i.duration_days ?? '',
  lc_amount: i.lc_amount ?? '',
  exchange_rate: i.exchange_rate ?? 1,
  cash_cover_pct: i.cash_cover_pct ?? (props.issuanceType === 'cash_cover' ? 100 : ''),
  commission_rate_pct: i.commission_rate_pct ?? '',
  min_commission_fee_amount: i.min_commission_fee_amount ?? '',
  issuance_fee_amount: i.issuance_fee_amount ?? '',
  cash_cover_account_id: i.cash_cover_account_id ?? '',
  fees_commission_account_id: i.fees_commission_account_id ?? '',
  cash_cover_currency_id: i.cash_cover_currency_id ?? '',
  financed_by: i.financed_by ?? 'self',
  financing_duration_days: i.financing_duration_days ?? '',
  comment: i.comment ?? '',
})

// ── Currency logic (corrected against CashVero's actual formulas —
// confirmed with Mahmoud from a screenshot of the real app) ────────
//
// Fees & Commission have NO separate currency field at all — they
// always post in the LC's own currency (form.currency_id). Cash
// Cover Currency IS a real, separate, user-picked field, but
// restricted to just [functional currency, LC currency] — never a
// free pick across every currency.

const isFunctionalCurrency = computed(() => String(form.currency_id) === String(props.functionalCurrencyId))

const cashCoverCurrencyOptions = computed(() => {
  const opts = props.currencies.filter(c => String(c.id) === String(props.functionalCurrencyId))
  if (form.currency_id && String(form.currency_id) !== String(props.functionalCurrencyId)) {
    const lcCcy = props.currencies.find(c => String(c.id) === String(form.currency_id))
    if (lcCcy) opts.push(lcCcy)
  }
  return opts
})

watch(() => form.currency_id, (newCurrency) => {
  // Exchange rate: 1:1 when the LC currency IS the functional
  // currency; otherwise auto-fetch (still editable afterward) —
  // exact port of CashVero's fetchExchangeRateForLcCurrency().
  fetchExchangeRateForLcCurrency(true)

  if (props.issuanceType === 'cash_cover') {
    form.cash_cover_currency_id = newCurrency
  } else if (!cashCoverCurrencyOptions.value.some(c => String(c.id) === String(form.cash_cover_currency_id))) {
    form.cash_cover_currency_id = props.functionalCurrencyId
  }
})
watch(() => form.issuance_date, () => fetchExchangeRateForLcCurrency())

let rateReq = 0, facilityReq = 0, purchaseOrdersReq = 0 // ticket numbers: only the newest reply may update the screen

async function fetchExchangeRateForLcCurrency(clearIfMissing = false) {
  const seq = ++rateReq
  if (!form.currency_id || String(form.currency_id) === String(props.functionalCurrencyId)) {
    form.exchange_rate = 1
    return
  }
  try {
    const { data } = await axios.get(route('fi.lc-issuance.exchange-rate'), {
      params: { currency_id: form.currency_id, date: form.issuance_date || todayLocal() },
    })
    if (seq !== rateReq) return
    if (data?.rate) form.exchange_rate = data.rate
    // No rate on file for a NEW currency: do not keep the previous currency's rate —
    // blank it so the person must type the right one (the server requires it).
    else if (clearIfMissing) form.exchange_rate = ''
  } catch {
    // Silent fail — field stays editable, user can type the rate in manually.
  }
}

// ── FACILITY type: Bank first, then LC Facility filtered to it ────

const facilitiesForBank = computed(() => props.lcFacilities.filter(f => f.bank_id == form.bank_id))
const selectedFacility = computed(() => props.lcFacilities.find(f => f.id == form.credit_facility_id))

function onBankChangeForFacility() {
  ++facilityReq
  form.credit_facility_id = ''
  form.facility_lc_type_id = ''
  form.lc_type = ''
  roomSummary.value = null
}

const roomSummary = ref(null)
async function onFacilityChange() {
  const seq = ++facilityReq
  form.facility_lc_type_id = ''
  form.lc_type = ''
  roomSummary.value = null
  if (!form.credit_facility_id) return
  const { data } = await axios.get(route('fi.lc-issuance.facility-room', form.credit_facility_id))
  if (seq !== facilityReq) return
  roomSummary.value = data
}

const lcTypeOutstanding = computed(() => {
  if (!roomSummary.value || !form.facility_lc_type_id) return '—'
  const raw = roomSummary.value.outstanding_by_lc_type_id?.[form.facility_lc_type_id]
  return fmt(raw ?? 0)
})

function onFacilityLcTypeChange() {
  const rate = selectedFacility.value?.lc_types?.find(lt => lt.id == form.facility_lc_type_id)
  if (!rate) return
  form.lc_type = rate.lc_type
  form.cash_cover_pct = rate.cash_cover_pct
  form.commission_rate_pct = rate.commission_rate_pct
  form.min_commission_fee_amount = rate.min_commission_fee_amount
  form.issuance_fee_amount = rate.issuance_fee_amount
}

// ── Supplier / Purchase Order ────────────────────────────────────

// Fix (same pattern as the LG beneficiary bug): seeded directly from
// the prop instead of an always-empty ref, so it's never dependent on
// a watch() firing on initial load.
const suppliers = ref(props.initialSuppliers ?? [])

const eligiblePurchaseOrders = ref(i.purchase_order ? [i.purchase_order] : [])
async function onSupplierChange() {
  const seq = ++purchaseOrdersReq
  form.purchase_order_id = ''
  eligiblePurchaseOrders.value = []
  if (!form.supplier_partner_id) return
  const { data } = await axios.get(route('fi.lc-issuance.purchase-orders', form.supplier_partner_id))
  if (seq !== purchaseOrdersReq) return
  eligiblePurchaseOrders.value = data
}

// ── Account filtering — by bank AND currency ────────────────────

const accountsForCashCover = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == (props.issuanceType === 'facility' ? selectedFacility.value?.bank_id : form.bank_id) &&
    a.account_type === 'current' &&
    a.currency_id == form.cash_cover_currency_id
  )
)
const accountsForCommission = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == (props.issuanceType === 'facility' ? selectedFacility.value?.bank_id : form.bank_id) &&
    a.account_type === 'current' &&
    a.currency_id == form.currency_id
  )
)

// When the bank or a currency changes, an account picked earlier may no longer be one of
// the choices on screen (the list shows blank) yet would still be saved — so drop it.
function dropAccountsNoLongerOffered() {
  const offered = (list, id) => !id || list.some(a => a.id == id)
  if (!offered(accountsForCashCover.value, form.cash_cover_account_id)) form.cash_cover_account_id = ''
  if (!offered(accountsForCommission.value, form.fees_commission_account_id)) form.fees_commission_account_id = ''
}
watch([accountsForCashCover, accountsForCommission], dropAccountsNoLongerOffered)

// ── Computed preview values ─────────────────────────────────────

const computedDueDate = computed(() => {
  if (!form.issuance_date || !form.duration_days) return ''
  return addDaysIso(form.issuance_date, Number(form.duration_days || 0))
})

// Previews use the same exact decimal maths and cut-off rules as the server (Utils/money.js),
// so the figures on screen equal the saved ones to the cent.
const computedAmountInPaymentCurrency = computed(() => {
  if (isNaN(parseFloat(form.lc_amount)) || isNaN(parseFloat(form.exchange_rate))) return 0
  return Number(mulMoney(form.lc_amount, form.exchange_rate, 2))
})

const computedCashCoverAmount = computed(() => {
  const pct = parseFloat(form.cash_cover_pct)
  if (isNaN(pct)) return 0
  return Number(lineVat(computedAmountInPaymentCurrency.value, form.cash_cover_pct))
})

const computedCommissionAmount = computed(() => {
  const minFee = parseFloat(form.min_commission_fee_amount)
  if (isNaN(parseFloat(form.lc_amount)) || isNaN(parseFloat(form.commission_rate_pct))) return isNaN(minFee) ? 0 : minFee
  const raw = Number(lineVat(form.lc_amount, form.commission_rate_pct))
  return Math.max(raw, isNaN(minFee) ? 0 : minFee)
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  isEdit.value
    ? form.put(route('fi.lc-issuance.update', props.issuance.id))
    : form.post(route('fi.lc-issuance.store'))
}

// ── Set to Draft / Delete ────────────────────────────────────────

function confirmSetToDraft() {
  if (! confirm(t('financialInstitutions.treasury.lcIssuanceForm.reopenThisLCForEditingIts'))) return
  router.patch(route('fi.lc-issuance.set-to-draft', props.issuance.id))
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('financialInstitutions.treasury.lcIssuanceForm.deleteThisLCIssuanceThisIs'))) return
  deleting.value = true
  router.delete(route('fi.lc-issuance.destroy', props.issuance.id), {
    onFinish: () => { deleting.value = false },
  })
}

// ── Undo (Mark as Paid / Settlement) ─────────────────────────────

const undoRouteFor = {
  mark_paid: 'fi.lc-issuance.undo-mark-as-paid',
  settlement: 'fi.lc-issuance.undo-settlement',
  cash_cover_release: 'fi.lc-issuance.undo-cash-cover-release',
}

function canUndo(h) {
  return !h.undone_at && props.undoableEvent?.id === h.id && !props.periodClosed
}

const undoing = ref(null)
function confirmUndo(h) {
  if (! confirm(t('financialInstitutions.treasury.lcIssuanceForm.undoThisEventAnyJournalEntry', { event: eventTypeLabel(h.event_type) }))) return
  undoing.value = h.id
  router.patch(route(undoRouteFor[h.event_type], props.issuance.id), {}, {
    onFinish: () => { undoing.value = null },
  })
}

function eventTypeLabel(type) {
  return { mark_paid: t('financialInstitutions.treasury.lcIssuanceForm.markAsPaid'), settlement: t('financialInstitutions.treasury.lcIssuanceForm.settlement'), cash_cover_release: t('financialInstitutions.treasury.lcIssuanceForm.cashCoverRelease') }[type] ?? type
}

function historyDetails(h) {
  if (h.event_type === 'mark_paid') return `${t('financialInstitutions.treasury.lcIssuanceForm.paid')} ${fmt(h.amount_paid_to_supplier)}` + (h.bank_debt_opened > 0 ? ` — ${t('financialInstitutions.treasury.lcIssuanceForm.bankDebtOpened')}: ${fmt(h.bank_debt_opened)}` : '')
  if (h.event_type === 'settlement') return `${t('financialInstitutions.treasury.lcIssuanceForm.principal')} ${fmt(h.principal_amount)}` + (h.interest_amount > 0 ? ` + ${t('financialInstitutions.treasury.lcIssuanceForm.interest')} ${fmt(h.interest_amount)}` : '') + ` → ${fmt(h.bank_debt_after_event)} ${t('financialInstitutions.treasury.lcIssuanceForm.remaining')}`
  if (h.event_type === 'cash_cover_release') return `${t('financialInstitutions.treasury.lcIssuanceForm.cashCoverReleased')} ${fmt(h.cash_cover_released_amount)}`
  return h.notes ?? '—'
}

function movementStatusLabel(status) {
  return { posted: t('financialInstitutions.treasury.lcIssuanceForm.posted'), reversed: t('financialInstitutions.treasury.lcIssuanceForm.reversed'), memo_only: t('financialInstitutions.treasury.lcIssuanceForm.memoNoGLEntry') }[status] ?? status
}

// ── Expenses (independent CRUD) ──────────────────────────────────

const expenseForm = ref({ show: false, editingId: null, processing: false })

function openAddExpense() {
  expenseErrors.value = []
  expenseForm.value = {
    show: true, editingId: null, processing: false,
    expense_name: '', expense_date: todayLocal(),
    amount: '', currency_id: form.currency_id, expense_account_id: '', paid_from_account_id: '',
  }
}

function editExpense(e) {
  expenseErrors.value = []
  expenseForm.value = {
    show: true, editingId: e.id, processing: false,
    expense_name: e.expense_name, expense_date: e.expense_date,
    amount: e.amount, currency_id: e.currency_id,
    expense_account_id: e.expense_account_id, paid_from_account_id: e.paid_from_account_id,
  }
}

function submitExpense() {
  expenseForm.value.processing = true
  const payload = { ...expenseForm.value }
  delete payload.show; delete payload.editingId; delete payload.processing

  if (expenseForm.value.editingId) {
    router.put(route('fi.lc-issuance.expenses.update', expenseForm.value.editingId), payload, expenseOptions())
  } else {
    router.post(route('fi.lc-issuance.expenses.store', props.issuance.id), payload, expenseOptions())
  }
}

// The expense panel closes only when the server accepted the expense. If it refuses (missing
// field, closed period, ...) the panel stays open with what was typed and shows the reason.
const expenseErrors = ref([])
function expenseOptions() {
  return {
    preserveScroll: true,
    onStart: () => { expenseErrors.value = [] },
    onSuccess: (page) => {
      const refusal = page.props.flash?.error
      if (refusal) { expenseErrors.value = [refusal]; return }
      expenseForm.value.show = false
    },
    onError: (errors) => { expenseErrors.value = Object.values(errors) },
    onFinish: () => { expenseForm.value.processing = false },
  }
}

function deleteExpense(e) {
  if (! confirm(t('financialInstitutions.treasury.lcIssuanceForm.deleteThisExpenseItsJournalEntry'))) return
  router.delete(route('fi.lc-issuance.expenses.destroy', e.id), { preserveScroll: true })
}
</script>

<style>
.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-grid-4 { grid-template-columns: repeat(4, 1fr); }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field-group-span-2 { grid-column: span 2; }
.field-hint { font-size: 11px; color: var(--zv-text-muted); margin-top: 2px; }

.lgi-summary-panel {
  min-width: 240px; background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); padding: 14px 18px; box-shadow: var(--zv-shadow-sm);
}
.lgi-summary-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.lgi-summary-value { font-size: 18px; font-weight: 800; color: #0F2554; font-variant-numeric: tabular-nums; }
[data-theme="dark"] .lgi-summary-value { color: #90a4c4; }
.lgi-summary-sub { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 4px; }

.lgi-room-highlight { color: #10b981 !important; font-weight: 700 !important; }

.lgi-posting-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.lgi-posting-option {
  text-align: start; padding: 14px 16px; border: 1.5px solid var(--zv-border); border-radius: var(--zv-radius-lg);
  background: var(--zv-bg-surface); cursor: pointer; transition: all 0.15s;
}
.lgi-posting-option:hover { border-color: #1490A8; }
.lgi-posting-active { border-color: #FFBF00; background: rgba(255,191,0,0.06); }
.lgi-posting-title { font-size: 14px; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 4px; }
.lgi-posting-sub { font-size: 12px; color: var(--zv-text-muted); line-height: 1.4; }

.zv-readonly-field {
  padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
  background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 600; min-height: 38px;
}

.lgi-history-undone { opacity: 0.5; }
.lgi-undone-tag {
  display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: var(--zv-bg-surface-alt); color: var(--zv-text-muted);
}
</style>
