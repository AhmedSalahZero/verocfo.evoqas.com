<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/LetterOfCreditIndex.vue

  Listing page for Letter of Credit facility AGREEMENTS — same
  dual-mode + KPI-card pattern as LetterOfGuaranteeIndex.vue.

  @layout    AppLayout
  @route     fi.facilities.lc.index (portfolio) / fi.banks.lc.index (bank-scoped)
  @props     facilities (paginator), stats, filters, banks, bank
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.banks') }}</Link>
            <template v-if="isScoped"><span class="zv-breadcrumb-sep">/</span><span>{{ bank.name_en }}</span></template>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.letterOfCredit') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.letterOfCreditFacilities') }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.importLCAgreementsRateCardsBy') }}</p>
        </div>

        <Link v-if="isScoped" :href="route('fi.facilities.lc.create', bank.id)" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newFacility') }}
        </Link>
        <div v-else class="lc-new-wrap" ref="newWrapRef">
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="showBankPicker = !showBankPicker">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newFacility') }}
          </button>
          <Transition name="lc-drop">
            <div v-if="showBankPicker" class="zv-dropdown">
              <div class="lc-bank-picker-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.chooseABankToBegin') }}</div>
              <Link v-for="b in banks" :key="b.id" :href="route('fi.facilities.lc.create', b.id)" class="zv-dropdown-item">{{ b.name_en }}</Link>
            </div>
          </Transition>
        </div>
      </div>

      <div class="lc-kpi-grid">
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.facilities') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">{{ stats.total_count === 0 ? t('financialInstitutions.banks.facilities.letterOfCreditIndex.noFacilitiesYet') : stats.active_count + ' ' + t('financialInstitutions.banks.facilities.letterOfCreditIndex.active') }}</div>
        </div>
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.totalLimit') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value lc-kpi-dash">—</div>
          <div v-else class="lc-currency-stack" :class="{ 'lc-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="c.currency_code" class="lc-currency-line">
              <span class="lc-currency-code">{{ c.currency_code }}</span>
              <span class="lc-currency-value">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </div>
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.fullySecured') }}</div>
          <div class="zv-kpi-value">{{ stats.fully_secured_count }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.backedByPledgedTD') }}</div>
        </div>
        <div class="zv-kpi-card" :class="{ 'lc-kpi-warn-card': stats.near_review_count > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.nearRenewal') }}</div>
          <div class="zv-kpi-value" :class="{ 'lc-kpi-warn-value': stats.near_review_count > 0 }">{{ stats.near_review_count }}</div>
          <div class="zv-kpi-sub">{{ stats.near_review_count > 0 ? t('financialInstitutions.banks.facilities.letterOfCreditIndex.within30Days') : t('financialInstitutions.banks.facilities.letterOfCreditIndex.noneDueSoon') }}</div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-filter-bar">
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>
          <select v-model="statusFilter" class="zv-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
          </select>
          <div class="lc-filter-count">{{ facilities.total }} {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.facilities2') }}</div>
        </div>

        <div v-if="facilities.data.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.noLetterOfCreditFacilitiesYet') }}</div>
        </div>

        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.bank') }}</th>
                <th>{{ t('common.name') }}</th>
                <th>{{ t('common.type') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.currency') }}</th>
                <th class="lc-th-num">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.limit') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.lCTypes') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewal') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="lc-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">
                <td v-if="!isScoped">{{ f.bank?.name_en }}</td>
                <td class="lc-name-cell">{{ f.name || '—' }}</td>
                <td><span class="lc-type-badge" :class="f.lc_security_type === 'fully_secured' ? 'lc-type-secured' : 'lc-type-unsecured'">{{ t(f.lc_security_type === 'fully_secured' ? 'Fully Secured' : 'Unsecured') }}</span></td>
                <td>{{ f.currency?.code }}</td>
                <td class="zv-table-num">{{ fmt(f.facility_limit) }}</td>
                <td><span class="lc-types-badge">{{ f.lc_types?.length ?? 0 }}</span></td>
                <td>
                  <span class="lc-renewal">{{ fmtDate(f.review_date) }}</span>
                  <button v-if="hasRenewals(f)" type="button" class="lc-renewal-badge" @click="openHistory(f)"
                          :title="t('financialInstitutions.banks.facilities.letterOfCreditIndex.viewRenewalHistory')">
                    {{ f.terms_histories.length - 1 }} {{ f.terms_histories.length - 1 === 1 ? t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewal2') : t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewals') }}
                  </button>
                </td>
                <td><span class="zv-badge" :class="f.status === 'active' ? 'zv-badge-success' : 'zv-badge-muted'">{{ t(f.status) }}</span></td>
                <td class="lc-th-actions">
                  <div class="lc-row-actions">
                    <Link :href="route('fi.facilities.lc.edit', f.id)" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('common.edit')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </Link>
                    <button type="button" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewFacility')" @click="openRenew(f)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/></svg>
                    </button>
                    <button v-if="hasRenewals(f)" type="button" class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                            :title="t('financialInstitutions.banks.facilities.letterOfCreditIndex.deleteLatestRenewal')" @click="confirmDeleteRenewal(f)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 9l6 6m0-6l-6 6" stroke-width="2"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           RENEW MODAL — Facility Renewal
      ══════════════════════════════════════════════════════ -->
      <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
        <div class="zv-modal rounded-lg w-full max-w-4xl lc-renew-modal">

          <div class="p-6 pb-0">
            <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewFacility') }} — {{ renewTarget.name }}</h2>
            <p class="text-xs zv-text-muted mb-4">
              {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.leaveAFieldBlankToKeep') }}
            </p>
            <div class="lc-renew-current mb-4">
              <span class="lc-renew-current-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.currentContractEndDate') }}:</span>
              <strong>{{ fmtDate(currentReviewDate) }}</strong>
            </div>
          </div>

          <div class="lc-renew-scroll px-6">

            <!-- Renewal Effective Date / New Contract End Date -->
            <div class="lc-renew-grid mb-4">
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewalEffectiveDate') }} *</label>
                <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
                <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
              </div>
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newContractEndDate') }} *</label>
                <input type="date" v-model="renewForm.review_date" class="zv-input w-full" />
                <p v-if="renewErrors.review_date" class="zv-field-error">{{ renewErrors.review_date }}</p>
              </div>
            </div>

            <!-- FINANCING TERMS & CONDITIONS -->
            <div class="lc-renew-section-title mb-3">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.financingTermsConditions') }}</div>

            <div class="lc-renew-grid mb-2">
              <!-- Left column: Limit OR CD/TD Lending %, depending on security type -->
              <div v-if="renewTarget.lc_security_type === 'fully_secured'">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newCDTDLendingPercentage') }}</label>
                <input type="number" step="0.01" min="0" max="100" v-model="renewForm.lc_td_lending_pct" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
                <p v-if="renewErrors.lc_td_lending_pct" class="zv-field-error">{{ renewErrors.lc_td_lending_pct }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.thisFacilityIsFullySecured') }}</p>
              </div>
              <div v-else>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newLimit') }}</label>
                <input type="number" step="0.01" min="0" v-model="renewForm.facility_limit" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
                <p v-if="renewErrors.facility_limit" class="zv-field-error">{{ renewErrors.facility_limit }}</p>
              </div>

              <!-- New Borrowing Rate — locked + CBE tag when the facility follows the corridor rate -->
              <div v-if="!renewTarget.follows_corridor_rate">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newBorrowingRate') }}</label>
                <input type="number" step="0.0001" min="0" v-model="renewForm.borrowing_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
              </div>
              <div v-else class="lc-renew-locked">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newBorrowingRate') }}</label>
                <div class="zv-readonly-field">
                  {{ Number(renewTarget.borrowing_rate).toFixed(2) }}%
                  <span class="lc-corridor-tag">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.cBE') }}</span>
                </div>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.drivenByTheCBECorridorRate') }}</p>
              </div>
            </div>

            <div class="lc-renew-grid mb-2">
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newBankMarginRate') }}</label>
                <input type="number" step="0.0001" min="0" v-model="renewForm.bank_margin_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
              </div>
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.interestRateBorrowingMargin') }}</label>
                <div class="zv-readonly-field">{{ renewComputedInterestRate }}%</div>
              </div>
            </div>

            <div class="lc-renew-grid mb-2">
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newMinInterestRate') }}</label>
                <input type="number" step="0.0001" min="0" v-model="renewForm.min_interest_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
              </div>
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newHighestDebtBalanceRate') }}</label>
                <input type="number" step="0.0001" min="0" v-model="renewForm.hdb_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
              </div>
            </div>

            <div class="lc-renew-grid mb-5">
              <div>
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newAdminFeesRate') }}</label>
                <input type="number" step="0.0001" min="0" v-model="renewForm.admin_fee_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.unchanged')" />
              </div>
              <div></div>
            </div>

            <!-- NEW TERM & CONDITIONS — BY LC TYPE -->
            <div class="lc-renew-section-title mb-3">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.newTermConditionsByLCType') }} *</div>

            <div v-if="renewDuplicateTypes.length" class="zv-alert zv-alert-warning mb-3">
              {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.thisLCTypeAppearsMoreThan') }} {{ renewDuplicateTypes.join(', ') }}
            </div>

            <div class="lc-tier-table">
              <div class="lc-tier-header">
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.lCType') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.cashCoverRate') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.commissionRate') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.minCommissionFees') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.issuanceFees') }}</div>
                <div></div>
              </div>

              <div v-for="(row, idx) in renewForm.lc_types" :key="idx" class="lc-tier-row">
                <div class="lc-tier-col">
                  <input v-model="row.lc_type" type="text" class="zv-input" :class="{ 'zv-input-error': renewErrors['lc_types.' + idx + '.lc_type'] }"/>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.cash_cover_pct" type="number" min="0" max="100" step="0.01" class="zv-input zv-input-has-suffix text-end"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.commission_rate_pct" type="number" min="0" max="100" step="0.0001" class="zv-input zv-input-has-suffix text-end"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ renewTarget.currency?.code }}</span>
                    <input v-model="row.min_commission_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end"/>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ renewTarget.currency?.code }}</span>
                    <input v-model="row.issuance_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end"/>
                  </div>
                </div>
                <div class="lc-tier-col lc-tier-col-action">
                  <button v-if="renewForm.lc_types.length > 1" type="button" class="lc-remove-btn" @click="removeRenewTypeRow(idx)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
                </div>
              </div>

              <div class="lc-tier-footer">
                <button type="button" class="lc-add-row-btn" @click="addRenewTypeRow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                  {{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.addLCType') }}
                </button>
              </div>
            </div>
            <p v-if="renewErrors.lc_types" class="zv-field-error mt-2">{{ renewErrors.lc_types }}</p>

            <div class="mt-5 mb-1">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.notes') }}</label>
              <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditIndex.eGBankRenewalLetterReference')"></textarea>
            </div>
          </div>

          <div class="flex justify-end gap-2 p-6 pt-4 lc-renew-footer">
            <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.recordRenewal') }}</button>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           HISTORY MODAL — read-only chapter timeline
      ══════════════════════════════════════════════════════ -->
      <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-3xl">
          <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewalHistory') }} — {{ historyTarget.name }}</h2>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.effectiveDate') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.contractEndDate') }}</th>
                  <th class="lc-th-num">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.limit') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.borrowing') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.margin') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.chapter') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="term in historyTarget.terms_histories" :key="term.id">
                  <td>{{ fmtDate(term.effective_date) }}</td>
                  <td>{{ fmtDate(term.review_date) }}</td>
                  <td class="zv-table-num">{{ fmt(term.facility_limit) }}</td>
                  <td>{{ Number(term.borrowing_rate).toFixed(2) }}%</td>
                  <td>{{ Number(term.bank_margin_rate).toFixed(2) }}%</td>
                  <td class="zv-text-muted">{{ term.is_original ? t('financialInstitutions.banks.facilities.letterOfCreditIndex.original') : t('financialInstitutions.banks.facilities.letterOfCreditIndex.renewal') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex justify-end mt-4">
            <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.close') }}</button>
          </div>
        </div>
      </div>

      <!-- Delete Renewal confirmation -->
      <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
          <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.deleteThisRenewal') }}</h2>
          <p class="text-xs zv-text-muted mb-4">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.theFacilityWillRevertToIts') }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.close') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.banks.facilities.letterOfCreditIndex.confirmDelete') }}</button>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps({
  facilities: { type: Object, required: true },
  stats: { type: Object, default: () => ({ total_count: 0, active_count: 0, by_currency: [], fully_secured_count: 0, near_review_count: 0 }) },
  banks: { type: Array, default: () => [] },
  bank: { type: Object, default: null },
  filters: { type: Object, default: () => ({}) },
})

