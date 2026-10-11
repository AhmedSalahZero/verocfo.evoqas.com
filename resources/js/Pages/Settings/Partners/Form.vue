<!--
══════════════════════════════════════════════════════════════════
  Settings/Partners/Form.vue

  Create and Edit partners — one page, premium design.

  Design principles applied:
  — Type tiles: large interactive cards with icon + label,
    each type has its own accent color when selected
  — Section headers: strong left color border matching type color
  — Conditional sections: smooth v-show transitions
  — Contacts: card-per-contact with avatar initial, amber Add btn
  — Sales Channel: selector with inline quick-add modal
  — Fields shown/hidden by type logic (Tax/Country only for
    business entity types: Customer, Supplier, Sister Company)

  @layout    AppLayout
  @route     settings.partners.create / settings.partners.edit
  @props     partner, business_sectors, payment_terms,
             cost_centers, sales_channels
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
            <Link :href="route('settings.partners.index')" class="zv-text-blue">{{ t('settings.partners.form.partners') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.partners.form.editPartner') : t('settings.partners.form.newPartner') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('settings.partners.form.editPartner') : t('settings.partners.form.newPartner') }}</h1>
          <p v-if="isEdit" class="zv-page-subtitle">
            <span class="font-mono font-semibold" style="color:var(--zv-blue-text)">{{ partner.code }}</span>
            <span class="mx-2" style="color:var(--zv-border-md)">·</span>
            <span>{{ partner.name_en }}</span>
          </p>
        </div>
      </div>

      <form @submit.prevent="submit" novalidate>

        <!-- ══════════════════════════════════════════════════
             STEP 1 — PARTNER TYPE
        ══════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:var(--zv-nav-bg)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('settings.partners.form.partnerType') }}</div>
              <div class="form-section-sub">{{ t('settings.partners.form.selectOneOrMoreSectionsBelow') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="type-tile-grid">
              <button v-for="type in allTypes" :key="type.value"
                      type="button"
                      class="type-tile"
                      :class="[`type-tile--${type.value}`, { 'type-tile--selected': hasType(type.value) }]"
                      @click="toggleType(type.value)">
                <div class="type-tile-icon" v-html="type.icon"></div>
                <div class="type-tile-label">{{ t(type.label) }}</div>
                <div class="type-tile-check">
                  <svg v-if="hasType(type.value)" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                </div>
              </button>
            </div>
            <p v-if="errors.types" class="zv-field-error mt-3">{{ errors.types }}</p>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             STEP 2 — IDENTITY
        ══════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:var(--zv-blue)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('settings.partners.form.partnerIdentity') }}</div>
              <div class="form-section-sub">{{ t('settings.partners.form.nameContactDetailsAndIdentification') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid">

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('settings.partners.form.nameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       :placeholder="t('settings.partners.form.fullNameInEnglish')" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label">{{ t('settings.partners.form.nameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl"
                       class="zv-input zv-input-ar"
                       :placeholder="t('settings.partners.form.text')" />
              </div>

              <div class="field-group">
                <label class="zv-label">{{ t('settings.partners.form.email') }}</label>
                <input v-model="form.email" type="email" class="zv-input"
                       :class="{ 'zv-input-error': errors.email }"
                       placeholder="name@company.com" />
                <p v-if="errors.email" class="zv-field-error">{{ errors.email }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label">{{ t('settings.partners.form.phone') }}</label>
                <input v-model="form.phone" type="text" class="zv-input" placeholder="+20 10 xxxx xxxx" />
              </div>

              <div class="field-group field-group--full">
                <label class="zv-label">{{ t('settings.partners.form.address') }}</label>
                <input v-model="form.address" type="text" class="zv-input" />
              </div>

              <!-- Tax & Country — only for business entities -->
              <template v-if="isBusinessEntity">
                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.taxRegistrationNumber') }}</label>
                  <input v-model="form.tax_number" type="text" class="zv-input" placeholder="e.g. 123-456-789" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.country') }}</label>
                  <input v-model="form.country" type="text" class="zv-input" placeholder="Egypt" />
                </div>
              </template>

              <div class="field-group field-group--full">
                <label class="zv-label">{{ t('settings.partners.form.notes') }}</label>
                <textarea v-model="form.notes" rows="2" class="zv-textarea"
                          :placeholder="t('settings.partners.form.internalNotesAboutThisPartner')"></textarea>
              </div>

            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             CUSTOMER SECTION
        ══════════════════════════════════════════════════ -->
        <Transition name="section-slide">
          <div v-if="hasType('customer')" class="form-section form-section--customer mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:var(--zv-blue)">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">
                  {{ t('settings.partners.form.customerDetails') }}
                  <span class="zv-badge zv-badge-blue ms-2">{{ t('common.customer') }}</span>
                </div>
                <div class="form-section-sub">{{ t('settings.partners.form.creditCollectionTermsAndSalesClassification') }}</div>
              </div>
            </div>

            <div class="form-section-body">
              <div class="field-grid">

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.creditLimit') }}</label>
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">EGP</span>
                    <input v-model="form.credit_limit" type="number" min="0" step="0.01"
                           class="zv-input zv-input-has-prefix text-end" placeholder="0.00" />
                  </div>
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.collectionTerms') }}</label>
                  <select v-model="form.collection_term_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectTerms') }} —</option>
                    <option v-for="pt in paymentTerms" :key="pt.id" :value="pt.id">
                      {{ pt.name_en }}
                    </option>
                  </select>
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.businessSector') }}</label>
                  <select v-model="form.business_sector_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectSector') }} —</option>
                    <option v-for="s in businessSectors" :key="s.id" :value="s.id">
                      {{ s.name_en }}
                    </option>
                  </select>
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.salesZone') }}</label>
                  <input v-model="form.sales_zone" type="text" class="zv-input"
                         :placeholder="t('settings.partners.form.eGCairoNorthDeltaRegion')" />
                </div>

                <!-- Sales Channel — smart selector -->
                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.salesChannel') }}</label>
                  <div class="channel-selector-wrap">
                    <select v-model="form.sales_channel_id" class="zv-select channel-select">
                      <option :value="null">— {{ t('settings.partners.form.selectChannel') }} —</option>
                      <option v-for="ch in salesChannelsList" :key="ch.id" :value="ch.id">
                        {{ ch.name_en }}
                      </option>
                    </select>
                    <button type="button" @click="showChannelModal = true"
                            class="channel-add-btn" :title="t('settings.partners.form.addNewChannel')">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                      </svg>
                    </button>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════
             SUPPLIER SECTION
        ══════════════════════════════════════════════════ -->
        <Transition name="section-slide">
          <div v-if="hasType('supplier')" class="form-section form-section--supplier mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:var(--zv-amber)">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">
                  {{ t('settings.partners.form.supplierDetails') }}
                  <span class="zv-badge zv-badge-amber ms-2">{{ t('settings.partners.form.supplier') }}</span>
                </div>
                <div class="form-section-sub">{{ t('settings.partners.form.paymentTermsAndPurchaseClassification') }}</div>
              </div>
            </div>

            <div class="form-section-body">
              <div class="field-grid">

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.paymentTerms') }}</label>
                  <select v-model="form.payment_term_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectTerms') }} —</option>
                    <option v-for="pt in paymentTerms" :key="pt.id" :value="pt.id">
                      {{ pt.name_en }}
                    </option>
                  </select>
                </div>

                <!-- Business Sector only if not already showing in Customer -->
                <div v-if="!hasType('customer')" class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.businessSector') }}</label>
                  <select v-model="form.business_sector_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectSector') }} —</option>
                    <option v-for="s in businessSectors" :key="s.id" :value="s.id">
                      {{ s.name_en }}
                    </option>
                  </select>
                </div>

                <div class="field-group">
                  <label class="zv-label">
                    {{ t('settings.partners.form.expenseCategory') }}
                    <span class="field-optional">{{ t('settings.partners.form.optional') }}</span>
                  </label>
                  <!-- placeholder until Expense Structure is built -->
                  <input v-model="form.expense_category" type="text" class="zv-input"
                         :placeholder="t('settings.partners.form.eGRawMaterialsOfficeSupplies')" />
                  <p class="field-hint">{{ t('settings.partners.form.willBecomeASelectorOnceExpense') }}</p>
                </div>

                <!-- Landing Cost Supplier — see
                     VeroCFO_Landing_Cost_Design_v1_0.md. Additive: a
                     partner can be a normal Supplier AND a Landing
                     Cost Supplier at the same time. -->
                <div class="field-group field-group-span-2">
                  <label class="zv-toggle-row">
                    <span class="zv-toggle" :class="{ 'zv-toggle-on': form.is_landing_cost_supplier }" @click="form.is_landing_cost_supplier = !form.is_landing_cost_supplier">
                      <span class="zv-toggle-knob"></span>
                    </span>
                    <span>{{ t('settings.partners.form.isLandingCostSupplier') }}</span>
                  </label>
                  <p class="field-hint">{{ t('settings.partners.form.isLandingCostSupplierHint') }}</p>
                </div>

                <div v-if="form.is_landing_cost_supplier" class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.landingCostAccount') }}</label>
                  <select v-model="form.landing_cost_account_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectAccount') }} —</option>
                    <option v-for="a in landingCostAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_en }}</option>
                  </select>
                  <p class="field-hint">{{ t('settings.partners.form.landingCostAccountHint') }}</p>
                </div>

              </div>
            </div>
          </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════
             EMPLOYEE SECTION
        ══════════════════════════════════════════════════ -->
        <Transition name="section-slide">
          <div v-if="hasType('employee')" class="form-section form-section--employee mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:var(--zv-green)">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">
                  {{ t('settings.partners.form.employeeDetails') }}
                  <span class="zv-badge zv-badge-green ms-2">{{ t('settings.partners.form.employee') }}</span>
                </div>
                <div class="form-section-sub">{{ t('settings.partners.form.departmentRoleCostCenterAndBank') }}</div>
              </div>
            </div>

            <div class="form-section-body">
              <div class="field-grid">

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.department') }}</label>
                  <input v-model="form.department" type="text" class="zv-input"
                         :placeholder="t('settings.partners.form.eGFinanceSalesOperations')" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.jobTitle') }}</label>
                  <input v-model="form.job_title" type="text" class="zv-input"
                         :placeholder="t('settings.partners.form.eGFinancialAnalyst')" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.costCenter') }}</label>
                  <select v-model="form.cost_center_id" class="zv-select">
                    <option :value="null">— {{ t('settings.partners.form.selectCostCenter') }} —</option>
                    <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">
                      {{ cc.name_en }}
                    </option>
                  </select>
                </div>

                <!-- Divider -->
                <div class="field-group--full">
                  <div class="field-divider">
                    <span>{{ t('settings.partners.form.bankDetailsForPayroll') }}</span>
                  </div>
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.bankName') }}</label>
                  <input v-model="form.bank_name" type="text" class="zv-input"
                         :placeholder="t('settings.partners.form.eGCIBNBEQNB')" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.bankBranch') }}</label>
                  <input v-model="form.bank_branch" type="text" class="zv-input" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.accountNumber') }}</label>
                  <input v-model="form.bank_account_number" type="text" class="zv-input font-mono" />
                </div>

                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.iBAN') }}</label>
                  <input v-model="form.iban" type="text" class="zv-input font-mono"
                         placeholder="EG00 0000 0000 0000" />
                </div>

              </div>
            </div>
          </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════
             SHAREHOLDER SECTION
        ══════════════════════════════════════════════════ -->
        <Transition name="section-slide">
          <div v-if="hasType('shareholder')" class="form-section form-section--shareholder mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:var(--zv-nav-bg)">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">
                  {{ t('settings.partners.form.shareholderDetails') }}
                  <span class="zv-badge zv-badge-navy ms-2">{{ t('settings.partners.form.shareholder') }}</span>
                </div>
                <div class="form-section-sub">{{ t('settings.partners.form.equityOwnershipPercentage') }}</div>
              </div>
            </div>

            <div class="form-section-body">
              <div class="field-grid">
                <div class="field-group">
                  <label class="zv-label">{{ t('settings.partners.form.equity') }}</label>
                  <div class="zv-input-group">
                    <input v-model="form.equity_pct" type="number" min="0" max="100" step="0.01"
                           class="zv-input zv-input-has-suffix text-end"
                           placeholder="0.00" />
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════
             SISTER COMPANY SECTION — no extra fields needed
             (name is already in Partner Identity above)
        ══════════════════════════════════════════════════ -->

        <!-- ══════════════════════════════════════════════════
             CONTACTS SECTION
        ══════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#7C3AED">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
            </div>
            <div class="flex-1">
              <div class="form-section-title">{{ t('settings.partners.form.contactPersons') }}</div>
              <div class="form-section-sub">{{ t('settings.partners.form.peopleToReachAtThisPartner') }}</div>
            </div>
            <button type="button" @click="addContact"
                    class="zv-btn zv-btn-warning zv-btn-sm ms-auto">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('settings.partners.form.addContact') }}
            </button>
          </div>

          <div class="form-section-body">

            <!-- Empty state -->
            <div v-if="form.contacts.length === 0" class="contacts-empty">
              <svg class="w-8 h-8 mb-2 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              <p class="text-sm" style="color:var(--zv-text-muted)">{{ t('settings.partners.form.noContactsYetClickAddContact') }}</p>
            </div>

            <!-- Contact cards -->
            <div v-for="(contact, index) in form.contacts" :key="index" class="contact-card">

              <!-- Contact card header -->
              <div class="contact-card-header">
                <div class="contact-avatar">
                  {{ contactInitial(contact) }}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-semibold truncate" style="color:var(--zv-text-primary)">
                    {{ contact.name_en || t('settings.partners.form.newContact') }}
                  </div>
                  <div class="text-xs truncate" style="color:var(--zv-text-muted)">
                    {{ contact.job_title || t('settings.partners.form.noTitleSet') }}
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <!-- Primary toggle -->
                  <button v-if="!contact.is_primary"
                          type="button"
                          @click="setPrimary(index)"
                          class="zv-btn zv-btn-secondary zv-btn-sm">
                    {{ t('settings.partners.form.setPrimary') }}
                  </button>
                  <span v-else class="zv-badge zv-badge-blue">{{ t('settings.partners.form.primary') }}</span>

                  <!-- Remove -->
                  <button type="button" @click="removeContact(index)"
                          class="zv-btn zv-btn-icon zv-btn-ghost contact-remove-btn"
                          :title="t('settings.partners.form.removeContact')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Contact fields -->
              <div class="contact-fields">
                <div>
                  <label class="zv-label zv-label-required">{{ t('settings.partners.form.nameEN') }}</label>
                  <input v-model="contact.name_en" type="text" class="zv-input"
                         :class="{ 'zv-input-error': errors[`contacts.${index}.name_en`] }" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.partners.form.nameAR') }}</label>
                  <input v-model="contact.name_ar" type="text" dir="rtl" class="zv-input zv-input-ar" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.partners.form.jobTitle') }}</label>
                  <input v-model="contact.job_title" type="text" class="zv-input" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.partners.form.email') }}</label>
                  <input v-model="contact.email" type="email" class="zv-input" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.partners.form.phone') }}</label>
                  <input v-model="contact.phone" type="text" class="zv-input" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.partners.form.mobile') }}</label>
                  <input v-model="contact.mobile" type="text" class="zv-input" />
                </div>
              </div>

            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             FORM ACTIONS
        ══════════════════════════════════════════════════ -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3"
               style="border-radius:var(--zv-radius-lg)">
            <Link :href="route('settings.partners.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing || form.types.length === 0"
                    class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.partners.form.createPartner')) }}
            </button>
          </div>
        </div>

      </form>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SALES CHANNEL QUICK-ADD MODAL
    ══════════════════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition name="scale">
        <div v-if="showChannelModal" class="zv-modal-backdrop" @click.self="showChannelModal = false">
          <div class="zv-modal zv-modal-sm">
            <div class="zv-modal-header">
              <h3 class="zv-modal-title">{{ t('settings.partners.form.addSalesChannel') }}</h3>
              <button type="button" @click="showChannelModal = false" class="zv-modal-close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <div class="zv-modal-body">
              <div class="mb-4">
                <label class="zv-label zv-label-required">{{ t('settings.partners.form.channelNameEN') }}</label>
                <input v-model="newChannel.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': newChannel.error }"
                       :placeholder="t('settings.partners.form.eGModernTradeECommerce')"
                       @keydown.enter.prevent="saveChannel" />
                <p v-if="newChannel.error" class="zv-field-error">{{ newChannel.error }}</p>
              </div>
              <div>
                <label class="zv-label">{{ t('settings.partners.form.channelNameAR') }}</label>
                <input v-model="newChannel.name_ar" type="text" dir="rtl"
                       class="zv-input zv-input-ar" />
              </div>
            </div>
            <div class="zv-modal-footer">
              <button type="button" @click="showChannelModal = false" class="zv-btn zv-btn-ghost">
                {{ t('common.cancel') }}
              </button>
              <button type="button" @click="saveChannel"
                      :disabled="!newChannel.name_en.trim() || newChannel.saving"
                      class="zv-btn zv-btn-primary">
                <div v-if="newChannel.saving" class="zv-spinner w-4 h-4 me-2"></div>
                {{ newChannel.saving ? t('common.saving') : t('settings.partners.form.addChannel') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

</template>

<script setup>
import { computed, ref, reactive } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  partner:            Object,
  business_sectors:   Array,
  payment_terms:      Array,
  cost_centers:       Array,
  sales_channels:     Array,
  landing_cost_accounts: Array,
})

const isEdit          = computed(() => !!props.partner?.id)
const businessSectors = computed(() => props.business_sectors ?? [])
const paymentTerms    = computed(() => props.payment_terms ?? [])
const costCenters     = computed(() => props.cost_centers ?? [])
const landingCostAccounts = computed(() => props.landing_cost_accounts ?? [])

// ── Sales channels list — reactive so quick-add updates it ────────
const salesChannelsList = ref([...(props.sales_channels ?? [])])

// ── Business entity check — Tax/Country only for these types ──────
const businessEntityTypes = ['customer', 'supplier', 'sister_company']
const isBusinessEntity = computed(() =>
  form.types.some(t => businessEntityTypes.includes(t))
)

// ── All partner types ─────────────────────────────────────────────
const allTypes = [
  {
    value: 'customer',
    label: 'settings.partners.form.type_customer',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
  },
  {
    value: 'supplier',
    label: 'settings.partners.form.type_supplier',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
  },
  {
    value: 'employee',
    label: 'settings.partners.form.type_employee',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
  },
  {
    value: 'shareholder',
    label: 'settings.partners.form.type_shareholder',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
  },
  {
    value: 'sister_company',
    label: 'settings.partners.form.type_sisterCompany',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/></svg>',
  },
  {
    value: 'other',
    label: 'settings.partners.form.type_other',
    icon: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/></svg>',
  },
]

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  name_en:             props.partner?.name_en             ?? '',
  name_ar:             props.partner?.name_ar             ?? '',
  types:               props.partner?.types               ?? [],
  email:               props.partner?.email               ?? '',
  phone:               props.partner?.phone               ?? '',
  address:             props.partner?.address             ?? '',
  country:             props.partner?.country             ?? 'Egypt',
  tax_number:          props.partner?.tax_number          ?? '',
  notes:               props.partner?.notes               ?? '',
  credit_limit:        props.partner?.credit_limit        ?? null,
  collection_term_id:  props.partner?.collection_term_id  ?? null,
  sales_zone:          props.partner?.sales_zone          ?? '',
  sales_channel_id:    props.partner?.sales_channel_id    ?? null,
  payment_term_id:     props.partner?.payment_term_id     ?? null,
  expense_category:    props.partner?.expense_category    ?? '',
  business_sector_id:  props.partner?.business_sector_id  ?? null,
  department:          props.partner?.department          ?? '',
  job_title:           props.partner?.job_title           ?? '',
  cost_center_id:      props.partner?.cost_center_id      ?? null,
  bank_name:           props.partner?.bank_name           ?? '',
  bank_branch:         props.partner?.bank_branch         ?? '',
  bank_account_number: props.partner?.bank_account_number ?? '',
  iban:                props.partner?.iban                ?? '',
  swift_code:          props.partner?.swift_code          ?? '',
  equity_pct:          props.partner?.equity_pct          ?? null,
  sister_company_name: props.partner?.sister_company_name ?? '',
  is_landing_cost_supplier: props.partner?.is_landing_cost_supplier ?? false,
  landing_cost_account_id: props.partner?.landing_cost_account_id ?? null,
  contacts: props.partner?.contacts?.length
    ? props.partner.contacts.map(c => ({
        id: c.id, name_en: c.name_en, name_ar: c.name_ar ?? '',
        job_title: c.job_title ?? '', email: c.email ?? '',
        phone: c.phone ?? '', mobile: c.mobile ?? '',
        is_primary: c.is_primary,
      }))
    : [],
})

