<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/ContractAssignmentOdIndex.vue

  Listing page for OD Against Contract/SO Assignment (223003)
  facility contracts. Follows the same dual-mode architecture as
  CleanOverdraftIndex and CommercialPaperOverdraftIndex.

  Two contexts, one page:
    - Portfolio mode (no 'bank' prop) — all banks, reached from
      the "Credit Facilities" menu on the Banks header.
      Shows Bank column, bank filter, bank-picker on "+ New".
    - Bank-scoped mode ('bank' prop present) — one bank only.
      Hides Bank column and bank filter. "+ New" goes straight
      to create for this bank.

  Unique to this facility type vs Clean OD / CP OD:
    - "Assigned Contracts" badge column — shows how many
      Projects/SOs are assigned to each facility. Clicking the
      badge opens the assignment modal for that facility.
    - "Assign Contracts" button per table row (teal outline).
    - Stats: extra KPI card "Total Assigned Contracts" in addition
      to the standard Facilities / Total Limit / Total Drawn /
      Avg Utilization ring.

  Assignment Modal (Teleport → body):
    - Customer dropdown → Contract/SO dropdown (filtered by customer)
    - Amount, Start Date, End Date — read-only (auto-filled from
      the selected contract)
    - Assignment Date, Lending %, Max Lending Limit per contract
    - Lending Amount (auto-calc read-only: contract × lending % / 100)
    - Effective Lending (auto-calc: MIN(lending_amount, max_limit))
    - Table of already-assigned contracts for the selected facility
    - Close + Confirm buttons

  Currency safety:
    Totals never summed across currencies — same reasoning as
    CleanOverdraftIndex (see that file for full explanation).

  @layout    AppLayout
  @route     fi.facilities.contract-od.index (portfolio)
             fi.banks.contract-od.index (bank-scoped)
  @props     facilities (paginator), stats, filters,
             banks (portfolio mode), bank (bank-scoped mode)
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page cao-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.banks') }}</Link>
            <template v-if="isScoped">
              <span class="zv-breadcrumb-sep">/</span>
              <span>{{ bank.name_en }}</span>
            </template>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.oDAgainstContractAssignment') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.oDAgainstContractAssignment') }}</h1>
          <p class="zv-page-subtitle">
            <template v-if="isScoped">
              {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractBackedOverdraftFacilitiesWith') }}
              <span class="cao-bank-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                </svg>
                {{ bank.name_en }}
                <span v-if="bank.name_ar" class="cao-bank-chip-ar">{{ bank.name_ar }}</span>
              </span>
            </template>
            <template v-else>
              {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.everyContractAssignmentOverdraftLineYour') }}
            </template>
          </p>
        </div>

        <!-- Bank-scoped: straight to create -->
        <Link
          v-if="isScoped"
          :href="route('fi.facilities.contract-od.create', bank.id)"
          class="zv-btn zv-btn-primary zv-btn-lg"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.newFacility') }}
        </Link>

        <!-- Portfolio mode: pick a bank first -->
        <div v-else class="cao-new-wrap" ref="newWrapRef">
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="toggleBankPicker">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.newFacility') }}
          </button>

          <Transition name="cao-drop">
            <div v-if="showBankPicker" class="zv-dropdown cao-bank-picker">
              <div class="cao-bank-picker-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.chooseABankToBegin') }}</div>
              <Link
                v-for="b in banks" :key="b.id"
                :href="route('fi.facilities.contract-od.create', b.id)"
                class="zv-dropdown-item"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 opacity-50 flex-shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                </svg>
                {{ b.name_en }}
              </Link>
              <div v-if="!banks.length" class="cao-bank-picker-empty">
                {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.youHavenTAddedABankYet') }}
                <Link :href="route('fi.banks.create')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.addOneFirst') }}</Link>
              </div>
            </div>
          </Transition>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW — 5 cards for this facility type
      ══════════════════════════════════════════════════════ -->
      <div class="cao-kpi-grid">

        <!-- Facilities count -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.facilities') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noFacilitiesYet') }}</template>
            <template v-else>{{ stats.active_count }} {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.active') }}</template>
          </div>
        </div>

        <!-- Total Assigned Contracts (unique to this facility type) -->
        <div class="zv-kpi-card cao-assigned-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assignedContracts') }}</div>
          <div class="zv-kpi-value cao-assigned-value">{{ stats.total_assigned }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.projectsSalesOrdersPledged') }}</div>
        </div>

        <!-- Total Limit per currency -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.totalLimit') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value cao-kpi-dash">—</div>
          <div v-else class="cao-currency-stack" :class="{ 'cao-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'limit-' + c.currency_code" class="cao-currency-line">
              <span class="cao-currency-code">{{ c.currency_code }}</span>
              <span class="cao-currency-value zv-num">{{ fmt(c.total_limit) }}</span>
            </div>
          </div>
        </div>

        <!-- Total Drawn per currency -->
        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.totalDrawn') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value cao-kpi-dash">—</div>
          <div v-else class="cao-currency-stack" :class="{ 'cao-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'drawn-' + c.currency_code" class="cao-currency-line">
              <span class="cao-currency-code">{{ c.currency_code }}</span>
              <span class="cao-currency-value zv-num-amber">{{ fmt(c.total_outstanding) }}</span>
            </div>
          </div>
        </div>

        <!-- Portfolio Utilization Ring -->
        <div class="zv-kpi-card cao-ring-card">
          <div class="cao-ring-card-text">
            <div class="zv-kpi-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.avgUtilization') }}</div>
            <div class="zv-kpi-sub" v-if="stats.near_limit_count > 0">
              <span class="zv-kpi-delta zv-kpi-delta-warn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                {{ stats.near_limit_count }} {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.above85') }}
              </span>
            </div>
            <div class="zv-kpi-sub" v-else>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unweightedPerFacility') }}</div>
          </div>
          <div class="cao-ring" :class="ringZoneClass">
            <svg viewBox="0 0 64 64" class="cao-ring-svg">
              <circle cx="32" cy="32" r="26" class="cao-ring-track"/>
              <circle cx="32" cy="32" r="26" class="cao-ring-fill"
                      :stroke-dasharray="ringCircumference"
                      :stroke-dashoffset="ringOffset"/>
            </svg>
            <div class="cao-ring-label">{{ stats.avg_utilization }}<span class="cao-ring-pct">%</span></div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD — FILTERS + TABLE + PAGINATION
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card cao-main-card">

        <div class="zv-filter-bar" style="flex-direction: row; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
          <select v-if="!isScoped" v-model="bankFilter" class="zv-select cao-filter-select" @change="applyFilters">
            <option value="">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.allBanks') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
          </select>

          <select v-model="statusFilter" class="zv-select cao-filter-select" @change="applyFilters">
            <option value="">{{ t('common.allStatuses') }}</option>
            <option value="active">{{ t('common.active') }}</option>
            <option value="expired">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.expired') }}</option>
            <option value="closed">{{ t('common.closed') }}</option>
            <option value="cancelled">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.cancelled') }}</option>
          </select>

          <button v-if="hasActiveFilters" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="clearFilters">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.clearFilters') }}
          </button>

          <div class="cao-filter-count">
            {{ facilities.total }} {{ facilities.total === 1 ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.facility') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.facilities2') }}
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
            <template v-if="hasActiveFilters">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noFacilitiesMatchTheseFilters') }}</template>
            <template v-else-if="isScoped">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noContractAssignmentODFacilitiesFor') }}</template>
            <template v-else>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noContractAssignmentODFacilitiesYet') }}</template>
          </div>
          <div class="zv-empty-sub">
            <template v-if="hasActiveFilters">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.tryADifferentBankOrStatus') }}</template>
            <template v-else-if="isScoped">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.useTheButtonAboveToSet') }}</template>
            <template v-else>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.pickABankAboveToSet') }}</template>
          </div>
          <button v-if="hasActiveFilters" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">
            {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.clearFilters') }}
          </button>
        </div>

        <!-- Table -->
        <div v-else class="zv-table-wrap cao-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!isScoped">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.accountNo') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.currency') }}</th>
                <th class="cao-th-num">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.limit') }}</th>
                <th class="cao-th-util">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.drawnUtilization') }}</th>
                <th>{{ t('common.rate') }}</th>
                <th class="cao-th-center">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assigned') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewal') }}</th>
                <th>{{ t('common.status') }}</th>
                <th class="cao-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in facilities.data" :key="f.id">

                <td v-if="!isScoped">
                  <div class="cao-bank-cell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="w-4 h-4 opacity-45 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                    </svg>
                    <span>{{ f.bank?.name_en }}</span>
                  </div>
                </td>

                <td class="font-mono cao-account-no">{{ f.account_number || '—' }}</td>

                <td>{{ f.currency?.code }}</td>

                <td class="zv-table-num">{{ fmt(f.facility_limit) }}</td>

                <!-- Drawn + utilization bar -->
                <td class="cao-util-cell">
                  <div class="cao-util-numbers">
                    <span class="zv-table-num">{{ fmt(f.outstanding_balance) }}</span>
                    <span class="cao-util-pct" :class="'cao-text-' + utilZone(f)">{{ utilPct(f) }}%</span>
                  </div>
                  <div class="od-util-track">
                    <div class="od-util-fill" :class="utilZone(f)" :style="{ width: Math.min(100, utilPct(f)) + '%' }"></div>
                  </div>
                </td>

                <!-- Rate + CBE corridor tag -->
                <td>
                  <span class="zv-num">{{ Number(f.interest_rate).toFixed(2) }}%</span>
                  <span v-if="f.follows_corridor_rate" class="cao-corridor-tag">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.cBE') }}</span>
                </td>

                <!-- Assigned contracts badge — clickable to open modal -->
                <td class="cao-th-center">
                  <button
                    type="button"
                    class="cao-assigned-badge"
                    :class="(f.contract_assignments_count || 0) > 0 ? 'cao-assigned-badge-active' : 'cao-assigned-badge-empty'"
                    @click="openModal(f)"
                    :title="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.manageAssignedContracts')"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    {{ f.contract_assignments_count || 0 }}
                  </button>
                </td>

                <!-- Renewal date -->
                <td>
                  <span class="cao-renewal" :class="reviewClass(f)">
                    <span class="zv-status-dot" :class="reviewDotClass(f)"></span>
                    {{ fmtDate(f.review_date) }}
                  </span>
                  <button
                    v-if="hasRenewals(f)"
                    type="button"
                    class="cao-renewal-badge"
                    @click="openHistory(f)"
                    :title="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.viewRenewalHistory')"
                  >{{ f.terms_histories.length - 1 }} {{ f.terms_histories.length - 1 === 1 ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewal2') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewals') }}</button>
                </td>

                <!-- Status badge -->
                <td>
                  <span class="zv-badge" :class="statusMeta(f.status).badge">
                    <span class="zv-status-dot" :class="statusMeta(f.status).dot"></span>
                    {{ t(statusMeta(f.status).label) }}
                  </span>
                </td>

                <!-- Actions -->
                <td class="cao-th-actions">
                  <div class="cao-row-actions">
                    <button
                      type="button"
                      class="zv-btn zv-btn-teal zv-btn-sm"
                      @click="openModal(f)"
                      :title="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assignContracts')"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                      </svg>
                      {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assign') }}
                    </button>
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
                      :href="route('fi.facilities.contract-od.edit', f.id)"
                      class="zv-btn zv-btn-ghost zv-btn-icon"
                      :title="t('common.edit')"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </Link>

                    <button type="button" class="zv-btn zv-btn-ghost zv-btn-icon" :title="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewFacility')" @click="openRenew(f)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-4.9M20 15a8 8 0 01-14 4.9"/>
                      </svg>
                    </button>

                    <button
                      v-if="!hasRenewals(f)"
                      type="button"
                      class="zv-btn zv-btn-ghost zv-btn-icon zv-btn-danger-ghost"
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.deleteFacility') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.cannotDeleteThisFacilityAlreadyHas')"
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
                      :title="canDeleteFacility(f) ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.deleteLatestRenewal') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.cannotDeleteThisRenewalAlreadyHas')"
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
        <div v-if="facilities.data.length > 0" class="zv-pagination">
          <span>{{ t('common.showing') }} {{ facilities.from }}–{{ facilities.to }} {{ t('common.of') }} {{ facilities.total }}</span>
          <div class="zv-pagination-pages">
            <template v-for="(link, idx) in facilities.links" :key="idx">
              <component
                :is="link.url ? Link : 'span'"
                :href="link.url || undefined"
                class="zv-page-btn cao-page-btn"
                :class="{ active: link.active, 'cao-page-btn-disabled': !link.url }"
              >
                <svg v-if="isPrevLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 cao-page-icon">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                <svg v-else-if="isNextLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 cao-page-icon">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span v-else>{{ link.label }}</span>
              </component>
            </template>
          </div>
        </div>
      </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         CONTRACT ASSIGNMENT MODAL (Teleport → body)
    ══════════════════════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition name="cao-modal">
        <div v-if="modal.open" class="cao-modal-backdrop" @click.self="closeModal">
          <div class="cao-modal">

            <!-- Modal header — navy stripe matching form-section-header -->
            <div class="cao-modal-header">
              <div class="cao-modal-header-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 text-white">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
              </div>
              <div class="cao-modal-header-text">
                <div class="cao-modal-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.lendingInformation') }}</div>
                <div class="cao-modal-sub">
                  {{ modal.facility?.bank?.name_en }}
                  <span v-if="modal.facility?.account_number" class="cao-modal-acct">
                    · {{ modal.facility?.account_number }}
                  </span>
                </div>
              </div>
              <button type="button" class="cao-modal-close" @click="closeModal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Modal body -->
            <div class="cao-modal-body">

              <!-- ── Section: Assign a Contract ── -->
              <div class="form-section mb-4">
                <div class="form-section-header">
                  <div class="form-section-icon" style="background:#1490A8">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                  </div>
                  <div>
                    <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assignAContractOrSalesOrder') }}</div>
                    <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.selectACustomerChooseAContract') }}</div>
                  </div>
                </div>

                <div class="form-section-body">

                  <!-- Loading state -->
                  <div v-if="modal.loading" class="cao-modal-loading">
                    <div class="zv-spinner w-5 h-5"></div>
                    <span>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.loadingEligibleContracts') }}</span>
                  </div>

                  <template v-else>

                    <!-- Row 1: Customer + Contract/SO -->
                    <div class="field-grid field-grid-3 mb-4">
                      <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('common.customer') }}</label>
                        <select v-model="modal.partnerId" class="zv-select" @change="onPartnerChange">
                          <option value="">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.selectCustomer') }}</option>
                          <option v-for="p in modal.partners" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                        </select>
                        <p v-if="!modal.partners.length && !modal.loading" class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noCustomersWithActiveEligibleContracts') }}</p>
                      </div>

                      <div class="field-group field-group-span-2">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractSalesOrder') }}</label>
                        <select v-model="modal.collateralId" class="zv-select"
                                :disabled="!modal.partnerId" @change="onCollateralChange">
                          <option value="">{{ modal.partnerId ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.selectContract') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.selectACustomerFirst') }}</option>
                          <option v-for="c in filteredCollateral" :key="c.type + '-' + c.id" :value="c.type + '-' + c.id">
                            {{ c.label }}{{ c.type === 'sales_order' ? ' (SO)' : '' }}
                          </option>
                        </select>
                        <p v-if="modal.partnerId && !filteredCollateral.length" class="field-hint">
                          {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noEligibleContractsForThisCustomer') }}
                        </p>
                      </div>
                    </div>

                    <!-- Contract info strip — shown when contract selected -->
                    <Transition name="section-slide">
                      <div v-if="modal.selectedCollateral" class="cao-contract-strip mb-4">
                        <div class="cao-contract-strip-item">
                          <span class="cao-strip-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractValue') }}</span>
                          <span class="cao-strip-val cao-strip-val--amount">
                            {{ modal.facility?.currency?.code }} {{ fmt(modal.selectedCollateral.amount) }}
                          </span>
                        </div>
                        <div class="cao-contract-strip-divider"></div>
                        <div class="cao-contract-strip-item">
                          <span class="cao-strip-label">{{ t('common.startDate') }}</span>
                          <span class="cao-strip-val">{{ fmtDate(modal.selectedCollateral.start_date) }}</span>
                        </div>
                        <div class="cao-contract-strip-divider"></div>
                        <div class="cao-contract-strip-item">
                          <span class="cao-strip-label">{{ t('common.endDate') }}</span>
                          <span class="cao-strip-val">{{ fmtDate(modal.selectedCollateral.end_date) }}</span>
                        </div>
                        <div class="cao-contract-strip-divider"></div>
                        <div class="cao-contract-strip-item">
                          <span class="cao-strip-label">{{ t('common.type') }}</span>
                          <span class="cao-strip-val">
                            <span class="cao-type-badge" :class="modal.selectedCollateral.type === 'project' ? 'cao-type-project' : 'cao-type-so'">
                              {{ modal.selectedCollateral.type === 'project' ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.project') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.salesOrder') }}
                            </span>
                          </span>
                        </div>
                      </div>
                    </Transition>

                    <!-- Row 2: Assignment Date + Lending % -->
                    <div class="field-grid field-grid-3 mb-4">
                      <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assignmentDate') }}</label>
                        <input v-model="modal.assignmentDate" type="date" class="zv-input"/>
                        <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.dateTheBankTookLegalAssignment') }}</p>
                      </div>

                      <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.lending') }}</label>
                        <div class="zv-input-group">
                          <input v-model="modal.lendingPct" type="number" step="0.01" min="0.01" max="100"
                                 class="zv-input zv-input-has-suffix text-end" placeholder="0.00"/>
                          <span class="zv-input-suffix">%</span>
                        </div>
                        <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.ofContractValueTheBankAgrees') }}</p>
                      </div>

                      <!-- Max Lending Limit Per Contract — read-only from facility -->
                      <div class="field-group">
                        <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.facilityCapPerContract') }}</label>
                        <div class="od-calc-field">
                          <div class="od-calc-value" style="font-size:15px">
                            <template v-if="modal.facility?.max_lending_limit_per_contract">
                              {{ modal.facility?.currency?.code }}
                              {{ fmt(modal.facility?.max_lending_limit_per_contract) }}
                            </template>
                            <template v-else>
                              <span style="font-size:13px;color:var(--zv-text-muted)">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noCapSet') }}</span>
                            </template>
                          </div>
                          <div class="od-calc-badge">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.fromContract') }}
                          </div>
                        </div>
                        <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.setOnTheODFacilityApplies') }}</p>
                      </div>
                    </div>

                    <!-- Lending calculation panel -->
                    <Transition name="section-slide">
                      <div v-if="modal.selectedCollateral && parseFloat(modal.lendingPct) > 0" class="od-rate-summary mb-4">
                        <div class="od-rate-item">
                          <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractValue') }}</div>
                          <div class="od-rate-value" style="font-size:14px">{{ fmt(modal.selectedCollateral.amount) }}</div>
                        </div>
                        <div class="od-rate-divider"></div>
                        <div class="od-rate-item">
                          <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.lending2') }}</div>
                          <div class="od-rate-value" style="font-size:14px">{{ modal.lendingPct || '0' }}%</div>
                        </div>
                        <div class="od-rate-divider"></div>
                        <div class="od-rate-item">
                          <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.lendingAmount') }}</div>
                          <div class="od-rate-value od-rate-value--main">{{ fmt(lendingAmount) }}</div>
                        </div>
                        <div class="od-rate-divider"></div>
                        <div class="od-rate-item">
                          <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.facilityCap') }}</div>
                          <div class="od-rate-value" style="font-size:14px">
                            {{ modal.facility?.max_lending_limit_per_contract ? fmt(modal.facility.max_lending_limit_per_contract) : '—' }}
                          </div>
                        </div>
                        <div class="od-rate-divider"></div>
                        <div class="od-rate-item">
                          <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.effectiveFunding') }}</div>
                          <div class="od-rate-value od-rate-value--effective">{{ fmt(effectiveLending) }}</div>
                        </div>
                      </div>
                    </Transition>

                    <!-- Error alert -->
                    <div v-if="modal.error" class="zv-alert zv-alert-danger mb-4">
                      <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                      </svg>
                      <span>{{ modal.error }}</span>
                    </div>

                  </template>
                </div>
              </div>

              <!-- ── Section: Currently Assigned ── -->
              <div class="form-section">
                <div class="form-section-header">
                  <div class="form-section-icon" style="background:#0F2554">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                  </div>
                  <div class="flex-1">
                    <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.currentlyAssignedContracts') }}</div>
                    <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.allContractsPledgedToThisOD') }}</div>
                  </div>
                  <div v-if="modal.assignments.length" class="cao-assign-count-badge">
                    {{ modal.assignments.length }}
                  </div>
                </div>

                <!-- Empty state -->
                <div v-if="modal.assignments.length === 0" class="cao-modal-empty">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-10 h-10 opacity-20">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  <span class="cao-empty-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.noContractsAssignedYet') }}</span>
                  <span class="cao-empty-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.useTheFormAboveToAssign') }}</span>
                </div>

                <!-- Assignments table -->
                <div v-else class="od-bd-table" style="border-radius:0; border:none; border-top:1px solid var(--zv-border)">
                  <div class="cao-assign-table-header">
                    <div>#</div>
                    <div>{{ t('common.customer') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractSO') }}</div>
                    <div class="text-end">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.contractValue') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.period') }}</div>
                    <div>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assigned') }}</div>
                    <div class="text-end">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.lending') }}</div>
                    <div class="text-end">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.effectiveLending') }}</div>
                    <div></div>
                  </div>

                  <div v-for="(a, idx) in modal.assignments" :key="a.id" class="cao-assign-table-row">
                    <div class="cao-row-seq">{{ idx + 1 }}</div>

                    <div class="cao-row-partner">{{ a.partner?.name_en || '—' }}</div>

                    <div class="cao-row-contract">
                      <span class="cao-type-badge cao-type-badge-sm"
                            :class="a.collateral_type === 'project' ? 'cao-type-project' : 'cao-type-so'">
                        {{ a.collateral_type === 'project' ? 'PRJ' : 'SO' }}
                      </span>
                      {{ collateralLabel(a) }}
                    </div>

                    <div class="cao-row-amount">{{ fmt(contractAmount(a)) }}</div>

                    <div class="cao-row-period">
                      <span>{{ fmtDate(contractStart(a)) }}</span>
                      <span class="cao-period-sep">→</span>
                      <span>{{ fmtDate(contractEnd(a)) }}</span>
                    </div>

                    <div class="cao-row-date">{{ fmtDate(a.assignment_date) }}</div>

                    <div class="cao-row-pct">{{ Number(a.lending_pct).toFixed(2) }}%</div>

                    <div class="cao-row-effective">{{ fmt(effectiveLendingForRow(a)) }}</div>

                    <div class="cao-row-action">
                      <button type="button" class="od-remove-btn"
                              @click="removeAssignment(a)"
                              :disabled="modal.removing === a.id"
                              :title="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.removeAssignment')">
                        <div v-if="modal.removing === a.id" class="zv-spinner w-3.5 h-3.5"></div>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

            </div>

            <!-- Modal footer -->
            <div class="cao-modal-footer">
              <button type="button" class="zv-btn zv-btn-ghost" @click="closeModal">
                {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.close') }}
              </button>
              <button type="button" class="zv-btn zv-btn-primary"
                      :disabled="!canConfirm || modal.saving" @click="confirmAssignment">
                <div v-if="modal.saving" class="zv-spinner w-4 h-4 me-2"></div>
                {{ modal.saving ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.assigning') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.confirmAssignment') }}
              </button>
            </div>

          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ══════════════════════════════════════════════════════
         RENEW MODAL — Facility Renewal
    ══════════════════════════════════════════════════════ -->
    <div v-if="renewTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="zv-modal rounded-lg p-6 w-full max-w-3xl">
        <h2 class="text-lg font-medium zv-text-primary mb-1">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewFacility') }} — {{ renewTarget.account_number }}</h2>
        <p class="text-xs zv-text-muted mb-4">
          {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.anythingLeftBlankKeepsItsCurrent') }}
        </p>

        <div class="cao-renew-current mb-4">
          <span class="cao-renew-current-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.currentReviewDate') }}:</span>
          <strong>{{ fmtDate(currentReviewDate) }}</strong>
        </div>

        <div class="cao-renew-grid">
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.effectiveDate') }} *</label>
            <input type="date" v-model="renewForm.effective_date" class="zv-input w-full" />
            <p v-if="renewErrors.effective_date" class="zv-field-error">{{ renewErrors.effective_date }}</p>
          </div>
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.newReviewDate') }} *</label>
            <input type="date" v-model="renewForm.review_date" class="zv-input w-full" />
            <p v-if="renewErrors.review_date" class="zv-field-error">{{ renewErrors.review_date }}</p>
          </div>
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.limit') }}</label>
            <input type="number" v-model="renewForm.facility_limit" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>

          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.maxLendingLimitContract') }}</label>
            <input type="number" v-model="renewForm.max_lending_limit_per_contract" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.settlementDays') }}</label>
            <input type="number" v-model="renewForm.settled_max_days" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>
          <div v-if="!renewTarget.follows_corridor_rate">
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.borrowingRate') }}</label>
            <input type="number" step="0.01" v-model="renewForm.borrowing_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>
          <div v-else class="cao-renew-locked">
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.borrowingRate') }}</label>
            <div class="zv-readonly-field">
              {{ Number(renewTarget.borrowing_rate).toFixed(2) }}%
              <span class="cao-corridor-tag">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.cBE') }}</span>
            </div>
            <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.drivenByTheCBECorridorRate') }}</p>
          </div>

          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.bankMarginRate') }}</label>
            <input type="number" step="0.01" v-model="renewForm.bank_margin_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.highestDebitBalanceRate') }}</label>
            <input type="number" step="0.01" v-model="renewForm.hdb_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>
          <div>
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.adminFeesRate') }}</label>
            <input type="number" step="0.01" v-model="renewForm.admin_fee_rate" class="zv-input w-full" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.unchanged')" />
          </div>

          <div class="cao-renew-span-3">
            <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.notes') }}</label>
            <textarea v-model="renewForm.notes" class="zv-input w-full" rows="2" :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.eGBankRenewalLetterReference')"></textarea>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-5">
          <button type="button" class="zv-btn zv-btn-secondary" @click="cancelRenew">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" @click="submitRenew">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.recordRenewal') }}</button>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         HISTORY MODAL — read-only chapter timeline
    ══════════════════════════════════════════════════════ -->
    <div v-if="historyTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="zv-modal rounded-lg p-6 w-full max-w-6xl">
        <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewalHistory') }} — {{ historyTarget.account_number }}</h2>
        <div class="zv-table-wrap">
          <table class="zv-table cao-history-table">
            <thead>
              <tr>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.effectiveDate') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.reviewDate') }}</th>
                <th class="cao-th-num">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.limit') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.borrowing') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.margin') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.hDB') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.adminFee') }}</th>
                <th>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.chapter') }}</th>
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
                <td class="zv-text-muted">{{ term.is_original ? t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.original') : t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.renewal') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end mt-4">
          <button type="button" class="zv-btn zv-btn-secondary" @click="historyTarget = null">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.close') }}</button>
        </div>
      </div>
    </div>

    <!-- Delete Renewal confirmation -->
    <div v-if="deleteRenewalTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
        <h2 class="text-lg font-medium zv-text-primary mb-2">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.deleteThisRenewal') }}</h2>
        <p class="text-xs zv-text-muted mb-4">
          {{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.theFacilityWillRevertToIts') }}
        </p>
        <div class="flex justify-end gap-2">
          <button type="button" class="zv-btn zv-btn-secondary" @click="deleteRenewalTarget = null">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.close') }}</button>
          <button type="button" class="zv-btn zv-btn-danger" @click="destroyRenewal">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.confirmDelete') }}</button>
        </div>
      </div>
    </div>

    <!-- Delete Facility confirmation -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="zv-modal rounded-lg p-6 w-full max-w-sm">
        <h2 class="text-lg font-medium zv-text-primary mb-4">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.deleteThisFacility') }}</h2>
        <div class="flex justify-end gap-2">
          <button type="button" class="zv-btn zv-btn-secondary" @click="deleteTarget = null">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.close') }}</button>
          <button type="button" class="zv-btn zv-btn-danger" @click="destroyFacility">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.confirmDelete') }}</button>
        </div>
      </div>
    </div>

</template>

<script setup>
import { computed, reactive, ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  facilities: { type: Object,  required: true },
  stats: {
    type: Object,
    default: () => ({
      total_count: 0, active_count: 0, total_assigned: 0,
      by_currency: [], avg_utilization: '0.00', near_limit_count: 0,
    }),
  },
  banks:   { type: Array,  default: () => [] },
  bank:    { type: Object, default: null },
  filters: { type: Object, default: () => ({}) },
})

const isScoped = computed(() => !!props.bank)

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
    ? route('fi.banks.contract-od.index', props.bank.id)
    : route('fi.facilities.contract-od.index')
  router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  if (!isScoped.value) bankFilter.value = ''
  statusFilter.value = ''
  applyFilters()
}

// ── Bank picker ───────────────────────────────────────────────────

const showBankPicker = ref(false)
const newWrapRef = ref(null)

function toggleBankPicker() { showBankPicker.value = !showBankPicker.value }

function handleOutsideClick(e) {
  if (newWrapRef.value && !newWrapRef.value.contains(e.target)) {
    showBankPicker.value = false
  }
}

onMounted(() => document.addEventListener('click', handleOutsideClick))
onUnmounted(() => document.removeEventListener('click', handleOutsideClick))

// ── Modal state ───────────────────────────────────────────────────

const modal = reactive({
  open:              false,
  facility:          null,
  partners:          [],
  collateral:        [],
  assignments:       [],
  partnerId:         '',
  collateralId:      '',
  selectedCollateral: null,
  assignmentDate:    '',
  lendingPct:        '',
  // max_lending_limit removed from here — cap now on facility.max_lending_limit_per_contract
  saving:            false,
  removing:          null,
  loading:           false,
  error:             '',
})

const filteredCollateral = computed(() => {
  if (!modal.partnerId) return modal.collateral
  return modal.collateral.filter(c => String(c.partner_id) === String(modal.partnerId))
})

const lendingAmount = computed(() => {
  if (!modal.selectedCollateral || !modal.lendingPct) return 0
  const val = parseFloat(modal.selectedCollateral.amount || 0)
  const pct = parseFloat(modal.lendingPct || 0)
  return (val * pct) / 100
})

const effectiveLending = computed(() => {
  const lend = lendingAmount.value
  const cap  = parseFloat(modal.facility?.max_lending_limit_per_contract || 0)
  if (!cap) return lend
  return Math.min(lend, cap)
})

const canConfirm = computed(() =>
  modal.partnerId &&
  modal.collateralId &&
  modal.assignmentDate &&
  parseFloat(modal.lendingPct || 0) > 0
)

async function openModal(facility) {
  modal.facility           = facility
  modal.open               = true
  modal.error              = ''
  modal.loading            = true
  modal.partnerId          = ''
  modal.collateralId       = ''
  modal.selectedCollateral = null
  modal.assignmentDate     = ''
  modal.lendingPct         = ''
  modal.assignments        = [...(facility.contract_assignments || [])]

  try {
    const res  = await fetch(route('fi.facilities.contract-od.collateral', facility.id), {
      headers: { 'Accept': 'application/json' },
    })
    const data = await res.json()
    modal.partners   = data.partners   || []
    modal.collateral = data.collateral || []
  } catch (e) {
    modal.error = t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.failedToLoadEligibleContractsPlease')
  } finally {
    modal.loading = false
  }
}

function closeModal() {
  modal.open = false
  modal.facility = null
}

function onPartnerChange() {
  modal.collateralId      = ''
  modal.selectedCollateral = null
  recalcModal()
}

function onCollateralChange() {
  if (!modal.collateralId) {
    modal.selectedCollateral = null
    return
  }
  const [type, id] = modal.collateralId.split('-')
  modal.selectedCollateral = modal.collateral.find(
    c => c.type === type && String(c.id) === id
  ) || null
  recalcModal()
}

function recalcModal() {
  // lendingAmount and effectiveLending are computed — nothing to do
}

async function confirmAssignment() {
  modal.saving = true
  modal.error  = ''

  const [type, id] = (modal.collateralId || '').split('-')

  const body = {
    partner_id:             modal.partnerId,
    collateral_type:        type,
    collateral_project_id:  type === 'project'      ? parseInt(id) : null,
    collateral_so_id:       type === 'sales_order'  ? parseInt(id) : null,
    assignment_date:        modal.assignmentDate,
    lending_pct:            modal.lendingPct,
    // max_lending_limit removed — cap is read from facility.max_lending_limit_per_contract
  }

  try {
    const res = await fetch(route('fi.facilities.contract-od.assign', modal.facility.id), {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept':       'application/json',
      },
      body: JSON.stringify(body),
    })

    const data = await res.json()

    if (!res.ok) {
      modal.error = data.message || t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.somethingWentWrongPleaseTryAgain')
      return
    }

    // Add the new assignment to the local list + reset inputs
    modal.assignments.push(data.assignment)

    // Update the badge count on the table row
    const row = props.facilities.data.find(f => f.id === modal.facility.id)
    if (row) row.contract_assignments_count = (row.contract_assignments_count || 0) + 1

    modal.partnerId          = ''
    modal.collateralId       = ''
    modal.selectedCollateral = null
    modal.assignmentDate     = ''
    modal.lendingPct         = ''

    // Refresh collateral list (the assigned one is now pledged)
    const refresh = await fetch(route('fi.facilities.contract-od.collateral', modal.facility.id), {
      headers: { 'Accept': 'application/json' },
    })
    const refreshData = await refresh.json()
    modal.collateral = refreshData.collateral || []

  } catch (e) {
    modal.error = t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.networkErrorPleaseTryAgain')
  } finally {
    modal.saving = false
  }
}