const isScoped = computed(() => !!props.bank)
const bankFilter = ref(props.filters?.bank_id ?? '')
const statusFilter = ref(props.filters?.status ?? '')
const showBankPicker = ref(false)
const newWrapRef = ref(null)

function applyFilters() {
  const params = isScoped.value ? { status: statusFilter.value || undefined } : { bank_id: bankFilter.value || undefined, status: statusFilter.value || undefined }
  const url = isScoped.value ? route('fi.banks.lc.index', props.bank.id) : route('fi.facilities.lc.index')
  router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })
}

function handleClickAway(e) { if (newWrapRef.value && !newWrapRef.value.contains(e.target)) showBankPicker.value = false }
onMounted(() => document.addEventListener('click', handleClickAway))
onUnmounted(() => document.removeEventListener('click', handleClickAway))

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }

// ── Facility Renewal ──────────────────────────────────────────────

function hasRenewals(f) {
  return (f.terms_histories?.length || 0) > 1
}

function defaultRenewTypeRow() {
  return {
    lc_type:                   '',
    cash_cover_pct:             '',
    commission_rate_pct:        '',
    min_commission_fee_amount:  '',
    issuance_fee_amount:        '',
  }
}

function cloneLcTypes(list) {
  return (list || []).map(row => ({
    lc_type:                   row.lc_type                   ?? '',
    cash_cover_pct:             row.cash_cover_pct             ?? '',
    commission_rate_pct:        row.commission_rate_pct        ?? '',
    min_commission_fee_amount:  row.min_commission_fee_amount  ?? '',
    issuance_fee_amount:        row.issuance_fee_amount        ?? '',
  }))
}