const errors = computed(() => form.errors)

// ── Type helpers ──────────────────────────────────────────────────
function hasType(type) { return form.types.includes(type) }
function toggleType(type) {
  const idx = form.types.indexOf(type)
  if (idx === -1) form.types.push(type)
  else form.types.splice(idx, 1)
}

// ── Contact helpers ───────────────────────────────────────────────
function contactInitial(contact) {
  return (contact.name_en || '?').charAt(0).toUpperCase()
}

function addContact() {
  form.contacts.push({
    id: null, name_en: '', name_ar: '', job_title: '',
    email: '', phone: '', mobile: '',
    is_primary: form.contacts.length === 0,
  })
}

function removeContact(index) {
  const wasPrimary = form.contacts[index].is_primary
  form.contacts.splice(index, 1)
  if (wasPrimary && form.contacts.length > 0) {
    form.contacts[0].is_primary = true
  }
}

function setPrimary(index) {
  form.contacts.forEach((c, i) => { c.is_primary = i === index })
}

// ── Sales Channel modal ───────────────────────────────────────────
const showChannelModal = ref(false)
const newChannel = reactive({ name_en: '', name_ar: '', error: '', saving: false })

async function saveChannel() {
  if (!newChannel.name_en.trim()) return
  newChannel.saving = true
  newChannel.error = ''

  try {
    const response = await window.axios.post(route('settings.sales-channels.store'), {
      name_en: newChannel.name_en.trim(),
      name_ar: newChannel.name_ar.trim() || null,
    })
    // Add to list and auto-select
    salesChannelsList.value.push(response.data)
    form.sales_channel_id = response.data.id
    showChannelModal.value = false
    newChannel.name_en = ''
    newChannel.name_ar = ''
  } catch (err) {
    newChannel.error = err.response?.data?.message ?? t('settings.partners.form.failedToSaveChannel')
  } finally {
    newChannel.saving = false
  }
}

