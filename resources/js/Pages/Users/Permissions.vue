<!--
══════════════════════════════════════════════════════════════════
  Users/Permissions.vue

  Feature-level permission matrix for a single user.

  Structure:
    - Six module sections (Accounting, Banking, HR, Analytics,
      Planning, Valuation)
    - Each module grouped by tab/section
    - Each feature row has up to 4 flags: View / Create / Edit / Reverse
    - Locked rows for modules not in subscription tier
    - View-only rows for report/analytics features

  Flag availability per feature type:
    full       → View + Create + Edit + Reverse
    no_reverse → View + Create + Edit only
    view_only  → View only (reports, analytics)

  Company Admin:
    Matrix is read-only — all in-tier features shown as granted
    with info banner.

  Quick actions:
    Grant All — grants all available flags on all in-tier features
    Revoke All — revokes all flags
    View Only  — grants view only on all in-tier features

  @layout    AppLayout
  @route     users.permissions
  @props     user: User
             permissions: nested object { module: { feature: { flags } } }
             tier: String — company subscription tier
             isManufacturing: Boolean — show manufacturing tab
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ── Page header ────────────────────────────────── -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('users.index')">{{ t('users.permissions.users') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <Link :href="route('users.edit', user.id)">{{ user.name }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ t('users.permissions.permissions') }}</span>
                    </div>
                    <div class="zv-page-title">{{ t('users.permissions.modulePermissions') }}</div>
                    <div class="zv-page-subtitle">
                        {{ t('users.permissions.configureWhat') }}
                        <strong>{{ user.name }}</strong>
                        {{ t('users.permissions.canAccessAndDoInEach') }}
                    </div>
                </div>
                <Link :href="route('users.edit', user.id)" class="zv-btn zv-btn-secondary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    {{ t('common.backToUser') }}
                </Link>
            </div>

            <!-- ── Company Admin banner ───────────────────────── -->
            <div v-if="user.role === 'company_admin'" class="zv-alert zv-alert-info" style="margin-bottom: 1.25rem;">
                <svg class="w-5 h-5 zv-alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <div>
                    <strong>{{ t('users.permissions.companyAdminFullAccess') }}</strong><br>
                    {{ t('users.permissions.companyAdminAutomaticallyHasFullAccess') }}
                </div>
            </div>

            <!-- ── Module tabs ────────────────────────────────── -->
            <div class="zv-tabs" style="margin-bottom: 1.25rem;">
                <button
                    v-for="mod in visibleModules"
                    :key="mod.key"
                    type="button"
                    class="zv-tab"
                    :class="{ active: activeModule === mod.key }"
                    @click="activeModule = mod.key"
                >
                    {{ t(mod.label) }}
                    <span v-if="!isInTier(mod.key)" class="locked-tab-badge">
                        {{ t('users.permissions.locked') }}
                    </span>
                </button>
            </div>

            <!-- ── Permission matrix ──────────────────────────── -->
            <div class="zv-card">
                <div class="zv-card-header">
                    <div class="zv-card-title">
                        <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        {{ t('users.permissions.permissionMatrix') }} — {{ t(activeModuleDef?.label) }}
                    </div>
                    <div style="font-size: 12px; color: var(--zv-text-muted);">
                        {{ t('users.permissions.subscriptionTier') }}: <strong>{{ tier }}</strong>
                    </div>
                </div>

                <!-- Locked module notice -->
                <div v-if="!isInTier(activeModule)" class="locked-notice">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <div>
                        <strong>{{ t('users.permissions.notInYourPlan') }}</strong><br>
                        <span style="font-size: 12px;">{{ t('users.permissions.upgradeYourSubscriptionToUnlockThis') }}</span>
                    </div>
                </div>

                <!-- Feature table -->
                <div v-else class="perm-table-wrap">
                    <table class="perm-table">
                        <thead>
                            <tr>
                                <th class="feature-col">{{ t('users.permissions.feature') }}</th>
                                <th class="flag-col">{{ t('common.view') }}</th>
                                <th class="flag-col">{{ t('common.create') }}</th>
                                <th class="flag-col">{{ t('common.edit') }}</th>
                                <th class="flag-col">{{ t('users.permissions.post') }}</th>
                                <th class="flag-col">{{ t('users.permissions.reverse') }}</th>
                                <th class="flag-col">{{ t('users.permissions.approve') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(tabFeatures, tabName) in groupedFeatures" :key="tabName">
                                <!-- Tab section header -->
                                <tr class="tab-header-row">
                                    <td colspan="7">
                                        <span class="tab-header-label">{{ t(tabName) }}</span>
                                    </td>
                                </tr>
                                <!-- Feature rows -->
                                <tr
                                    v-for="feature in tabFeatures"
                                    :key="feature.key"
                                    class="feature-row"
                                >
                                    <td class="feature-cell">
                                        <span class="feature-name">{{ t(feature.label) }}</span>
                                    </td>

                                    <!-- View -->
                                    <td class="flag-cell">
                                        <div class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_view')"
                                                    @change="setFlag(feature.key, 'can_view', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_view') }">
                                                    <svg v-if="getFlag(feature.key, 'can_view')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                    </td>

                                    <!-- Create -->
                                    <td class="flag-cell">
                                        <div v-if="feature.flags !== 'view_only'" class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_create')"
                                                    @change="setFlag(feature.key, 'can_create', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_create') }">
                                                    <svg v-if="getFlag(feature.key, 'can_create')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                        <span v-else class="flag-na">—</span>
                                    </td>

                                    <!-- Edit -->
                                    <td class="flag-cell">
                                        <div v-if="feature.flags !== 'view_only'" class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_edit')"
                                                    @change="setFlag(feature.key, 'can_edit', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_edit') }">
                                                    <svg v-if="getFlag(feature.key, 'can_edit')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                        <span v-else class="flag-na">—</span>
                                    </td>

                                    <!-- can_post (Audit H3) -->
                                    <td class="flag-cell">
                                        <div v-if="feature.flags === 'full'" class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_post')"
                                                    @change="setFlag(feature.key, 'can_post', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_post') }">
                                                    <svg v-if="getFlag(feature.key, 'can_post')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                        <span v-else class="flag-na">—</span>
                                    </td>

                                    <!-- Reverse -->
                                    <td class="flag-cell">
                                        <div v-if="feature.flags === 'full'" class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_reverse')"
                                                    @change="setFlag(feature.key, 'can_reverse', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_reverse') }">
                                                    <svg v-if="getFlag(feature.key, 'can_reverse')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                        <span v-else class="flag-na">—</span>
                                    </td>

                                    <!-- can_approve (Audit H3) -->
                                    <td class="flag-cell">
                                        <div v-if="feature.flags === 'full'" class="perm-check-wrap">
                                            <div v-if="user.role === 'company_admin'" class="perm-admin-dot">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <label v-else class="perm-label">
                                                <input type="checkbox" class="perm-input"
                                                    :checked="getFlag(feature.key, 'can_approve')"
                                                    @change="setFlag(feature.key, 'can_approve', $event.target.checked)"
                                                />
                                                <span class="perm-box" :class="{ checked: getFlag(feature.key, 'can_approve') }">
                                                    <svg v-if="getFlag(feature.key, 'can_approve')" class="w-3 h-3" fill="none" stroke="white" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            </label>
                                        </div>
                                        <span v-else class="flag-na">—</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Quick actions -->
                <div class="quick-actions" v-if="user.role !== 'company_admin' && isInTier(activeModule)">
                    <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="grantAll">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ t('users.permissions.grantAllAvailable') }}
                    </button>
                    <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="revokeAll">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        {{ t('users.permissions.revokeAll') }}
                    </button>
                    <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="viewOnly">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        {{ t('users.permissions.viewOnly') }}
                    </button>
                </div>
            </div>

            <!-- ── Form actions ───────────────────────────────── -->
            <div class="form-actions" v-if="user.role !== 'company_admin'">
                <Link :href="route('users.index')" class="zv-btn zv-btn-secondary">
                    {{ t('common.cancel') }}
                </Link>
                <button
                    type="button"
                    class="zv-btn zv-btn-success"
                    :disabled="saving"
                    @click="savePermissions"
                >
                    <svg v-if="!saving" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                    </svg>
                    <span v-if="!saving">{{ t('users.permissions.savePermissions') }}</span>
                    <span v-else style="display:flex;align-items:center;gap:6px;">
                        <span class="zv-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;"></span>
                        {{ t('common.saving') }}
                    </span>
                </button>
            </div>

        </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