function blankRenewForm(f) {
  return {
    effective_date: '',
    review_date: '',
    facility_limit: '',
    lc_td_amount: '',
    lc_td_lending_pct: '',
    borrowing_rate: '',
    bank_margin_rate: '',
    min_interest_rate: '',
    hdb_rate: '',
    admin_fee_rate: '',
    notes: '',
    // Pre-filled with the facility's CURRENT rate card — a renewal
    // usually keeps most terms the same with a few tweaks, so the
    // user edits in place rather than starting from a blank table.
    lc_types: f ? cloneLcTypes(f.lc_types) : [],
  }
}

const renewTarget = ref(null)
const renewForm = ref(blankRenewForm(null))
const renewErrors = ref({})

function openRenew(f) {
  renewTarget.value = f
  renewErrors.value = {}
  renewForm.value = blankRenewForm(f)
}
function cancelRenew() { renewTarget.value = null }

function addRenewTypeRow() {
  renewForm.value.lc_types.push(defaultRenewTypeRow())
}
function removeRenewTypeRow(idx) {
  renewForm.value.lc_types.splice(idx, 1)
}

const renewDuplicateTypes = computed(() => {
  const seen  = new Set()
  const dupes = new Set()
  ;(renewForm.value.lc_types || []).forEach(row => {
    const type = (row.lc_type || '').trim().toLowerCase()
    if (!type) return
    if (seen.has(type)) dupes.add(row.lc_type.trim())
    seen.add(type)
  })
  return [...dupes]
})

