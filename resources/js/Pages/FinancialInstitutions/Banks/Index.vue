<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Index.vue

  Lists all Banks for the active company.
  Shows bank name, branch, contact, account count, facility count.

  Actions per bank row:
    1. Edit button
    2. Toggle active/inactive button
    3. Debit Accounts dropdown → Current Account / Time Deposit
    4. Credit Facilities dropdown → all 7 facility types

  Page-header dropdowns (v1.1):
    "Credit Facilities" and "Bank Accounts" — same look as the
    per-row dropdowns, but scoped to ALL banks instead of one.
    Clean Overdraft, OD Against Commercial Paper, and the two
    account types go to their own dedicated pages. The remaining
    4 facility types go to the existing generic facilities page
    (fi.facilities.index), pre-filtered by type — that page
    already exists and already supports this filter, it just
    didn't have a menu pointing at it yet. Fully Secured
    Overdraft is shown disabled ("Coming soon") — it has no
    matching value in facility_type yet, so there's nowhere for
    it to go until that's defined.

  Bug fixes (v1.1):
    - Per-row "Debit Accounts" links sent ?type=current /
      ?type=time_deposit, but the page reads ?account_type=
      — so that filter never actually applied. Corrected the
      parameter name in both links (here and in the new
      header dropdown).
    - Per-row "Clean Overdraft" link went straight to "create a
      new one" every time, with no way to see existing facilities
      for that bank first. Now points at the bank-scoped
      listing page (fi.banks.clean-od.index), which has its own
      "+ New Facility" button.

  v1.2 — OD Against Commercial Paper graduated from the generic
    placeholder to its own dedicated build (mirroring Clean
    Overdraft): per-row item now points at
    fi.banks.commercial-paper-od.index (was: generic create with
    ?type=commercial_paper), and the page-header item now points
    at fi.facilities.commercial-paper-od.index (was: generic
    fi.facilities.index?facility_type=commercial_paper). The
    remaining 4 facility types are untouched — still pointing at
    the generic placeholder until each gets the same treatment.

  v1.3 — OD Against Contract Assignment graduated from the generic
    placeholder to its own dedicated build (same pattern as Clean
    Overdraft v1.1 and Commercial Paper v1.2):
    - Per-row item now points at fi.banks.contract-od.index
      (was: fi.facilities.create?type=contract_so&bank_id=X)
    - Page-header item now points at fi.facilities.contract-od.index
      (was: fi.facilities.index?facility_type=contract_so)
    The remaining facility types (LG, LC, MTL) are untouched —
    still pointing at the generic placeholder until each gets
    its own dedicated build.

  v1.4 — Letter of Guarantee graduated from the generic placeholder
    to its own dedicated AGREEMENT-level build (same pattern as
    Contract Assignment v1.3):
    - Per-row item now points at fi.banks.lg.index, bank.id
      (was: fi.facilities.create?type=letter_guarantee&bank_id=X)
    - Page-header item now points at fi.facilities.lg.index
      (was: fi.facilities.index?facility_type=letter_guarantee)
    This is an agreement + rate-card build (LG types: Bid Bond,
    Final LG, Advanced Payment LG, Performance LG) — individual LG
    issuance (beneficiary, amount, expiry) is a separate future
    layer, not yet built. The remaining facility types (LC, MTL)
    are untouched — still pointing at the generic placeholder.

  v1.5 — Letter of Credit graduated the same way. The page-header
    item was already pointing at fi.facilities.lc.index from an
    earlier pass — only the per-row item needed fixing, from the
    generic fi.facilities.create?type=import_lc&bank_id=X to the
    dedicated fi.banks.lc.index, bank.id. MTL is untouched — still
    on the generic placeholder.

  v1.6 — Medium Term Loan graduated. Both the page-header item
    (fi.facilities.index?facility_type=mtl → fi.facilities.mtl.index)
    and the per-row item (fi.facilities.create?type=mtl&bank_id=X →
    fi.banks.mtl.index, bank.id) fixed. Also corrected the per-row
    description text — it previously said "Fixed amortization
    schedule with interest", which is no longer accurate: MTL's
    Loan Schedule is entered manually or via Excel upload, with NO
    auto-calculated amortization (confirmed with Mahmoud, same
    mechanism as Leasing's cheque schedule).

  v1.7 — Fully Secured Overdraft graduated from its "Coming Soon"
    placeholder (both the page-header item and the per-row item) to
    a real link, now that facility_type = 'fully_secured' actually
    exists and has its own Controller/Form/Index built. Same pattern
    as MTL's v1.6 graduation above — nothing else on this page
    changed.

  Dropdowns close on outside click (click-away) and on item selection.
  All four dropdowns (2 per-row, 2 page-header) share one
  "only one open at a time" state model — opening any of them
  closes the other three.

  @layout    AppLayout
  @route     fi.banks.index
  @props     banks: PaginatedCollection, filters: Object
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-header">
                <div class="fi-header-left">
                    <div class="fi-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="fi-title">{{ t('financialInstitutions.banks.index.banks') }}</h1>
                        <p class="fi-subtitle">{{ t('financialInstitutions.banks.index.manageYourBankingRelationshipsAndCredit') }}</p>
                    </div>
                </div>
                <div class="fi-header-actions">

                    <!-- ── Credit Facilities (all banks) ───────── -->
                    <div class="fi-dropdown-wrap">
                        <button
                            type="button"
                            class="fi-split-btn fi-split-navy"
                            :title="t('financialInstitutions.banks.index.creditFacilities')"
                            @click.stop="toggleHeaderFacilities"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                            <span class="fi-split-label">{{ t('financialInstitutions.banks.index.creditFacilities') }}</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3 fi-chevron" :class="{ 'fi-chevron-up': showHeaderFacilities }">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <Transition name="fi-drop">
                            <div v-if="showHeaderFacilities" class="fi-dropdown-menu fi-dropdown-wide">
                                <div class="fi-dropdown-group-label">{{ t('financialInstitutions.banks.index.allBanksCreditFacilities') }}</div>

                                <Link :href="route('fi.facilities.clean-od.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-blue">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.cleanOverdraft') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.facilities.fully-secured-od.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-blue">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.fullySecuredOverdraft') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.oDSecuredByCashOrFixed') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.facilities.commercial-paper-od.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-blue">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.oDAgainstCommercialPaper') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.facilities.contract-od.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-blue">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.oDAgainstContractAssignment') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <div class="fi-dropdown-divider"></div>

                                <Link :href="route('fi.facilities.lg.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-gold">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.letterOfGuaranteeFacility') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.facilities.lc.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-gold">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.letterOfCreditFacility') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.facilities.mtl.index')" class="fi-dropdown-item" @click="showHeaderFacilities = false">
                                    <div class="fi-item-icon fi-icon-navy">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.mediumTermLoan') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>
                            </div>
                        </Transition>
                    </div>

                    <!-- ── Bank Accounts (all banks) ───────────── -->
                    <div class="fi-dropdown-wrap">
                        <button
                            type="button"
                            class="fi-split-btn fi-split-blue"
                            :title="t('financialInstitutions.banks.index.bankAccounts')"
                            @click.stop="toggleHeaderAccounts"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                            <span class="fi-split-label">{{ t('financialInstitutions.banks.index.bankAccounts') }}</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3 fi-chevron" :class="{ 'fi-chevron-up': showHeaderAccounts }">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <Transition name="fi-drop">
                            <div v-if="showHeaderAccounts" class="fi-dropdown-menu">
                                <div class="fi-dropdown-group-label">{{ t('financialInstitutions.banks.index.allBanksAccounts') }}</div>

                                <Link :href="route('fi.bank-accounts.index') + '?account_type=current'" class="fi-dropdown-item" @click="showHeaderAccounts = false">
                                    <div class="fi-item-icon fi-icon-teal">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.currentAccount') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>

                                <Link :href="route('fi.bank-accounts.index') + '?account_type=time_deposit'" class="fi-dropdown-item" @click="showHeaderAccounts = false">
                                    <div class="fi-item-icon fi-icon-gold">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.timeOfDeposit') }}</div>
                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.everyBankOneList') }}</div>
                                    </div>
                                </Link>
                            </div>
                        </Transition>
                    </div>

                    <Link :href="route('fi.banks.create')" class="fi-btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ t('financialInstitutions.banks.index.addBank') }}
                    </Link>
                </div>
            </div>

            <!-- Search -->
            <div class="fi-filters">
                <div class="fi-search-wrap">
                    <svg class="fi-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
                    </svg>
                    <input v-model="search" type="text" :placeholder="t('financialInstitutions.banks.index.searchBanks')" class="fi-search" @input="doSearch"/>
                </div>
            </div>

            <!-- Table -->
            <div class="fi-card">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>{{ t('financialInstitutions.banks.index.bankName') }}</th>
                            <th>{{ t('financialInstitutions.banks.index.branch') }}</th>
                            <th>{{ t('financialInstitutions.banks.index.contact') }}</th>
                            <th>{{ t('financialInstitutions.banks.index.phone') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.banks.index.accounts') }}</th>
                            <th class="text-center">{{ t('financialInstitutions.banks.index.facilities') }}</th>
                            <th class="text-center">{{ t('common.status') }}</th>
                            <th class="text-end">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="banks.data.length === 0">
                            <td colspan="8" class="fi-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="w-12 h-12 opacity-20 mb-3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                                </svg>
                                <p class="text-sm opacity-50">{{ t('financialInstitutions.banks.index.noBanksAddedYetAddYour') }}</p>
                            </td>
                        </tr>

                        <tr v-for="bank in banks.data" :key="bank.id" class="fi-row">
                            <!-- Bank Name -->
                            <td>
                                <div class="fi-name-primary">{{ bank.name_en }}</div>
                                <div v-if="bank.name_ar" class="fi-name-ar">{{ bank.name_ar }}</div>
                                <div v-if="bank.has_functional_current_account === false" class="fi-no-functional-warning"
                                     :title="t('financialInstitutions.banks.index.noActiveCurrentAccountInThe')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                    </svg>
                                    {{ t('financialInstitutions.banks.index.noFunctionalCurrencyCurrentAccount') }}
                                </div>
                            </td>

                            <!-- Branch -->
                            <td class="fi-muted">{{ bank.branch_name || '—' }}</td>

                            <!-- Contact -->
                            <td class="fi-muted">{{ bank.contact_person || '—' }}</td>

                            <!-- Phone -->
                            <td class="fi-muted">{{ bank.phone || '—' }}</td>

                            <!-- Account count -->
                            <td class="text-center">
                                <span class="fi-count-badge fi-count-blue">{{ bank.accounts_count ?? 0 }}</span>
                            </td>

                            <!-- Facility count -->
                            <td class="text-center">
                                <span class="fi-count-badge fi-count-gold">{{ bank.credit_facilities_count ?? 0 }}</span>
                            </td>

                            <!-- Status -->
                            <td class="text-center">
                                <span class="fi-status-badge" :class="bank.is_active ? 'fi-active' : 'fi-inactive'">
                                    {{ bank.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="text-end">
                                <div class="fi-actions">

                                    <!-- Edit -->
                                    <Link
                                        :href="route('fi.banks.edit', bank.id)"
                                        class="fi-action-btn"
                                        :title="t('financialInstitutions.banks.index.editBank')"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </Link>

                                    <!-- Toggle active -->
                                    <button
                                        class="fi-action-btn"
                                        :class="bank.is_active ? 'fi-action-deactivate' : 'fi-action-activate'"
                                        :title="bank.is_active ? t('common.deactivate') : t('common.activate')"
                                        @click="toggle(bank)"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                            <path v-if="bank.is_active" stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>

                                    <!-- ── Debit Accounts dropdown ─────────────── -->
                                    <div class="fi-dropdown-wrap" ref="debitRefs">
                                        <button
                                            type="button"
                                            class="fi-split-btn fi-split-blue"
                                            :title="t('financialInstitutions.banks.index.debitAccounts')"
                                            @click.stop="toggleDebit(bank.id)"
                                        >
                                            <!-- Bank card icon -->
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                            </svg>
                                            <span class="fi-split-label">{{ t('financialInstitutions.banks.index.debitAccounts') }}</span>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3 fi-chevron" :class="{ 'fi-chevron-up': openDebit === bank.id }">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>

                                        <!-- Debit dropdown menu -->
                                        <Transition name="fi-drop">
                                            <div v-if="openDebit === bank.id" class="fi-dropdown-menu">
                                                <div class="fi-dropdown-group-label">{{ t('financialInstitutions.banks.index.debitAccounts') }}</div>

                                                <Link
                                                    :href="route('fi.bank-accounts.index') + '?bank_id=' + bank.id + '&account_type=current'"
                                                    class="fi-dropdown-item"
                                                    @click="openDebit = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-teal">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.currentAccount') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.operatingAccountsInAnyCurrency') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.bank-accounts.index') + '?bank_id=' + bank.id + '&account_type=time_deposit'"
                                                    class="fi-dropdown-item"
                                                    @click="openDebit = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-gold">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.timeOfDeposit') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.fixedTermDepositsWithMaturityTracking') }}</div>
                                                    </div>
                                                </Link>

                                            </div>
                                        </Transition>
                                    </div>

                                    <!-- ── Credit Facilities dropdown ──────────── -->
                                    <div class="fi-dropdown-wrap">
                                        <button
                                            type="button"
                                            class="fi-split-btn fi-split-navy"
                                            :title="t('financialInstitutions.banks.index.creditFacilities')"
                                            @click.stop="toggleFacility(bank.id)"
                                        >
                                            <!-- Chart bars icon -->
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                            </svg>
                                            <span class="fi-split-label">{{ t('financialInstitutions.banks.index.creditFacilities') }}</span>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3 h-3 fi-chevron" :class="{ 'fi-chevron-up': openFacility === bank.id }">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>

                                        <!-- Credit Facilities dropdown menu -->
                                        <Transition name="fi-drop">
                                            <div v-if="openFacility === bank.id" class="fi-dropdown-menu fi-dropdown-wide">
                                                <div class="fi-dropdown-group-label">{{ t('financialInstitutions.banks.index.creditFacilities') }}</div>

                                                <Link
                                                    :href="route('fi.banks.clean-od.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-blue">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.cleanOverdraft') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.unsecuredRevolvingCreditLine') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.banks.fully-secured-od.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-blue">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.fullySecuredOverdraft') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.oDSecuredByCashOrFixed') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.banks.commercial-paper-od.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-blue">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.oDAgainstCommercialPaper') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.securedByPromissoryNotesOrCheques') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.banks.contract-od.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-blue">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.oDAgainstContractAssignment') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.securedByAnActiveProjectOr') }}</div>
                                                    </div>
                                                </Link>

                                                <div class="fi-dropdown-divider"></div>

                                                <Link
                                                    :href="route('fi.banks.lg.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-gold">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.letterOfGuaranteeFacility') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.contingentLiabilityNoCashDisbursement') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.banks.lc.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-gold">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.letterOfCreditFacility') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.importDocumentaryCredit') }}</div>
                                                    </div>
                                                </Link>

                                                <Link
                                                    :href="route('fi.banks.mtl.index', bank.id)"
                                                    class="fi-dropdown-item"
                                                    @click="openFacility = null"
                                                >
                                                    <div class="fi-item-icon fi-icon-navy">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="fi-item-label">{{ t('financialInstitutions.banks.index.mediumTermLoan') }}</div>
                                                        <div class="fi-item-desc">{{ t('financialInstitutions.banks.index.manuallyEnteredOrUploadedRepaymentSchedule') }}</div>
                                                    </div>
                                                </Link>

                                            </div>
                                        </Transition>
                                    </div>

                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <Pagination :paginator="banks" />
            </div>
        </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    banks:   Object,
    filters: Object,
})