// Checkboxes rendered inline in template — no component needed

const { t } = useI18n()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    user:            Object,
    permissions:     Object,
    tier:            String,
    isManufacturing: { type: Boolean, default: false },
})

// ── Tier module map ───────────────────────────────────────────────
const TIER_MODULES = {
    Starter:    ['accounting', 'banking', 'hr'],
    Growth:     ['accounting', 'banking', 'hr', 'analytics'],
    Pro:        ['accounting', 'banking', 'hr', 'analytics', 'planning'],
    Enterprise: ['accounting', 'banking', 'hr', 'analytics', 'planning', 'valuation'],
}

function isInTier(mod) {
    return (TIER_MODULES[props.tier] ?? []).includes(mod)
}

// ── Module definitions ────────────────────────────────────────────
const allModules = [
    { key: 'accounting', label: 'Accounting' },
    { key: 'banking',    label: 'Banking'    },
    { key: 'hr',         label: 'HR'         },
    { key: 'analytics',  label: 'Analytics'  },
    { key: 'planning',   label: 'Planning'   },
    { key: 'valuation',  label: 'Valuation'  },
]

const visibleModules = computed(() => allModules)

// ── Feature definitions (mirroring UserModulePermission::FEATURES) ─
const FEATURES = {
    accounting: [
        { key: 'sales_contracts',       label: 'Contracts',               tab: 'Sales Cycle',       flags: 'full'      },
        { key: 'sales_orders',          label: 'Sales Orders',            tab: 'Sales Cycle',       flags: 'full'      },
        { key: 'customer_invoices',     label: 'Customer Invoices',       tab: 'Sales Cycle',       flags: 'full'      },
        { key: 'customer_receipts',     label: 'Customer Receipts',       tab: 'Sales Cycle',       flags: 'full'      },
        { key: 'accounts_receivable',   label: 'Accounts Receivable',     tab: 'Sales Cycle',       flags: 'view_only' },
        { key: 'purchase_orders',       label: 'Purchase Orders',         tab: 'Purchase Cycle',    flags: 'full'      },
        { key: 'goods_receipt',         label: 'Goods Receipt',           tab: 'Purchase Cycle',    flags: 'full'      },
        { key: 'supplier_bills',        label: 'Supplier Bills',          tab: 'Purchase Cycle',    flags: 'full'      },
        { key: 'supplier_payments',     label: 'Supplier Payments',       tab: 'Purchase Cycle',    flags: 'full'      },
        { key: 'accounts_payable',      label: 'Accounts Payable',        tab: 'Purchase Cycle',    flags: 'view_only' },
        { key: 'inventory_items',       label: 'Inventory Items',         tab: 'Inventory',         flags: 'no_reverse'},
        { key: 'inventory_movement',    label: 'Inventory Movement',      tab: 'Inventory',         flags: 'full'      },
        { key: 'internal_transfer',     label: 'Internal Transfer',       tab: 'Inventory',         flags: 'full'      },
        { key: 'inventory_adjustment',  label: 'Inventory Adjustment',    tab: 'Inventory',         flags: 'full'      },
        { key: 'stock_ledger',          label: 'Stock Ledger',            tab: 'Inventory',         flags: 'view_only' },
        { key: 'cash_expenses',         label: 'Cash Expenses',           tab: 'Expenses',          flags: 'full'      },
        { key: 'employee_expenses',     label: 'Employee Expenses',       tab: 'Expenses',          flags: 'full'      },
        { key: 'shareholder_transactions',label:'Shareholder Transactions',tab: 'Expenses',          flags: 'full'      },
        { key: 'journal_entries',       label: 'Journal Entries',         tab: 'General',           flags: 'full'      },
        { key: 'general_ledger',        label: 'General Ledger',          tab: 'General',           flags: 'view_only' },
        { key: 'fixed_assets',          label: 'Fixed Assets',            tab: 'General',           flags: 'full'      },
        ...(props.isManufacturing ? [
            { key: 'bill_of_materials', label: 'Bill of Materials',       tab: 'Manufacturing',     flags: 'no_reverse'},
            { key: 'production_orders', label: 'Production Orders',       tab: 'Manufacturing',     flags: 'full'      },
        ] : []),
        { key: 'trial_balance',         label: 'Trial Balance',           tab: 'Financial Reports', flags: 'view_only' },
        { key: 'profit_loss',           label: 'Profit & Loss',           tab: 'Financial Reports', flags: 'view_only' },
        { key: 'balance_sheet',         label: 'Balance Sheet',           tab: 'Financial Reports', flags: 'view_only' },
        { key: 'cash_flow',             label: 'Cash Flow Statement',     tab: 'Financial Reports', flags: 'view_only' },
        { key: 'acc_chart_of_accounts', label: 'Chart of Accounts',       tab: 'Acc. Settings',     flags: 'no_reverse'},
        { key: 'acc_cost_centers',      label: 'Cost Centers',            tab: 'Acc. Settings',     flags: 'no_reverse'},
        { key: 'acc_fiscal_periods',    label: 'Fiscal Periods',          tab: 'Acc. Settings',     flags: 'no_reverse'},
        { key: 'acc_currencies',        label: 'Currencies',              tab: 'Acc. Settings',     flags: 'no_reverse'},
        { key: 'opening_balances',      label: 'Opening Balances',        tab: 'Acc. Settings',     flags: 'no_reverse'},
    ],
    banking: [
        { key: 'credit_facilities',   label: 'Credit Facilities',        tab: 'Facilities',        flags: 'full'      },
        { key: 'cash_position',       label: 'Cash Position',            tab: 'Cash Management',   flags: 'view_only' },
        { key: 'cash_forecast',       label: 'Cash Forecast',            tab: 'Cash Management',   flags: 'no_reverse'},
        { key: 'treasury_operations', label: 'Treasury Operations',      tab: 'Cash Management',   flags: 'full'      },
        { key: 'banks_lenders',       label: 'Banks & Lenders',          tab: 'Master Data',       flags: 'no_reverse'},
    ],
    hr: [
        { key: 'employees',           label: 'Employees',                tab: 'Master Data',       flags: 'no_reverse'},
        { key: 'payroll',             label: 'Payroll',                  tab: 'Payroll',           flags: 'full'      },
        { key: 'advances_settlements',label: 'Advances & Settlements',   tab: 'Payroll',           flags: 'full'      },
        { key: 'leave_management',    label: 'Leave Management',         tab: 'Attendance',        flags: 'no_reverse'},
        { key: 'expense_claims',      label: 'Expense Claims',           tab: 'Attendance',        flags: 'full'      },
    ],
    analytics: [
        { key: 'cfo_dashboard',       label: 'CFO Dashboard',            tab: 'Dashboards',        flags: 'view_only' },
        { key: 'sales_analysis',      label: 'Sales Analysis',           tab: 'Analysis',          flags: 'view_only' },
        { key: 'expense_analysis',    label: 'Expense Analysis',         tab: 'Analysis',          flags: 'view_only' },
        { key: 'profitability',       label: 'Profitability',            tab: 'Analysis',          flags: 'view_only' },
        { key: 'financial_ratios',    label: 'Financial Ratios',         tab: 'Analysis',          flags: 'view_only' },
    ],
    planning: [
        { key: 'annual_plan',         label: 'Annual Plan',              tab: 'Planning',          flags: 'full'      },
        { key: 'budget_vs_actual',    label: 'Budget vs Actual',         tab: 'Planning',          flags: 'view_only' },
        { key: 'business_plan',       label: 'Business Plan',            tab: 'Planning',          flags: 'full'      },
        { key: 'scenario_modeling',   label: 'Scenario Modeling',        tab: 'Planning',          flags: 'full'      },
    ],
    valuation: [
        { key: 'dcf_valuation',       label: 'DCF Valuation',            tab: 'Valuation',         flags: 'full'      },
        { key: 'multiples',           label: 'Multiples',                tab: 'Valuation',         flags: 'full'      },
        { key: 'scenario_valuation',  label: 'Scenario Valuation',       tab: 'Valuation',         flags: 'full'      },
        { key: 'investor_report',     label: 'Investor Report',          tab: 'Reports',           flags: 'view_only' },
    ],
}