const currentReviewDate = computed(() => {
  if (!renewTarget.value) return null
  const history = renewTarget.value.terms_histories || []
  const latest = history[history.length - 1]
  return latest?.review_date || renewTarget.value.review_date
})

// Live preview: (typed new rate, else current rate) for both legs —
// mirrors LetterOfCreditForm.vue's own computedInterestRate, but
// falling back to the CURRENT facility rate when a field is left
// blank (renewal's "blank = unchanged" convention), not to 0.
const renewComputedInterestRate = computed(() => {
  if (!renewTarget.value) return '0.00'
  const borrow = renewForm.value.borrowing_rate !== '' && renewForm.value.borrowing_rate !== null
    ? parseFloat(renewForm.value.borrowing_rate) : parseFloat(renewTarget.value.borrowing_rate || 0)
  const margin = renewForm.value.bank_margin_rate !== '' && renewForm.value.bank_margin_rate !== null
    ? parseFloat(renewForm.value.bank_margin_rate) : parseFloat(renewTarget.value.bank_margin_rate || 0)
  return (borrow + margin).toFixed(2)
})

function submitRenew() {
  router.post(route('fi.facilities.lc.renew', renewTarget.value.id), renewForm.value, {
    preserveScroll: true,
    onError: (errors) => { renewErrors.value = errors },
    onSuccess: () => { renewTarget.value = null },
  })
}

