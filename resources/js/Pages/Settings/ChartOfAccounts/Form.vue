<!--
══════════════════════════════════════════════════════════════════
  Settings/ChartOfAccounts/Form.vue

  Create and Edit user-defined sub-accounts (Level 4 and 5).
  System accounts (Levels 1–3) cannot be created or edited here.

  Create mode:
    — User selects a parent account (Level 3 or 4)
    — Auto-code is suggested and shown (can be overridden)
    — Section, Class, Normal Balance inherit from parent (read-only)
    — Currency field shown only if parent has allow_currency = true

  Edit mode:
    — Only name_en, name_ar, currency, requires_cost_center can change
    — Code, parent, section, class are immutable

  @layout    AppLayout
  @route     settings.coa.create / settings.coa.edit
  @props     account: ChartOfAccount|null
             currencies: Currency[]
             parents: ChartOfAccount[] — level 3 and 4 options, plus
             Sales Expenses (52) / Marketing (53) / G&A (54) at level
             2, which have no level 3 accounts of their own (fix, July
             2026)
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.coa.index')" class="zv-text-blue">
              {{ t('settings.chartOfAccounts.form.chartOfAccounts') }}
            </Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.chartOfAccounts.form.editAccount') : t('settings.chartOfAccounts.form.newAccount') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('settings.chartOfAccounts.form.editAccount') : t('settings.chartOfAccounts.form.newSubAccount') }}</h1>
          <p class="zv-page-subtitle">
            {{ isEdit
              ? t('settings.chartOfAccounts.form.updateTheNameOrSettingsOf')
              : t('settings.chartOfAccounts.form.addANewSubAccountUnder') }}
          </p>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- ── Parent Selection (create only) ─────────────────── -->
        <div v-if="!isEdit" class="form-section mb-4">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:var(--zv-nav-bg)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('settings.chartOfAccounts.form.parentAccount') }}</div>
              <div class="form-section-sub">{{ t('settings.chartOfAccounts.form.theNewAccountWillInheritSection') }}</div>
            </div>
          </div>
          <div class="form-section-body">
            <div style="max-width:480px">
              <label class="zv-label zv-label-required">{{ t('settings.chartOfAccounts.form.parentAccount') }}</label>

              <!-- Pre-selected parent — show as read-only pill with change option -->
              <div v-if="selectedParent && !showParentSelector"
                   class="parent-selected-pill">
                <div class="parent-pill-info">
                  <span class="font-mono font-semibold text-m" style="color:#FFFFFF">
                    {{ selectedParent.code }}
                  </span>
                  <span class="mx-2" style="color:var(--zv-border-md)">—</span>
                  <span class="font-medium text-m" style="color:#FFFFFF">
                    {{ selectedParent.name_en }}
                  </span>
                </div>
                <button type="button" @click="showParentSelector = true"
                        class="zv-btn zv-btn-ghost zv-btn-sm" style="color:#000000">
                  {{ t('common.change') }}
                </button>
              </div>

              <!-- Selector — shown when no pre-selection or user clicks Change -->
              <select v-else
                      v-model="form.parent_id" class="zv-select"
                      :class="{ 'zv-input-error': errors.parent_id }"
                      @change="onParentChange">
                <option :value="null">— {{ t('settings.chartOfAccounts.form.selectParentAccount') }} —</option>
                <optgroup v-for="section in parentGrouped" :key="section.label" :label="section.label">
                  <option v-for="p in section.accounts" :key="p.id" :value="p.id">
                    {{ p.code }} — {{ p.name_en }}
                  </option>
                </optgroup>
              </select>
              <p v-if="errors.parent_id" class="zv-field-error">{{ errors.parent_id }}</p>
            </div>

            <!-- Parent info card -->
            <div v-if="selectedParent" class="parent-info-card mt-4">
              <div class="parent-info-row">
                <span class="parent-info-label">{{ t('settings.chartOfAccounts.form.section') }}</span>
                <span class="zv-badge" :class="selectedParent.section === 'balance_sheet' ? 'zv-badge-blue' : 'zv-badge-green'">
                  {{ selectedParent.section === 'balance_sheet' ? t('settings.chartOfAccounts.form.balanceSheet') : t('settings.chartOfAccounts.form.incomeStatement') }}
                </span>
              </div>
              <div class="parent-info-row">
                <span class="parent-info-label">{{ t('settings.chartOfAccounts.form.class') }}</span>
                <span class="font-medium text-sm" style="color:var(--zv-text-primary)">
                  {{ selectedParent.class?.charAt(0).toUpperCase() + selectedParent.class?.slice(1) }}
                </span>
              </div>
              <div class="parent-info-row">
                <span class="parent-info-label">{{ t('settings.chartOfAccounts.form.normalBalance') }}</span>
                <span class="zv-badge" :class="selectedParent.normal_balance === 'debit' ? 'zv-badge-blue' : 'zv-badge-amber'">
                  {{ selectedParent.normal_balance === 'debit' ? t('settings.chartOfAccounts.form.debit') : t('settings.chartOfAccounts.form.credit') }}
                </span>
              </div>
              <div class="parent-info-row">
                <span class="parent-info-label">{{ t('settings.chartOfAccounts.form.newAccountLevel') }}</span>
                <span class="font-semibold text-sm" style="color:var(--zv-blue-text)">
                  {{ t('settings.chartOfAccounts.form.level') }} {{ (selectedParent.level || 3) + 1 }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Account Details ────────────────────────────────── -->
        <div class="form-section mb-4">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:var(--zv-blue)">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('settings.chartOfAccounts.form.accountDetails') }}</div>
              <div v-if="isEdit" class="form-section-sub">
                <span class="font-mono font-semibold" style="color:var(--zv-blue-text)">{{ account.code }}</span>
                <span class="mx-2" style="color:var(--zv-border-md)">·</span>
                <span>{{ t('settings.chartOfAccounts.form.level') }} {{ account.level }}</span>
              </div>
            </div>
          </div>
          <div class="form-section-body">
            <div class="field-grid">

              <!-- Code — create only -->
              <template v-if="!isEdit">
                <div class="field-group">
                  <label class="zv-label">{{ t('settings.chartOfAccounts.form.accountCode') }}</label>
                  <div class="flex gap-3 items-start">
                    <label class="flex items-center gap-2 cursor-pointer mt-2.5">
                      <input type="checkbox" v-model="form.use_auto_code" class="zv-checkbox" />
                      <span class="text-sm" style="color:var(--zv-text-secondary)">{{ t('settings.chartOfAccounts.form.autoGenerate') }}</span>
                    </label>
                    <div class="flex-1">
                      <input v-if="form.use_auto_code"
                             :value="suggestedCode || t('settings.chartOfAccounts.form.selectParentFirst')"
                             class="zv-input font-mono"
                             disabled />
                      <input v-else
                             v-model="form.code"
                             type="text"
                             class="zv-input font-mono"
                             :class="{ 'zv-input-error': errors.code }"
                             :placeholder="t('settings.chartOfAccounts.form.enterAccountCode')" />
                      <p v-if="errors.code" class="zv-field-error">{{ errors.code }}</p>
                    </div>
                  </div>
                </div>
              </template>

              <!-- Name EN -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('settings.chartOfAccounts.form.accountNameEN') }}</label>
                <input v-model="form.name_en" type="text" class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       :placeholder="t('settings.chartOfAccounts.form.eGPettyCashHeadOffice')" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <!-- Name AR -->
              <div class="field-group">
                <label class="zv-label">{{ t('settings.chartOfAccounts.form.accountNameAR') }}</label>
                <input v-model="form.name_ar" type="text" dir="rtl"
                       class="zv-input zv-input-ar"
                       placeholder="مثال: الصندوق — المركز الرئيسي" />
              </div>

              <!-- Currency — shown for cash/bank accounts -->
              <div v-if="parentAllowsCurrency || (isEdit && account.allow_currency)" class="field-group">
                <label class="zv-label zv-label-required">{{ t('settings.chartOfAccounts.form.accountCurrency') }}</label>
                <select v-model="form.currency_id" class="zv-select"
                        :class="{ 'zv-input-error': errors.currency_id }">
                  <option :value="null">— {{ t('settings.chartOfAccounts.form.selectCurrency') }} —</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}
                  </option>
                </select>
                <p v-if="errors.currency_id" class="zv-field-error">{{ errors.currency_id }}</p>
                <p class="field-hint">{{ t('settings.chartOfAccounts.form.requiredForCashAndBankAccounts') }}</p>
              </div>

              <!-- Track by Cost Center — Income Statement accounts only -->
              <!-- Makes zero sense on Balance Sheet accounts (cash, AR, AP etc.) -->
              <div v-if="parentSection === 'income_statement'" class="field-group">
                <label class="zv-label">{{ t('settings.chartOfAccounts.form.trackByCostCenter') }}</label>
                <label class="flex items-center gap-2.5 cursor-pointer mt-1">
                  <input type="checkbox" v-model="form.requires_cost_center" class="zv-checkbox" />
                  <span class="text-sm" style="color:var(--zv-text-secondary)">
                    {{ t('settings.chartOfAccounts.form.everyTransactionPostedToThisAccount') }}
                  </span>
                </label>
              </div>

            </div>
          </div>
        </div>

        <!-- ── Actions ──────────────────────────────────────────── -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3"
               style="border-radius:var(--zv-radius-lg)">
            <Link :href="route('settings.coa.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('settings.chartOfAccounts.form.createAccount')) }}
            </button>
          </div>
        </div>

      </form>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  account:          Object,
  selected_parent:  Object,   // full parent object passed from controller — avoids ID type issues
  currencies:       Array,
  parents:          Array,
})