// ── Active module tab ─────────────────────────────────────────────
const activeModule    = ref('accounting')
const activeModuleDef = computed(() => allModules.find(m => m.key === activeModule.value))

// ── Grouped features for active module ───────────────────────────
const groupedFeatures = computed(() => {
    const features = FEATURES[activeModule.value] ?? []
    const groups = {}
    for (const f of features) {
        if (!groups[f.tab]) groups[f.tab] = []
        groups[f.tab].push(f)
    }
    return groups
})

// ── Local permissions state ───────────────────────────────────────
const localPerms = reactive(
    JSON.parse(JSON.stringify(props.permissions ?? {}))
)


function getFlag(feature, flag) {
    if (user.role === 'company_admin') return true
    return localPerms[activeModule.value]?.[feature]?.[flag] ?? false
}

function setFlag(feature, flag, value) {
    if (!localPerms[activeModule.value]) localPerms[activeModule.value] = {}
    if (!localPerms[activeModule.value][feature]) {
        localPerms[activeModule.value][feature] = {
            can_view: false, can_create: false, can_edit: false, can_reverse: false,
            can_post: false, can_approve: false,
        }
    }
    localPerms[activeModule.value][feature][flag] = value
}

// ── Quick actions (operate on active module only) ─────────────────
function grantAll() {
    const features = FEATURES[activeModule.value] ?? []
    for (const f of features) {
        setFlag(f.key, 'can_view', true)
        if (f.flags !== 'view_only') {
            setFlag(f.key, 'can_create', true)
            setFlag(f.key, 'can_edit', true)
        }
        if (f.flags === 'full') {
            setFlag(f.key, 'can_reverse', true)
            setFlag(f.key, 'can_post', true)
            // can_approve deliberately NOT granted by "grant all" (Audit H3):
            // approvers are a conscious, separate choice.
        }
    }
}

