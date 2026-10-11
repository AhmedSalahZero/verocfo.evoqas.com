<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeIndex.vue

  Listing page for Letter of Guarantee facility AGREEMENTS.
  Structurally the same dual-mode pattern as every other facility
  type (Clean OD, CP OD, Contract-OD) — same KPI-row shell, same
  ZAVERO design tokens — adapted for what LG actually has at the
  agreement level.

  Two contexts, one page:
    - Portfolio mode (no 'bank' prop) — every bank, reached from
      the "Credit Facilities" menu on the Banks page header.
    - Bank-scoped mode ('bank' prop present) — one bank only,
      reached from that bank's own row on the Banks page.
    Both modes call the exact same service methods
    (LetterOfGuaranteeService::paginate()/stats()).

  What's different from the OD-family index pages:
    - No utilization ring / drawn amount. LG agreements don't carry
      an outstanding balance at this level — that only exists once
      individual issuances are tracked (a later build). In its
      place: "Total LG Types Configured" and "Near Renewal" (an
      agreement's review_date within 30 days), the LG equivalent
      of the OD family's "near limit" warning.
    - Table shows an "LG Types" badge (count) per row instead of a
      Drawn/Utilization column.

  Currency safety:
    facility_limit is NEVER summed across currencies — see
    LetterOfGuaranteeService::stats() for the full reasoning.

  @layout    AppLayout
  @route     fi.facilities.lg.index (portfolio)
             fi.banks.lg.index (bank-scoped)
  @props     facilities (paginator), stats, filters,
             banks (portfolio mode), bank (bank-scoped mode)
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page lg-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.banks') }}</Link>
            <template v-if="isScoped">
              <span class="zv-breadcrumb-sep">/</span>
              <span>{{ bank.name_en }}</span>
            </template>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.letterOfGuarantee') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.letterOfGuaranteeFacilities') }}</h1>
          <p class="zv-page-subtitle">
            <template v-if="isScoped">
              {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.everyLetterOfGuaranteeAgreementWith') }}
              <span class="lg-bank-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                </svg>
                {{ bank.name_en }}
                <span v-if="bank.name_ar" class="lg-bank-chip-ar">{{ bank.name_ar }}</span>
              </span>
            </template>
            <template v-else>
              {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.everyLetterOfGuaranteeAgreementYour') }}
            </template>
          </p>
        </div>

        <!-- Bank-scoped: straight to create, the bank is already known -->
        <Link
          v-if="isScoped"
          :href="route('fi.facilities.lg.create', bank.id)"
          class="zv-btn zv-btn-primary zv-btn-lg"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.newFacility') }}
        </Link>

        <!-- Portfolio mode: pick a bank first -->
        <div v-else class="lg-new-wrap" ref="newWrapRef">
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="toggleBankPicker">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.newFacility') }}
          </button>

          <Transition name="lg-drop">
            <div v-if="showBankPicker" class="zv-dropdown lg-bank-picker">
              <div class="lg-bank-picker-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.chooseABankToBegin') }}</div>

              <Link
                v-for="b in banks"
                :key="b.id"
                :href="route('fi.facilities.lg.create', b.id)"
                class="zv-dropdown-item"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 opacity-50 flex-shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                </svg>
                {{ b.name_en }}
              </Link>

              <div v-if="!banks.length" class="lg-bank-picker-empty">
                {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.youHavenTAddedABankYet') }}
                <Link :href="route('fi.banks.create')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.addOneFirst') }}</Link>
              </div>
            </div>
          </Transition>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW
      ══════════════════════════════════════════════════════ -->
      <div class="lg-kpi-grid">

        <!-- Facilities -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.facilities') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.noFacilitiesYet') }}</template>
            <template v-else>{{ stats.active_count }} {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.active') }}</template>
          </div>
        </div>

        <!-- Total Limit -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.totalLimit') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value lg-kpi-dash">—</div>
          <div v-else class="lg-currency-stack" :class="{ 'lg-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'limit-' + c.currency_code" class="lg-currency-line">
              <span class="lg-currency-code">{{ c.currency_code }}</span>
              <span class="lg-currency-value zv-num">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </div>

        <!-- Total LG Types Configured -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.lGTypesConfigured') }}</div>
          <div class="zv-kpi-value">{{ stats.total_lg_types }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.acrossAllAgreements') }}</div>
        </div>

        <!-- Near Renewal -->
        <div class="zv-kpi-card" :class="{ 'lg-kpi-warn-card': stats.near_review_count > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.nearRenewal') }}</div>
          <div class="zv-kpi-value" :class="{ 'lg-kpi-warn-value': stats.near_review_count > 0 }">
            {{ stats.near_review_count }}
          </div>
          <div class="zv-kpi-sub">
            <span v-if="stats.near_review_count > 0" class="zv-kpi-delta zv-kpi-delta-warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
              </svg>
              {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.within30Days') }}
            </span>
            <template v-else>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.noneDueSoon') }}</template>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD — FILTERS + TABLE + PAGINATION
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card lg-main-card">

        <div class="zv-filter-bar">
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select lg-filter-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>

          <select v-model="statusFilter" class="zv-select lg-filter-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="expired">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.expired') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
            <option value="cancelled">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.cancelled') }}</option>
          </select>

          <button v-if="hasActiveFilters" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="clearFilters">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.clearFilters') }}
          </button>

          <div class="lg-filter-count">
            {{ facilities.total }} {{ facilities.total === 1 ? t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.facility') : t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.facilities2') }}
          </div>
        </div>

        <!-- Empty state -->
        <div v-if="facilities.data.length === 0" class="zv-empty">
          <div class="zv-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-10 h-10">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div class="zv-empty-title">
            <template v-if="hasActiveFilters">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.noFacilitiesMatchTheseFilters') }}</template>
            <template v-else-if="isScoped">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.noLetterOfGuaranteeFacilitiesFor') }}</template>
            <template v-else>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.noLetterOfGuaranteeFacilitiesYet') }}</template>
          </div>
          <div class="zv-empty-sub">
            <template v-if="hasActiveFilters && isScoped">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.tryADifferentStatusOrClear') }}</template>
            <template v-else-if="hasActiveFilters">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.tryADifferentBankOrStatus') }}</template>
            <template v-else-if="isScoped">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.useTheButtonAboveToSet') }}</template>
            <template v-else>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.pickABankAboveToSet') }}</template>
          </div>
          <button v-if="hasActiveFilters" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.clearFilters') }}
          </button>
        </div>

        <!-- Table -->
        <div v-else class="zv-table-wrap lg-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.bank') }}</th>
                <th>{{ t('common.name') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.currency') }}</th>
                <th class="lg-th-num">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.limit') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.lGTypes') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewal') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="lg-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">

                <td v-if="!isScoped">
                  <div class="lg-bank-cell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="w-4 h-4 opacity-45 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                    </svg>
                    <span>{{ f.bank?.name_en }}</span>
                  </div>
                </td>

                <td class="lg-name-cell">{{ f.name || '—' }}</td>

                <td>{{ f.currency?.code }}</td>

                <td class="zv-table-num">{{ fmt(f.facility_limit) }}</td>

                <td>
                  <span class="lg-types-badge">{{ f.lg_types?.length ?? 0 }}</span>
                </td>

                <td>
                  <span class="lg-renewal" :class="reviewClass(f)">
                    <span class="zv-status-dot" :class="reviewDotClass(f)"></span>
                    {{ fmtDate(f.review_date) }}
                  </span>
                  <button
                    v-if="hasRenewals(f)"
                    type="button"
                    class="lg-renewal-badge"
                    @click="openHistory(f)"
                    :title="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.viewRenewalHistory')"
                  >{{ f.terms_histories.length - 1 }} {{ f.terms_histories.length - 1 === 1 ? t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewal2') : t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewals') }}</button>
                </td>

                <td>
                  <span class="zv-badge" :class="statusMeta(f.status).badge">
                    <span class="zv-status-dot" :class="statusMeta(f.status).dot"></span>
                    {{ t(statusMeta(f.status).label) }}
                  </span>
                </td>

                <td class="lg-th-actions">
                  <div class="lg-row-actions">
                    <Link
                      :href="route('fi.facilities.lg.edit', f.id)"
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
                      :title="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewFacility')"
                      @click="openRenew(f)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/>
                      </svg>
                    </button>

                    <button
                      v-if="hasRenewals(f)"
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                      :title="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.deleteLatestRenewal')"
                      @click="confirmDeleteRenewal(f)"
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

        <!-- ══════════════════════════════════════════════════════
             RENEW MODAL — Facility Renewal (agreement-level, NOT
             the LG Issuance renewal — see LgIssuanceIndex.vue for
             that separate action)
        ══════════════════════════════════════════════════════ -->
        <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
          <div class="zv-modal rounded-lg w-full max-w-4xl lg-renew-modal">

            <div class="p-6 pb-0">
              <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewFacility') }} — {{ renewTarget.name }}</h2>
              <p class="text-xs zv-text-muted mb-4">
                {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.anythingLeftBlankKeepsItsCurrent') }}
              </p>

              <div class="lg-renew-current mb-4">
                <span class="lg-renew-current-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.currentReviewDate') }}:</span>
                <strong>{{ fmtDate(currentReviewDate) }}</strong>
              </div>
            </div>

            <div class="lg-renew-scroll px-6">
              <div class="lg-renew-grid">
                <div>
                  <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.effectiveDate') }} *</label>
                  <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
                  <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
                </div>
                <div>
                  <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.newReviewDate') }} *</label>
                  <input type="date" v-model="renewForm.review_date" class="zv-input w-full" />
                  <p v-if="renewErrors.review_date" class="zv-field-error">{{ renewErrors.review_date }}</p>
                </div>
                <div class="lg-renew-span-2">
                  <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.limit') }}</label>
                  <input type="number" v-model="renewForm.facility_limit" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.unchanged')" />
                  <p v-if="renewErrors.facility_limit" class="zv-field-error">{{ renewErrors.facility_limit }}</p>
                </div>
              </div>

              <!-- ── Terms & Conditions (rate card) ──────────────────
                   The gap Mahmoud flagged: renewal isn't just the
                   limit/review date — the bank can revise cash cover
                   %, commission %, interval, minimum fee, or issuance
                   fee per LG type at renewal too. Pre-filled with the
                   facility's CURRENT rate card so the user edits in
                   place rather than starting from a blank table. -->
              <div class="mt-5">
                <div class="lg-renew-tc-header">
                  <span class="lg-renew-tc-title">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.termsAndConditions') }}</span>
                  <span class="lg-renew-tc-sub">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.reviseTheRateCardIfThe') }}</span>
                </div>

                <div v-if="renewDuplicateTypes.length" class="zv-alert zv-alert-warning mb-3">
                  <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                  </svg>
                  <span>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.thisLGTypeAppearsMoreThan') }} {{ renewDuplicateTypes.join(', ') }}</span>
                </div>

                <div class="lg-tier-table">
                  <div class="lg-tier-header">
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.lGType') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.cashCover') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.commissionRate') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.commissionInterval') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.minCommissionFeeAmount') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.issuanceFeeAmount') }}</div>
                    <div></div>
                  </div>

                  <div v-for="(row, idx) in renewForm.lg_types" :key="idx" class="lg-tier-row">
                    <div class="lg-tier-col">
                      <input v-model="row.lg_type" type="text" class="zv-input"
                             :class="{ 'zv-input-error': renewErrors['lg_types.' + idx + '.lg_type'] }"/>
                      <p v-if="renewErrors['lg_types.' + idx + '.lg_type']" class="zv-field-error">
                        {{ renewErrors['lg_types.' + idx + '.lg_type'] }}
                      </p>
                    </div>
                    <div class="lg-tier-col">
                      <div class="zv-input-group">
                        <input v-model="row.cash_cover_pct" type="number" min="0" max="100" step="0.01"
                               class="zv-input zv-input-has-suffix text-end"
                               :class="{ 'zv-input-error': renewErrors['lg_types.' + idx + '.cash_cover_pct'] }"/>
                        <span class="zv-input-suffix text-xs font-semibold">%</span>
                      </div>
                    </div>
                    <div class="lg-tier-col">
                      <div class="zv-input-group">
                        <input v-model="row.commission_rate_pct" type="number" min="0" max="100" step="0.0001"
                               class="zv-input zv-input-has-suffix text-end"
                               :class="{ 'zv-input-error': renewErrors['lg_types.' + idx + '.commission_rate_pct'] }"/>
                        <span class="zv-input-suffix text-xs font-semibold">%</span>
                      </div>
                    </div>
                    <div class="lg-tier-col">
                      <select v-model="row.commission_interval" class="zv-select">
                        <option v-for="(label, key) in commissionIntervals" :key="key" :value="key">{{ t(label) }}</option>
                      </select>
                    </div>
                    <div class="lg-tier-col">
                      <div class="zv-input-group">
                        <span class="zv-input-prefix text-xs font-semibold">{{ renewTarget.currency?.code }}</span>
                        <input v-model="row.min_commission_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end"/>
                      </div>
                    </div>
                    <div class="lg-tier-col">
                      <div class="zv-input-group">
                        <span class="zv-input-prefix text-xs font-semibold">{{ renewTarget.currency?.code }}</span>
                        <input v-model="row.issuance_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end"/>
                      </div>
                    </div>
                    <div class="lg-tier-col lg-tier-col-action">
                      <button v-if="renewForm.lg_types.length > 1" type="button" class="lg-remove-btn"
                              @click="removeRenewTypeRow(idx)" :title="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.remove')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                      </button>
                    </div>
                  </div>

                  <div class="lg-tier-footer">
                    <button type="button" class="lg-add-row-btn" @click="addRenewTypeRow">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                      </svg>
                      {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.addLGType') }}
                    </button>
                  </div>
                </div>
                <p v-if="renewErrors.lg_types" class="zv-field-error mt-2">{{ renewErrors.lg_types }}</p>
              </div>

              <div class="mt-5 mb-1">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.notes') }}</label>
                <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.eGBankRenewalLetterReference')"></textarea>
              </div>
            </div>

            <div class="flex justify-end gap-2 p-6 pt-4 lg-renew-footer">
              <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
              <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.recordRenewal') }}</button>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             HISTORY MODAL — read-only chapter timeline
        ══════════════════════════════════════════════════════ -->
        <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
          <div class="zv-modal rounded-lg p-6 w-full max-w-2xl">
            <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewalHistory') }} — {{ historyTarget.name }}</h2>
            <div class="zv-table-wrap">
              <table class="zv-table lg-history-table">
                <thead>
                  <tr>
                    <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.effectiveDate') }}</th>
                    <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.reviewDate') }}</th>
                    <th class="lg-th-num">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.limit') }}</th>
                    <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.chapter') }}</th>
                    <th>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.notes') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="term in historyTarget.terms_histories" :key="term.id">
                    <td>{{ fmtDate(term.effective_date) }}</td>
                    <td>{{ fmtDate(term.review_date) }}</td>
                    <td class="zv-table-num">{{ fmt(term.facility_limit) }}</td>
                    <td class="zv-text-muted">{{ term.is_original ? t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.original') : t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.renewal') }}</td>
                    <td class="zv-text-muted">{{ term.notes || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex justify-end mt-4">
              <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.close') }}</button>
            </div>
          </div>
        </div>

        <!-- Delete Renewal confirmation -->
        <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[2500] p-4">
          <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
            <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.deleteThisRenewal') }}</h2>
            <p class="text-xs zv-text-muted mb-4">
              {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.theFacilityWillRevertToIts') }}
            </p>
            <div class="flex justify-end gap-2">
              <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.close') }}</button>
              <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeIndex.confirmDelete') }}</button>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="facilities.data.length > 0" class="zv-pagination">
          <span>{{ t('common.showing') }} {{ facilities.from }}–{{ facilities.to }} {{ t('common.of') }} {{ facilities.total }}</span>

          <div class="zv-pagination-pages">
            <template v-for="(link, idx) in facilities.links" :key="idx">

              <component
                :is="link.url ? Link : 'span'"
                :href="link.url || undefined"
                class="zv-page-btn lg-page-btn"
                :class="{ active: link.active, 'lg-page-btn-disabled': !link.url }"
              >
                <svg v-if="isPrevLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 lg-page-icon">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                <svg v-else-if="isNextLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 lg-page-icon">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span v-else>{{ link.label }}</span>
              </component>
            </template>
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
  stats: {
    type: Object,
    default: () => ({
      total_count: 0,
      active_count: 0,
      by_currency: [],
      total_lg_types: 0,
      near_review_count: 0,
    }),
  },
  banks:   { type: Array,  default: () => [] },
  bank:    { type: Object, default: null },
  filters: { type: Object, default: () => ({}) },
  commissionIntervals: { type: Object, default: () => ({}) },
})

const isScoped = computed(() => !!props.bank)

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
    ? route('fi.banks.lg.index', props.bank.id)
    : route('fi.facilities.lg.index')

  router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  bankFilter.value = ''
  statusFilter.value = ''
  applyFilters()
}

