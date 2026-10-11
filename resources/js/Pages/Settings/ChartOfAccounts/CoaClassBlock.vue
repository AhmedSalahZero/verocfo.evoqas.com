<!--
══════════════════════════════════════════════════════════════════
  Settings/ChartOfAccounts/CoaClassBlock.vue

  Renders one COA class (Assets / Liabilities / Equity /
  Revenue / Expenses) as an expandable card with account rows.

  Each row shows: indent, code, name, normal balance badge,
  postable indicator, currency (if set), system lock or actions.

  Account rows are indented by level (1–5) with a subtle
  connecting line to show hierarchy visually.

  Emits: toggle (expand/collapse), edit(account), toggle-active(account)

  @props  accounts: ChartOfAccount[] — flat list filtered to this class
          classKey: string — assets | liabilities | equity | revenue | expenses
          expanded: boolean
          search: string
══════════════════════════════════════════════════════════════════
-->

<template>
  <div class="coa-class-card mb-3">

    <!-- Class header ────────────────────────────────────────── -->
    <button type="button" class="coa-class-header" @click="$emit('toggle')">
      <div class="flex items-center gap-3">
        <div class="coa-class-dot" :class="`coa-dot--${classKey}`"></div>
        <span class="coa-class-title">{{ t(classLabels[classKey]) }}</span>
        <span class="zv-badge" :class="classBadge[classKey]">
          {{ accounts.length }} {{ t('settings.chartOfAccounts.coaClassBlock.accounts') }}
        </span>
      </div>
      <svg class="coa-chevron w-4 h-4 transition-transform duration-200"
           :class="{ 'rotate-180': expanded }"
           fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
      </svg>
    </button>

    <!-- Account rows ─────────────────────────────────────────── -->
    <Transition name="coa-expand">
      <div v-if="expanded" class="coa-rows">

        <div v-if="accounts.length === 0" class="coa-empty">
          {{ t('settings.chartOfAccounts.coaClassBlock.noAccountsFound') }}
        </div>

        <div v-for="account in sortedAccounts" :key="account.id"
             class="coa-row"
             :class="[`coa-row--level-${account.level}`, { 'coa-row--inactive': !account.is_active }]">

          <!-- Indent connector -->
          <div class="coa-indent" :style="{ width: ((account.level - 1) * 20) + 'px' }">
            <div v-if="account.level > 1" class="coa-connector"></div>
          </div>

          <!-- Level indicator dot -->
          <div class="coa-level-dot" :class="`coa-level-dot--${account.level}`"></div>

          <!-- Code -->
          <div class="coa-code font-mono">{{ account.code }}</div>

          <!-- Name -->
          <div class="coa-name flex-1 min-w-0">
            <span :class="{ 'font-semibold': account.level <= 2, 'font-medium': account.level === 3 }">
              {{ account.name_en }}
            </span>
            <span v-if="account.name_ar" class="coa-name-ar">{{ account.name_ar }}</span>
          </div>

          <!-- Badges row -->
          <div class="coa-badges">
            <!-- Normal balance -->
            <span class="zv-badge" :class="account.normal_balance === 'debit' ? 'zv-badge-blue' : 'zv-badge-amber'">
              {{ account.normal_balance === 'debit' ? t('settings.chartOfAccounts.coaClassBlock.dr') : t('settings.chartOfAccounts.coaClassBlock.cr') }}
            </span>

            <!-- Postable -->
            <span v-if="account.is_postable" class="zv-badge zv-badge-green">
              {{ t('settings.chartOfAccounts.coaClassBlock.postable') }}
            </span>

            <!-- Currency -->
            <span v-if="account.currency" class="zv-badge zv-badge-gray">
              {{ account.currency.code }}
            </span>

            <!-- System lock -->
            <span v-if="account.is_system" class="coa-lock-badge" :title="t('settings.chartOfAccounts.coaClassBlock.systemAccountLocked')">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
            </span>
          </div>

          <!--
            Actions.

            Fix (Sept 2026): "Add sub-account" used to live inside the
            v-else below, so ONLY system accounts had it. A user's own
            account — 111007 dd sitting right beside the seeded 111001
            Land — showed Edit and Deactivate and no way to add
            anything under it, at any level.

            Nothing in the service supports that split:
            validateUserAccount() checks the parent's LEVEL and its
            code, and never looks at is_system. The backend has always
            accepted a child under a user account; the button was
            simply in the wrong branch. It is now outside the
            system/user split, governed by its own level rule alone,
            so a user account gets Edit + Deactivate + Add sub and a
            system account gets Add sub.
          -->
          <div class="coa-actions">
            <template v-if="!account.is_system">
              <button type="button" @click="$emit('edit', account)"
                      class="zv-btn zv-btn-secondary zv-btn-sm">
                {{ t('common.edit') }}
              </button>
              <button type="button" @click="$emit('toggle-active', account)"
                      class="zv-btn zv-btn-sm"
                      :class="account.is_active ? 'zv-btn-danger' : 'zv-btn-success'">
                {{ account.is_active ? t('common.deactivate') : t('common.activate') }}
              </button>
            </template>

            <!--
              Add sub-account button — levels 3 and 4 normally.
              Fix (July 2026): Sales Expenses (52), Marketing (53),
                and G&A (54) have no Level 3 accounts under them at
                all, so they need this button too, even at Level 2 —
                otherwise there's no way to ever add anything under
                these three. Every other Level 2 group (Assets,
                Current Assets, etc.) still only gets the button on
                its Level 3/4 accounts, same as before.

                Fix (July 2026): 135001 (Raw Materials), 135002 (WIP),
                135003 (Finished Goods), and 135004 (Project WIP) are
                excluded outright — sub-accounts under these are
                created automatically, one per Product Category, the
                moment a product needs one (ProductService::
                resolveCategoryInventoryAccount()). The backend
                already refuses a manual add here (ChartOfAccount
                Service::validateUserAccount()); this button was still
                showing and letting someone start the flow before
                hitting that wall — now it just doesn't appear.
              -->
            <Link v-if="((account.level >= 3 && account.level < 5) || (account.level === 2 && ['52', '53', '54'].includes(account.code))) && !INVENTORY_MANAGED_CODES.includes(account.code)"
                  :href="route('settings.coa.create', { parent_id: account.id })"
                  class="zv-btn zv-btn-secondary zv-btn-sm">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('settings.chartOfAccounts.coaClassBlock.addSub') }}
            </Link>
          </div>

        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// Fix (July 2026): these 4 accounts are fully managed by Product
