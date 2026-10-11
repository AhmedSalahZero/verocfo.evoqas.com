<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/EmployeeProfileForm.vue

  Create and Edit share this one page — mode detected by whether
  props.profile exists (Rule 2). On create, the employee dropdown
  only lists Employee-type partners who don't already have a profile
  (server-side filter — see EmployeePayrollProfileController::create()).
  On edit, the employee itself is fixed and shown read-only.

  @layout    AppLayout
  @route     hr.employees.create / hr.employees.edit
  @props     profile?, employees?, currencies
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="isEdit ? t('accounting.hr.employeeProfileForm.editPayrollProfile') : t('accounting.hr.employeeProfileForm.newPayrollProfile')"
      :subtitle="isEdit ? profile.partner?.name_en : t('accounting.hr.employeeProfileForm.setUpAnEmployeesSalaryExpense')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/>'
    />

    <form @submit.prevent="submit">

      <!-- ══════════════════════════════════════════════════════
           EMPLOYEE
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.employee') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.employeeSectionSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="!isEdit" class="field-group" style="max-width: 480px;">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.employee') }}</label>
            <SelectBox v-model="form.partner_id" :options="employees" value-key="id" :label-fn="e => `${e.code} — ${e.name_en}`" :placeholder="t('accounting.hr.employeeProfileForm.selectEmployee')" :clearable="false" :class="{ 'zv-input-error': form.errors.partner_id }" />
            <p v-if="employees.length === 0" class="text-sm opacity-70">{{ t('accounting.hr.employeeProfileForm.everyEmployeeAlreadyHasAPayroll') }}</p>
            <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
          </div>
          <div v-else class="field-group" style="max-width: 480px;">
            <label class="zv-label">{{ t('accounting.hr.employeeProfileForm.employee') }}</label>
            <div class="text-sm font-semibold" style="padding: 8px 0;">{{ profile.partner?.code }} — {{ profile.partner?.name_en }}</div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           COMPENSATION & CLASSIFICATION
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.compensationSection') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.compensationSectionSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group" v-if="!isEdit">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.baseSalary') }}</label>
              <input v-model="form.base_salary" type="number" step="0.01" min="0" class="zv-input" :class="{ 'zv-input-error': form.errors.base_salary }"/>
              <p v-if="form.errors.base_salary" class="zv-field-error">{{ form.errors.base_salary }}</p>
            </div>
            <div class="field-group" v-if="!isEdit">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</label>
              <input v-model="form.effective_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.effective_date }"/>
              <p v-if="form.errors.effective_date" class="zv-field-error">{{ form.errors.effective_date }}</p>
              <p class="field-hint">{{ t('accounting.hr.employeeProfileForm.effectiveDateHint') }}</p>
            </div>
            <div class="field-group" v-else>
              <label class="zv-label">{{ t('accounting.hr.employeeProfileForm.currentBaseSalary') }}</label>
              <div class="text-sm font-semibold" style="padding: 8px 0;">{{ formatAmount(profile.base_salary) }}</div>
              <p class="text-sm opacity-70">{{ t('accounting.hr.employeeProfileForm.changeItBelowInSalaryHistory') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.currency') }}</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" label-key="code" :clearable="false" :class="{ 'zv-input-error': form.errors.currency_id }" />
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>
          </div>

          <div class="field-divider"></div>

          <div class="field-group">
            <label class="zv-label" :class="{ 'zv-label-required': !isEdit }">{{ t('accounting.hr.employeeProfileForm.expenseCategory') }}</label>
            <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.employeeProfileForm.decidesWhichPLLineThisEmployees') }}</p>

            <template v-if="isEdit">
              <div class="text-sm font-semibold" style="padding: 8px 0;">{{ currentCategoryLabel }}</div>
              <p class="text-sm opacity-70">{{ t('accounting.hr.employeeProfileForm.changeItBelowInClassificationHistory') }}</p>

              <template v-if="profile.expense_category === 'manufacturing'">
                <div class="field-divider"></div>
                <label class="zv-label">{{ t('accounting.hr.employeeProfileForm.manufacturingRole') }}</label>
                <div class="text-sm font-semibold" style="padding: 8px 0;">{{ currentRoleLabel }}</div>
                <p class="text-sm opacity-70">{{ t('accounting.hr.employeeProfileForm.changeItBelowInRoleHistory') }}</p>
              </template>
            </template>

            <template v-else>
              <div class="zv-tile-group">
                <button v-for="c in categories" :key="c.value" type="button"
                  class="zv-tile" :class="{ 'zv-tile-active': form.expense_category === c.value }"
                  @click="form.expense_category = c.value">
                  <div class="zv-tile-label">{{ c.label }}</div>
                  <div class="zv-tile-desc">{{ c.desc }}</div>
                </button>
              </div>
              <p v-if="form.expense_category === 'manufacturing'" class="text-sm opacity-70 mt-2">{{ t('accounting.hr.employeeProfileForm.manufacturingSalariesDoNotPostTo') }}</p>
              <p v-if="form.errors.expense_category" class="zv-field-error">{{ form.errors.expense_category }}</p>

              <!-- Manufacturing Role (Section 2) — only relevant once
                   Manufacturing is picked above. Optional at creation:
                   the company may prefer to assign it in a follow-up
                   edit instead. -->
              <div v-if="form.expense_category === 'manufacturing'" class="field-divider"></div>
              <div v-if="form.expense_category === 'manufacturing'" class="field-group">
                <label class="zv-label">{{ t('accounting.hr.employeeProfileForm.manufacturingRole') }}</label>
                <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.employeeProfileForm.manufacturingRoleDesc') }}</p>
                <div class="zv-tile-group">
                  <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.manufacturing_role === 'direct_labor' }"
                    @click="form.manufacturing_role = 'direct_labor'; form.overhead_pool_id = ''">
                    <div class="zv-tile-label">{{ t('accounting.hr.employeeProfileForm.directLabor') }}</div>
                    <div class="zv-tile-desc">{{ t('accounting.hr.employeeProfileForm.directLaborDesc') }}</div>
                  </button>
                  <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.manufacturing_role === 'overhead' }"
                    @click="form.manufacturing_role = 'overhead'">
                    <div class="zv-tile-label">{{ t('accounting.hr.employeeProfileForm.manufacturingOverhead') }}</div>
                    <div class="zv-tile-desc">{{ t('accounting.hr.employeeProfileForm.manufacturingOverheadDesc') }}</div>
                  </button>
                </div>
                <p v-if="form.errors.manufacturing_role" class="zv-field-error">{{ form.errors.manufacturing_role }}</p>

                <div v-if="form.manufacturing_role === 'overhead'" class="field-group mt-3" style="max-width: 380px;">
                  <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.overheadPool') }}</label>
                  <SelectBox v-model="form.overhead_pool_id" :options="overheadPools" value-key="id" label-key="name" :placeholder="t('common.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.overhead_pool_id }" />
                  <p v-if="form.errors.overhead_pool_id" class="zv-field-error">{{ form.errors.overhead_pool_id }}</p>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

    </form>

    <!-- ══════════════════════════════════════════════════════
         SALARY HISTORY (edit mode only) — deliberately its OWN
         <form>, structurally separate from the main profile form
         above and below. Enter inside these three fields can now
         only ever submit THIS form (submitRevision), never the
         main Save action — no keydown tricks needed.
    ══════════════════════════════════════════════════════ -->
    <form v-if="isEdit" @submit.prevent="submitRevision">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#B45309">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.salaryHistory') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.salaryHistorySub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="profile.salary_revisions?.length" class="zv-table-wrap mb-4">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</th>
                  <th class="zv-table-num">{{ t('accounting.hr.employeeProfileForm.amount') }}</th>
                  <th>{{ t('common.notes') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.enteredBy') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="rev in profile.salary_revisions" :key="rev.id">
                  <td>{{ rev.effective_date }}</td>
                  <td class="zv-table-num font-semibold">{{ formatAmount(rev.base_salary) }}</td>
                  <td>{{ rev.note }}</td>
                  <td>{{ rev.created_by?.name }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mb-3">
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="backdateStart">Move start date earlier (migration)</button>
            <p class="field-hint">Entered an existing employee with today's date? Move their starting salary back to the real hire date so earlier payroll months use it.</p>
          </div>

          <div class="field-divider"></div>

          <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.employeeProfileForm.scheduleANewSalary') }}</p>
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.newBaseSalary') }}</label>
              <input v-model="revisionForm.base_salary" type="number" step="0.01" min="0" class="zv-input" :class="{ 'zv-input-error': revisionForm.errors.base_salary }" @keydown.enter.prevent="submitRevision"/>
              <p v-if="revisionForm.errors.base_salary" class="zv-field-error">{{ revisionForm.errors.base_salary }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</label>
              <input v-model="revisionForm.effective_date" type="date" class="zv-input" :class="{ 'zv-input-error': revisionForm.errors.effective_date }" @keydown.enter.prevent="submitRevision"/>
              <p v-if="revisionForm.errors.effective_date" class="zv-field-error">{{ revisionForm.errors.effective_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('common.notes') }}</label>
              <input v-model="revisionForm.note" type="text" class="zv-input" :placeholder="t('accounting.hr.employeeProfileForm.eGAnnualReviewPromotion')" @keydown.enter.prevent="submitRevision"/>
            </div>
          </div>
          <div class="mt-3">
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="revisionForm.processing" @click="submitRevision">
              {{ revisionForm.processing ? t('common.saving') : t('accounting.hr.employeeProfileForm.addSalaryChange') }}
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- ══════════════════════════════════════════════════════
         CLASSIFICATION HISTORY (edit mode only) — same pattern as
         Salary History above: its OWN <form>, structurally
         independent of the main profile form. Employee
         classification history fix (VeroCFO_Manufacturing_Costing_
         Redesign_v1_0.md, Section 1) — Expense Category now gets
         the same dated-history treatment Salary already had, so
         Payroll always resolves "what applied during this period"
         instead of "whatever it is today."
    ══════════════════════════════════════════════════════ -->
    <form v-if="isEdit" @submit.prevent="submitClassificationRevision">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.classificationHistory') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.classificationHistorySub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="profile.classification_revisions?.length" class="zv-table-wrap mb-4">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.category') }}</th>
                  <th>{{ t('common.notes') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.enteredBy') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="rev in profile.classification_revisions" :key="rev.id">
                  <td>{{ rev.effective_date }}</td>
                  <td class="font-semibold">{{ categoryLabel(rev.expense_category) }}</td>
                  <td>{{ rev.note }}</td>
                  <td>{{ rev.created_by?.name }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="field-divider"></div>

          <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.employeeProfileForm.scheduleANewClassification') }}</p>
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.newCategory') }}</label>
              <SelectBox v-model="classificationForm.expense_category" :options="categories" value-key="value" label-key="label" :placeholder="t('common.select')" :clearable="false" :class="{ 'zv-input-error': classificationForm.errors.expense_category }" />
              <p v-if="classificationForm.errors.expense_category" class="zv-field-error">{{ classificationForm.errors.expense_category }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</label>
              <input v-model="classificationForm.effective_date" type="date" class="zv-input" :class="{ 'zv-input-error': classificationForm.errors.effective_date }" @keydown.enter.prevent="submitClassificationRevision"/>
              <p v-if="classificationForm.errors.effective_date" class="zv-field-error">{{ classificationForm.errors.effective_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('common.notes') }}</label>
              <input v-model="classificationForm.note" type="text" class="zv-input" :placeholder="t('accounting.hr.employeeProfileForm.eGPromotedToProductionManager')" @keydown.enter.prevent="submitClassificationRevision"/>
            </div>
          </div>
          <div class="mt-3">
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="classificationForm.processing" @click="submitClassificationRevision">
              {{ classificationForm.processing ? t('common.saving') : t('accounting.hr.employeeProfileForm.addClassificationChange') }}
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- ══════════════════════════════════════════════════════
         MANUFACTURING ROLE HISTORY (edit mode + currently
         Manufacturing only) — same pattern as Classification
         History above. Employee Manufacturing Role (VeroCFO_
         Manufacturing_Costing_Redesign_v1_0.md, Section 2):
         Direct Labor vs. Manufacturing Overhead, dated, so a
         promotion from the shop floor to Production Manager never
         rewrites how their past cost was already classified.
    ══════════════════════════════════════════════════════ -->
    <form v-if="isEdit && profile.expense_category === 'manufacturing'" @submit.prevent="submitRoleRevision">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.roleHistory') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.roleHistorySub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="profile.manufacturing_role_revisions?.length" class="zv-table-wrap mb-4">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.role') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.overheadPool') }}</th>
                  <th>{{ t('common.notes') }}</th>
                  <th>{{ t('accounting.hr.employeeProfileForm.enteredBy') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="rev in profile.manufacturing_role_revisions" :key="rev.id">
                  <td>{{ rev.effective_date }}</td>
                  <td class="font-semibold">{{ rev.role === 'direct_labor' ? t('accounting.hr.employeeProfileForm.directLabor') : t('accounting.hr.employeeProfileForm.manufacturingOverhead') }}</td>
                  <td>{{ rev.overhead_pool?.name ?? '—' }}</td>
                  <td>{{ rev.note }}</td>
                  <td>{{ rev.created_by?.name }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="field-divider"></div>

          <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.employeeProfileForm.scheduleARoleChange') }}</p>
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.newRole') }}</label>
              <SelectBox v-model="roleForm.role" :options="roleOptions" value-key="value" label-key="label" :placeholder="t('common.select')" :clearable="false" :class="{ 'zv-input-error': roleForm.errors.role }" @update:modelValue="roleForm.overhead_pool_id = ''" />
              <p v-if="roleForm.errors.role" class="zv-field-error">{{ roleForm.errors.role }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.effectiveDate') }}</label>
              <input v-model="roleForm.effective_date" type="date" class="zv-input" :class="{ 'zv-input-error': roleForm.errors.effective_date }" @keydown.enter.prevent="submitRoleRevision"/>
              <p v-if="roleForm.errors.effective_date" class="zv-field-error">{{ roleForm.errors.effective_date }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('common.notes') }}</label>
              <input v-model="roleForm.note" type="text" class="zv-input" :placeholder="t('accounting.hr.employeeProfileForm.eGPromotedToProductionManager')" @keydown.enter.prevent="submitRoleRevision"/>
            </div>
          </div>
          <div v-if="roleForm.role === 'overhead'" class="field-group mt-3" style="max-width: 380px;">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.employeeProfileForm.overheadPool') }}</label>
            <SelectBox v-model="roleForm.overhead_pool_id" :options="overheadPools" value-key="id" label-key="name" :placeholder="t('common.select')" :clearable="false" :class="{ 'zv-input-error': roleForm.errors.overhead_pool_id }" />
            <p v-if="roleForm.errors.overhead_pool_id" class="zv-field-error">{{ roleForm.errors.overhead_pool_id }}</p>
          </div>
          <div class="mt-3">
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="roleForm.processing" @click="submitRoleRevision">
              {{ roleForm.processing ? t('common.saving') : t('accounting.hr.employeeProfileForm.addRoleChange') }}
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- ══════════════════════════════════════════════════════
         Main profile form resumes here — EOSB + the real Save
         button. Independent of the Salary History form above.
    ══════════════════════════════════════════════════════ -->
    <form @submit.prevent="submit">

      <!-- ══════════════════════════════════════════════════════
           END OF SERVICE BENEFITS
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.286z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.employeeProfileForm.eosbSection') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.employeeProfileForm.eosbSectionSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-group" style="max-width: 260px;">
            <label class="zv-label">{{ t('accounting.hr.employeeProfileForm.eosbRate') }}</label>
            <div class="zv-input-group">
              <input v-model="form.eosb_rate_percent" type="number" step="0.01" min="0" max="100" class="zv-input zv-input-has-suffix" :placeholder="t('accounting.hr.employeeProfileForm.none')"/>
              <span class="zv-input-suffix text-xs font-semibold">%</span>
            </div>
            <p v-if="form.errors.eosb_rate_percent" class="zv-field-error">{{ form.errors.eosb_rate_percent }}</p>
          </div>
          <div class="field-group mt-3" style="max-width: 260px;">
            <label class="zv-label">Date of birth</label>
            <input v-model="form.date_of_birth" type="date" class="zv-input"/>
            <p class="field-hint">Used for the age-50 leave entitlement (30 days instead of 21).</p>
            <p v-if="form.errors.date_of_birth" class="zv-field-error">{{ form.errors.date_of_birth }}</p>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('hr.employees.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">{{ form.processing ? t('common.saving') : t('common.save') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()
const page = usePage()

const props = defineProps({
  profile:               { type: Object, default: null },
  employees:              { type: Array,  default: () => [] },
  currencies:             { type: Array,  required: true },
  functionalCurrencyId:   { type: [Number, String], default: null },
  overheadPools:          { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.profile?.id)

// Manufacturing only applies — and only shows as a choice — for companies
// whose company_type includes Manufacturing. See Company::isManufacturing().
const isManufacturing = computed(() => (page.props.auth?.company?.company_type ?? []).includes('Manufacturing'))

// Cost of Service only applies to Service / Service-with-Inventory
// companies. See Company::TYPE_SERVICE / TYPE_SERVICE_WITH_INV.
const isServiceType = computed(() => {
  const types = page.props.auth?.company?.company_type ?? []
  return types.includes('Service') || types.includes('Service with Inventory')
})

const allCategories = [
  { value: 'sales',           label: t('accounting.hr.employeeProfileForm.salesDistribution'), desc: t('accounting.hr.employeeProfileForm.salesDistributionDesc') },
  { value: 'marketing',       label: t('accounting.hr.employeeProfileForm.marketing'),          desc: t('accounting.hr.employeeProfileForm.marketingDesc') },
  { value: 'general_admin',   label: t('accounting.hr.employeeProfileForm.gA'),                 desc: t('accounting.hr.employeeProfileForm.gADesc') },
  { value: 'cost_of_service', label: t('accounting.hr.employeeProfileForm.costOfService'),      desc: t('accounting.hr.employeeProfileForm.costOfServiceDesc'), requires: 'service' },
  { value: 'manufacturing',   label: t('accounting.hr.employeeProfileForm.manufacturing'),      desc: t('accounting.hr.employeeProfileForm.manufacturingDesc'), requires: 'manufacturing' },
]

// Hide Manufacturing / Cost of Service tiles for companies they don't apply
// to — unless a profile was already saved with that value (e.g. company
// type changed after the fact), in which case we still show it so the
// current value isn't hidden silently.
const categories = computed(() =>
  allCategories.filter(c => {
    if (c.value === form.expense_category) return true
    if (c.requires === 'manufacturing') return isManufacturing.value
    if (c.requires === 'service') return isServiceType.value
    return true
  })
)

const form = useForm({
  partner_id:         props.profile?.partner_id ?? '',
  base_salary:        props.profile?.base_salary ?? '',
  effective_date:      todayLocal(),
  currency_id:         props.profile?.currency_id ?? props.functionalCurrencyId ?? '',
  expense_category:    props.profile?.expense_category ?? '',
  manufacturing_role:  props.profile?.manufacturing_role ?? '',
  overhead_pool_id:    props.profile?.overhead_pool_id ?? '',
  eosb_rate_percent:   props.profile?.eosb_rate_percent ?? '',
  date_of_birth:       props.profile?.date_of_birth ? String(props.profile.date_of_birth).slice(0, 10) : '',
})

function submit() {
  if (isEdit.value) {
    form.put(route('hr.employees.update', props.profile.id))
  } else {
    form.post(route('hr.employees.store'))
  }
}

// ── Salary History — separate form, saves independently of the
// main Save button (see EmployeePayrollProfileService::addSalaryRevision) ──
const revisionForm = useForm({
  base_salary:    '',
  effective_date: todayLocal(),
  note:           '',
})

// M-34: back-date the starting salary (for staff migrated with today's date).
function backdateStart() {
  const d = prompt('Real start (hire) date, format YYYY-MM-DD:')
  if (!d) return
  router.post(route('hr.employees.backdate-start', props.profile.id), { start_date: d }, { preserveScroll: true })
}

function submitRevision() {
  revisionForm.post(route('hr.employees.salary-revisions.store', props.profile.id), {
    preserveScroll: true,
    onSuccess: () => revisionForm.reset('base_salary', 'note'),
  })
}

// ── Classification History — separate form, saves independently of
// the main Save button (see
// EmployeePayrollProfileService::addClassificationRevision()) ──
const currentCategoryLabel = computed(() => categoryLabel(props.profile?.expense_category))

function categoryLabel(value) {
  return allCategories.find(c => c.value === value)?.label ?? value
}

const classificationForm = useForm({
  expense_category: '',
  effective_date:   todayLocal(),
  note:             '',
})

function submitClassificationRevision() {
  classificationForm.post(route('hr.employees.classification-revisions.store', props.profile.id), {
    preserveScroll: true,
    onSuccess: () => classificationForm.reset('note'),
  })
}

// ── Manufacturing Role History — separate form, saves independently
// of the main Save button (see
// EmployeePayrollProfileService::addManufacturingRoleRevision()) ──
const roleOptions = [
  { value: 'direct_labor', label: t('accounting.hr.employeeProfileForm.directLabor') },
  { value: 'overhead',     label: t('accounting.hr.employeeProfileForm.manufacturingOverhead') },
]

const currentRoleLabel = computed(() => {
  const role = props.profile?.manufacturing_role
  if (!role) return t('accounting.hr.employeeProfileForm.notSetYet')
  const label = roleOptions.find(r => r.value === role)?.label ?? role
  return role === 'overhead' && props.profile?.overhead_pool
    ? `${label} — ${props.profile.overhead_pool.name}`
    : label
})

const roleForm = useForm({
  role:              '',
  overhead_pool_id:  '',
  effective_date:    todayLocal(),
  note:              '',
})

function submitRoleRevision() {
  roleForm.post(route('hr.employees.manufacturing-role-revisions.store', props.profile.id), {
    preserveScroll: true,
    onSuccess: () => roleForm.reset('note'),
  })
}

function formatAmount(n) {
  const formatted = Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return props.profile?.currency?.code ? `${formatted} ${props.profile.currency.code}` : formatted
}
</script>