// ── Bank picker dropdown (portfolio mode "New Facility") ───────────

const showBankPicker = ref(false)
const newWrapRef = ref(null)

function toggleBankPicker() {
  showBankPicker.value = !showBankPicker.value
}

function handleClickAway(e) {
  if (newWrapRef.value && !newWrapRef.value.contains(e.target)) {
    showBankPicker.value = false
  }
}

onMounted(() => document.addEventListener('click', handleClickAway))
onUnmounted(() => document.removeEventListener('click', handleClickAway))

// ── Formatting helpers ───────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

function daysUntil(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}

function reviewClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return ''
  if (d < 0)  return 'lg-review-overdue'
  if (d <= 30) return 'lg-review-warn'
  return ''
}

function reviewDotClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return 'zv-dot-muted'
  if (d < 0)  return 'zv-dot-danger'
  if (d <= 30) return 'zv-dot-warn'
  return 'zv-dot-ok'
}

const STATUS_META = {
  active:    { label: 'Active',    badge: 'zv-badge-success', dot: 'zv-dot-ok' },
  expired:   { label: 'Expired',   badge: 'zv-badge-danger',  dot: 'zv-dot-danger' },
  closed:    { label: 'Closed',    badge: 'zv-badge-muted',   dot: 'zv-dot-muted' },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-muted',   dot: 'zv-dot-muted' },
}