const isEdit = computed(() => !!props.account?.id)
const errors = computed(() => form.errors)

// ── Form ──────────────────────────────────────────────────────────
const initialParentId = props.account?.parent_id ?? props.selected_parent?.id ?? null

const form = useForm({
  parent_id:            initialParentId,
  use_auto_code:        true,
  code:                 '',
  name_en:              props.account?.name_en              ?? '',
  name_ar:              props.account?.name_ar              ?? '',
  currency_id:          props.account?.currency_id          ?? null,
  requires_cost_center: props.account?.requires_cost_center ?? false,
})

// ── Parent resolution ─────────────────────────────────────────────
// Priority: full object from server (pre-selected) → lookup in parents array
// We never rely on ID type-matching alone — controller passes the full object
const selectedParent = computed(() => {
  if (!form.parent_id) return null
  if (props.selected_parent && props.selected_parent.id == form.parent_id) {
    return props.selected_parent
  }
  return props.parents?.find(p => p.id == form.parent_id) ?? null
})

// Suggested code shown in auto-code field
const suggestedCode = ref(
  props.selected_parent ? (props.selected_parent.code ?? '') + '001' : ''
)

function onParentChange() {
  if (!form.parent_id) { suggestedCode.value = ''; return }
  suggestedCode.value = (selectedParent.value?.code ?? '') + '001'
}