// ── History modal (read-only) ────────────────────────────────────

const historyTarget = ref(null)
function openHistory(f) { historyTarget.value = f }

// ── Delete renewal ────────────────────────────────────────────────

const deleteRenewalTarget = ref(null)
function confirmDeleteRenewal(f) { deleteRenewalTarget.value = f }
function destroyRenewal() {
  router.delete(route('fi.facilities.lc.delete-renewal', deleteRenewalTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteRenewalTarget.value = null },
  })
}
</script>

<style>
.lc-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .lc-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
.zv-kpi-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 16px 18px; box-shadow: var(--zv-shadow-sm); }
.zv-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 8px; }
.zv-kpi-value { font-size: 24px; font-weight: 800; color: var(--zv-text-primary); }
.zv-kpi-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 6px; }
.lc-kpi-dash { color: var(--zv-text-muted); }
.lc-kpi-warn-card { border-color: rgba(245,158,11,0.35); }
.lc-kpi-warn-value { color: #f59e0b; }
.lc-currency-stack { display: flex; flex-direction: column; gap: 4px; }
.lc-currency-line { display: flex; align-items: baseline; gap: 8px; }
.lc-currency-code { font-size: 11px; font-weight: 700; color: var(--zv-text-muted); min-width: 32px; }
.lc-currency-value { font-size: 18px; font-weight: 800; color: var(--zv-text-primary); }
.lc-currency-stack-multi .lc-currency-value { font-size: 15px; }

.zv-dropdown { position: absolute; top: calc(100% + 8px); right: 0; z-index: 30; min-width: 240px; background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-lg); padding: 8px; }
.lc-new-wrap { position: relative; }
.lc-bank-picker-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--zv-text-muted); padding: 6px 10px 8px; }
.zv-dropdown-item { display: block; padding: 8px 10px; border-radius: var(--zv-radius); font-size: 13.5px; color: var(--zv-text-primary); }
.zv-dropdown-item:hover { background: var(--zv-bg-surface-alt); }
.lc-drop-enter-active, .lc-drop-leave-active { transition: all 0.15s ease; }
.lc-drop-enter-from, .lc-drop-leave-to { opacity: 0; transform: translateY(-6px); }