function statusMeta(status) {
  return STATUS_META[status] ?? { label: status, badge: 'zv-badge-muted', dot: 'zv-dot-muted' }
}

function isPrevLink(label) { return label?.includes('Previous') || label === '&laquo; Previous' }
function isNextLink(label) { return label?.includes('Next') || label === 'Next &raquo;' }

// ── Facility Renewal ──────────────────────────────────────────────
// IMPORTANT: this is the LG FACILITY (agreement) renewal — a
// separate action from the LG Issuance renewal (LgIssuanceIndex.vue),
// which changes the terms of one individual issued guarantee, not
// the agreement itself.

function hasRenewals(f) {
  return (f.terms_histories?.length || 0) > 1
}

const renewTarget = ref(null)
const renewForm = ref(blankRenewForm())
const renewErrors = ref({})

// Rate card rows default to a fresh, empty row when the user adds one
// (matches LetterOfGuaranteeForm.vue's own defaultRow()) — existing
// rows are pre-filled from the facility's current terms in openRenew().
function defaultRenewTypeRow() {
  return {
    lg_type:                   '',
    cash_cover_pct:             '',
    commission_rate_pct:        '',
    commission_interval:        'quarterly',
    min_commission_fee_amount:  '',
    issuance_fee_amount:        '',
  }
}