// ── Submit ────────────────────────────────────────────────────────
// Fields that belong to a Partner Type the user has switched OFF are still
// held in the form (the sections are only hidden). They are blanked in the
// OUTGOING copy so a deselected type's values are not saved — the values
// stay on screen in case the user re-selects the type.
form.transform(data => {
  const d = { ...data }
  const has = (t) => (d.types ?? []).includes(t)
  if (!has('customer')) { d.credit_limit = null; d.collection_term_id = null; d.sales_zone = ''; d.sales_channel_id = null }
  if (!has('supplier')) { d.payment_term_id = null; d.expense_category = ''; d.is_landing_cost_supplier = false; d.landing_cost_account_id = null }
  if (!has('customer') && !has('supplier')) d.business_sector_id = null
  if (!has('employee')) { d.department = ''; d.job_title = ''; d.cost_center_id = null; d.bank_name = ''; d.bank_branch = ''; d.bank_account_number = ''; d.iban = ''; d.swift_code = '' }
  if (!has('shareholder')) d.equity_pct = null
  if (!businessEntityTypes.some(t => has(t))) { d.tax_number = '' }
  if (!d.is_landing_cost_supplier) d.landing_cost_account_id = null
  return d
})

function submit() {
  if (isEdit.value) {
    form.put(route('settings.partners.update', props.partner.id))
  } else {
    form.post(route('settings.partners.store'))
  }
}
</script>