.zv-filter-bar { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); }
.lc-filter-count { margin-inline-start: auto; font-size: 12.5px; color: var(--zv-text-muted); font-weight: 600; }
.lc-th-num { text-align: end; }
.lc-th-actions { width: 56px; }
.zv-table-num { text-align: end; }
.lc-name-cell { font-weight: 600; color: var(--zv-text-primary); }
.lc-type-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.lc-type-unsecured { background: rgba(100,116,139,0.1); color: #64748b; }
.lc-type-secured { background: rgba(16,185,129,0.1); color: #10b981; }
.lc-types-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 22px; padding: 0 8px; border-radius: 11px; background: rgba(20,144,168,0.1); color: #1490A8; font-size: 12px; font-weight: 700; }
.zv-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.zv-badge-success { background: rgba(16,185,129,0.1); color: #10b981; }
.zv-badge-muted { background: rgba(100,116,139,0.1); color: #64748b; }
.zv-empty { padding: 60px 24px; text-align: center; color: var(--zv-text-muted); }

/* ── Renewal badge + row actions ─────────────────────────────────── */
.lc-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.lc-renewal-badge {
  display: inline-flex; align-items: center; margin-inline-start: 6px;
  padding: 2px 8px; border-radius: 10px; border: none; cursor: pointer;
  background: rgba(20,144,168,0.1); color: #1490A8; font-size: 10.5px; font-weight: 700;
}
.lc-renewal-badge:hover { background: rgba(20,144,168,0.18); }
.lc-row-actions { display: flex; align-items: center; gap: 2px; justify-content: flex-end; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

/* ── Facility Renewal modal ───────────────────────────────────────── */
.lc-renew-modal { display: flex; flex-direction: column; max-height: calc(100vh - 48px); }
.lc-renew-scroll { overflow-y: auto; flex: 1 1 auto; }
.lc-renew-footer { flex-shrink: 0; border-top: 1px solid var(--zv-border); }
.lc-renew-current {
  display: flex; align-items: center; gap: 8px; padding: 10px 14px;
  background: var(--zv-bg-surface-alt); border-radius: var(--zv-radius); font-size: 13px;
}
.lc-renew-current-label { color: var(--zv-text-muted); font-weight: 600; }
.lc-renew-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px 16px; }
.lc-renew-section-title { font-size: 13.5px; font-weight: 700; color: var(--zv-text-primary); }
.lc-renew-locked {}
.lc-corridor-tag {
  display: inline-flex; align-items: center; margin-inline-start: 8px;
  padding: 1px 7px; border-radius: 8px; background: rgba(124,58,237,0.12);
  color: #7c3aed; font-size: 10px; font-weight: 700;
}

.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

/* ── LC type rate card table (shared with LetterOfCreditForm.vue) ── */
.lc-tier-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.lc-tier-header {
  display: grid; grid-template-columns: 1.6fr 1fr 1.2fr 1.4fr 1.4fr 40px;
  padding: 9px 14px; gap: 10px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--zv-text-muted);
}
.lc-tier-row {
  display: grid; grid-template-columns: 1.6fr 1fr 1.2fr 1.4fr 1.4fr 40px;
  padding: 10px 14px; gap: 10px; align-items: start;
  border-bottom: 1px solid var(--zv-border);
}
.lc-tier-row:last-of-type { border-bottom: none; }
.lc-tier-row:hover { background: var(--zv-bg-surface-alt); }
.lc-tier-col { display: flex; flex-direction: column; }
.lc-tier-col-action { align-items: center; justify-content: center; padding-top: 2px; }
.lc-remove-btn {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
  color: var(--zv-text-muted); transition: all 0.12s;
}
.lc-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }
.lc-tier-footer { display: flex; padding: 10px 14px; border-top: 1px dashed var(--zv-border); background: var(--zv-bg-surface); }
.lc-add-row-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border: 1px dashed var(--zv-border);
  border-radius: var(--zv-radius); background: transparent;
  color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.lc-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

@media (max-width: 900px) {
  .lc-tier-header, .lc-tier-row { grid-template-columns: 1fr 1fr; }
  .lc-renew-grid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .lc-tier-header { display: none; }
  .lc-tier-row { grid-template-columns: 1fr; }
}
</style>