async function removeAssignment(assignment) {
  if (!confirm(t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.removeThisContractAssignmentThisCannot'))) return

  modal.removing = assignment.id

  try {
    const res = await fetch(route('fi.facilities.contract-od.unassign', assignment.id), {
      method:  'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept':       'application/json',
      },
    })

    if (res.ok) {
      modal.assignments = modal.assignments.filter(a => a.id !== assignment.id)
      const row = props.facilities.data.find(f => f.id === modal.facility.id)
      if (row && row.contract_assignments_count > 0) row.contract_assignments_count -= 1

      // Refresh available collateral
      const refresh = await fetch(route('fi.facilities.contract-od.collateral', modal.facility.id), {
        headers: { 'Accept': 'application/json' },
      })
      const data    = await refresh.json()
      modal.collateral = data.collateral || []
    } else {
      const data = await res.json()
      modal.error = data.message || t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.failedToRemoveAssignment')
    }
  } catch (e) {
    modal.error = t('financialInstitutions.banks.facilities.contractAssignmentOdIndex.networkErrorPleaseTryAgain')
  } finally {
    modal.removing = null
  }
}

// ── Assignment row helpers ────────────────────────────────────────

function collateralLabel(a) {
  if (a.collateral_project) {
    const p = a.collateral_project
    return (p.contract_code || 'PRJ-' + p.id) + ' — ' + p.name_en
  }
  if (a.collateral_so) {
    const so = a.collateral_so
    // so_code is the correct column (migration 000039)
    return so.so_code + (so.subject ? ' — ' + so.subject : '')
  }
  return '—'
}

function contractAmount(a) {
  const p  = a.collateral_project
  const so = a.collateral_so
  // contract_value for projects, so_value for SOs (migration columns)
  return p  ? (p.contract_value || 0)
       : so ? (so.so_value      || 0)
       : 0
}

function contractStart(a) {
  return a.collateral_project?.start_date || a.collateral_so?.start_date || null
}

function contractEnd(a) {
  return a.collateral_project?.end_date || a.collateral_so?.end_date || null
}

function effectiveLendingForRow(a) {
  const lending = parseFloat(a.lending_amount || 0)
  // Cap comes from the facility, passed via modal.facility
  const cap = parseFloat(modal.facility?.max_lending_limit_per_contract || 0)
  return cap > 0 ? Math.min(lending, cap) : lending
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── Utilization ───────────────────────────────────────────────────

function utilPct(f) {
  const limit = parseFloat(f.facility_limit     || 0)
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

// ── Status display ────────────────────────────────────────────────

const STATUS_META = {
  active:    { label: 'Active',    badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },
  expired:   { label: 'Expired',   badge: 'zv-badge-amber',  dot: 'zv-status-dot-amber'  },
  closed:    { label: 'Closed',    badge: 'zv-badge-gray',   dot: 'zv-status-dot-gray'   },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-danger', dot: 'zv-status-dot-danger' },
}

function statusMeta(s) {
  return STATUS_META[s] ?? { label: s, badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' }
}

// ── Renewal urgency ───────────────────────────────────────────────

function daysUntil(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}

function reviewClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return 'zv-text-muted'
  if (d < 0)      return 'zv-text-danger'
  if (d <= 30)    return 'zv-text-amber'
  return 'zv-text-secondary'
}

function reviewDotClass(f) {
  const d = daysUntil(f.review_date)
  if (d === null) return 'zv-status-dot-gray'
  if (d < 0)      return 'zv-status-dot-danger'
  if (d <= 30)    return 'zv-status-dot-amber'
  return 'zv-status-dot-green'
}

// ── Pagination ────────────────────────────────────────────────────

function isPrevLink(label) { return label.includes('Previous') }
function isNextLink(label) { return label.includes('Next') }

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
    facility_limit: '',
    max_lending_limit_per_contract: '',
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
  router.post(route('fi.facilities.contract-od.renew', renewTarget.value.id), renewForm.value, {
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
  router.delete(route('fi.facilities.contract-od.delete-renewal', deleteRenewalTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteRenewalTarget.value = null },
  })
}

// ── Delete facility (whole record — Original chapter only) ───────

const deleteTarget = ref(null)
function confirmDelete(f) { deleteTarget.value = f }
function destroyFacility() {
  router.delete(route('fi.facilities.contract-od.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onFinish: () => { deleteTarget.value = null },
  })
}

// ── Portfolio utilization ring ────────────────────────────────────

const RING_RADIUS      = 26
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
/* ── Bank chip ─────────────────────────────────────────────────── */
.cao-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 2px 9px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
  margin-inline-start: 4px; vertical-align: middle;
}
[data-theme="dark"] .cao-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.cao-bank-chip-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Bank picker ───────────────────────────────────────────────── */
.cao-new-wrap { position: relative; }
.cao-bank-picker {
  position: absolute; top: calc(100% + 8px); inset-inline-end: 0;
  min-width: 260px; max-height: 320px; overflow-y: auto;
}
.cao-bank-picker-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--zv-text-muted); padding: 0.4rem 0.6rem 0.3rem;
}
.cao-bank-picker-empty { padding: 0.6rem; font-size: 12.5px; color: var(--zv-text-muted); line-height: 1.5; }
.cao-drop-enter-active, .cao-drop-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.cao-drop-enter-from, .cao-drop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }

/* ── KPI grid — 5 columns ─────────────────────────────────────── */
.cao-kpi-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.cao-kpi-dash { color: var(--zv-text-muted); }
.cao-assigned-card { border-top: 3px solid #1490A8; }
.cao-assigned-value { color: #1490A8; }

.cao-currency-stack { display: flex; flex-direction: column; gap: 0.3rem; margin-top: 0.1rem; }
.cao-currency-line { display: flex; align-items: baseline; gap: 0.4rem; }
.cao-currency-code { font-size: 0.6875rem; font-weight: 700; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.04em; min-width: 28px; }
.cao-currency-value { font-size: 1.625rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; }
.cao-currency-stack-multi .cao-currency-value { font-size: 1.125rem; }

/* ── Utilization ring ─────────────────────────────────────────── */
.cao-ring-card { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.cao-ring-card-text { flex: 1; min-width: 0; }
.cao-ring { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.cao-ring-svg { width: 64px; height: 64px; transform: rotate(-90deg); }
.cao-ring-track { fill: none; stroke: var(--zv-border); stroke-width: 6; }
.cao-ring-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.5s ease; }
.cao-ring.od-ok     .cao-ring-fill { stroke: var(--zv-green); }
.cao-ring.od-warn   .cao-ring-fill { stroke: var(--zv-amber); }
.cao-ring.od-danger .cao-ring-fill { stroke: var(--zv-danger); }
.cao-ring-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 0.9375rem; font-weight: 800; color: var(--zv-text-primary); font-variant-numeric: tabular-nums; }
.cao-ring-pct { font-size: 0.625rem; font-weight: 700; margin-inline-start: 1px; opacity: 0.65; }

/* ── Main card ─────────────────────────────────────────────────── */
.cao-main-card { overflow: hidden; }
.cao-filter-select { min-width: 250px; width: auto; }
.cao-filter-count { margin-inline-start: auto; font-size: 0.75rem; color: var(--zv-text-muted); font-weight: 500; }
.cao-table-wrap { border: none; border-radius: 0; box-shadow: none; }

/* ── Table cells ───────────────────────────────────────────────── */
.cao-th-num     { text-align: end; }
.cao-th-util    { min-width: 180px; }
.cao-th-center  { text-align: center; }
.cao-th-actions { width: 1%; }

.cao-bank-cell { display: flex; align-items: center; gap: 0.5rem; font-weight: 500; }
.cao-account-no { color: var(--zv-text-secondary); font-size: 0.78125rem; }
.cao-util-cell  { min-width: 170px; }
.cao-util-numbers { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.3rem; }
.cao-util-pct { font-size: 0.75rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.cao-text-od-ok     { color: var(--zv-num-green); }
.cao-text-od-warn   { color: var(--zv-num-amber); }
.cao-text-od-danger { color: var(--zv-danger); }

/* Reuses od-util-track/fill from CleanOverdraftIndex (same global token) */
.od-util-track { height: 5px; background: var(--zv-border); border-radius: 4px; overflow: hidden; }
.od-util-fill  { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
.od-util-fill.od-ok     { background: var(--zv-green); }
.od-util-fill.od-warn   { background: var(--zv-amber); }
.od-util-fill.od-danger { background: var(--zv-danger); }

.cao-corridor-tag {
  display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 9px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  margin-inline-start: 5px;
}

/* ── Assigned badge ────────────────────────────────────────────── */
.cao-assigned-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
  border: none; cursor: pointer; transition: all 0.15s ease;
}
.cao-assigned-badge-active {
  background: rgba(20,144,168,0.12); color: #1490A8; border: 1.5px solid rgba(20,144,168,0.3);
}
.cao-assigned-badge-active:hover { background: rgba(20,144,168,0.22); }
.cao-assigned-badge-empty {
  background: var(--zv-bg-muted); color: var(--zv-text-muted); border: 1.5px dashed var(--zv-border);
}
.cao-assigned-badge-empty:hover { border-color: #1490A8; color: #1490A8; }

/* ── Row actions ───────────────────────────────────────────────── */
.cao-row-actions { display: flex; align-items: center; gap: 6px; justify-content: flex-end; }

.zv-btn-teal {
  background: rgba(20,144,168,0.1); color: #1490A8;
  border: 1.5px solid rgba(20,144,168,0.3);
  padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px; cursor: pointer;
  transition: background 0.15s ease;
}
.zv-btn-teal:hover { background: rgba(20,144,168,0.2); }

.cao-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8125rem; font-weight: 500; }

/* ── Facility Renewal ─────────────────────────────────────────────── */
.cao-renewal-badge {
  display: block; margin-top: 3px; padding: 0; border: none; background: none;
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
  color: #1490A8; cursor: pointer;
}
.cao-renewal-badge:hover { text-decoration: underline; }

.cao-row-actions { display: flex; align-items: center; gap: 2px; }

.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

.cao-renew-current {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
  background: var(--zv-bg-hover); font-size: 0.8125rem;
}
.cao-renew-current-label { color: var(--zv-text-muted); }

.cao-renew-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.9rem 1rem; }
.cao-renew-span-3 { grid-column: span 3; }
.cao-renew-locked .zv-readonly-field { display: flex; align-items: center; gap: 6px; }
.cao-corridor-tag {
  display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 9px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
}

.cao-history-table th, .cao-history-table td { white-space: nowrap; }

@media (max-width: 640px) {
  .cao-renew-grid { grid-template-columns: 1fr 1fr; }
  .cao-renew-span-3 { grid-column: span 2; }
}

/* ── Pagination ────────────────────────────────────────────────── */
.cao-page-btn { text-decoration: none; }
.cao-page-btn-disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }
.cao-page-icon { flex-shrink: 0; }
[dir="rtl"] .cao-page-icon { transform: scaleX(-1); }

/* ════════════════════════════════════════════════════════════════
   MODAL FORM SYSTEM — copied from CleanOverdraftForm <style> block.
   These classes are needed by the modal but CleanOverdraftForm may
   not be loaded when the user lands directly on this index page
   (e.g. after a page reload). They must live here too.
════════════════════════════════════════════════════════════════ */

.form-section {
  background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); overflow: hidden;
}
.form-section-header {
  display: flex; align-items: center; gap: 1rem;
  padding: 1.125rem 1.375rem;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
}
.form-section-icon {
  width: 36px; height: 36px; border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.form-section-title { font-size: 0.9375rem; font-weight: 600; color: var(--zv-text-primary); }
.form-section-sub   { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.form-section-body  { padding: 1.375rem; }

.field-grid   { display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-group  { display: flex; flex-direction: column; gap: 5px; }
.field-group-span-2 { grid-column: span 2; }
.field-hint   { font-size: 0.6875rem; color: var(--zv-text-muted); font-style: italic; }
.field-divider {
  display: flex; align-items: center; gap: 0.75rem;
  font-size: 0.75rem; font-weight: 700; color: var(--zv-text-muted);
  text-transform: uppercase; letter-spacing: 0.08em;
}
.field-divider::before, .field-divider::after { content: ''; flex: 1; height: 1px; background: var(--zv-border); }

.zv-label-required::after { content: ' *'; color: #ef4444; font-weight: 700; }

.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger  { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

.od-rate-summary {
  display: flex; align-items: stretch;
  border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden;
}
.od-rate-item    { flex: 1; padding: 14px 16px; }
.od-rate-divider { width: 1px; background: var(--zv-border); }
.od-rate-label   { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 5px; }
.od-rate-value   { font-size: 17px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--zv-text-primary); }
.od-rate-value--main      { color: #0F2554; }
.od-rate-value--effective { color: #10b981; }
[data-theme="dark"] .od-rate-value--main { color: #90a4c4; }

.od-remove-btn {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
  color: var(--zv-text-muted); transition: all 0.12s;
}
.od-remove-btn:hover:not(:disabled) { background: rgba(239,68,68,0.1); color: #ef4444; }
.od-remove-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.section-slide-enter-active { transition: all 0.25s ease; overflow: hidden; }
.section-slide-leave-active { transition: all 0.20s ease; overflow: hidden; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-8px); max-height: 0; }
.section-slide-enter-to, .section-slide-leave-from { opacity: 1; transform: translateY(0); max-height: 2000px; }

/* ════════════════════════════════════════════════════════════════
   MODAL — uses form-section / field-grid / od-rate-summary above.
════════════════════════════════════════════════════════════════ */

.cao-modal-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(10,18,40,0.6);
  backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem;
}

.cao-modal {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  box-shadow: 0 32px 80px rgba(0,0,0,0.28);
  width: 100%;
  max-width: 860px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Modal header — same weight as form-section-header */
.cao-modal-header {
  display: flex; align-items: center; gap: 1rem;
  padding: 1.125rem 1.375rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
  flex-shrink: 0;
}
.cao-modal-header-icon {
  width: 36px; height: 36px; border-radius: var(--zv-radius-md);
  background: #0F2554;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.cao-modal-header-text { flex: 1; min-width: 0; }
.cao-modal-title { font-size: 0.9375rem; font-weight: 600; color: var(--zv-text-primary); }
.cao-modal-sub   { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.cao-modal-acct  { font-family: monospace; color: var(--zv-text-secondary); }

.cao-modal-close {
  background: transparent; border: none; cursor: pointer;
  color: var(--zv-text-muted); padding: 4px;
  border-radius: var(--zv-radius); transition: color 0.15s;
  flex-shrink: 0;
}
.cao-modal-close:hover { color: var(--zv-text-primary); }

/* Modal body — scrollable, padded */
.cao-modal-body {
  padding: 1.375rem;
  overflow-y: auto;
  flex: 1;
}

/* Loading state */
.cao-modal-loading {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 2rem;
  color: var(--zv-text-muted); font-size: 13.5px;
}

/* Contract info strip — mirrors od-rate-summary layout */
.cao-contract-strip {
  display: flex; align-items: stretch;
  border: 1px solid rgba(20,144,168,0.2);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  background: rgba(20,144,168,0.03);
}
.cao-contract-strip-item { flex: 1; padding: 12px 16px; display: flex; flex-direction: column; gap: 4px; }
.cao-contract-strip-divider { width: 1px; background: rgba(20,144,168,0.15); }
.cao-strip-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.08em; color: var(--zv-text-muted);
}
.cao-strip-val { font-size: 14px; font-weight: 700; color: var(--zv-text-primary); font-variant-numeric: tabular-nums; }
.cao-strip-val--amount { color: #0F2554; font-size: 16px; }
[data-theme="dark"] .cao-strip-val--amount { color: #90a4c4; }

/* Type badges */
.cao-type-badge {
  display: inline-flex; align-items: center;
  padding: 2px 7px; border-radius: 9px;
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
}
.cao-type-badge-sm { padding: 1px 5px; font-size: 9.5px; margin-inline-end: 5px; }
.cao-type-project { background: rgba(15,37,84,0.08); color: #0F2554; }
.cao-type-so      { background: rgba(20,144,168,0.1); color: #1490A8; }
[data-theme="dark"] .cao-type-project { background: rgba(144,164,196,0.12); color: #90a4c4; }

/* Assigned count badge in section header */
.cao-assign-count-badge {
  font-size: 12px; font-weight: 700;
  padding: 2px 9px; border-radius: 12px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-variant-numeric: tabular-nums;
}

/* Currently assigned table — custom grid (no html table for flex control) */
.cao-assign-table-header {
  display: grid;
  grid-template-columns: 28px 1fr 1.6fr 1fr 1.4fr 100px 80px 110px 36px;
  padding: 8px 16px; gap: 10px;
  background: var(--zv-bg-surface);
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--zv-text-muted);
  border-bottom: 1px solid var(--zv-border);
}
.cao-assign-table-row {
  display: grid;
  grid-template-columns: 28px 1fr 1.6fr 1fr 1.4fr 100px 80px 110px 36px;
  padding: 10px 16px; gap: 10px;
  align-items: center;
  border-bottom: 1px solid var(--zv-border);
  font-size: 12.5px;
  transition: background 0.1s;
}
.cao-assign-table-row:last-child { border-bottom: none; }
.cao-assign-table-row:hover { background: var(--zv-bg-surface-alt); }

.cao-row-seq      { color: var(--zv-text-muted); font-size: 11px; font-weight: 600; }
.cao-row-partner  { font-weight: 600; color: var(--zv-text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cao-row-contract { display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--zv-text-secondary); }
.cao-row-amount   { text-align: end; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--zv-text-primary); }
.cao-row-period   { display: flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--zv-text-secondary); }
.cao-period-sep   { color: var(--zv-text-muted); }
.cao-row-date     { font-size: 11.5px; color: var(--zv-text-secondary); }
.cao-row-pct      { text-align: end; font-variant-numeric: tabular-nums; color: var(--zv-text-secondary); }
.cao-row-effective { text-align: end; font-variant-numeric: tabular-nums; font-weight: 700; color: #10b981; }
.cao-row-action   { display: flex; justify-content: center; }

/* Empty state inside modal */
.cao-modal-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 6px; padding: 3rem 1.5rem;
  color: var(--zv-text-muted);
}
.cao-empty-title { font-size: 13.5px; font-weight: 600; color: var(--zv-text-secondary); }
.cao-empty-sub   { font-size: 12px; color: var(--zv-text-muted); text-align: center; max-width: 340px; }

/* Modal footer */
.cao-modal-footer {
  display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;
  padding: 1rem 1.375rem;
  border-top: 1px solid var(--zv-border);
  background: var(--zv-bg-surface);
  flex-shrink: 0;
}

/* Modal transition */
.cao-modal-enter-active, .cao-modal-leave-active { transition: opacity 0.2s ease; }
.cao-modal-enter-from, .cao-modal-leave-to { opacity: 0; }
.cao-modal-enter-active .cao-modal, .cao-modal-leave-active .cao-modal { transition: transform 0.2s ease, opacity 0.2s ease; }
.cao-modal-enter-from .cao-modal, .cao-modal-leave-to .cao-modal { transform: scale(0.97) translateY(8px); opacity: 0; }

/* ── Responsive ────────────────────────────────────────────────── */
@media (max-width: 1200px) {
  .cao-kpi-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
  .cao-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .cao-assign-table-header,
  .cao-assign-table-row { grid-template-columns: 28px 1fr 1fr 80px 36px; }
  .cao-row-period, .cao-row-date, .cao-row-pct { display: none; }
  .cao-contract-strip { flex-direction: column; }
  .cao-contract-strip-divider { width: 100%; height: 1px; }
}
@media (max-width: 640px) {
  .cao-kpi-grid { grid-template-columns: 1fr; }
  .zv-page-header { flex-direction: column; align-items: stretch; }
  .cao-new-wrap .zv-btn { width: 100%; }
  .cao-filter-count { margin-inline-start: 0; }
  .cao-modal-body { padding: 1rem; }
}
</style>