<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftIndex.vue

  Listing page for Fully Secured Overdraft (223006) facility
  contracts. Same shape as CleanOverdraftIndex.vue — portfolio mode
  and bank-scoped mode sharing one page, KPI row, filter bar,
  Renew/History/Delete modals — with two differences:
    - The table shows the linked TD account instead of a plain
      "Limit" column typed by the user (since here the limit is
      always computed).
    - The Renew modal edits fsod_td_amount / fsod_td_lending_pct
      instead of a directly-typed facility_limit — the limit itself
      is shown read-only, recomputed live as those two fields change.

  @layout    AppLayout
  @route     fi.facilities.fully-secured-od.index (portfolio)
             fi.banks.fully-secured-od.index (bank-scoped)
  @props     facilities (paginator), stats, filters,
             banks (portfolio mode), bank (bank-scoped mode)
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page fsod-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <PageHeader
        :breadcrumbs="breadcrumbs"
        :title="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.fullySecuredOverdraftFacilities')"
      >
        <template #subtitle>
          <template v-if="isScoped">
            {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.everyFullySecuredOverdraftContractWith') }}
            <span class="fsod-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank.name_en }}
              <span v-if="bank.name_ar" class="fsod-bank-chip-ar">{{ bank.name_ar }}</span>
            </span>
          </template>
          <template v-else>
            {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.everyOverdraftLineSecuredByAPledged') }}
          </template>
        </template>

        <template #actions>
          <Link
            v-if="isScoped"
            :href="route('fi.facilities.fully-secured-od.create', bank.id)"
            class="zv-btn zv-btn-primary zv-btn-lg"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.newFacility') }}
          </Link>

          <div v-else class="fsod-new-wrap" ref="newWrapRef">
            <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="toggleBankPicker">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.newFacility') }}
            </button>

            <Transition name="fsod-drop">
              <div v-if="showBankPicker" class="zv-dropdown fsod-bank-picker">
                <div class="fsod-bank-picker-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.chooseABankToBegin') }}</div>
                <Link
                  v-for="b in banks"
                  :key="b.id"
                  :href="route('fi.facilities.fully-secured-od.create', b.id)"
                  class="zv-dropdown-item"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 opacity-50 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                  {{ b.name_en }}
                </Link>
                <div v-if="!banks.length" class="fsod-bank-picker-empty">
                  {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.youHavenTAddedABankYet') }}
                  <Link :href="route('fi.banks.create')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.addOneFirst') }}</Link>
                </div>
              </div>
            </Transition>
          </div>
        </template>
      </PageHeader>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW
      ══════════════════════════════════════════════════════ -->
      <div class="fsod-kpi-grid">

        <KpiCard :label="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.facilities')" :value="stats.total_count">
          <template #sub>
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.noFacilitiesYet') }}</template>
            <template v-else>{{ stats.active_count }} {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.active') }}</template>
          </template>
        </KpiCard>

        <KpiCard :label="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.totalLimit')">
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value fsod-kpi-dash">—</div>
          <div v-else class="fsod-currency-stack" :class="{ 'fsod-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'limit-' + c.currency_code" class="fsod-currency-line">
              <span class="fsod-currency-code">{{ c.currency_code }}</span>
              <span class="fsod-currency-value zv-num">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </KpiCard>

        <KpiCard :label="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.totalDrawn')">
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value fsod-kpi-dash">—</div>
          <div v-else class="fsod-currency-stack" :class="{ 'fsod-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'drawn-' + c.currency_code" class="fsod-currency-line">
              <span class="fsod-currency-code">{{ c.currency_code }}</span>
              <span class="fsod-currency-value zv-num-amber">{{ fmt(c.total_outstanding) }}</span>
            </div>
          </div>
        </KpiCard>

        <div class="zv-kpi-card fsod-ring-card">
          <div class="fsod-ring-card-text">
            <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.avgUtilization') }}</div>
            <div class="zv-kpi-sub" v-if="stats.near_limit_count > 0">
              <span class="zv-kpi-delta zv-kpi-delta-warn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                {{ stats.near_limit_count }} {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.above85') }}
              </span>
            </div>
            <div class="zv-kpi-sub" v-else>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unweightedPerFacility') }}</div>
          </div>

          <div class="fsod-ring" :class="ringZoneClass">
            <svg viewBox="0 0 64 64" class="fsod-ring-svg">
              <circle cx="32" cy="32" r="26" class="fsod-ring-track" />
              <circle cx="32" cy="32" r="26" class="fsod-ring-fill" :stroke-dasharray="ringCircumference" :stroke-dashoffset="ringOffset" />
            </svg>
            <div class="fsod-ring-label">{{ stats.avg_utilization }}<span class="fsod-ring-pct">%</span></div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD — FILTERS + TABLE + PAGINATION
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card fsod-main-card">

        <FilterBar
          :has-active-filters="hasActiveFilters"
          :results-count="facilities.total"
          results-label="facility"
          results-label-plural="facilities"
          @clear="clearFilters"
        >
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select fsod-filter-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>

          <select v-model="statusFilter" class="zv-select fsod-filter-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="expired">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.expired') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
            <option value="cancelled">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.cancelled') }}</option>
          </select>
        </FilterBar>

        <EmptyState
          v-if="facilities.data.length === 0"
          :title="emptyTitle"
          :message="emptyMessage"
          icon-path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        >
          <template v-if="hasActiveFilters" #action>
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
              {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.clearFilters') }}
            </button>
          </template>
        </EmptyState>

        <div v-else class="zv-table-wrap fsod-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.accountNo') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.pledgedTD') }}</th>
                <th class="fsod-th-num">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.computedLimit') }}</th>
                <th class="fsod-th-util">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.drawnUtilization') }}</th>
                <th>{{ t('common.rate') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewal') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="fsod-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">

                <td v-if="!isScoped">
                  <div class="fsod-bank-cell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="w-4 h-4 opacity-45 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                    </svg>
                    <span>{{ f.bank?.name_en }}</span>
                  </div>
                </td>

                <td class="font-mono fsod-account-no">{{ f.account_number }}</td>

                <td>
                  <div class="fsod-td-cell">
                    <span class="font-mono">{{ f.fsod_td_account?.account_number ?? '—' }}</span>
                    <span class="fsod-td-pct">{{ Number(f.fsod_td_lending_pct || 0).toFixed(0) }}%</span>
                  </div>
                </td>

                <td class="zv-table-num">{{ f.currency?.code }} {{ fmt(f.facility_limit) }}</td>

                <td class="fsod-util-cell">
                  <div class="fsod-util-numbers">
                    <span class="zv-table-num">{{ fmt(f.outstanding_balance) }}</span>
                    <span class="fsod-util-pct" :class="'fsod-text-' + utilZone(f)">{{ utilPct(f) }}%</span>
                  </div>
                  <div class="od-util-track">
                    <div class="od-util-fill" :class="utilZone(f)" :style="{ width: Math.min(100, utilPct(f)) + '%' }"></div>
                  </div>
                </td>

                <td>
                  <span class="zv-num">{{ Number(f.interest_rate).toFixed(2) }}%</span>
                  <span v-if="f.follows_corridor_rate" class="fsod-corridor-tag">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.cBE') }}</span>
                </td>

                <td>
                  <span class="fsod-renewal" :class="reviewClass(f)">
                    <span class="zv-status-dot" :class="reviewDotClass(f)"></span>
                    {{ fmtDate(f.review_date) }}
                  </span>
                  <button
                    v-if="hasRenewals(f)"
                    type="button"
                    class="fsod-renewal-badge"
                    @click="openHistory(f)"
                    :title="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.viewRenewalHistory')"
                  >{{ f.terms_histories.length - 1 }} {{ f.terms_histories.length - 1 === 1 ? t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewal2') : t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewals') }}</button>
                </td>

                <td>
                  <StatusBadge :status="f.status" />
                </td>

                <td class="fsod-th-actions">
                  <div class="fsod-row-actions">
                    <Link :href="route('fi.facilities.settlements.index', f.id)" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('financialInstitutions.banks.facilities.facilitySettlements.settlements')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </Link>
                    <Link :href="route('fi.facilities.fully-secured-od.edit', f.id)" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('common.edit')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </Link>

                    <button type="button" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewFacility')" @click="openRenew(f)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/>
                      </svg>
                    </button>

                    <button
                      v-if="!hasRenewals(f)"
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.deleteFacility') : t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.cannotDeleteThisFacilityAlreadyHas')"
                      :disabled="!canDeleteFacility(f)"
                      @click="canDeleteFacility(f) && confirmDelete(f)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m2 0v13a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12z"/>
                      </svg>
                    </button>
                    <button
                      v-else
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.deleteLatestRenewal') : t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.cannotDeleteThisRenewalAlreadyHas')"
                      :disabled="!canDeleteFacility(f)"
                      @click="canDeleteFacility(f) && confirmDeleteRenewal(f)"
                    >
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

        <Pagination :paginator="facilities" />
      </div>

      <!-- ══════════════════════════════════════════════════════
           RENEW MODAL — Facility Renewal
      ══════════════════════════════════════════════════════ -->
      <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-3xl">
          <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewFacility') }} — {{ renewTarget.account_number }}</h2>
          <p class="text-xs zv-text-muted mb-4">
            {{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.anythingLeftBlankKeepsItsCurrent') }}
          </p>

          <div class="fsod-renew-current mb-4">
            <span class="fsod-renew-current-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.currentReviewDate') }}:</span>
            <strong>{{ fmtDate(currentReviewDate) }}</strong>
          </div>

          <div class="fsod-renew-grid">
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.effectiveDate') }} *</label>
              <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
              <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.newReviewDate') }} *</label>
              <input type="date" v-model="renewForm.review_date" class="zv-input w-full" />
              <p v-if="renewErrors.review_date" class="zv-field-error">{{ renewErrors.review_date }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.tDAmount') }}</label>
              <input type="number" step="0.01" v-model="renewForm.fsod_td_amount" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
              <p v-if="renewErrors.fsod_td_amount" class="zv-field-error">{{ renewErrors.fsod_td_amount }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.lendingPercentage') }}</label>
              <input type="number" step="0.01" v-model="renewForm.fsod_td_lending_pct" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
              <p v-if="renewErrors.fsod_td_lending_pct" class="zv-field-error">{{ renewErrors.fsod_td_lending_pct }}</p>
            </div>
            <div class="fsod-renew-span-2">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.newComputedLimit') }}</label>
              <div class="zv-readonly-field fsod-renew-computed">{{ renewComputedLimitLabel }}</div>
            </div>

            <div v-if="!renewTarget.follows_corridor_rate">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.borrowingRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.borrowing_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
            </div>
            <div v-else class="fsod-renew-locked">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.borrowingRate') }}</label>
              <div class="zv-readonly-field">
                {{ Number(renewTarget.borrowing_rate).toFixed(2) }}%
                <span class="fsod-corridor-tag">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.cBE') }}</span>
              </div>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.bankMarginRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.bank_margin_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
            </div>

            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.highestDebitBalanceRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.hdb_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.adminFeesRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.admin_fee_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.settlementDays') }}</label>
              <input type="number" v-model="renewForm.settled_max_days" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.unchanged')" />
            </div>

            <div class="fsod-renew-span-2">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.notes') }}</label>
              <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.eGBankRenewalLetterReference')"></textarea>
            </div>
          </div>

          <div class="flex justify-end gap-2 mt-5">
            <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.recordRenewal') }}</button>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           HISTORY MODAL — read-only chapter timeline
      ══════════════════════════════════════════════════════ -->
      <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-6xl">
          <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewalHistory') }} — {{ historyTarget.account_number }}</h2>
          <div class="zv-table-wrap">
            <table class="zv-table fsod-history-table">
              <thead>
                <tr>
                  <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.effectiveDate') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.reviewDate') }}</th>
                  <th class="fsod-th-num">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.tDAmount') }}</th>
                  <th class="fsod-th-num">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.lendingPct') }}</th>
                  <th class="fsod-th-num">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.limit') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.borrowing') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.margin') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.chapter') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="term in historyTarget.terms_histories" :key="term.id">
                  <td>{{ fmtDate(term.effective_date) }}</td>
                  <td>{{ fmtDate(term.review_date) }}</td>
                  <td class="zv-table-num">{{ fmt(term.fsod_td_amount) }}</td>
                  <td class="zv-table-num">{{ Number(term.fsod_td_lending_pct || 0).toFixed(2) }}%</td>
                  <td class="zv-table-num">{{ fmt(term.facility_limit) }}</td>
                  <td>{{ Number(term.borrowing_rate).toFixed(2) }}%</td>
                  <td>{{ Number(term.bank_margin_rate).toFixed(2) }}%</td>
                  <td class="zv-text-muted">{{ term.is_original ? t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.original') : t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.renewal') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex justify-end mt-4">
            <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.close') }}</button>
          </div>
        </div>
      </div>

      <!-- Delete Renewal confirmation -->
      <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
          <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.deleteThisRenewal') }}</h2>
          <p class="text-xs zv-text-muted mb-4">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.theFacilityWillRevertToIts') }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.close') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.confirmDelete') }}</button>
          </div>
        </div>
      </div>

      <!-- Delete Facility confirmation -->
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
          <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.deleteThisFacility') }}</h2>
          <div class="flex justify-end gap-2">
            <button type="button" class="zv-btn zv-btn-secondary" @click="deleteTarget = null">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.close') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" @click="destroyFacility">{{ t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.confirmDelete') }}</button>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  facilities: { type: Object, required: true },
  stats: {
    type: Object,
    default: () => ({
      total_count: 0,
      active_count: 0,
      by_currency: [],
      avg_utilization: '0.00',
      near_limit_count: 0,
    }),
  },
  banks:   { type: Array,  default: () => [] },
  bank:    { type: Object, default: null },
  filters: { type: Object, default: () => ({}) },
})