// !! cast — allow_currency may be 0/1 integer from Laravel get() with column list
const parentAllowsCurrency = computed(() =>
  !!selectedParent.value?.allow_currency
)

// Controls Track by Cost Center visibility — Income Statement only
const parentSection = computed(() =>
  selectedParent.value?.section ?? props.account?.section ?? null
)

// ── Group parents by section for the optgroup select ─────────────
const parentGrouped = computed(() => {
  const bs = (props.parents ?? []).filter(p => p.section === 'balance_sheet')
  const is = (props.parents ?? []).filter(p => p.section === 'income_statement')
  return [
    { label: t('settings.chartOfAccounts.form.balanceSheet'), accounts: bs },
    { label: t('settings.chartOfAccounts.form.incomeStatement'), accounts: is },
  ].filter(g => g.accounts.length > 0)
})

// Show selector open only when no parent pre-selected
const showParentSelector = ref(!props.selected_parent && !props.account?.parent_id)

// ── Submit ────────────────────────────────────────────────────────
function submit() {
  if (isEdit.value) {
    form.put(route('settings.coa.update', props.account.id))
  } else {
    form.post(route('settings.coa.store'))
  }
}
</script>

<style scoped>
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
  width: 36px; height: 36px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.form-section-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--zv-text-primary);
}
.form-section-sub {
  font-size: 0.75rem;
  color: var(--zv-text-muted);
  margin-top: 0.125rem;
}
.form-section-body { padding: 1.375rem; }

.field-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}
@media (min-width: 640px) {
  .field-grid { grid-template-columns: repeat(2, 1fr); }
}
.field-group { display: flex; flex-direction: column; }

.parent-selected-pill {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.625rem 0.875rem;
  border: 2px solid var(--zv-blue-border);
  border-radius: var(--zv-radius);
  background: var(--zv-blue-light);
  color:#ffffff;
}
.parent-pill-info {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;
  
  
}

.field-hint {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  margin-top: 0.25rem;
  font-style: italic;
}

.parent-info-card {
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.875rem 1.125rem;
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
  
  
}
.parent-info-row {
  display: flex;
  align-items: center;
  gap: 3rem;
  
  
}
.parent-info-label {
  font-size: 0.9rem;
  color: var(--zv-text-muted);
  min-width: 110px;
  color:black;
}
</style>