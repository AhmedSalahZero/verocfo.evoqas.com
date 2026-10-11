<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/LgIssuanceIndex.vue

  v2 — Redesigned to match the Clean OD / LG Agreement visual
  standard (Mahmoud flagged the first version as too basic). Same
  KPI-card row, breadcrumb header, and table polish as
  LetterOfGuaranteeIndex.vue (the Agreement index) — this file
  reuses that exact zv-kpi-card pattern rather than inventing a new
  one, for visual consistency across the whole LG feature.

  Individual LG issuances — tabbed by LG TYPE (Bid Bond / Final LG /
  Advanced Payment LG / Performance LG), matching the reference
  image. This is a different axis than issuance_type (facility /
  cash_cover / time_deposit) — an issuance of any type can land
  under any LG Type tab. KPIs are scoped to the ACTIVE tab only,
  same working-context principle as the rest of the app.

  3 create buttons (confirmed — the 4th "LG Against CDs" button in
  the original mockup was disregarded). Lifecycle actions (Renew /
  Decrease / Cancel / Liquidate) open an inline modal — Decrease
  only shows for Advanced Payment LG rows. No hard delete anywhere
  (Rule — no hard deletes on financial data): the trash-style icon
  in the original mockup is Liquidate here, not Delete.

  @layout    AppLayout
  @route     fi.lg-issuance.index
  @props     issuances (paginator), stats, activeLgType, lgTypes,
             filters, bankAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <!-- ══════════════════════════════════════════════════════
           HEADER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.treasury.lgIssuanceIndex.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.treasury.lgIssuanceIndex.letterOfGuaranteeIssuance') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.treasury.lgIssuanceIndex.letterOfGuaranteeIssuance') }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.treasury.lgIssuanceIndex.individualGuaranteesIssuedAgainstYourFacilities') }}</p>
        </div>

        <div class="lgi-create-group">
          <Link :href="route('fi.lg-issuance.create', 'facility')" class="lgi-create-btn lgi-create-navy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            {{ t('financialInstitutions.treasury.lgIssuanceIndex.fromLGFacility') }}
          </Link>
          <Link :href="route('fi.lg-issuance.create', 'time_deposit')" class="lgi-create-btn lgi-create-teal">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ t('financialInstitutions.treasury.lgIssuanceIndex.lGAgainstTDs') }}
          </Link>
          <Link :href="route('fi.lg-issuance.create', 'cash_cover')" class="lgi-create-btn lgi-create-gold">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('financialInstitutions.treasury.lgIssuanceIndex.lG100CashCover') }}
          </Link>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           LG TYPE TABS
      ══════════════════════════════════════════════════════ -->
      <div class="lgi-tabs">
        <Link
          v-for="type in lgTypes"
          :key="type"
          :href="route('fi.lg-issuance.index', type)"
          class="lgi-tab"
          :class="{ 'lgi-tab-active': activeLgType === type }"
        >
          {{ t(type) }}
        </Link>
      </div>

      <!-- ══════════════════════════════════════════════════════
           KPI ROW — scoped to the active tab's LG Type
      ══════════════════════════════════════════════════════ -->
      <div class="lgi-kpi-grid">

        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t(activeLgType) }} {{ t('financialInstitutions.treasury.lgIssuanceIndex.issuances') }}</div>
          <div class="zv-kpi-value">{{ stats.total_count }}</div>
          <div class="zv-kpi-sub">
            <template v-if="stats.total_count === 0">{{ t('financialInstitutions.treasury.lgIssuanceIndex.noneIssuedYet') }}</template>
            <template v-else>{{ stats.running_count }} {{ t('financialInstitutions.treasury.lgIssuanceIndex.running') }}</template>
          </div>
        </div>

        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.outstandingAmount') }}</div>
          <div v-if="stats.by_currency.length === 0" class="zv-kpi-value lgi-kpi-dash">—</div>
          <div v-else class="lgi-currency-stack" :class="{ 'lgi-currency-stack-multi': stats.by_currency.length > 1 }">
            <div v-for="c in stats.by_currency" :key="'out-' + c.currency_code" class="lgi-currency-line">
              <span class="lgi-currency-code">{{ c.currency_code }}</span>
              <span class="lgi-currency-value zv-num">{{ fmt(c.total_outstanding) }}</span>
            </div>
          </div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lgIssuanceIndex.runningIssuancesOnly') }}</div>
        </div>

        <div class="zv-kpi-card" :class="{ 'lgi-kpi-warn-card': stats.near_expiry_count > 0 }">
          <div class="zv-kpi-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.nearExpiry') }}</div>
          <div class="zv-kpi-value" :class="{ 'lgi-kpi-warn-value': stats.near_expiry_count > 0 }">
            {{ stats.near_expiry_count }}
          </div>
          <div class="zv-kpi-sub">
            <span v-if="stats.near_expiry_count > 0" class="zv-kpi-delta zv-kpi-delta-warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
              </svg>
              {{ t('financialInstitutions.treasury.lgIssuanceIndex.within30Days') }}
            </span>
            <template v-else>{{ t('financialInstitutions.treasury.lgIssuanceIndex.noneDueSoon') }}</template>
          </div>
        </div>

        <div class="zv-kpi-card">
          <div class="zv-kpi-label">{{ t('common.showing') }}</div>
          <div class="zv-kpi-value">{{ issuances.total }}</div>
          <div class="zv-kpi-sub">{{ t('financialInstitutions.treasury.lgIssuanceIndex.matchingCurrentFilters') }}</div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           MAIN CARD
      ══════════════════════════════════════════════════════ -->
      <div class="zv-card lgi-main-card">

        <div class="zv-filter-bar">
          <label class="lgi-filter-label">{{ t('common.startDate') }}</label>
          <input v-model="startDate" type="date" class="zv-input lgi-date-input"/>
          <label class="lgi-filter-label">{{ t('common.endDate') }}</label>
          <input v-model="endDate" type="date" class="zv-input lgi-date-input"/>
          <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" @click="applyFilters">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
            </svg>
            {{ t('common.search') }}
          </button>
          <button v-if="startDate || endDate" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="clearFilters">
            {{ t('financialInstitutions.treasury.lgIssuanceIndex.clearFilters') }}
          </button>
        </div>

        <!-- Empty state -->
        <div v-if="issuances.data.length === 0" class="zv-empty">
          <div class="zv-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-10 h-10">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <div class="zv-empty-title">{{ t('common.no') }} {{ t(activeLgType) }} {{ t('financialInstitutions.treasury.lgIssuanceIndex.issuancesFound') }}</div>
          <div class="zv-empty-sub">{{ t('financialInstitutions.treasury.lgIssuanceIndex.useOneOfTheButtonsAbove') }}</div>
        </div>

        <!-- Table -->
        <div v-else class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.transactionName') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.beneficiary') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.source') }}</th>
                <th>{{ t('common.status') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.bank') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.lGCode') }}</th>
                <th class="lgi-th-num">{{ t('financialInstitutions.treasury.lgIssuanceIndex.lGAmount') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.issuanceDate') }}</th>
                <th>{{ t('financialInstitutions.treasury.lgIssuanceIndex.renewalDate') }}</th>
                <th class="lgi-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in issuances.data" :key="row.id" class="lgi-row">
                <td class="lgi-muted">{{ issuances.from + idx }}</td>
                <td class="lgi-name-cell">{{ row.transaction_name }}</td>
                <td>{{ row.beneficiary?.name_en }}</td>
                <td><span class="lgi-source-badge" :class="sourceBadgeClass(row.issuance_type)">{{ t(sourceLabel(row.issuance_type)) }}</span></td>
                <td>
                  <span class="zv-badge" :class="statusMeta(row).badge">
                    <span class="zv-status-dot" :class="statusMeta(row).dot"></span>
                    {{ t(statusMeta(row).label) }}
                  </span>
                  <span v-if="row.posting_status === 'draft'" class="zv-badge zv-badge-warning" :title="t('financialInstitutions.treasury.lgIssuanceIndex.journalEntryReversedReopenedForEditing')">
                    {{ t('financialInstitutions.treasury.lgIssuanceIndex.draft') }}
                  </span>
                  <span v-if="row.renewed_at" class="lgi-renewed-tag" :title="t('financialInstitutions.treasury.lgIssuanceIndex.renewed') + ' ' + fmtDate(row.renewed_at)">
                    {{ t('financialInstitutions.treasury.lgIssuanceIndex.renewed') }}
                  </span>
                </td>
                <td class="lgi-muted">{{ row.bank?.name_en }}</td>
                <td class="lgi-muted">{{ row.lg_code || '—' }}</td>
                <td class="zv-table-num">{{ row.currency?.code }} {{ fmt(row.lg_amount) }}</td>
                <td class="lgi-muted">{{ fmtDate(row.issuance_date) }}</td>
                <td>
                  <span class="lgi-renewal" :class="expiryClass(row)">
                    <span class="zv-status-dot" :class="expiryDotClass(row)"></span>
                    {{ fmtDate(row.end_date) }}
                  </span>
                </td>
                <td class="lgi-th-actions">
                  <div class="lgi-row-actions">
                    <Link :href="route('fi.lg-issuance.edit', row.id)" class="lgi-icon-btn" :title="t('common.edit')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </Link>
                    <button v-if="row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lgIssuanceIndex.renew')" @click="openModal('renew', row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                      </svg>
                    </button>
                    <button v-if="row.lg_type === 'Advanced Payment LG' && row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn" :title="t('financialInstitutions.treasury.lgIssuanceIndex.decrease')" @click="openModal('decrease', row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                      </svg>
                    </button>
                    <button v-if="row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn lgi-icon-warn" :title="t('common.cancel')" @click="openModal('cancel', row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                      </svg>
                    </button>
                    <button v-if="row.status === 'running' && row.posting_status !== 'draft'" type="button" class="lgi-icon-btn lgi-icon-danger" :title="t('financialInstitutions.treasury.lgIssuanceIndex.liquidate')" @click="openModal('liquidate', row)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="issuances.data.length > 0" class="zv-pagination">
          <span>{{ t('common.showing') }} {{ issuances.from }}–{{ issuances.to }} {{ t('common.of') }} {{ issuances.total }}</span>
          <div class="zv-pagination-pages">
            <template v-for="(link, idx) in issuances.links" :key="idx">
              <component
                :is="link.url ? Link : 'span'"
                :href="link.url || undefined"
                class="zv-page-btn"
                :class="{ active: link.active, 'lgi-page-btn-disabled': !link.url }"
              >
                <svg v-if="link.label.includes('Previous')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                <svg v-else-if="link.label.includes('Next')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span v-else>{{ link.label }}</span>
              </component>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ Action Modals ══════════════════════════ -->
    <Teleport to="body">
      <div v-if="modal.type" class="lgi-modal-overlay" @click.self="closeModal">
        <div class="lgi-modal">
          <div class="lgi-modal-body">

          <template v-if="modal.type === 'renew'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lgIssuanceIndex.renewLG') }} — {{ modal.row.lg_code }}</h3>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.transactionDate') }}</label>
              <input v-model="modalForm.transaction_date" type="date" class="zv-input"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.transactionDateHint') }}</p>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.newEndDate') }}</label>
              <input v-model="modalForm.new_end_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.renewalFee') }}</label>
              <input v-model="modalForm.renewal_fee_amount" type="number" step="0.01" class="zv-input"/>
            </div>

            <!-- Cash Cover rate — only shown when this issuance actually
                 carries a cash cover requirement (facility/cash_cover
                 types). Only the INCREMENTAL delta between the old and
                 new cash cover amount posts — the original placement is
                 already sitting in 138001. Leave blank for no change. -->
            <div v-if="modal.row.cash_cover_pct !== null" class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.newCashCoverPct') }}</label>
              <input v-model="modalForm.cash_cover_rate_pct" type="number" step="0.01" min="0" max="100" class="zv-input" :placeholder="t('financialInstitutions.treasury.lgIssuanceIndex.currentRate') + ': ' + modal.row.cash_cover_pct + '%'"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.cashCoverRenewalHint') }}</p>
            </div>

            <!-- Commission rate — applies to every issuance type. New
                 rate takes effect from the renewal date forward only;
                 nothing before this date is recalculated. Leave blank
                 for no change. -->
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.newCommissionRatePct') }}</label>
              <input v-model="modalForm.commission_rate_pct" type="number" step="0.0001" min="0" class="zv-input" :placeholder="t('financialInstitutions.treasury.lgIssuanceIndex.currentRate') + ': ' + modal.row.commission_rate_pct + '%'"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.commissionRenewalHint') }}</p>
            </div>

            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'decrease'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lgIssuanceIndex.decreaseLGAmount') }} — {{ modal.row.lg_code }}</h3>
            <p class="lgi-modal-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.currentOutstanding') }}: {{ modal.row.currency?.code }} {{ fmt(modal.row.current_amount) }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.decreaseAmount') }}</label>
              <input v-model="modalForm.decrease_amount" type="number" step="0.01" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.eventDate') }}</label>
              <input v-model="modalForm.event_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'cancel'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lgIssuanceIndex.cancelLG') }} — {{ modal.row.lg_code }}</h3>
            <p class="lgi-modal-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.cashCoverIfAnyWillBe') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.cancellationDate') }}</label>
              <input v-model="modalForm.cancelled_at" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.reversalAccount') }}</label>
              <select v-model="modalForm.cancelled_reversal_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lgIssuanceIndex.sameAsCashCoverAccount') }}</option>
                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'liquidate'">
            <h3 class="lgi-modal-title">{{ t('financialInstitutions.treasury.lgIssuanceIndex.liquidateLG') }} — {{ modal.row.lg_code }}</h3>
            <p class="lgi-modal-hint">{{ t('financialInstitutions.treasury.lgIssuanceIndex.cashCoverHeldIsConsumedFirst') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.liquidationDate') }}</label>
              <input v-model="modalForm.liquidated_at" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.claimAmount') }}</label>
              <input v-model="modalForm.claim_amount" type="number" step="0.01" class="zv-input" :placeholder="modal.row.current_amount"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceIndex.expenseLossAccount') }} <span class="lgi-modal-hint-inline">({{ t('financialInstitutions.treasury.lgIssuanceIndex.theClaimIsBookedHere') }})</span></label>
              <select v-model="modalForm.liquidation_expense_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lgIssuanceIndex.select') }}</option>
                <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_en }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.shortfallAccount') }} <span class="lgi-modal-hint-inline">({{ t('financialInstitutions.treasury.lgIssuanceIndex.onlyIfClaimExceedsCashCover') }})</span></label>
              <select v-model="modalForm.liquidation_shortfall_account_id" class="zv-select">
                <option value="">{{ t('financialInstitutions.treasury.lgIssuanceIndex.select') }}</option>
                <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceIndex.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          </div>

          <div class="lgi-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModal">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="modalSubmitting" @click="submitModal">
              {{ modalSubmitting ? t('common.saving') : t('common.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  issuances:    { type: Object, required: true },
  stats: {
    type: Object,
    default: () => ({ total_count: 0, running_count: 0, by_currency: [], near_expiry_count: 0 }),
  },
  activeLgType: { type: String, required: true },
  lgTypes:      { type: Array, default: () => [] },
  filters:      { type: Object, default: () => ({}) },
  bankAccounts: { type: Array, default: () => [] },
  expenseAccounts: { type: Array, default: () => [] }, // Fix (July 2026 audit) — Liquidate modal's expense/loss account picker
})

// ── Filters ───────────────────────────────────────────────────────

const startDate = ref(props.filters?.start_date ?? '')
const endDate   = ref(props.filters?.end_date ?? '')

function applyFilters() {
  router.get(route('fi.lg-issuance.index', props.activeLgType), {
    start_date: startDate.value || undefined,
    end_date: endDate.value || undefined,
  }, { preserveState: true, preserveScroll: true })
}

function clearFilters() {
  startDate.value = ''
  endDate.value = ''
  applyFilters()
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
function sourceLabel(issuanceType) {
  return { facility: 'LG Facility', cash_cover: '100% Cash Cover', time_deposit: 'Time Deposit' }[issuanceType] ?? issuanceType
}
function sourceBadgeClass(issuanceType) {
  return { facility: 'lgi-source-navy', cash_cover: 'lgi-source-gold', time_deposit: 'lgi-source-teal' }[issuanceType] ?? ''
}
function statusMeta(row) {
  if (row.status === 'cancelled' && row.liquidated_at) return { label: 'Liquidated', badge: 'zv-badge-danger', dot: 'zv-dot-danger' }
  if (row.status === 'cancelled') return { label: 'Cancelled', badge: 'zv-badge-muted', dot: 'zv-dot-muted' }
  if (row.status === 'expired')  return { label: 'Expired', badge: 'zv-badge-danger', dot: 'zv-dot-danger' }
  return { label: 'Running', badge: 'zv-badge-success', dot: 'zv-dot-ok' }
}
function daysUntil(dateStr) {
  if (!dateStr) return null
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
}
function expiryClass(row) {
  const d = daysUntil(row.end_date)
  if (row.status !== 'running' || d === null) return ''
  if (d < 0) return 'lgi-expiry-overdue'
  if (d <= 30) return 'lgi-expiry-warn'
  return ''
}
function expiryDotClass(row) {
  const d = daysUntil(row.end_date)
  if (row.status !== 'running' || d === null) return 'zv-dot-muted'
  if (d < 0) return 'zv-dot-danger'
  if (d <= 30) return 'zv-dot-warn'
  return 'zv-dot-ok'
}

// ── Action modals ────────────────────────────────────────────────

const modal = ref({ type: null, row: null })
const modalForm = ref({})
const modalSubmitting = ref(false)

function addDays(dateStr, days) {
  // UTC-safe: avoids the off-by-one-day bug that plain new Date(str)
  // + setDate() can cause depending on the browser's local timezone.
  const [y, m, d] = dateStr.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d))
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

function openModal(type, row) {
  modal.value = { type, row }
  modalForm.value = {
    // Default: the day right after the LG's current end date — a
    // renewal is very often processed today for an LG that actually
    // expired weeks ago, so "today" is the wrong default here. User
    // can still change it to any date they want.
    transaction_date: type === 'renew' ? addDays(row.end_date, 1) : '',
    new_end_date: '', renewal_fee_amount: '', notes: '',
    cash_cover_rate_pct: '', commission_rate_pct: '',
    decrease_amount: '', event_date: todayLocal(),
    cancelled_at: todayLocal(), cancelled_reversal_account_id: '',
    liquidated_at: todayLocal(), claim_amount: '',
    liquidation_expense_account_id: '', liquidation_shortfall_account_id: '',
  }
}

function closeModal() {
  modal.value = { type: null, row: null }
}

function submitModal() {
  modalSubmitting.value = true
  const routeName = { renew: 'fi.lg-issuance.renew', decrease: 'fi.lg-issuance.decrease', cancel: 'fi.lg-issuance.cancel', liquidate: 'fi.lg-issuance.liquidate' }[modal.value.type]

  router.post(route(routeName, modal.value.row.id), modalForm.value, {
    preserveScroll: true,
    onFinish: () => { modalSubmitting.value = false; closeModal() },
  })
}
</script>

<style>
/* ── Create buttons — color-coded per issuance type ─────────────── */
.lgi-create-group { display: flex; gap: 8px; flex-wrap: wrap; }
.lgi-create-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border-radius: var(--zv-radius); font-size: 12.5px; font-weight: 600;
  border: 1px solid transparent; transition: all 0.15s; white-space: nowrap;
}
.lgi-create-navy { background: rgba(15,37,84,0.07); border-color: rgba(15,37,84,0.18); color: #0F2554; }
.lgi-create-navy:hover { background: rgba(15,37,84,0.13); }
[data-theme="dark"] .lgi-create-navy { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); color: #90a4c4; }
.lgi-create-teal { background: rgba(20,144,168,0.08); border-color: rgba(20,144,168,0.2); color: #1490A8; }
.lgi-create-teal:hover { background: rgba(20,144,168,0.14); }
.lgi-create-gold { background: #FFBF00; border-color: #FFBF00; color: #0F2554; }
.lgi-create-gold:hover { background: #e6ac00; }

/* ── Tabs ─────────────────────────────────────────────────────────── */
.lgi-tabs { display: flex; gap: 4px; margin-bottom: 1.25rem; border-bottom: 1px solid var(--zv-border); }
.lgi-tab {
  padding: 10px 18px; font-size: 13px; font-weight: 600; color: var(--zv-text-muted);
  border-bottom: 2px solid transparent; transition: all 0.15s;
}
.lgi-tab:hover { color: var(--zv-text-primary); }
.lgi-tab-active { color: #0F2554; border-bottom-color: #FFBF00; }
[data-theme="dark"] .lgi-tab-active { color: #90a4c4; }

/* ── KPI grid — same tokens as LetterOfGuaranteeIndex.vue ────────── */
.lgi-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .lgi-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lgi-kpi-grid { grid-template-columns: 1fr; } }

.zv-kpi-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 16px 18px; box-shadow: var(--zv-shadow-sm); }
.zv-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 8px; }
.zv-kpi-value { font-size: 24px; font-weight: 800; color: var(--zv-text-primary); font-variant-numeric: tabular-nums; }
.zv-kpi-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 6px; }
.zv-kpi-delta { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.zv-kpi-delta-warn { color: #f59e0b; }
.lgi-kpi-dash { color: var(--zv-text-muted); }
.lgi-kpi-warn-card { border-color: rgba(245,158,11,0.35); }
.lgi-kpi-warn-value { color: #f59e0b; }

.lgi-currency-stack { display: flex; flex-direction: column; gap: 4px; }
.lgi-currency-line { display: flex; align-items: baseline; gap: 8px; }
.lgi-currency-code { font-size: 11px; font-weight: 700; color: var(--zv-text-muted); min-width: 32px; }
.lgi-currency-value { font-size: 18px; font-weight: 800; color: var(--zv-text-primary); }
.lgi-currency-stack-multi .lgi-currency-value { font-size: 15px; }

/* ── Filter bar ───────────────────────────────────────────────────── */
.zv-filter-bar { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); flex-wrap: wrap; }
.lgi-filter-label { font-size: 12px; font-weight: 600; color: var(--zv-text-muted); }
.lgi-date-input { max-width: 160px; }

/* ── Table ────────────────────────────────────────────────────────── */
.lgi-muted { color: var(--zv-text-muted); }
.lgi-name-cell { font-weight: 600; color: var(--zv-text-primary); }
.lgi-th-num { text-align: end; }
.lgi-th-actions { width: 160px; }
.zv-table-num { text-align: end; font-variant-numeric: tabular-nums; }
.lgi-row:hover { background: var(--zv-bg-surface-alt); }

.lgi-source-badge { display: inline-flex; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.lgi-source-navy { background: rgba(15,37,84,0.08); color: #0F2554; }
[data-theme="dark"] .lgi-source-navy { background: rgba(255,255,255,0.08); color: #90a4c4; }
.lgi-source-teal { background: rgba(20,144,168,0.1); color: #1490A8; }
.lgi-source-gold { background: rgba(255,191,0,0.15); color: #b8860b; }

.lgi-renewed-tag { display: inline-flex; margin-inline-start: 6px; padding: 2px 7px; border-radius: 10px; background: rgba(255,191,0,0.15); color: #b8860b; font-size: 10px; font-weight: 700; text-transform: uppercase; }

.lgi-renewal { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
.lgi-expiry-warn { color: #f59e0b; font-weight: 600; }
.lgi-expiry-overdue { color: #ef4444; font-weight: 700; }

.zv-status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.zv-dot-ok { background: #10b981; } .zv-dot-warn { background: #f59e0b; } .zv-dot-danger { background: #ef4444; } .zv-dot-muted { background: var(--zv-text-muted); }

.zv-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.zv-badge-success { background: rgba(16,185,129,0.1); color: #10b981; }
.zv-badge-danger  { background: rgba(239,68,68,0.1);  color: #ef4444; }
.zv-badge-muted   { background: rgba(100,116,139,0.1); color: #64748b; }
.zv-badge-warning { background: rgba(245,158,11,0.12); color: #b45309; margin-inline-start: 6px; }

.lgi-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
.lgi-icon-btn {
  display: flex; align-items: center; justify-content: center; width: 28px; height: 28px;
  border-radius: 6px; border: none; background: transparent; cursor: pointer; color: var(--zv-text-muted); transition: all 0.12s;
}
.lgi-icon-btn:hover { background: var(--zv-bg-surface-alt); color: #1490A8; }
.lgi-icon-warn:hover { color: #f59e0b; }
.lgi-icon-danger:hover { color: #ef4444; }

/* ── Empty state ──────────────────────────────────────────────────── */
.zv-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 24px; text-align: center; }
.zv-empty-icon { color: var(--zv-text-muted); opacity: 0.4; margin-bottom: 14px; }
.zv-empty-title { font-size: 15px; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 6px; }
.zv-empty-sub { font-size: 13px; color: var(--zv-text-muted); max-width: 380px; }

/* ── Pagination ───────────────────────────────────────────────────── */
.zv-pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px solid var(--zv-border); font-size: 12.5px; color: var(--zv-text-muted); }
.zv-pagination-pages { display: flex; align-items: center; gap: 4px; }
.zv-page-btn { display: flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 8px; border-radius: var(--zv-radius); font-size: 12.5px; font-weight: 600; color: var(--zv-text-primary); transition: all 0.1s; }
.zv-page-btn:hover:not(.lgi-page-btn-disabled) { background: var(--zv-bg-surface-alt); }
.zv-page-btn.active { background: #0F2554; color: #fff; }
.lgi-page-btn-disabled { opacity: 0.35; pointer-events: none; }

/* ── Modal ────────────────────────────────────────────────────────── */
.lgi-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2500; display: flex; align-items: center; justify-content: center; padding: 24px 12px; overflow-y: auto; }
.lgi-modal { width: 420px; max-width: 92vw; max-height: calc(100vh - 48px); display: flex; flex-direction: column; background: var(--zv-bg-card); border-radius: var(--zv-radius-lg); border: 1px solid var(--zv-border); box-shadow: var(--zv-shadow-lg); padding: 20px; }
.lgi-modal-body { overflow-y: auto; padding-right: 4px; }
.lgi-modal-title { font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--zv-text-primary); }
.lgi-modal-hint { font-size: 12.5px; color: var(--zv-text-muted); margin-bottom: 14px; }
.lgi-modal-hint-inline { font-weight: 400; text-transform: none; letter-spacing: 0; font-size: 11px; }
.lgi-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; padding-top: 14px; border-top: 1px solid var(--zv-border); flex-shrink: 0; }
</style>