function cloneLgTypes(list) {
  return (list || []).map(row => ({
    lg_type:                   row.lg_type                   ?? '',
    cash_cover_pct:             row.cash_cover_pct             ?? '',
    commission_rate_pct:        row.commission_rate_pct        ?? '',
    commission_interval:        row.commission_interval        ?? 'quarterly',
    min_commission_fee_amount:  row.min_commission_fee_amount  ?? '',
    issuance_fee_amount:        row.issuance_fee_amount        ?? '',
  }))
}

function blankRenewForm(f) {
  return {
    effective_date: '',
    review_date: '',
    facility_limit: '',
    notes: '',
    // Pre-filled with the facility's CURRENT rate card — a renewal
    // usually keeps most terms the same with a few tweaks, so the
    // user edits in place rather than starting from a blank table
    // (this was the gap: the original modal never surfaced these
    // fields at all, only limit/review date).
    lg_types: f ? cloneLgTypes(f.lg_types) : [],
  }
}

function openRenew(f) {
  renewTarget.value = f
  renewErrors.value = {}
  renewForm.value = blankRenewForm(f)
}
function cancelRenew() { renewTarget.value = null }

function addRenewTypeRow() {
  renewForm.value.lg_types.push(defaultRenewTypeRow())
}
function removeRenewTypeRow(idx) {
  renewForm.value.lg_types.splice(idx, 1)
}