// ── Search ────────────────────────────────────────────────────────
const search = ref(props.filters?.search ?? '')
let timer = null
function doSearch() {
    clearTimeout(timer)
    timer = setTimeout(() => router.get(
        route('fi.banks.index'),
        { search: search.value || undefined },
        { preserveState: true, replace: true }
    ), 350)
}

// ── Toggle active ─────────────────────────────────────────────────
function toggle(bank) {
    router.patch(route('fi.banks.toggle', bank.id))
}

// ── Pagination ────────────────────────────────────────────────────
// ── Dropdown state ────────────────────────────────────────────────
// Only one dropdown open at a time across the whole page — 2 per-row
// (per bank, per type) + 2 page-header (all banks).
const openDebit            = ref(null)   // bank.id of the open per-row Debit dropdown, or null
const openFacility         = ref(null)   // bank.id of the open per-row Facility dropdown, or null
const showHeaderFacilities = ref(false)  // page-header "Credit Facilities" (all banks)
const showHeaderAccounts   = ref(false)  // page-header "Bank Accounts" (all banks)

function toggleDebit(bankId) {
    const wasOpen = openDebit.value === bankId
    closeAll()
    openDebit.value = wasOpen ? null : bankId
}

function toggleFacility(bankId) {
    const wasOpen = openFacility.value === bankId
    closeAll()
    openFacility.value = wasOpen ? null : bankId
}

