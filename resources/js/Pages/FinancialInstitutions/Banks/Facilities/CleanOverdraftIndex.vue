<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/CleanOverdraftIndex.vue

  Listing page for Clean Overdraft (223001) facility contracts.
  Design matches the ZAVERO premium standard — reuses the global
  --zv- token system and component classes (zv-kpi-card, zv-table,
  zv-badge, zv-pagination, zv-filter-bar, zv-empty) rather than
  introducing a new visual language, so this page reads as part of
  the same product as the Form page, not a one-off.

  Two contexts, one page (v1.1):
    - Portfolio mode (no 'bank' prop) — every bank, reached from
      the new "Credit Facilities" menu on the Banks page header.
      Shows the Bank column, the bank filter, and a bank-picker on
      "+ New Facility".
    - Bank-scoped mode ('bank' prop present) — one bank only,
      reached from that bank's own row on the Banks page. Hides
      the Bank column and bank filter (every row is already this
      bank), and "+ New Facility" links straight to create for
      this bank — no picker needed, the bank is already known.
    Both modes call the exact same service methods
    (paginate()/stats()) — a bank-scoped view is just "the same
    list, permanently filtered to one bank." Keeping this as one
    file means a fix here (like the currency bug below) only has
    to happen once, and the same pattern is ready to reuse for the
    other 6 facility types as each gets its own dedicated build.

  Sections:
    1. Header — breadcrumb, title, "+ New Facility"
    2. KPI row — portfolio totals, currency-safe (see note below)
    3. Filter bar — bank (portfolio mode only) / status
    4. Facilities table — one row per OD contract, with a per-row
       utilization bar (same visual language as the edit-mode
       header panel on CleanOverdraftForm.vue)
    5. Pagination

  Currency safety:
    facility_limit / outstanding_balance are NEVER summed across
    currencies in this page. A company can hold OD facilities in
    EGP and USD at once — adding those raw numbers together would
    produce a number with no real meaning. The "Total Limit" and
    "Total Drawn" KPI cards show one line per currency instead.
    Average utilization is safe to average directly, because it's
    already a per-facility, currency-neutral ratio (outstanding ÷
    limit) computed server-side in CleanOverdraftService::stats().

  v1.2 — Migrated onto the new shared components (Tier 2, Item 4):
    PageHeader, KpiCard (Facilities / Total Limit / Total Drawn
    only), FilterBar, EmptyState, StatusBadge, and Pagination.
    No visual or behavioral change — this is a like-for-like swap
    of hand-built markup for the shared version of the same markup.
    The 4th KPI card (utilization ring) is deliberately NOT migrated
    — its side-by-side text+ring layout doesn't fit KpiCard's
    label→value→sub vertical shape, and forcing it in would either
    distort KpiCard for every other page or need a special-case prop
    for one card. Left as its own custom markup, exactly as before.
    STATUS_META/statusMeta() and isPrevLink()/isNextLink() removed
    from this file — that logic now lives once in StatusBadge.vue
    and Pagination.vue respectively.

  @layout    AppLayout
  @route     fi.facilities.clean-od.index (portfolio)
             fi.banks.clean-od.index (bank-scoped)
  @props     facilities (paginator), stats, filters,
             banks (portfolio mode), bank (bank-scoped mode)
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page cod-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <PageHeader
        :breadcrumbs="breadcrumbs"
        :title="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cleanOverdraftFacilities')"
      >
        <template #subtitle>
          <template v-if="isScoped">
            {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.everyCleanOverdraftContractWith') }}
            <span class="cod-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank.name_en }}
              <span v-if="bank.name_ar" class="cod-bank-chip-ar">{{ bank.name_ar }}</span>
            </span>
          </template>
          <template v-else>
            {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.everyOverdraftLineYourCompanyHolds') }}
          </template>
        </template>

        <template #actions>
          <!-- Bank-scoped: straight to create, the bank is already known -->
          <Link
            v-if="isScoped"
            :href="route('fi.facilities.clean-od.create', bank.id)"
            class="zv-btn zv-btn-primary zv-btn-lg"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.newFacility') }}
          </Link>

          <!-- Portfolio mode: pick a bank first -->
          <div v-else class="cod-new-wrap" ref="newWrapRef">
            <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="toggleBankPicker">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.newFacility') }}
            </button>

            <Transition name="cod-drop">
              <div v-if="showBankPicker" class="zv-dropdown cod-bank-picker">
                <div class="cod-bank-picker-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.chooseABankToBegin') }}</div>

                <Link
                  v-for="b in banks"
                  :key="b.id"
                  :href="route('fi.facilities.clean-od.create', b.id)"
                  class="zv-dropdown-item"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 opacity-50 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                  {{ b.name_en }}
                </Link>

                <div v-if="!banks.length" class="cod-bank-picker-empty">
                  {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.youHavenTAddedABankYet') }}
                  <Link :href="route('fi.banks.create')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.addOneFirst') }}</Link>
                </div>
              </div>
            </Transition>
          </div>
        </template>
      </PageHeader>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW
      ══════════════════════════════════════════════════════ -->
      <div class="cod-kpi-grid">

        <!-- Facilities -->
        <KpiCard :label="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.facilities')" :value="stats.total_count">
          <template #sub>
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.noFacilitiesYet') }}</template>
            <template v-else>{{ stats.active_count }} {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.active') }}</template>
          </template>
        </KpiCard>

        <!-- Total Limit -->
        <KpiCard :label="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.totalLimit')">
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value cod-kpi-dash">—</div>
          <div v-else class="cod-currency-stack" :class="{ 'cod-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'limit-' + c.currency_code" class="cod-currency-line">
              <span class="cod-currency-code">{{ c.currency_code }}</span>
              <span class="cod-currency-value zv-num">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </KpiCard>

        <!-- Total Drawn -->
        <KpiCard :label="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.totalDrawn')">
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value cod-kpi-dash">—</div>
          <div v-else class="cod-currency-stack" :class="{ 'cod-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'drawn-' + c.currency_code" class="cod-currency-line">
              <span class="cod-currency-code">{{ c.currency_code }}</span>
              <span class="cod-currency-value zv-num-amber">{{ fmt(c.total_outstanding) }}</span>
            </div>
          </div>
        </KpiCard>

        <!-- Portfolio Utilization — the signature element. Deliberately
             NOT migrated to KpiCard — see v1.2 note in the file header. -->
        <div class="zv-kpi-card cod-ring-card">
          <div class="cod-ring-card-text">
            <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.avgUtilization') }}</div>
            <div class="zv-kpi-sub" v-if="stats.near_limit_count > 0">
              <span class="zv-kpi-delta zv-kpi-delta-warn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                {{ stats.near_limit_count }} {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.above85') }}
              </span>
            </div>
            <div class="zv-kpi-sub" v-else>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unweightedPerFacility') }}</div>
          </div>

          <div class="cod-ring" :class="ringZoneClass">
            <svg viewBox="0 0 64 64" class="cod-ring-svg">
              <circle cx="32" cy="32" r="26" class="cod-ring-track" />
              <circle
                cx="32" cy="32" r="26" class="cod-ring-fill"
                :stroke-dasharray="ringCircumference"
                :stroke-dashoffset="ringOffset"
              />
            </svg>
            <div class="cod-ring-label">{{ stats.avg_utilization }}<span class="cod-ring-pct">%</span></div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD — FILTERS + TABLE + PAGINATION
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card cod-main-card">

        <FilterBar
          :has-active-filters="hasActiveFilters"
          :results-count="facilities.total"
          results-label="facility"
          results-label-plural="facilities"
          @clear="clearFilters"
        >
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select cod-filter-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>

          <select v-model="statusFilter" class="zv-select cod-filter-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="expired">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.expired') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
            <option value="cancelled">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cancelled') }}</option>
          </select>
        </FilterBar>

        <!-- Empty state -->
        <EmptyState
          v-if="facilities.data.length === 0"
          :title="emptyTitle"
          :message="emptyMessage"
          icon-path="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"
        >
          <template v-if="hasActiveFilters" #action>
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
              {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.clearFilters') }}
            </button>
          </template>
        </EmptyState>

        <!-- Table -->
        <div v-else class="zv-table-wrap cod-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.accountNo') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.currency') }}</th>
                <th class="cod-th-num">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.limit') }}</th>
                <th class="cod-th-util">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.drawnUtilization') }}</th>
                <th>{{ t('common.rate') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewal') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="cod-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">

                <td v-if="!isScoped">
                  <div class="cod-bank-cell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="w-4 h-4 opacity-45 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                    </svg>
                    <span>{{ f.bank?.name_en }}</span>
                  </div>
                </td>

                <td class="font-mono cod-account-no">{{ f.account_number }}</td>

                <td>{{ f.currency?.code }}</td>

                <td class="zv-table-num">{{ fmt(f.facility_limit) }}</td>

                <td class="cod-util-cell">
                  <div class="cod-util-numbers">
                    <span class="zv-table-num">{{ fmt(f.outstanding_balance) }}</span>
                    <span class="cod-util-pct" :class="'cod-text-' + utilZone(f)">{{ utilPct(f) }}%</span>
                  </div>
                  <div class="od-util-track">
                    <div class="od-util-fill" :class="utilZone(f)" :style="{ width: Math.min(100, utilPct(f)) + '%' }"></div>
                  </div>
                </td>

                <td>
                  <span class="zv-num">{{ Number(f.interest_rate).toFixed(2) }}%</span>
                  <span v-if="f.follows_corridor_rate" class="cod-corridor-tag">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cBE') }}</span>
                </td>

                <td>
                  <span class="cod-renewal" :class="reviewClass(f)">
                    <span class="zv-status-dot" :class="reviewDotClass(f)"></span>
                    {{ fmtDate(f.review_date) }}
                  </span>
                  <button
                    v-if="hasRenewals(f)"
                    type="button"
                    class="cod-renewal-badge"
                    @click="openHistory(f)"
                    :title="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.viewRenewalHistory')"
                  >{{ f.terms_histories.length - 1 }} {{ f.terms_histories.length - 1 === 1 ? t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewal2') : t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewals') }}</button>
                </td>

                <td>
                  <StatusBadge :status="f.status" />
                </td>

                <td class="cod-th-actions">
                  <div class="cod-row-actions">
                    <Link
                      :href="route('fi.facilities.settlements.index', f.id)"
                      class="zv-btn zv-btn-ghost zv-btn-icon"
                      :title="t('financialInstitutions.banks.facilities.facilitySettlements.settlements')"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </Link>

                    <Link
                      :href="route('fi.facilities.clean-od.edit', f.id)"
                      class="zv-btn zv-btn-ghost zv-btn-icon"
                      :title="t('common.edit')"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </Link>

                    <button
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon"
                      :title="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewFacility')"
                      @click="openRenew(f)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/>
                      </svg>
                    </button>

                    <!-- Never renewed: delete removes the whole facility.
                         Already renewed: delete removes only the latest
                         renewal (facility can't be deleted until it's
                         back down to Original — confirmed with Mahmoud). -->
                    <button
                      v-if="!hasRenewals(f)"
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.cleanOverdraftIndex.deleteFacility') : t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cannotDeleteThisFacilityAlreadyHas')"
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
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.cleanOverdraftIndex.deleteLatestRenewal') : t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cannotDeleteThisRenewalAlreadyHas')"
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

        <!-- Pagination -->
        <Pagination :paginator="facilities" />
      </div>

      <!-- ══════════════════════════════════════════════════════
           RENEW MODAL — Facility Renewal
      ══════════════════════════════════════════════════════ -->
      <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-3xl">
          <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewFacility') }} — {{ renewTarget.account_number }}</h2>
          <p class="text-xs zv-text-muted mb-4">
            {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.anythingLeftBlankKeepsItsCurrent') }}
          </p>

          <div class="cod-renew-current mb-4">
            <span class="cod-renew-current-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.currentReviewDate') }}:</span>
            <strong>{{ fmtDate(currentReviewDate) }}</strong>
          </div>

          <div class="cod-renew-grid">
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.effectiveDate') }} *</label>
              <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
              <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.newReviewDate') }} *</label>
              <input type="date" v-model="renewForm.review_date" class="zv-input w-full" />
              <p v-if="renewErrors.review_date" class="zv-field-error">{{ renewErrors.review_date }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.limit') }}</label>
              <input type="number" v-model="renewForm.facility_limit" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
              <p v-if="renewErrors.facility_limit" class="zv-field-error">{{ renewErrors.facility_limit }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.settlementDays') }}</label>
              <input type="number" v-model="renewForm.settled_max_days" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
            </div>

            <div v-if="!renewTarget.follows_corridor_rate">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.borrowingRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.borrowing_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
            </div>
            <div v-else class="cod-renew-locked">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.borrowingRate') }}</label>
              <div class="zv-readonly-field">
                {{ Number(renewTarget.borrowing_rate).toFixed(2) }}%
                <span class="cod-corridor-tag">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cBE') }}</span>
              </div>
              <p class="field-hint">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.drivenByTheCBECorridorRate') }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.bankMarginRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.bank_margin_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
            </div>

            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.highestDebitBalanceRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.hdb_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
            </div>
            <div>
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.adminFeesRate') }}</label>
              <input type="number" step="0.01" v-model="renewForm.admin_fee_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.unchanged')" />
            </div>

            <div class="cod-renew-span-2">
              <label class="zv-label">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.notes') }}</label>
              <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.banks.facilities.cleanOverdraftIndex.eGBankRenewalLetterReference')"></textarea>
            </div>
          </div>

          <div class="flex justify-end gap-2 mt-5">
            <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.recordRenewal') }}</button>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           HISTORY MODAL — read-only chapter timeline
      ══════════════════════════════════════════════════════ -->
      <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-6xl">
          <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewalHistory') }} — {{ historyTarget.account_number }}</h2>
          <div class="zv-table-wrap">
            <table class="zv-table cod-history-table">
              <thead>
                <tr>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.effectiveDate') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.reviewDate') }}</th>
                  <th class="cod-th-num">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.limit') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.borrowing') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.margin') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.hDB') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.adminFee') }}</th>
                  <th>{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.chapter') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="term in historyTarget.terms_histories" :key="term.id">
                  <td>{{ fmtDate(term.effective_date) }}</td>
                  <td>{{ fmtDate(term.review_date) }}</td>
                  <td class="zv-table-num">{{ fmt(term.facility_limit) }}</td>
                  <td>{{ Number(term.borrowing_rate).toFixed(2) }}%</td>
                  <td>{{ Number(term.bank_margin_rate).toFixed(2) }}%</td>
                  <td>{{ Number(term.hdb_rate).toFixed(2) }}%</td>
                  <td>{{ Number(term.admin_fee_rate).toFixed(2) }}%</td>
                  <td class="zv-text-muted">{{ term.is_original ? t('financialInstitutions.banks.facilities.cleanOverdraftIndex.original') : t('financialInstitutions.banks.facilities.cleanOverdraftIndex.renewal') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex justify-end mt-4">
            <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.close') }}</button>
          </div>
        </div>
      </div>

      <!-- Delete Renewal confirmation -->
      <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
          <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.deleteThisRenewal') }}</h2>
          <p class="text-xs zv-text-muted mb-4">
            {{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.theFacilityWillRevertToIts') }}
          </p>
          <div class="flex justify-end gap-2">
            <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.close') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.confirmDelete') }}</button>
          </div>
        </div>
      </div>

      <!-- Delete Facility confirmation -->
      <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
          <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.deleteThisFacility') }}</h2>
          <div class="flex justify-end gap-2">
            <button type="button" class="zv-btn zv-btn-secondary" @click="deleteTarget = null">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.close') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" @click="destroyFacility">{{ t('financialInstitutions.banks.facilities.cleanOverdraftIndex.confirmDelete') }}</button>
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

// ── PageHeader breadcrumbs ────────────────────────────────────────

const breadcrumbs = computed(() => {
  const crumbs = [{ label: t('financialInstitutions.banks.facilities.cleanOverdraftIndex.banks'), href: route('fi.banks.index') }]
  if (isScoped.value) crumbs.push({ label: props.bank.name_en })
  crumbs.push({ label: t('financialInstitutions.banks.facilities.cleanOverdraftIndex.cleanOverdraft') })
  return crumbs
})

// ── EmptyState messages ────────────────────────────────────────────
// Same 4-way logic that used to live inline in the template as
// nested v-if/else-if chains — moved here as computed properties
// since EmptyState takes plain title/message props, not template
// fragments.

const emptyTitle = computed(() => {
  if (hasActiveFilters.value) return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.noFacilitiesMatchTheseFilters')
  if (isScoped.value) return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.noCleanOverdraftFacilitiesForThis')
  return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.noCleanOverdraftFacilitiesYet')
})

const emptyMessage = computed(() => {
  if (hasActiveFilters.value && isScoped.value) return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.tryADifferentStatusOrClear')
  if (hasActiveFilters.value) return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.tryADifferentBankOrStatus')
  if (isScoped.value) return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.useTheButtonAboveToSet')
  return t('financialInstitutions.banks.facilities.cleanOverdraftIndex.pickABankAboveToSet')
})

// ── Filters ───────────────────────────────────────────────────────

const bankFilter   = ref(props.filters?.bank_id ?? '')
const statusFilter = ref(props.filters?.status ?? '')

// In bank-scoped mode, bank_id is always present (it's forced by the
// route) — that's not a "filter" the person chose, so it shouldn't
// count toward hasActiveFilters or get cleared by "Clear filters".
const hasActiveFilters = computed(() =>
  isScoped.value ? !!statusFilter.value : (!!bankFilter.value || !!statusFilter.value)
)

function applyFilters() {
  const params = isScoped.value
    ? { status: statusFilter.value || undefined }
    : { bank_id: bankFilter.value || undefined, status: statusFilter.value || undefined }

  const url = isScoped.value
    ? route('fi.banks.clean-od.index', props.bank.id)
    : route('fi.facilities.clean-od.index')

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

function toggleBankPicker() {
  showBankPicker.value = !showBankPicker.value
}

function handleOutsideClick(e) {
  if (newWrapRef.value && !newWrapRef.value.contains(e.target)) {
    showBankPicker.value = false
  }
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

// Matches CreditFacility::hasAnyTransactions() — a nonzero outstanding
// balance, a real withdrawal, or a posted liability charge. Counts
// come from paginate()'s withCount() so this needs no extra request.
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
    facility_limit: '',
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

function submitRenew() {
  // Blank fields mean "unchanged from the previous chapter" —
  // handled server-side in CreditFacility::renew().
  router.post(route('fi.facilities.clean-od.renew', renewTarget.value.id), renewForm.value, {
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
  router.delete(route('fi.facilities.clean-od.delete-renewal', deleteRenewalTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteRenewalTarget.value = null },
  })
}

// ── Delete facility (whole record — Original chapter only) ───────

const deleteTarget = ref(null)
function confirmDelete(f) { deleteTarget.value = f }
function destroyFacility() {
  router.delete(route('fi.facilities.clean-od.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteTarget.value = null },
  })
}

// ── Portfolio utilization ring (signature element) ───────────────

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
/* ── Bank chip (scoped mode subtitle) ────────────────────────────── */
.cod-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 2px 9px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
  margin-inline-start: 4px; vertical-align: middle;
}
[data-theme="dark"] .cod-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.cod-bank-chip-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Bank picker dropdown ─────────────────────────────────────────── */
.cod-new-wrap { position: relative; }

.cod-bank-picker {
  position: absolute;
  top: calc(100% + 8px);
  inset-inline-end: 0;
  min-width: 260px;
  max-height: 320px;
  overflow-y: auto;
}
[dir="rtl"] .cod-bank-picker { inset-inline-start: 0; inset-inline-end: auto; }

.cod-bank-picker-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--zv-text-muted); padding: 0.4rem 0.6rem 0.3rem;
}

.cod-bank-picker-empty {
  padding: 0.6rem 0.6rem 0.3rem;
  font-size: 12.5px; color: var(--zv-text-muted); line-height: 1.5;
}

.cod-drop-enter-active, .cod-drop-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.cod-drop-enter-from, .cod-drop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }

/* ── KPI grid ─────────────────────────────────────────────────────── */
.cod-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.cod-kpi-dash { color: var(--zv-text-muted); }

.cod-currency-stack { display: flex; flex-direction: column; gap: 0.3rem; margin-top: 0.1rem; }
.cod-currency-line { display: flex; align-items: baseline; gap: 0.4rem; }
.cod-currency-code {
  font-size: 0.6875rem; font-weight: 700; color: var(--zv-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em; min-width: 28px;
}
.cod-currency-value { font-size: 1.625rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; }
.cod-currency-stack-multi .cod-currency-value { font-size: 1.125rem; }

/* ── Utilization ring (signature element) ────────────────────────── */
.cod-ring-card { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.cod-ring-card-text { flex: 1; min-width: 0; }

.cod-ring { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.cod-ring-svg { width: 64px; height: 64px; transform: rotate(-90deg); }

.cod-ring-track {
  fill: none; stroke: var(--zv-border); stroke-width: 6;
}
.cod-ring-fill {
  fill: none; stroke-width: 6; stroke-linecap: round;
  transition: stroke-dashoffset 0.5s ease;
}
.cod-ring.od-ok     .cod-ring-fill { stroke: var(--zv-green); }
.cod-ring.od-warn   .cod-ring-fill { stroke: var(--zv-amber); }
.cod-ring.od-danger .cod-ring-fill { stroke: var(--zv-danger); }

.cod-ring-label {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  font-size: 0.9375rem; font-weight: 800; color: var(--zv-text-primary);
  font-variant-numeric: tabular-nums;
}
.cod-ring-pct { font-size: 0.625rem; font-weight: 700; margin-inline-start: 1px; opacity: 0.65; }

/* ── Main card ────────────────────────────────────────────────────── */
.cod-main-card { overflow: hidden; }

.cod-filter-select { min-width: 160px; width: auto; }

.cod-table-wrap { border: none; border-radius: 0; box-shadow: none; }

/* ── Table cells ──────────────────────────────────────────────────── */
.cod-th-num   { text-align: end; }
.cod-th-util  { min-width: 180px; }
.cod-th-actions { width: 1%; }

.cod-bank-cell { display: flex; align-items: center; gap: 0.5rem; font-weight: 500; }
.cod-account-no { color: var(--zv-text-secondary); font-size: 0.78125rem; }

.cod-util-cell { min-width: 170px; }
.cod-util-numbers { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.3rem; }
.cod-util-pct { font-size: 0.75rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.cod-text-od-ok     { color: var(--zv-num-green); }
.cod-text-od-warn   { color: var(--zv-num-amber); }
.cod-text-od-danger { color: var(--zv-num-danger); }

/* Reuses the same visual language as the OD form's edit-mode utilization panel */
.od-util-track { height: 5px; background: var(--zv-border); border-radius: 4px; overflow: hidden; }
.od-util-fill  { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
.od-util-fill.od-ok     { background: var(--zv-green); }
.od-util-fill.od-warn   { background: var(--zv-amber); }
.od-util-fill.od-danger { background: var(--zv-danger); }

.cod-corridor-tag {
  display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 9px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  margin-inline-start: 5px;
}

.cod-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8125rem; font-weight: 500; }

/* ── Facility Renewal ─────────────────────────────────────────────── */
.cod-renewal-badge {
  display: block; margin-top: 3px; padding: 0; border: none; background: none;
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
  color: #1490A8; cursor: pointer;
}
.cod-renewal-badge:hover { text-decoration: underline; }

.cod-row-actions { display: flex; align-items: center; gap: 2px; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

.cod-renew-current {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
  background: var(--zv-bg-hover); font-size: 0.8125rem;
}
.cod-renew-current-label { color: var(--zv-text-muted); }

.cod-renew-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.9rem 1rem; }
.cod-renew-span-2 { grid-column: span 3; }
@media (max-width: 640px) {
  .cod-renew-grid { grid-template-columns: 1fr 1fr; }
  .cod-renew-span-2 { grid-column: span 2; }
}
.cod-renew-locked .zv-readonly-field { display: flex; align-items: center; gap: 6px; }

.cod-history-table th, .cod-history-table td { white-space: nowrap; }

/* ── Responsive ───────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .cod-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
  .cod-kpi-grid { grid-template-columns: 1fr; }
  .zv-page-header { flex-direction: column; align-items: stretch; }
  .cod-new-wrap .zv-btn { width: 100%; }
}
</style>