<style scoped>
/* ══════════════════════════════════════════════════════════════════
   FORM SECTION — the primary building block of this form
══════════════════════════════════════════════════════════════════ */
.form-section {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  box-shadow: var(--zv-shadow-sm);
  overflow: hidden;
}

.form-section-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.125rem 1.375rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
}

.form-section-icon {
  width: 36px;
  height: 36px;
  border-radius: var(--zv-radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.form-section-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--zv-text-primary);
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.375rem;
}

.form-section-sub {
  font-size: 0.75rem;
  color: var(--zv-text-muted);
  margin-top: 0.125rem;
}

.form-section-body {
  padding: 1.375rem;
}

/* ── Colored left border per type ──────────────────────────────── */
.form-section--customer  { border-inline-start: 3px solid var(--zv-blue); }
.form-section--supplier  { border-inline-start: 3px solid var(--zv-amber); }
.form-section--employee  { border-inline-start: 3px solid var(--zv-green); }
.form-section--shareholder { border-inline-start: 3px solid #0F2554; }

/* ══════════════════════════════════════════════════════════════════
   TYPE TILES
══════════════════════════════════════════════════════════════════ */
.type-tile-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;
}

@media (min-width: 768px) {
  .type-tile-grid { grid-template-columns: repeat(6, 1fr); }
}