// Category auto-creation — see ProductService::
// resolveCategoryInventoryAccount(). No manual sub-account, ever.
const INVENTORY_MANAGED_CODES = ['135001', '135002', '135003', '135004']

const props = defineProps({
  accounts: Array,
  classKey: String,
  expanded: Boolean,
  search:   String,
})

defineEmits(['toggle', 'edit', 'toggle-active'])

const classLabels = {
  assets:      'Assets',
  liabilities: 'Liabilities',
  equity:      'Equity',
  revenue:     'Revenue',
  expenses:    'Expenses',
}

const classBadge = {
  assets:      'zv-badge-blue',
  liabilities: 'zv-badge-danger',
  equity:      'zv-badge-navy',
  revenue:     'zv-badge-green',
  expenses:    'zv-badge-amber',
}

const sortedAccounts = computed(() =>
  [...(props.accounts ?? [])].sort((a, b) => a.code.localeCompare(b.code))
)
</script>

<style scoped>
/* ── Class Card ─────────────────────────────────────────────── */
.coa-class-card {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  box-shadow: var(--zv-shadow-sm);
}

/* ── Class Header ───────────────────────────────────────────── */
.coa-class-header {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.875rem 1.25rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
  cursor: pointer;
  transition: background 0.15s ease;
  text-align: start;
}
.coa-class-header:hover { background: var(--zv-bg-surface-alt); }

.coa-class-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.coa-dot--assets      { background: var(--zv-blue); }
.coa-dot--liabilities { background: var(--zv-danger); }
.coa-dot--equity      { background: #0F2554; }
.coa-dot--revenue     { background: var(--zv-green); }
.coa-dot--expenses    { background: var(--zv-amber); }

.coa-class-title {
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--zv-text-primary);
}

.coa-chevron { color: var(--zv-text-muted); }

/* ── Account Rows ───────────────────────────────────────────── */
.coa-rows { border-top: 1px solid var(--zv-border); }

.coa-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1.25rem;
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.1s ease;
  min-height: 44px;
}
.coa-row:last-child { border-bottom: none; }
.coa-row:hover { background: var(--zv-bg-hover); }
.coa-row--inactive { opacity: 0.45; }

/* Level-based left border accent */
.coa-row--level-1 { background: var(--zv-bg-surface-alt); }
.coa-row--level-2 { border-inline-start: 3px solid var(--zv-border-md); }
.coa-row--level-3 { border-inline-start: 3px solid var(--zv-blue-border); }
.coa-row--level-4 { border-inline-start: 3px solid var(--zv-blue); }
.coa-row--level-5 { border-inline-start: 3px solid var(--zv-green); }

.coa-indent { flex-shrink: 0; position: relative; }
.coa-connector {
  position: absolute;
  inset-inline-end: 0;
  top: 50%;
  width: 12px;
  height: 1px;
  background: var(--zv-border-md);
}

.coa-level-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.coa-level-dot--1 { background: var(--zv-text-primary); width: 8px; height: 8px; }
.coa-level-dot--2 { background: var(--zv-text-secondary); }
.coa-level-dot--3 { background: var(--zv-blue); }
.coa-level-dot--4 { background: var(--zv-green); }
.coa-level-dot--5 { background: var(--zv-amber); }

.coa-code {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--zv-blue-text);
  min-width: 72px;
  flex-shrink: 0;
}

.coa-name {
  font-size: 0.8125rem;
  color: var(--zv-text-primary);
  display: flex;
  flex-direction: column;
}
.coa-name-ar {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  direction: rtl;
  text-align: start;
}

.coa-badges {
  display: flex;
  gap: 0.375rem;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.coa-lock-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px; height: 20px;
  border-radius: var(--zv-radius-sm);
  background: var(--zv-bg-surface-alt);
  color: var(--zv-text-muted);
}

.coa-actions {
  display: flex;
  gap: 0.375rem;
  flex-shrink: 0;
  margin-inline-start: auto;
}

.coa-empty {
  padding: 2rem;
  text-align: center;
  font-size: 0.875rem;
  color: var(--zv-text-muted);
}

/* ── Expand Transition ──────────────────────────────────────── */
.coa-expand-enter-active { transition: all 0.2s ease; }
.coa-expand-leave-active { transition: all 0.15s ease; }
.coa-expand-enter-from, .coa-expand-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