// Client-side mirror of the server's duplicate-type check — same
// logic as LetterOfGuaranteeForm.vue's duplicateTypes computed.
const renewDuplicateTypes = computed(() => {
  const seen  = new Set()
  const dupes = new Set()

  ;(renewForm.value.lg_types || []).forEach(row => {
    const type = (row.lg_type || '').trim().toLowerCase()
    if (!type) return
    if (seen.has(type)) dupes.add(row.lg_type.trim())
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

function submitRenew() {
  // Blank fields mean "unchanged from the previous chapter" —
  // handled server-side in CreditFacility::renew().
  router.post(route('fi.facilities.lg.renew', renewTarget.value.id), renewForm.value, {
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
  router.delete(route('fi.facilities.lg.delete-renewal', deleteRenewalTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteRenewalTarget.value = null },
  })
}
</script>

<style>
/* ── KPI grid ─────────────────────────────────────────────────────── */
.lg-kpi-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem;
}
@media (max-width: 900px) { .lg-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lg-kpi-grid { grid-template-columns: 1fr; } }

.zv-kpi-card {
  background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); padding: 16px 18px; box-shadow: var(--zv-shadow-sm);
}
.zv-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 8px; }
.zv-kpi-value { font-size: 24px; font-weight: 800; color: var(--zv-text-primary); font-variant-numeric: tabular-nums; }
.zv-kpi-sub   { font-size: 12px; color: var(--zv-text-muted); margin-top: 6px; }
.zv-kpi-delta { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.zv-kpi-delta-warn { color: #f59e0b; }

.lg-kpi-dash { color: var(--zv-text-muted); }
.lg-kpi-warn-card  { border-color: rgba(245,158,11,0.35); }
.lg-kpi-warn-value { color: #f59e0b; }

.lg-currency-stack { display: flex; flex-direction: column; gap: 4px; }
.lg-currency-line  { display: flex; align-items: baseline; gap: 8px; }
.lg-currency-code  { font-size: 11px; font-weight: 700; color: var(--zv-text-muted); min-width: 32px; }
.lg-currency-value { font-size: 18px; font-weight: 800; color: var(--zv-text-primary); }
.lg-currency-stack-multi .lg-currency-value { font-size: 15px; }

/* ── Header bank chip ────────────────────────────────────────────── */
.lg-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
}
[data-theme="dark"] .lg-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.lg-bank-chip-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Bank picker dropdown ────────────────────────────────────────── */
.lg-new-wrap { position: relative; }
.zv-dropdown {
  position: absolute; top: calc(100% + 8px); right: 0; z-index: 30;
  min-width: 260px; background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-lg); padding: 8px;
}
.lg-bank-picker-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-muted); padding: 6px 10px 8px; }
.zv-dropdown-item {
  display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: var(--zv-radius);
  font-size: 13.5px; color: var(--zv-text-primary); transition: background 0.1s;
}
.zv-dropdown-item:hover { background: var(--zv-bg-surface-alt); }
.lg-bank-picker-empty { padding: 10px; font-size: 12.5px; color: var(--zv-text-muted); }

.lg-drop-enter-active, .lg-drop-leave-active { transition: all 0.15s ease; }
.lg-drop-enter-from, .lg-drop-leave-to { opacity: 0; transform: translateY(-6px); }

/* ── Filter bar ───────────────────────────────────────────────────── */
.zv-filter-bar { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); flex-wrap: wrap; }
.lg-filter-select { min-width: 160px; }
.lg-filter-count { margin-inline-start: auto; font-size: 12.5px; color: var(--zv-text-muted); font-weight: 600; }