.type-tile {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 1rem 0.5rem;
  border-radius: var(--zv-radius-md);
  border: 2px solid var(--zv-border);
  background: var(--zv-bg-surface);
  cursor: pointer;
  transition: all 0.18s ease;
  text-align: center;
  color: var(--zv-text-secondary);
}

.type-tile:hover {
  border-color: var(--zv-border-md);
  background: var(--zv-bg-card);
  transform: translateY(-1px);
  box-shadow: var(--zv-shadow-sm);
}

.type-tile-icon { opacity: 0.5; transition: opacity 0.15s ease; }
.type-tile-label { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.03em; }

.type-tile-check {
  position: absolute;
  top: 0.375rem;
  inset-inline-end: 0.375rem;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  transition: all 0.15s ease;
}

/* Selected states — each type has its own color */
.type-tile--customer.type-tile--selected  {
  border-color: var(--zv-blue);
  background: var(--zv-blue-light);
  color: var(--zv-blue-text);
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}
.type-tile--supplier.type-tile--selected  {
  border-color: var(--zv-amber);
  background: var(--zv-amber-light);
  color: var(--zv-amber-text);
  box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.12);
}
.type-tile--employee.type-tile--selected  {
  border-color: var(--zv-green);
  background: var(--zv-green-light);
  color: var(--zv-green-text);
  box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
}
.type-tile--shareholder.type-tile--selected {
  border-color: #0F2554;
  background: #EEF2FF;
  color: #0F2554;
  box-shadow: 0 0 0 3px rgba(15, 37, 84, 0.12);
}
.type-tile--sister_company.type-tile--selected {
  border-color: #7C3AED;
  background: #F5F3FF;
  color: #7C3AED;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
}
.type-tile--other.type-tile--selected {
  border-color: var(--zv-border-md);
  background: var(--zv-bg-surface-alt);
  color: var(--zv-text-primary);
  box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.12);
}