function revokeAll() {
    const features = FEATURES[activeModule.value] ?? []
    for (const f of features) {
        setFlag(f.key, 'can_view', false)
        setFlag(f.key, 'can_create', false)
        setFlag(f.key, 'can_edit', false)
        setFlag(f.key, 'can_reverse', false)
        setFlag(f.key, 'can_post', false)
        setFlag(f.key, 'can_approve', false)
    }
}

function viewOnly() {
    const features = FEATURES[activeModule.value] ?? []
    for (const f of features) {
        setFlag(f.key, 'can_view', true)
        setFlag(f.key, 'can_create', false)
        setFlag(f.key, 'can_edit', false)
        setFlag(f.key, 'can_reverse', false)
        setFlag(f.key, 'can_post', false)
        setFlag(f.key, 'can_approve', false)
    }
}

// ── Save all modules ──────────────────────────────────────────────
const saving = ref(false)
const user   = props.user

function savePermissions() {
    saving.value = true
    router.put(
        route('users.permissions.save', user.id),
        { permissions: localPerms },
        {
            preserveScroll: true,
            onFinish: () => { saving.value = false },
        }
    )
}
</script>

<style scoped>
/* ── Module tabs ──────────────────────────────────────────────── */
.locked-tab-badge {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    background: var(--zv-bg-surface-alt);
    color: var(--zv-text-muted);
    border-radius: 4px;
    padding: 1px 5px;
    margin-inline-start: 5px;
}

