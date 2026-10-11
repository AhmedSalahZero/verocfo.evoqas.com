<!--
══════════════════════════════════════════════════════════════════
  Settings/Products/Form.vue

  Create and Edit Products & Services — one page.

  Sections:
  1. Product Type tiles (usage_types multiselect + nature)
  2. Identity (code, name EN/AR, barcode, description, category)
  3. Units of Measure (primary + up to 3 alternates)
  4. Physical Details (weight, reorder — stocked items only)
  5. Auto-assigned Accounts (read-only display)

  Accounts are auto-resolved from type/nature — never manual.
  Shown as read-only info so the user can see what will post where.

  @layout    AppLayout
  @route     settings.products.create / settings.products.edit
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page" style="max-width:1400px">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.products.index')" class="zv-text-blue">{{ t('settings.products.form.productsServices') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.products.form.editProduct') : t('settings.products.form.newProduct') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? product.name_en : t('settings.products.form.newProduct') }}</h1>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- ── Section 1: Classification ─────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <div class="form-section-icon me-3" style="background:var(--zv-nav-bg)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
              </svg>
            </div>
            <h2 class="zv-card-title">{{ t('settings.products.form.productClassification') }}</h2>
          </div>
          <div class="zv-card-body">

            <!-- Usage Type tiles -->
            <div class="mb-4">
              <label class="zv-label zv-label-required">{{ t('settings.products.form.usageType') }} <span class="text-m font-normal" style="color:var(--zv-text-muted)">({{ t('settings.products.form.selectAllThatApply') }})</span></label>
              <div class="type-grid mt-2">
                <div v-for="ut in usageTypes" :key="ut.value"
                     class="type-tile"
                     :class="{ 'type-tile--selected': form.usage_types.includes(ut.value) }"
                     @click="toggleUsageType(ut.value)">
                  <div class="type-tile-icon" :style="{ background: ut.color }">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" v-html="ut.icon"></svg>
                  </div>
                  <div class="type-tile-label ">{{ t(ut.label) }}</div>
                  <div class="type-tile-desc text-s">{{ t(ut.desc) }}</div>
                </div>
              </div>
              <p v-if="errors.usage_types" class="zv-field-error">{{ errors.usage_types }}</p>
            </div>

            <!-- Nature — shown only for saleable items -->
            <Transition name="section-slide">
              <div v-if="form.usage_types.includes('saleable')" class="mt-4">
                <label class="zv-label zv-label-required">{{ t('settings.products.form.nature') }}</label>

                <!-- Single nature auto-selected — show as read-only info -->
                <div v-if="availableNatures.length === 1"
                     class="p-3 rounded-lg flex items-center gap-2"
                     style="background:var(--zv-blue-light);border:1px solid var(--zv-blue-border)">
                  <svg class="w-4 h-4 flex-shrink-0" style="color:#ffffff" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                  <span class="text-sm" style="color:#ffffff">
                    {{ t('settings.products.form.natureAutoSetTo') }} <strong>{{ t(availableNatures[0].label) }}</strong>
                    {{ t('settings.products.form.basedOnYourCompanyType') }}
                  </span>
                </div>

                <!-- Multiple natures available — show tiles -->
                <div v-else class="nature-grid mt-2">
                  <div v-for="nat in availableNatures" :key="nat.value"
                       class="nature-tile"
                       :class="{ 'nature-tile--selected': form.nature === nat.value }"
                       @click="form.nature = nat.value">
                    <div class="nature-tile-label">{{ t(nat.label) }}</div>
                    <div class="nature-tile-desc text-xs">{{ t(nat.desc) }}</div>
                  </div>
                </div>
                <p v-if="errors.nature" class="zv-field-error">{{ errors.nature }}</p>
              </div>
            </Transition>

          </div>
        </div>

        <!-- ── Section 2: Identity ────────────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <div class="form-section-icon me-3" style="background:var(--zv-blue)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <h2 class="zv-card-title">{{ t('settings.products.form.productIdentity') }}</h2>
          </div>
          <div class="zv-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

              <!-- Code -->
              <div v-if="!isEdit">
                <label class="zv-label">{{ t('settings.products.form.productCode') }}</label>
                <div class="flex gap-3 items-start">
                  <label class="flex items-center gap-2 cursor-pointer mt-2.5 flex-shrink-0">
                    <input type="checkbox" v-model="form.use_auto_code" class="zv-checkbox" />
                    <span class="text-sm" style="color:var(--zv-text-secondary)">{{ t('settings.products.form.auto') }}</span>
                  </label>
                  <div class="flex-1">
                    <input v-if="form.use_auto_code"
                           :value="t('settings.products.form.pXXXXXAuto')" class="zv-input font-mono" disabled />
                    <input v-else v-model="form.code" type="text" class="zv-input font-mono"
                           :class="{ 'zv-input-error': errors.code }"
                           :placeholder="t('settings.products.form.eGSW48CISCO')" />
                    <p v-if="errors.code" class="zv-field-error">{{ errors.code }}</p>
                  </div>
                </div>
              </div>

              <div v-else>
                <label class="zv-label">{{ t('settings.products.form.productCode') }}</label>
                <input :value="product.code" class="zv-input font-mono" disabled />
              </div>

              <!-- Category -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.products.form.category') }}</label>
                <div class="flex gap-2">
                  <select v-model="form.category_id" class="zv-select flex-1"
                          :class="{ 'zv-input-error': errors.category_id }">
                    <option :value="null">— {{ t('settings.products.form.selectCategory') }} —</option>
                    <template v-for="cat in localCategories" :key="cat.id">
                      <option :value="cat.id">{{ cat.name_en }}</option>
                      <option v-for="sub in cat.children" :key="sub.id" :value="sub.id">
                        &nbsp;&nbsp;↳ {{ sub.name_en }}
                      </option>
                    </template>
                  </select>
                  <button type="button"
                          @click="quickCatModal.show = true"
                          class="zv-btn zv-btn-secondary zv-btn-sm flex-shrink-0"
                          :title="t('settings.products.form.quickCreateANewCategory')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                  </button>
                </div>
                <p v-if="errors.category_id" class="zv-field-error">{{ errors.category_id }}</p>
              </div>

              <!-- Name EN -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.products.form.nameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       :placeholder="t('settings.products.form.eGCiscoSwitch48Port')" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <!-- Name AR -->
              <div>
                <label class="zv-label">{{ t('settings.products.form.nameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl" class="zv-input zv-input-ar" />
              </div>

              <!-- Barcode -->
              <div>
                <label class="zv-label">{{ t('settings.products.form.barcode') }}</label>
                <input v-model="form.barcode" type="text" class="zv-input font-mono"
                       placeholder="EAN-13 or custom" />
              </div>

            </div>

            <!-- Description -->
            <div class="mt-4">
              <label class="zv-label">{{ t('settings.products.form.description') }}</label>
              <textarea v-model="form.description" class="zv-input" rows="2"
                        :placeholder="t('settings.products.form.optionalProductDescription')"></textarea>
            </div>

          </div>
        </div>

        <!-- ── Section 3: Units of Measure ───────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <div class="form-section-icon me-3" style="background:#1490A8">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
              </svg>
            </div>
            <h2 class="zv-card-title">{{ t('settings.products.form.unitsOfMeasure') }}</h2>
          </div>
          <div class="zv-card-body">

            <!-- Primary UOM -->
            <div class="mb-5" style="max-width:360px">
              <label class="zv-label zv-label-required">{{ t('settings.products.form.primaryStorageUOM') }}</label>
              <select v-model="form.primary_uom_id" class="zv-select"
                      :class="{ 'zv-input-error': errors.primary_uom_id }">
                <option :value="null">— {{ t('settings.products.form.selectUOM') }} —</option>
                <optgroup v-for="type in uomGroups" :key="type.label" :label="type.label">
                  <option v-for="uom in type.items" :key="uom.id" :value="uom.id">
                    {{ uom.code }} — {{ uom.name_en }}
                  </option>
                </optgroup>
              </select>
              <p class="text-xs mt-1" style="color:var(--zv-text-muted)">
                {{ t('settings.products.form.inventoryValuationAlwaysUsesThisUOM') }}
              </p>
              <p v-if="errors.primary_uom_id" class="zv-field-error">{{ errors.primary_uom_id }}</p>
            </div>

            <!-- Alternate UOMs -->
            <div>
              <div class="flex items-center justify-between mb-3">
                <div>
                  <label class="zv-label mb-0">{{ t('settings.products.form.alternateUOMs') }}</label>
                  <span class="text-xs ms-2" style="color:var(--zv-text-muted)">({{ t('settings.products.form.max3') }})</span>
                </div>
                <button v-if="form.alternate_uoms.length < 3"
                        type="button" @click="addUomLine"
                        class="zv-btn zv-btn-secondary zv-btn-sm">
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                  </svg>
                  {{ t('settings.products.form.addAlternateUOM') }}
                </button>
              </div>

              <!-- Empty hint -->
              <div v-if="form.alternate_uoms.length === 0"
                   class="uom-empty-hint">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--zv-blue-text)"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="text-sm" style="color:var(--zv-blue-text)">
                  {{ t('settings.products.form.exampleIfPrimaryUNITAndYou') }}
                </span>
              </div>

              <!-- Alternate UOM cards -->
              <div v-for="(line, idx) in form.alternate_uoms" :key="idx" class="uom-alt-card">

                <!-- Top row: UOM select + checkboxes + remove -->
                <div class="uom-alt-top">
                  <div style="flex:1;min-width:0">
                    <label class="uom-field-label">{{ t('settings.products.form.alternateUOM') }}</label>
                    <select v-model="line.uom_id" class="zv-select">
                      <option :value="null">— {{ t('settings.products.form.selectUOM') }} —</option>
                      <optgroup v-for="type in uomGroups" :key="type.label" :label="type.label">
                        <option v-for="uom in type.items" :key="uom.id" :value="uom.id">
                          {{ uom.code }} — {{ uom.name_en }}
                        </option>
                      </optgroup>
                    </select>
                  </div>

                  <div class="uom-alt-checks">
                    <label class="uom-check-label">
                      <input type="checkbox" v-model="line.use_for_purchase" class="zv-checkbox" />
                      {{ t('settings.products.form.useForPurchase') }}
                    </label>
                    <label class="uom-check-label">
                      <input type="checkbox" v-model="line.use_for_sales" class="zv-checkbox" />
                      {{ t('settings.products.form.useForSales') }}
                    </label>
                  </div>

                  <button type="button" @click="removeUomLine(idx)"
                          class="zv-btn zv-btn-danger zv-btn-sm flex-shrink-0">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>

                <!-- Conversion sentence row -->
                <div class="uom-conversion-row">

                  <!-- Left side: 1 [ALT UOM] -->
                  <div class="uom-conv-side">
                    <span class="uom-conv-number">1</span>
                    <div class="uom-conv-badge uom-conv-badge--alt">
                      {{ getUomCode(line.uom_id) || t('settings.products.form.alternate') }}
                    </div>
                  </div>

                  <span class="uom-conv-equals">=</span>

                  <!-- Right side: [calculator input] [PRIMARY UOM] -->
                  <div class="uom-conv-side" style="flex:1">
                    <div class="uom-conv-input-wrap">
                      <input
                        :value="line.conversion_display"
                        @input="onConversionInput(line, $event.target.value)"
                        @blur="evaluateConversion(line)"
                        @keydown.enter.prevent="evaluateConversion(line)"
                        type="text"
                        class="zv-input font-mono text-center uom-conv-input"
                        :class="{ 'zv-input-error': line.conversion_error }"
                        :placeholder="t('settings.products.form.eG100Or128')"
                      />
                      <!-- Evaluated result preview -->
                      <div v-if="line.conversion_evaluated && line.conversion_display !== String(line.conversion_evaluated)"
                           class="uom-conv-preview">
                        ≈ {{ line.conversion_evaluated }}
                      </div>
                    </div>
                    <div class="uom-conv-badge uom-conv-badge--primary">
                      <svg class="w-2.5 h-2.5 me-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                      </svg>
                      {{ primaryUomCode }}
                    </div>
                  </div>

                </div>

                <!-- Error + hint row -->
                <div class="px-4 pb-3">
                  <p v-if="line.conversion_error" class="zv-field-error">{{ line.conversion_error }}</p>
                  <p v-else class="text-xs" style="color:var(--zv-text-muted)">
                    {{ t('settings.products.form.enterANumberEG100') }}
                  </p>
                </div>

              </div>
            </div>

          </div>
        </div>

        <!-- ── Section 4: Physical Details (stocked only) ─────── -->
        <Transition name="section-slide">
          <div v-if="hasStock" class="zv-card mb-4">
            <div class="zv-card-header">
              <div class="form-section-icon me-3" style="background:var(--zv-green)">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
              </div>
              <h2 class="zv-card-title">{{ t('settings.products.form.stockPhysicalDetails') }}</h2>
            </div>
            <div class="zv-card-body">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                  <label class="zv-label">{{ t('settings.products.form.reorderLevel') }}</label>
                  <input v-model="form.reorder_level" type="number" step="0.0001" min="0"
                         class="zv-input" :placeholder="t('settings.products.form.minimumStockBeforeAlert')" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.products.form.reorderQuantity') }}</label>
                  <input v-model="form.reorder_qty" type="number" step="0.0001" min="0"
                         class="zv-input" :placeholder="t('settings.products.form.suggestedOrderQuantity')" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.products.form.weightKG') }}</label>
                  <input v-model="form.weight" type="number" step="0.0001" min="0"
                         class="zv-input" placeholder="0.0000" />
                </div>

              </div>
            </div>
          </div>
        </Transition>

        <!-- ── Section 5: Auto-assigned Accounts (read-only) ──── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <div class="form-section-icon me-3" style="background:#6366f1">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
              </svg>
            </div>
            <h2 class="zv-card-title">{{ t('settings.products.form.autoAssignedAccounts') }}</h2>
            <div class="ms-auto">
              <span class="zv-badge zv-badge-blue text-xs">{{ t('settings.products.form.systemNoManualInputRequired') }}</span>
            </div>
          </div>
          <div class="zv-card-body">
            <p class="text-sm mb-4" style="color:var(--zv-text-secondary)">
              {{ t('settings.products.form.accountsAreAssignedAutomaticallyBasedOn') }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="account-chip">
                <div class="account-chip-label">{{ t('settings.products.form.revenueAccount') }}</div>
                <div class="account-chip-value">411 — {{ t('settings.products.form.salesLocal') }}</div>
                <div class="account-chip-note">{{ t('settings.products.form.changeableAtInvoiceLine') }}</div>
              </div>
              <div class="account-chip" v-if="cogsAccountLabel">
                <div class="account-chip-label">{{ t('settings.products.form.cOGSAccount') }}</div>
                <div class="account-chip-value">{{ cogsAccountLabel }}</div>
              </div>
              <div class="account-chip" v-if="inventoryAccountLabel">
                <div class="account-chip-label">{{ t('settings.products.form.inventoryAccount') }}</div>
                <div class="account-chip-value">{{ inventoryAccountLabel }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Actions ──────────────────────────────────────────── -->
        <div class="zv-card">
          <div class="zv-card-footer flex justify-end gap-3" style="border-radius:var(--zv-radius-lg)">
            <Link :href="route('settings.products.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.products.form.createProduct')) }}
            </button>
          </div>
        </div>

      </form>
    </div>

    <!-- ── Quick-Create Category Modal ──────────────────────── -->
    <Teleport to="body">
      <Transition name="scale">
        <div v-if="quickCatModal.show" class="zv-modal-backdrop" @click.self="quickCatModal.show = false">
          <div class="zv-modal zv-modal-sm">
            <div class="zv-modal-header">
              <h3 class="zv-modal-title">{{ t('settings.products.form.newCategory') }}</h3>
              <button type="button" @click="quickCatModal.show = false" class="zv-modal-close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <div class="zv-modal-body">
              <p class="text-sm mb-4" style="color:var(--zv-text-secondary)">
                {{ t('settings.products.form.createAQuickCategoryForFull') }}
              </p>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="zv-label zv-label-required">{{ t('settings.products.form.nameEN') }}</label>
                  <input v-model="quickCatModal.name_en" type="text" class="zv-input"
                         :placeholder="t('settings.products.form.eGNetworkingEquipment')"
                         @keydown.enter.prevent="quickCreateCategory" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.products.form.nameAR') }}</label>
                  <input v-model="quickCatModal.name_ar" type="text" dir="rtl" class="zv-input zv-input-ar" />
                </div>
              </div>
              <p v-if="quickCatModal.error" class="zv-field-error mt-2">{{ quickCatModal.error }}</p>
            </div>
            <div class="zv-modal-footer">
              <button type="button" @click="quickCatModal.show = false" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</button>
              <button type="button" @click="quickCreateCategory" :disabled="quickCatModal.submitting" class="zv-btn zv-btn-primary">
                <div v-if="quickCatModal.submitting" class="zv-spinner w-4 h-4 me-2"></div>
                {{ quickCatModal.submitting ? t('settings.products.form.creating') : t('settings.products.form.createCategory') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const page  = usePage()

const props = defineProps({
  product:    Object,
  categories: Array,
  uoms:       Array,
  currencies: Array,
})

const isEdit  = computed(() => !!props.product?.id)
const errors  = computed(() => form.errors)

// ── Company type — from shared auth props ─────────────────────────
const companyTypes = computed(() => page.props.auth?.company?.company_type ?? [])
const isManufacturing     = computed(() => companyTypes.value.includes('Manufacturing'))
const isTrading           = computed(() => companyTypes.value.includes('Trading'))
const isService           = computed(() => companyTypes.value.includes('Service'))
const isServiceWithInv    = computed(() => companyTypes.value.includes('Service with Inventory'))

const form = useForm({
  use_auto_code:   true,
  code:            props.product?.code          ?? '',
  name_en:         props.product?.name_en       ?? '',
  name_ar:         props.product?.name_ar       ?? '',
  barcode:         props.product?.barcode        ?? '',
  description:     props.product?.description   ?? '',
  category_id:     props.product?.category_id   ?? null,
  usage_types:     props.product?.usage_types   ?? [],
  nature:          props.product?.nature        ?? null,
  primary_uom_id:  props.product?.primary_uom_id ?? null,
  alternate_uoms:  (props.product?.uom_lines ?? [])
                     .filter(l => !l.is_primary)
                     .map(l => ({
                       uom_id:               l.uom_id,
                       conversion_rate:      l.conversion_rate,
                       conversion_display:   String(l.conversion_rate),
                       conversion_evaluated: parseFloat(l.conversion_rate),
                       conversion_error:     '',
                       use_for_purchase:     l.use_for_purchase,
                       use_for_sales:        l.use_for_sales,
                     })),
  weight:          props.product?.weight        ?? '',
  weight_uom_id:   props.product?.weight_uom_id ?? null,
  reorder_level:   props.product?.reorder_level ?? '',
  reorder_qty:     props.product?.reorder_qty   ?? '',
})

// ── Classification data ───────────────────────────────────────────
const usageTypes = [
  { value: 'saleable',     label: 'Saleable',     desc: 'Sold to customers on invoices', color: '#0F2554',
    icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>' },
  { value: 'raw_material', label: 'Raw Material', desc: 'Used in production / manufacturing', color: '#B45309',
    icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>' },
  { value: 'consumable',   label: 'Consumable',   desc: 'Internal use — spare parts, stationery', color: '#047857',
    icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>' },
]
// NOTE: Fixed Asset is NOT a usage type here — it has its own
// dedicated page (Settings → Fixed Asset Types), separate from
// Products & Services entirely. It was briefly added as a 4th tile
// on this form; reverted per Mahmoud — mixing "owned equipment,
// depreciated over time" into the same form as sold/inventory
// products (with UOM, barcode, selling price sections that don't
// apply to it at all) confused the user rather than helping them.

// ── Nature filtered by company type ──────────────────────────────
const allNatures = [
  { value: 'tradable',     label: 'Tradable',     desc: 'Purchased and resold as-is' },
  { value: 'manufactured', label: 'Manufactured', desc: 'Produced internally — has BOM' },
  { value: 'service',      label: 'Service',       desc: 'No physical item, no stock' },
]

const availableNatures = computed(() => {
  // Manufacturing company — all three natures
  if (isManufacturing.value) return allNatures
  // Trading only — tradable only (no manufacturing, no pure service)
  if (isTrading.value && !isService.value && !isServiceWithInv.value) {
    return allNatures.filter(n => n.value === 'tradable')
  }
  // Service only — service only
  if (isService.value && !isTrading.value && !isServiceWithInv.value) {
    return allNatures.filter(n => n.value === 'service')
  }
  // Service with Inventory — tradable + service (no manufacturing)
  if (isServiceWithInv.value) {
    return allNatures.filter(n => ['tradable', 'service'].includes(n.value))
  }
  // Mixed (Trading + Service) — tradable + service
  return allNatures.filter(n => ['tradable', 'service'].includes(n.value))
})

// Auto-select nature if only one option available.
//
// FIX (July 2026): this used to be a `watch(() => form.usage_types, ...)`
// with no `{ deep: true }`. toggleUsageType() below mutates the SAME
// array in place (push/splice), so the array's reference never
// changes — a non-deep watch compares by reference and never saw a
// change, so this callback only ever ran once at mount (when
// usage_types was still empty for a new product). The result: the
// blue "Nature auto-set to Tradable" box would display, but
// form.nature was actually still null when the form was submitted —
// silently producing a product with no resolvable inventory account
// (see GoodsReceiptNoteService — "no account to post to").
//
// Fixed by calling this directly, right after the array actually
// changes, instead of relying on a watcher to notice a mutation it
// can't see.
function syncNature() {
  if (form.usage_types.includes('saleable') && availableNatures.value.length === 1) {
    form.nature = availableNatures.value[0].value
  }
}
syncNature() // still run once on mount, e.g. when editing an existing product

function toggleUsageType(value) {
  const idx = form.usage_types.indexOf(value)
  if (idx === -1) form.usage_types.push(value)
  else form.usage_types.splice(idx, 1)
  if (value === 'saleable' && idx !== -1) form.nature = null
  syncNature()
}

// ── has_stock derived ─────────────────────────────────────────────
const hasStock = computed(() => {
  if (form.nature === 'service') return false
  if (form.usage_types.includes('raw_material')) return true
  if (form.usage_types.includes('consumable')) return true
  if (['tradable', 'manufactured'].includes(form.nature)) return true
  return false
})

// ── UOM groups ────────────────────────────────────────────────────
const uomGroups = computed(() => {
  const groups = {}
  ;(props.uoms ?? []).forEach(uom => {
    if (!groups[uom.type]) groups[uom.type] = { label: uom.type.charAt(0).toUpperCase() + uom.type.slice(1), items: [] }
    groups[uom.type].items.push(uom)
  })
  return Object.values(groups)
})

const primaryUomCode = computed(() => {
  const uom = (props.uoms ?? []).find(u => u.id == form.primary_uom_id)
  return uom?.code ?? '?'
})

function getUomCode(uomId) {
  if (!uomId) return ''
  return (props.uoms ?? []).find(u => u.id == uomId)?.code ?? ''
}

function addUomLine() {
  form.alternate_uoms.push({
    uom_id:              null,
    conversion_rate:     '',
    conversion_display:  '',
    conversion_evaluated: null,
    conversion_error:    '',
    use_for_purchase:    false,
    use_for_sales:       false,
  })
}
function removeUomLine(idx) { form.alternate_uoms.splice(idx, 1) }

// ── Calculator input handlers ─────────────────────────────────────

function onConversionInput(line, value) {
  line.conversion_display  = value
  line.conversion_error    = ''
  line.conversion_evaluated = null
}

function evaluateConversion(line) {
  const raw = (line.conversion_display ?? '').trim()
  if (!raw) return

  let expression = raw
  // If starts with = treat as formula
  if (raw.startsWith('=')) {
    expression = raw.slice(1).trim()
  }

  try {
    // Only allow safe math characters: digits, operators, decimals, parentheses, spaces
    if (!/^[0-9+\-*/().\s]+$/.test(expression)) {
      throw new Error('Invalid characters')
    }
    // eslint-disable-next-line no-new-func
    const result = new Function('return ' + expression)()
    if (typeof result !== 'number' || isNaN(result) || !isFinite(result) || result <= 0) {
      throw new Error('Must be a positive number')
    }
    const rounded = parseFloat(result.toFixed(6))
    line.conversion_evaluated = rounded
    line.conversion_rate      = rounded
    line.conversion_error     = ''
    // Update display to show evaluated result if it was a formula
    if (raw.startsWith('=')) {
      line.conversion_display = String(rounded)
    }
  } catch {
    line.conversion_error    = t('settings.products.form.invalidExpressionEnterANumberLike')
    line.conversion_rate     = ''
    line.conversion_evaluated = null
  }
}

// ── Account labels ────────────────────────────────────────────────
const cogsAccountLabel = computed(() => {
  return { tradable: '511 — COGS Trading', manufactured: '512 — COGS Manufactured', service: '513 — COGS Services' }[form.nature] ?? null
})
const inventoryAccountLabel = computed(() => {
  if (form.usage_types.includes('raw_material')) return '135001 — Raw Materials'
  if (form.nature === 'manufactured') return '135003 — Finished Goods'
  if (form.nature === 'tradable') return '135003 — Finished Goods'
  if (form.usage_types.includes('consumable')) return '135001 — Raw Materials'
  return null
})

// ── Quick-create category modal ───────────────────────────────────
const quickCatModal = reactive({
  show:       false,
  name_en:    '',
  name_ar:    '',
  submitting: false,
  error:      '',
})
const localCategories = ref([...(props.categories ?? [])])

async function quickCreateCategory() {
  if (!quickCatModal.name_en.trim()) { quickCatModal.error = t('settings.products.form.nameIsRequired'); return }
  quickCatModal.submitting = true
  quickCatModal.error      = ''
  try {
    const res = await window.axios.post(route('settings.product-categories.quick'), {
      name_en: quickCatModal.name_en,
      name_ar: quickCatModal.name_ar,
    })
    // Add to local categories list
    localCategories.value.push({ ...res.data, children: [] })
    // Auto-select new category
    form.category_id = res.data.id
    // Reset modal
    quickCatModal.show    = false
    quickCatModal.name_en = ''
    quickCatModal.name_ar = ''
  } catch (e) {
    quickCatModal.error = e.response?.data?.error ?? t('settings.products.form.failedToCreateCategory')
  } finally {
    quickCatModal.submitting = false
  }
}

// ── Submit ────────────────────────────────────────────────────────
function submit() {
  if (isEdit.value) form.put(route('settings.products.update', props.product.id))
  else form.post(route('settings.products.store'))
}
</script>

<style scoped>
.type-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;
}
@media (max-width: 640px) { .type-grid { grid-template-columns: 1fr; } }

.type-tile {
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  padding: 1rem;
  cursor: pointer;
  transition: all 0.15s ease;
  background: var(--zv-bg-surface);
}
.type-tile:hover { border-color: var(--zv-blue-border); background: var(--zv-blue-light); }
.type-tile--selected { border-color: var(--zv-blue); background: var(--zv-blue-light); }

.type-tile-icon {
  width: 36px; height: 36px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 0.5rem;
}
.type-tile-label { font-weight: 600; font-size: 1rem; color: var(--zv-text-primary); }
.type-tile-desc  { color: #000000; margin-top: 0.25rem; line-height: 1.4; }

.nature-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
}
.nature-tile {
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.75rem 1rem;
  cursor: pointer;
  transition: all 0.15s ease;
  background: var(--zv-bg-surface);
}
.nature-tile:hover { border-color: var(--zv-blue-border); }
.nature-tile--selected { border-color: var(--zv-blue); background: var(--zv-blue-light); }
.nature-tile-label { font-weight: 600; font-size: 0.875rem; color: var(--zv-text-primary); }
.nature-tile-desc  { color: var(--zv-text-muted); margin-top: 0.125rem; }

/* ── UOM Section ─────────────────────────────────────────── */
.uom-empty-hint {
  display: flex;
  align-items: flex-start;
  gap: 0.625rem;
  padding: 0.875rem 1rem;
  background: var(--zv-blue-light);
  border: 1px solid var(--zv-blue-border);
  border-radius: var(--zv-radius-md);
  margin-bottom: 0.75rem;
}

.uom-alt-card {
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  background: var(--zv-bg-surface);
  margin-bottom: 0.75rem;
  overflow: hidden;
}

/* Top row */
.uom-alt-top {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  padding: 0.875rem 1rem 0.75rem;
  border-bottom: 1px solid var(--zv-border);
  background: var(--zv-bg-card);
}
.uom-field-label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--zv-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.3rem;
}
.uom-alt-checks {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  flex-shrink: 0;
  padding-bottom: 0.25rem;
}
.uom-check-label {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.8125rem;
  color: var(--zv-text-secondary);
  cursor: pointer;
  white-space: nowrap;
}

/* Conversion sentence */
.uom-conversion-row {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 0.875rem 1rem 0.5rem;
  flex-wrap: wrap;
}
.uom-conv-side {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.uom-conv-number {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--zv-text-primary);
  line-height: 1;
}
.uom-conv-equals {
  font-size: 1.5rem;
  font-weight: 300;
  color: var(--zv-text-muted);
  line-height: 1;
  flex-shrink: 0;
}
.uom-conv-badge {
  display: flex;
  align-items: center;
  padding: 0.375rem 0.75rem;
  border-radius: var(--zv-radius-md);
  font-size: 0.875rem;
  font-weight: 700;
  font-family: monospace;
  white-space: nowrap;
  flex-shrink: 0;
}
.uom-conv-badge--alt {
  background: var(--zv-blue-light);
  color: var(--zv-blue-text);
  border: 1.5px solid var(--zv-blue-border);
}
.uom-conv-badge--primary {
  background: var(--zv-green-light);
  color: var(--zv-green-text);
  border: 1.5px solid var(--zv-green-border);
}

/* Input wrap */
.uom-conv-input-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.uom-conv-input {
  width: 180px;
  font-size: 1rem !important;
  text-align: center;
  font-family: monospace;
}
.uom-conv-preview {
  font-size: 0.6875rem;
  color: var(--zv-green-text);
  text-align: center;
  font-family: monospace;
  font-weight: 600;
}

.account-chip {
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.75rem 1rem;
}
.account-chip-label { font-size: 0.6875rem; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.account-chip-value { font-size: 0.875rem; font-weight: 600; color: var(--zv-blue-text); font-family: monospace; margin-top: 0.25rem; }
.account-chip-note  { font-size: 0.6875rem; color: var(--zv-text-muted); margin-top: 0.25rem; font-style: italic; }

.section-slide-enter-active { transition: all 0.25s ease; }
.section-slide-leave-active { transition: all 0.2s ease; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-8px); }
.form-section-icon {
  width: 32px; height: 32px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
</style>