/* ── Table ────────────────────────────────────────────────────────── */
.lg-table-wrap { overflow-x: auto; }
.lg-th-num { text-align: center; }
.lg-th-actions { width: 56px; }
.zv-table-num { text-align: center; font-variant-numeric: tabular-nums; }

.lg-bank-cell { display: flex; align-items: center; gap: 8px; }
.lg-name-cell { font-weight: 600; color: var(--zv-text-primary); }

.lg-types-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 26px; height: 22px; padding: 0 8px; border-radius: 11px;
  background: rgba(20,144,168,0.1); color: #1490A8; font-size: 12px; font-weight: 700;
}

.lg-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.lg-review-warn    { color: #f59e0b; font-weight: 600; }
.lg-review-overdue { color: #ef4444; font-weight: 700; }

.lg-renewal-badge {
  display: inline-flex; align-items: center; margin-inline-start: 6px;
  padding: 2px 8px; border-radius: 10px; border: none; cursor: pointer;
  background: rgba(20,144,168,0.1); color: #1490A8; font-size: 10.5px; font-weight: 700;
}
.lg-renewal-badge:hover { background: rgba(20,144,168,0.18); }

.lg-row-actions { display: flex; align-items: center; gap: 2px; justify-content: flex-end; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

/* ── Facility Renewal modal ───────────────────────────────────────── */
.lg-renew-modal { display: flex; flex-direction: column; max-height: calc(100vh - 48px); }
.lg-renew-scroll { overflow-y: auto; flex: 1 1 auto; }
.lg-renew-footer { flex-shrink: 0; border-top: 1px solid var(--zv-border); }
.lg-renew-current {
  display: flex; align-items: center; gap: 8px; padding: 10px 14px;
  background: var(--zv-bg-surface-alt); border-radius: var(--zv-radius); font-size: 13px;
}
.lg-renew-current-label { color: var(--zv-text-muted); font-weight: 600; }
.lg-renew-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px 16px; }
.lg-renew-span-2 { grid-column: span 2; }
.lg-history-table th, .lg-history-table td { white-space: nowrap; }

.lg-renew-tc-header { margin-bottom: 10px; }
.lg-renew-tc-title { display: block; font-size: 13.5px; font-weight: 700; color: var(--zv-text-primary); }
.lg-renew-tc-sub { display: block; font-size: 12px; color: var(--zv-text-muted); margin-top: 2px; }

.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

/* ── LG type rate card table (shared with LetterOfGuaranteeForm.vue) ── */
.lg-tier-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.lg-tier-header {
  display: grid; grid-template-columns: 1.4fr 1fr 1.1fr 1.1fr 1.3fr 1.3fr 40px;
  padding: 9px 14px; gap: 10px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--zv-text-muted);
}
.lg-tier-row {
  display: grid; grid-template-columns: 1.4fr 1fr 1.1fr 1.1fr 1.3fr 1.3fr 40px;
  padding: 10px 14px; gap: 10px; align-items: start;
  border-bottom: 1px solid var(--zv-border); transition: background 0.1s;
}
.lg-tier-row:last-of-type { border-bottom: none; }
.lg-tier-row:hover { background: var(--zv-bg-surface-alt); }
.lg-tier-col { display: flex; flex-direction: column; }
.lg-tier-col-action { align-items: center; justify-content: center; padding-top: 2px; }

.lg-remove-btn {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
  color: var(--zv-text-muted); transition: all 0.12s;
}
.lg-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.lg-tier-footer {
  display: flex; align-items: center; justify-content: flex-start;
  padding: 10px 14px; border-top: 1px dashed var(--zv-border);
  background: var(--zv-bg-surface);
}
.lg-add-row-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border: 1px dashed var(--zv-border);
  border-radius: var(--zv-radius); background: transparent;
  color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.lg-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

@media (max-width: 900px) {
  .lg-tier-header, .lg-tier-row { grid-template-columns: 1fr 1fr; }
  .lg-tier-header > div:nth-child(n+3),
  .lg-tier-row > .lg-tier-col:nth-child(n+3) { grid-column: span 1; }
  .lg-renew-grid { grid-template-columns: 1fr; }
  .lg-renew-span-2 { grid-column: span 1; }
}
@media (max-width: 600px) {
  .lg-tier-header { display: none; }
  .lg-tier-row { grid-template-columns: 1fr; }
}

.zv-status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.zv-dot-ok     { background: #10b981; }
.zv-dot-warn   { background: #f59e0b; }
.zv-dot-danger { background: #ef4444; }
.zv-dot-muted  { background: var(--zv-text-muted); }

.zv-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.zv-badge-success { background: rgba(16,185,129,0.1); color: #10b981; }
.zv-badge-danger  { background: rgba(239,68,68,0.1);  color: #ef4444; }
.zv-badge-muted   { background: rgba(100,116,139,0.1); color: #64748b; }

/* ── Pagination ───────────────────────────────────────────────────── */
.zv-pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px solid var(--zv-border); font-size: 12.5px; color: var(--zv-text-muted); }
.zv-pagination-pages { display: flex; align-items: center; gap: 4px; }
.zv-page-btn {
  display: flex; align-items: center; justify-content: center;
  min-width: 30px; height: 30px; padding: 0 8px; border-radius: var(--zv-radius);
  font-size: 12.5px; font-weight: 600; color: var(--zv-text-primary); transition: all 0.1s;
}
.zv-page-btn:hover:not(.lg-page-btn-disabled) { background: var(--zv-bg-surface-alt); }
.zv-page-btn.active { background: #0F2554; color: #fff; }
.lg-page-btn-disabled { opacity: 0.35; pointer-events: none; }

/* ── Empty state ──────────────────────────────────────────────────── */
.zv-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 24px; text-align: center; }
.zv-empty-icon { color: var(--zv-text-muted); opacity: 0.4; margin-bottom: 14px; }
.zv-empty-title { font-size: 15px; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 6px; }
.zv-empty-sub { font-size: 13px; color: var(--zv-text-muted); max-width: 380px; }
</style>