/* ── Locked module notice ─────────────────────────────────────── */
.locked-notice {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 24px;
    color: var(--zv-text-muted);
    font-size: 14px;
}

/* ── Permission table ─────────────────────────────────────────── */
.perm-table-wrap { overflow-x: auto; }

.perm-table {
    width: 100%;
    border-collapse: collapse;
}

.perm-table thead th {
    padding: 10px 16px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    background: #0F2554;
    color: #FFFFFF;
    text-align: center;
    border-bottom: 1px solid var(--zv-border);
}

.feature-col { text-align: start !important; width: 300px; }
.flag-col    { width: 110px; }

/* Tab section header */
.tab-header-row td {
    padding: 10px 16px 4px;
    background: var(--zv-bg-surface);
    border-top: 1px solid var(--zv-border);
    border-bottom: 1px solid var(--zv-border);
}

.tab-header-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #1490A8;
}

/* Feature row */
.feature-row {
    border-bottom: 1px solid var(--zv-border);
    transition: background 0.1s;
}

.feature-row:hover { background: var(--zv-bg-surface); }

.feature-cell {
    padding: 10px 16px;
}

.feature-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--zv-text-primary);
}

.flag-cell {
    padding: 10px 16px;
    text-align: center;
}

.flag-na {
    color: var(--zv-text-muted);
    font-size: 14px;
}

/* ── Permission checkbox ──────────────────────────────────────── */
.perm-check-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
}

.perm-admin-dot {
    width: 26px; height: 26px;
    background: var(--zv-green-light);
    border: 1.5px solid var(--zv-green-border);
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--zv-green-text);
}

.perm-lock-dot {
    width: 26px; height: 26px;
    background: var(--zv-bg-surface-alt);
    border: 1.5px solid var(--zv-border);
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--zv-text-muted);
}

.perm-label {
    display: inline-block;
    cursor: pointer;
    position: relative;
}

.perm-input {
    position: absolute;
    opacity: 0;
    width: 0; height: 0;
}

.perm-box {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px; height: 26px;
    background: var(--zv-bg-card);
    border: 1.5px solid var(--zv-border-md);
    border-radius: 7px;
    transition: all 0.12s ease;
}

.perm-box.checked {
    background: var(--zv-blue);
    border-color: var(--zv-blue);
}

.perm-label:hover .perm-box {
    border-color: var(--zv-blue);
    box-shadow: 0 0 0 3px var(--zv-blue-light);
}

/* ── Quick actions + form actions ────────────────────────────── */
.quick-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--zv-border);
    background: var(--zv-bg-surface);
}

.form-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--zv-border);
}
</style>