/* Check mark colors per type */
.type-tile--customer.type-tile--selected  .type-tile-check { background: var(--zv-blue); color: #fff; }
.type-tile--supplier.type-tile--selected  .type-tile-check { background: var(--zv-amber); color: #fff; }
.type-tile--employee.type-tile--selected  .type-tile-check { background: var(--zv-green); color: #fff; }
.type-tile--shareholder.type-tile--selected .type-tile-check { background: #0F2554; color: #fff; }
.type-tile--sister_company.type-tile--selected .type-tile-check { background: #7C3AED; color: #fff; }
.type-tile--other.type-tile--selected .type-tile-check { background: #64748B; color: #fff; }

.type-tile--selected .type-tile-icon { opacity: 1; }

/* ══════════════════════════════════════════════════════════════════
   FIELD GRID
══════════════════════════════════════════════════════════════════ */
.field-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}

@media (min-width: 640px) {
  .field-grid { grid-template-columns: repeat(2, 1fr); }
}

.field-group { display: flex; flex-direction: column; }
.field-group--full { grid-column: 1 / -1; }

.field-optional {
  font-size: 0.6875rem;
  font-weight: 400;
  color: var(--zv-text-muted);
  margin-inline-start: 0.375rem;
  text-transform: lowercase;
}

.field-hint {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  margin-top: 0.25rem;
  font-style: italic;
}

.field-divider {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--zv-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 0.25rem 0;
}
.field-divider::before, .field-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--zv-border);
}

/* ══════════════════════════════════════════════════════════════════
   SALES CHANNEL SELECTOR
══════════════════════════════════════════════════════════════════ */
.channel-selector-wrap {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.channel-select { flex: 1; }
.channel-add-btn {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--zv-radius);
  border: 1px solid var(--zv-amber-border);
  background: var(--zv-amber-light);
  color: var(--zv-amber-text);
  cursor: pointer;
  transition: var(--zv-transition);
}
.channel-add-btn:hover {
  background: var(--zv-amber);
  color: #fff;
  border-color: var(--zv-amber);
}

/* ══════════════════════════════════════════════════════════════════
   CONTACTS
══════════════════════════════════════════════════════════════════ */
.contacts-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 2.5rem 1rem;
  text-align: center;
}

.contact-card {
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  overflow: hidden;
  margin-bottom: 0.875rem;
  background: var(--zv-bg-card);
  box-shadow: var(--zv-shadow-sm);
  transition: box-shadow 0.15s ease;
}
.contact-card:hover { box-shadow: var(--zv-shadow); }

.contact-card-header {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 0.875rem 1rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
}

.contact-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--zv-nav-bg);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  font-weight: 700;
  flex-shrink: 0;
}

.contact-remove-btn {
  color: var(--zv-text-muted);
  transition: var(--zv-transition);
}
.contact-remove-btn:hover {
  color: var(--zv-danger-text);
  background: var(--zv-danger-light);
}

.contact-fields {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
  padding: 1rem;
}

@media (min-width: 768px) {
  .contact-fields { grid-template-columns: repeat(3, 1fr); }
}

/* ══════════════════════════════════════════════════════════════════
   SECTION TRANSITIONS
══════════════════════════════════════════════════════════════════ */
.section-slide-enter-active {
  transition: all 0.25s ease;
  overflow: hidden;
}
.section-slide-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}
.section-slide-enter-from,
.section-slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
  max-height: 0;
}
.section-slide-enter-to,
.section-slide-leave-from {
  opacity: 1;
  transform: translateY(0);
  max-height: 1000px;
}
</style>