function toggleHeaderFacilities() {
    const wasOpen = showHeaderFacilities.value
    closeAll()
    showHeaderFacilities.value = !wasOpen
}

function toggleHeaderAccounts() {
    const wasOpen = showHeaderAccounts.value
    closeAll()
    showHeaderAccounts.value = !wasOpen
}

function closeAll() {
    openDebit.value            = null
    openFacility.value         = null
    showHeaderFacilities.value = false
    showHeaderAccounts.value   = false
}

// ── Click-away to close dropdowns ────────────────────────────────
function handleClickOutside(e) {
    if (!e.target.closest('.fi-dropdown-wrap')) {
        closeAll()
    }
}

onMounted(()  => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))
</script>

<style scoped>
.fi-page { padding: 20px 32px; max-width: 1600px; margin: 0 auto; }

/* ── Header ─────────────────────────────────────────────────────── */
.fi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 14px; }
.fi-header-left { display: flex; align-items: center; gap: 14px; }
.fi-header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.fi-header-icon { width: 42px; height: 42px; border-radius: 10px; background: rgba(15,37,84,0.1); display: flex; align-items: center; justify-content: center; color: #0F2554; flex-shrink: 0; }
.fi-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 0; }
.fi-subtitle { font-size: 13px; color: var(--vero-text-muted); margin: 2px 0 0; }
.fi-btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #0F2554; color: #fff; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; transition: background 0.15s; }
.fi-btn-primary:hover { background: #1a3a7a; }

/* ── Search ─────────────────────────────────────────────────────── */
.fi-filters { margin-bottom: 18px; }
.fi-search-wrap { position: relative; max-width: 320px; }
.fi-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--vero-text-muted); }
.fi-search { width: 100%; padding: 8px 12px 8px 34px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-card); color: var(--vero-text); outline: none; }
.fi-search:focus { border-color: #1490A8; }

/* ── Table ───────────────────────────────────────────────────────── */
.fi-card { background: var(--vero-card); border-radius: 12px; border: 1px solid var(--vero-border); overflow: visible; }
.fi-table { width: 100%; border-collapse: collapse; }
.fi-table thead tr { background: #0F2554; }
.fi-table thead th { padding: 11px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.85); white-space: nowrap; }
.fi-row { border-bottom: 1px solid var(--vero-border); transition: background 0.12s; }
.fi-row:last-child { border-bottom: none; }
.fi-row:hover { background: var(--vero-hover); }
.fi-table td { padding: 12px 14px; font-size: 13px; color: var(--vero-text); vertical-align: middle; }
.fi-name-primary { font-weight: 600; }
.fi-no-functional-warning {
    display: inline-flex; align-items: center; gap: 4px; margin-top: 4px;
    font-size: 10.5px; font-weight: 600; color: #f59e0b; cursor: default;
}
.fi-name-ar { font-size: 12px; color: var(--vero-text-muted); margin-top: 2px; direction: rtl; }
.fi-muted { color: var(--vero-text-muted); font-size: 13px; }

/* ── Badges ──────────────────────────────────────────────────────── */
.fi-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: 6px; font-size: 12px; font-weight: 700; padding: 0 6px; }
.fi-count-blue { background: rgba(20,144,168,0.12); color: #1490A8; }
.fi-count-gold { background: rgba(255,191,0,0.12); color: #b8860b; }
.fi-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fi-active   { background: rgba(16,185,129,0.12); color: #10b981; }
.fi-inactive { background: rgba(100,116,139,0.12); color: #64748b; }

/* ── Actions row ─────────────────────────────────────────────────── */
.fi-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }

/* Edit / toggle icon buttons */
.fi-action-btn {
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; border: none;
    background: transparent; cursor: pointer; color: var(--vero-text-muted);
    text-decoration: none; transition: all 0.15s; flex-shrink: 0;
}
.fi-action-btn:hover       { background: var(--vero-hover); color: #1490A8; }
.fi-action-deactivate:hover { color: #f59e0b; }
.fi-action-activate:hover   { color: #10b981; }

/* ── Split dropdown buttons ──────────────────────────────────────── */
.fi-dropdown-wrap { position: relative; flex-shrink: 0; }

.fi-split-btn {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 9px; border-radius: 7px; border: 1px solid transparent;
    font-size: 11.5px; font-weight: 600; cursor: pointer;
    transition: all 0.15s; white-space: nowrap; font-family: inherit;
}

/* Debit Accounts — teal tint */
.fi-split-blue {
    background: rgba(1, 110, 30, 0.973);
    background: rgba(1, 110, 30, 0.973);
    color: #ffffff;
}
.fi-split-blue:hover {
    background: rgba(3, 230, 63, 0.973);
    background: rgba(3, 230, 63, 0.973);
    color: #0e0d0d;
}

/* Credit Facilities — navy tint */
.fi-split-navy {
    background: rgba(24, 28, 243, 0.932);
    border-color: rgba(24, 28, 243, 0.932);
    color: #ffffff;
}
[data-theme="dark"] .fi-split-navy {
    background: rgba(24, 28, 243, 0.932);
    border-color: rgba(24, 28, 243, 0.932);
    color: #ffffff;
}
.fi-split-navy:hover {
    background: rgba(88, 151, 245, 0.932);
    border-color: rgba(88, 151, 245, 0.932);
    color: #0e0d0d;
}
[data-theme="dark"] .fi-split-navy:hover {
    background: rgba(255,255,255,0.10);
}

.fi-split-label { font-size: 11.5px; }

.fi-chevron { transition: transform 0.2s ease; flex-shrink: 0; }
.fi-chevron-up { transform: rotate(180deg); }

/* ── Dropdown menu ───────────────────────────────────────────────── */
.fi-dropdown-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 230px;
    background: var(--vero-card);
    border: 1px solid var(--vero-border);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.08);
    z-index: 200;
    overflow: hidden;
    padding: 4px 0;
}

.fi-dropdown-wide { min-width: 270px; }

.fi-dropdown-group-label {
    padding: 8px 14px 5px;
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.1em; color: var(--vero-text-muted);
}

.fi-dropdown-divider {
    height: 1px; background: var(--vero-border); margin: 4px 0;
}

.fi-dropdown-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 14px; text-decoration: none; color: var(--vero-text);
    transition: background 0.12s; cursor: pointer; text-align: left;
}
.fi-dropdown-item:hover { background: var(--vero-hover); }

.fi-dropdown-item-disabled {
    cursor: not-allowed; opacity: 0.5;
}
.fi-dropdown-item-disabled:hover { background: transparent; }
.fi-dropdown-item-disabled .fi-item-icon { filter: grayscale(1); }

.fi-soon-tag {
    display: inline-flex; align-items: center;
    padding: 1px 7px; border-radius: 9px; margin-inline-start: 4px;
    background: rgba(100,116,139,0.14); color: var(--vero-text-muted);
    font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
    vertical-align: middle;
}

.fi-item-icon {
    width: 28px; height: 28px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.fi-icon-teal  { background: rgba(20,144,168,0.12); color: #1490A8; }
.fi-icon-gold  { background: rgba(255,191,0,0.12);  color: #b8860b; }
.fi-icon-blue  { background: rgba(15,37,84,0.08);   color: #0F2554; }
.fi-icon-navy  { background: rgba(15,37,84,0.12);   color: #0F2554; }
[data-theme="dark"] .fi-icon-blue,
[data-theme="dark"] .fi-icon-navy { color: #90a4c4; background: rgba(255,255,255,0.07); }

.fi-item-label { font-size: 13px; font-weight: 600; color: var(--vero-text); text-align: left; }
.fi-item-desc  { font-size: 11.5px; color: var(--vero-text-muted); margin-top: 1px; text-align: left; }

/* ── Dropdown transition ─────────────────────────────────────────── */
.fi-drop-enter-active { transition: opacity 0.14s ease, transform 0.14s ease; }
.fi-drop-leave-active { transition: opacity 0.10s ease, transform 0.10s ease; }
.fi-drop-enter-from, .fi-drop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.97); }

/* ── Empty / pagination ──────────────────────────────────────────── */
.fi-empty { text-align: center; padding: 60px 20px; display: flex; flex-direction: column; align-items: center; }
.fi-pagination { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 14px; border-top: 1px solid var(--vero-border); }
.fi-page-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--vero-border); background: var(--vero-card); color: var(--vero-text); font-size: 13px; cursor: pointer; transition: all 0.12s; }
.fi-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.fi-page-btn:disabled { opacity: 0.4; cursor: default; }
.fi-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }

.text-end    { text-align: end; }
.text-center { text-align: center; }
.text-sm     { font-size: 13px; }
</style>