const isScoped = computed(() => !!props.bank)

const breadcrumbs = computed(() => {
  const crumbs = [{ label: t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.banks'), href: route('fi.banks.index') }]
  if (isScoped.value) crumbs.push({ label: props.bank.name_en })
  crumbs.push({ label: t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.fullySecuredOverdraft') })
  return crumbs
})

const emptyTitle = computed(() => {
  if (hasActiveFilters.value) return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.noFacilitiesMatchTheseFilters')
  if (isScoped.value) return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.noFullySecuredOverdraftFacilitiesFor')
  return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.noFullySecuredOverdraftFacilitiesYet')
})

const emptyMessage = computed(() => {
  if (hasActiveFilters.value && isScoped.value) return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.tryADifferentStatusOrClear')
  if (hasActiveFilters.value) return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.tryADifferentBankOrStatus')
  if (isScoped.value) return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.useTheButtonAboveToSet')
  return t('financialInstitutions.banks.facilities.fullySecuredOverdraftIndex.pickABankAboveToSet')
})

// ── Filters ───────────────────────────────────────────────────────

const bankFilter   = ref(props.filters?.bank_id ?? '')
const statusFilter = ref(props.filters?.status ?? '')

const hasActiveFilters = computed(() =>
  isScoped.value ? !!statusFilter.value : (!!bankFilter.value || !!statusFilter.value)
)

function applyFilters() {
  const params = isScoped.value
    ? { status: statusFilter.value || undefined }
    : { bank_id: bankFilter.value || undefined, status: statusFilter.value || undefined }

  const url = isScoped.value
    ? route('fi.banks.fully-secured-od.index', props.bank.id)
    : route('fi.facilities.fully-secured-od.index')

  router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  if (!isScoped.value) bankFilter.value = ''
  statusFilter.value = ''
  applyFilters()
}

// ── "New Facility" bank picker ───────────────────────────────────

const showBankPicker = ref(false)
const newWrapRef = ref(null)

function toggleBankPicker() { showBankPicker.value = !showBankPicker.value }

function handleOutsideClick(e) {
  if (newWrapRef.value && !newWrapRef.value.contains(e.target)) showBankPicker.value = false
}

onMounted(() => document.addEventListener('click', handleOutsideClick))
onUnmounted(() => document.removeEventListener('click', handleOutsideClick))

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── Per-row utilization ──────────────────────────────────────────

function utilPct(f) {
  const limit = parseFloat(f.facility_limit || 0)
  const drawn = parseFloat(f.outstanding_balance || 0)
  if (!limit) return 0
  return Math.round((drawn / limit) * 100)
}

function utilZone(f) {
  const p = utilPct(f)
  if (p >= 85) return 'od-danger'
  if (p >= 60) return 'od-warn'
  return 'od-ok'
}

// ── Renewal urgency ──────────────────────────────────────────────

function daysUntil(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}

function reviewClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return 'zv-text-muted'
  if (d < 0) return 'zv-text-danger'
  if (d <= 30) return 'zv-text-amber'
  return 'zv-text-secondary'
}

function reviewDotClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return 'zv-status-dot-gray'
  if (d < 0) return 'zv-status-dot-danger'
  if (d <= 30) return 'zv-status-dot-amber'
  return 'zv-status-dot-green'
}

// ── Facility Renewal ──────────────────────────────────────────────

function hasRenewals(f) {
  return (f.terms_histories?.length || 0) > 1
}

function canDeleteFacility(f) {
  return parseFloat(f.outstanding_balance || 0) <= 0
    && (f.withdrawals_count || 0) === 0
    && (f.posted_liability_charges_count || 0) === 0
}

// ── Renew modal ──────────────────────────────────────────────────

const renewTarget = ref(null)
const renewForm = ref(blankRenewForm())
const renewErrors = ref({})

function blankRenewForm() {
  return {
    effective_date: '',
    review_date: '',
    fsod_td_amount: '',
    fsod_td_lending_pct: '',
    borrowing_rate: '',
    bank_margin_rate: '',
    hdb_rate: '',
    admin_fee_rate: '',
    settled_max_days: '',
    notes: '',
  }
}

function openRenew(f) {
  renewTarget.value = f
  renewErrors.value = {}
  renewForm.value = blankRenewForm()
}
function cancelRenew() { renewTarget.value = null }

const currentReviewDate = computed(() => {
  if (!renewTarget.value) return null
  const history = renewTarget.value.terms_histories || []
  const latest = history[history.length - 1]
  return latest?.review_date || renewTarget.value.review_date
})

// Live preview of what the new chapter's limit will be — mirrors
// FullySecuredOverdraftService's computeLimit()/CreditFacility::
// renew()'s recompute. Falls back to the current chapter's own
// amount/pct for any field left blank ("unchanged").
const renewComputedLimitLabel = computed(() => {
  if (!renewTarget.value) return '—'
  const latest = renewTarget.value.terms_histories?.[renewTarget.value.terms_histories.length - 1]
  const amount = parseFloat(renewForm.value.fsod_td_amount || latest?.fsod_td_amount || renewTarget.value.fsod_td_amount || 0)
  const pct    = parseFloat(renewForm.value.fsod_td_lending_pct || latest?.fsod_td_lending_pct || renewTarget.value.fsod_td_lending_pct || 0)
  const limit  = (amount * pct) / 100
  return (renewTarget.value.currency?.code ?? '') + ' ' + fmt(limit)
})

function submitRenew() {
  router.post(route('fi.facilities.fully-secured-od.renew', renewTarget.value.id), renewForm.value, {
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
  router.delete(route('fi.facilities.fully-secured-od.delete-renewal', deleteRenewalTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteRenewalTarget.value = null },
  })
}

// ── Delete facility ────────────────────────────────────────────────

const deleteTarget = ref(null)
function confirmDelete(f) { deleteTarget.value = f }
function destroyFacility() {
  router.delete(route('fi.facilities.fully-secured-od.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteTarget.value = null },
  })
}

// ── Portfolio utilization ring ────────────────────────────────────

const RING_RADIUS = 26
const ringCircumference = 2 * Math.PI * RING_RADIUS

const ringOffset = computed(() => {
  const pct = Math.min(100, Math.max(0, parseFloat(props.stats.avg_utilization || 0)))
  return ringCircumference * (1 - pct / 100)
})

const ringZoneClass = computed(() => {
  const pct = parseFloat(props.stats.avg_utilization || 0)
  if (pct >= 85) return 'od-danger'
  if (pct >= 60) return 'od-warn'
  return 'od-ok'
})
</script>

<style>
/* ── Bank chip ────────────────────────────────────────────────────── */
.fsod-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 2px 9px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
  margin-inline-start: 4px; vertical-align: middle;
}
[data-theme="dark"] .fsod-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.fsod-bank-chip-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Bank picker dropdown ─────────────────────────────────────────── */
.fsod-new-wrap { position: relative; }
.fsod-bank-picker {
  position: absolute; top: calc(100% + 8px); inset-inline-end: 0;
  min-width: 260px; max-height: 320px; overflow-y: auto;
}
[dir="rtl"] .fsod-bank-picker { inset-inline-start: 0; inset-inline-end: auto; }
.fsod-bank-picker-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--zv-text-muted); padding: 0.4rem 0.6rem 0.3rem;
}
.fsod-bank-picker-empty { padding: 0.6rem 0.6rem 0.3rem; font-size: 12.5px; color: var(--zv-text-muted); line-height: 1.5; }
.fsod-drop-enter-active, .fsod-drop-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.fsod-drop-enter-from, .fsod-drop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }

/* ── KPI grid ─────────────────────────────────────────────────────── */
.fsod-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
.fsod-kpi-dash { color: var(--zv-text-muted); }
.fsod-currency-stack { display: flex; flex-direction: column; gap: 0.3rem; margin-top: 0.1rem; }
.fsod-currency-line { display: flex; align-items: baseline; gap: 0.4rem; }
.fsod-currency-code {
  font-size: 0.6875rem; font-weight: 700; color: var(--zv-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em; min-width: 28px;
}
.fsod-currency-value { font-size: 1.625rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; }
.fsod-currency-stack-multi .fsod-currency-value { font-size: 1.125rem; }

/* ── Utilization ring ─────────────────────────────────────────────── */
.fsod-ring-card { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.fsod-ring-card-text { flex: 1; min-width: 0; }
.fsod-ring { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.fsod-ring-svg { width: 64px; height: 64px; transform: rotate(-90deg); }
.fsod-ring-track { fill: none; stroke: var(--zv-border); stroke-width: 6; }
.fsod-ring-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.5s ease; }
.fsod-ring.od-ok     .fsod-ring-fill { stroke: var(--zv-green); }
.fsod-ring.od-warn   .fsod-ring-fill { stroke: var(--zv-amber); }
.fsod-ring.od-danger .fsod-ring-fill { stroke: var(--zv-danger); }
.fsod-ring-label {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  font-size: 0.9375rem; font-weight: 800; color: var(--zv-text-primary); font-variant-numeric: tabular-nums;
}
.fsod-ring-pct { font-size: 0.625rem; font-weight: 700; margin-inline-start: 1px; opacity: 0.65; }

/* ── Main card ────────────────────────────────────────────────────── */
.fsod-main-card { overflow: hidden; }
.fsod-filter-select { min-width: 160px; width: auto; }
.fsod-table-wrap { border: none; border-radius: 0; box-shadow: none; }

/* ── Table cells ──────────────────────────────────────────────────── */
.fsod-th-num   { text-align: end; }
.fsod-th-util  { min-width: 180px; }
.fsod-th-actions { width: 1%; }
.fsod-bank-cell { display: flex; align-items: center; gap: 0.5rem; font-weight: 500; }
.fsod-account-no { color: var(--zv-text-secondary); font-size: 0.78125rem; }

.fsod-td-cell { display: flex; flex-direction: column; gap: 2px; font-size: 0.78125rem; }
.fsod-td-pct { font-size: 0.6875rem; color: var(--zv-text-muted); font-weight: 600; }

.fsod-util-cell { min-width: 170px; }
.fsod-util-numbers { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.3rem; }
.fsod-util-pct { font-size: 0.75rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.fsod-text-od-ok     { color: var(--zv-num-green); }
.fsod-text-od-warn   { color: var(--zv-num-amber); }
.fsod-text-od-danger { color: var(--zv-num-danger); }

.od-util-track { height: 5px; background: var(--zv-border); border-radius: 4px; overflow: hidden; }
.od-util-fill  { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
.od-util-fill.od-ok     { background: var(--zv-green); }
.od-util-fill.od-warn   { background: var(--zv-amber); }
.od-util-fill.od-danger { background: var(--zv-danger); }

.fsod-corridor-tag {
  display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 9px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  margin-inline-start: 5px;
}

.fsod-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8125rem; font-weight: 500; }
.fsod-renewal-badge {
  display: block; margin-top: 3px; padding: 0; border: none; background: none;
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
  color: #1490A8; cursor: pointer;
}
.fsod-renewal-badge:hover { text-decoration: underline; }

.fsod-row-actions { display: flex; align-items: center; gap: 2px; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

.fsod-renew-current {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
  background: var(--zv-bg-hover); font-size: 0.8125rem;
}
.fsod-renew-current-label { color: var(--zv-text-muted); }

.fsod-renew-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.9rem 1rem; }
.fsod-renew-span-2 { grid-column: span 3; }
@media (max-width: 640px) {
  .fsod-renew-grid { grid-template-columns: 1fr 1fr; }
  .fsod-renew-span-2 { grid-column: span 2; }
}
.fsod-renew-locked .zv-readonly-field { display: flex; align-items: center; gap: 6px; }
.fsod-renew-computed { font-weight: 700; color: #10b981; }

.fsod-history-table th, .fsod-history-table td { white-space: nowrap; }

/* ── Responsive ───────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .fsod-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
  .fsod-kpi-grid { grid-template-columns: 1fr; }
  .zv-page-header { flex-direction: column; align-items: stretch; }
  .fsod-new-wrap .zv-btn { width: 100%; }
}
</style>
