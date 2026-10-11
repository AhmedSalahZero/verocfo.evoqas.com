<!--
══════════════════════════════════════════════════════════════════
  Companies/Form.vue

  Create and Edit Company — single page for both modes.

  Mode detection:
    - props.company === null  → Create mode (POST to companies.store)
    - props.company has id    → Edit mode   (PUT  to companies.update)

  Sections:
    - Company Identity    (name EN/AR, type, business sector, legal structure, tax number)
    - Contact & Location  (phone, address, country, timezone)
    - Financial Settings  (base currency, fiscal year start month, opening balance date)
    - Subscription        (tier, additional users, expiry, active toggle)

  business_sectors comes from the backend as a prop — loaded once
  at render time from the platform-level business_sectors table.
  Never fetched via API from the frontend.

  The tier info box shows base user limit and included modules
  dynamically as the user changes the subscription tier.

  @layout    SuperAdminLayout
  @route     companies.create | companies.edit
  @props     company: Company|null — null for create, model for edit
             business_sectors: Array — platform-level sector list
══════════════════════════════════════════════════════════════════
-->
<template>
    <SuperAdminLayout>

        <!-- ── Page header ──────────────────────────────────────── -->
        <div class="zv-page-header">
            <div>
                <div class="zv-breadcrumb">
                    <Link :href="route('companies.index')">{{ t('companies.form.companies') }}</Link>
                    <span class="zv-breadcrumb-sep">/</span>
                    <span>{{ isEdit ? t('companies.form.editCompany') : t('companies.form.newCompany') }}</span>
                </div>
                <div class="zv-page-title">
                    {{ isEdit ? t('companies.form.editCompany') : t('companies.form.newCompany') }}
                </div>
                <div class="zv-page-subtitle">
                    {{ isEdit ? company.name_en : t('companies.form.fillInTheDetailsToCreate') }}
                </div>
            </div>
        </div>

        <form @submit.prevent="submit">
            <div class="form-grid">

                <!-- ── Left column ─────────────────────────────── -->
                <div class="form-col">

                    <!-- Identity -->
                    <div class="zv-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">
                                <i class="ti ti-building" aria-hidden="true"></i>
                                {{ t('companies.form.companyIdentity') }}
                            </div>
                        </div>
                        <div class="zv-card-body form-fields">

                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label zv-label-required">{{ t('companies.form.companyNameEnglish') }}</label>
                                    <input
                                        v-model="form.name_en"
                                        type="text"
                                        class="zv-input"
                                        :class="{ 'zv-input-error': form.errors.name_en }"
                                        placeholder="e.g. TechnoMetal Egypt"
                                    />
                                    <div v-if="form.errors.name_en" class="zv-field-error">
                                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                        {{ form.errors.name_en }}
                                    </div>
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('companies.form.companyNameArabic') }}</label>
                                    <input
                                        v-model="form.name_ar"
                                        type="text"
                                        class="zv-input zv-input-ar"
                                        dir="rtl"
                                        placeholder="مثال: تكنوميتال مصر"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="zv-label zv-label-required">{{ t('companies.form.companyType') }}</label>
                                <div class="type-multiselect">
                                    <label
                                        v-for="type in companyTypes"
                                        :key="type.value"
                                        class="type-option"
                                        :class="{ 'type-option--selected': form.company_type.includes(type.value) }"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="type.value"
                                            v-model="form.company_type"
                                            class="type-checkbox"
                                        />
                                        <div class="type-option-inner">
                                            <span class="type-option-icon">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="type.icon"></svg>
                                            </span>
                                            <div>
                                                <div class="type-option-label">{{ t(type.value) }}</div>
                                                <div class="type-option-desc">{{ t(type.desc) }}</div>
                                            </div>
                                            <div class="type-option-check" v-if="form.company_type.includes(type.value)">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <div v-if="form.errors.company_type" class="zv-field-error">
                                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                    {{ form.errors.company_type }}
                                </div>
                            </div>

                            <div>
                                <label class="zv-label zv-label-required">{{ t('companies.form.goodsReceiptPolicy') }}</label>
                                <OptionTiles
                                    :model-value="form.grn_policy" variant="card"
                                    :options="[
                                        { value: 'po_required', label: t('companies.form.pORequired'), description: t('companies.form.everyGoodsReceiptNoteMustTrace') },
                                        { value: 'allow_ad_hoc', label: t('companies.form.allowAdHocReceipts'), description: t('companies.form.goodsReceiptNotesMayAlsoBe') },
                                    ]"
                                    @update:model-value="(v) => form.grn_policy = v"
                                />
                                <div class="field-hint">{{ t('companies.form.setOnceAtCompanyCreationCan') }}</div>
                                <div v-if="form.errors.grn_policy" class="zv-field-error">
                                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                    {{ form.errors.grn_policy }}
                                </div>
                            </div>

                            <div>
                                <label class="zv-label">{{ t('companies.form.legalStructure') }}</label>
                                <select v-model="form.legal_structure" class="zv-select">
                                    <option value="">{{ t('companies.form.selectLegalStructure') }}</option>
                                    <option v-for="ls in legalStructures" :key="ls.value" :value="ls.value">
                                        {{ ls.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label">{{ t('companies.form.businessSector') }}</label>
                                    <select v-model="form.business_sector_id" class="zv-select">
                                        <option :value="null">{{ t('companies.form.selectSector') }}</option>
                                        <option
                                            v-for="sector in businessSectors"
                                            :key="sector.id"
                                            :value="sector.id"
                                        >
                                            {{ currentLocale === 'ar' && sector.name_ar ? sector.name_ar : sector.name_en }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('companies.form.taxRegistrationNumber') }}</label>
                                    <input v-model="form.tax_registration_number" type="text" class="zv-input" />
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="zv-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">
                                <i class="ti ti-map-pin" aria-hidden="true"></i>
                                {{ t('companies.form.contactLocation') }}
                            </div>
                        </div>
                        <div class="zv-card-body form-fields">

                            <div>
                                <label class="zv-label">{{ t('companies.form.phone') }}</label>
                                <input v-model="form.phone" type="text" class="zv-input" placeholder="+20 2 xxxx xxxx" />
                            </div>

                            <div>
                                <label class="zv-label">{{ t('companies.form.address') }}</label>
                                <textarea v-model="form.address" class="zv-textarea" rows="2"></textarea>
                            </div>

                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label">{{ t('companies.form.country') }}</label>
                                    <input v-model="form.country" type="text" class="zv-input" />
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('companies.form.timezone') }}</label>
                                    <select v-model="form.timezone" class="zv-select">
                                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                                    </select>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

                <!-- ── Right column ────────────────────────────── -->
                <div class="form-col">

                    <!-- Financial settings -->
                    <div class="zv-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">
                                <i class="ti ti-currency-dollar" aria-hidden="true"></i>
                                {{ t('companies.form.financialSettings') }}
                            </div>
                        </div>
                        <div class="zv-card-body form-fields">

                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label zv-label-required">{{ t('companies.form.baseCurrency') }}</label>
                                    <select
                                        v-model="form.base_currency"
                                        class="zv-select"
                                        :class="{ 'zv-input-error': form.errors.base_currency }"
                                    >
                                        <option v-for="c in currencies" :key="c.code" :value="c.code">
                                            {{ c.code }} — {{ c.name }}
                                        </option>
                                    </select>
                                    <div v-if="form.errors.base_currency" class="zv-field-error">
                                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                        {{ form.errors.base_currency }}
                                    </div>
                                </div>
                                <div>
                                    <label class="zv-label zv-label-required">{{ t('companies.form.fiscalYearStartMonth') }}</label>
                                    <select v-model="form.fiscal_year_start_month" class="zv-select">
                                        <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Opening Balance Date — required on create, locked on edit -->
                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label" :class="!isEdit ? 'zv-label-required' : ''">
                                        {{ t('companies.form.openingBalanceDate') }}
                                    </label>
                                    <input
                                        v-model="form.opening_balance_date"
                                        type="date"
                                        class="zv-input"
                                        :class="{ 'zv-input-error': form.errors.opening_balance_date }"
                                        :disabled="isEdit"
                                        :title="isEdit ? t('companies.form.openingBalanceDateCannotBeChanged') : ''"
                                    />
                                    <div v-if="form.errors.opening_balance_date" class="zv-field-error">
                                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                        {{ form.errors.opening_balance_date }}
                                    </div>
                                    <div class="field-hint">
                                        {{ isEdit
                                            ? t('companies.form.lockedSetAtCompanyCreationFirst')
                                            : t('companies.form.theDateAsOfWhichAll') }}
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Subscription -->
                    <div class="zv-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">
                                <i class="ti ti-star" aria-hidden="true"></i>
                                {{ t('companies.form.subscription') }}
                            </div>
                        </div>
                        <div class="zv-card-body form-fields">

                            <div>
                                <label class="zv-label zv-label-required">{{ t('companies.form.subscriptionTier') }}</label>
                                <select
                                    v-model="form.subscription_tier"
                                    class="zv-select"
                                    :class="{ 'zv-input-error': form.errors.subscription_tier }"
                                >
                                    <option v-for="tier in tiers" :key="tier" :value="tier">{{ tier }}</option>
                                </select>
                                <div v-if="form.errors.subscription_tier" class="zv-field-error">
                                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                    {{ form.errors.subscription_tier }}
                                </div>
                            </div>

                            <!-- Tier info box -->
                            <div class="tier-info-box" v-if="form.subscription_tier">
                                <div class="tier-info-row">
                                    <span>{{ t('companies.form.baseUserLimit') }}</span>
                                    <span class="zv-num-blue">
                                        {{ tierLimits[form.subscription_tier] }} {{ t('common.users') }}
                                    </span>
                                </div>
                                <div class="tier-info-row">
                                    <span>{{ t('companies.form.modulesIncluded') }}</span>
                                    <span style="font-size: 12px; color: var(--zv-text-secondary);">
                                        {{ tierModules[form.subscription_tier] }}
                                    </span>
                                </div>
                            </div>

                            <div class="form-row-2">
                                <div>
                                    <label class="zv-label">{{ t('companies.form.additionalUsers') }}</label>
                                    <input v-model.number="form.additional_users" type="number" min="0" class="zv-input" />
                                    <div style="font-size: 11px; color: var(--zv-text-muted); margin-top: 4px;">
                                        {{ t('common.totalMaxUsers') }}: <strong>{{ totalMaxUsers }}</strong>
                                    </div>
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('companies.form.subscriptionExpiryDate') }}</label>
                                    <input v-model="form.subscription_expires_at" type="date" class="zv-input" />
                                </div>
                            </div>

                            <!-- Active toggle -->
                            <div class="toggle-row">
                                <div>
                                    <div class="zv-label" style="margin: 0;">{{ t('companies.form.companyActive') }}</div>
                                    <div style="font-size: 11px; color: var(--zv-text-muted); margin-top: 2px;">
                                        {{ form.is_active ? t('companies.form.companyIsActiveAndAccessible') : t('companies.form.companyIsDeactivated') }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="toggle-btn"
                                    :class="{ 'toggle-btn--on': form.is_active }"
                                    @click="form.is_active = !form.is_active"
                                    :aria-pressed="form.is_active"
                                >
                                    <span class="toggle-thumb"></span>
                                </button>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Form actions ───────────────────────────────────── -->
            <div class="form-actions">
                <Link :href="route('companies.index')" class="zv-btn zv-btn-secondary">
                    {{ t('common.cancel') }}
                </Link>
                <button type="submit" class="zv-btn zv-btn-success" :disabled="form.processing">
                    <span v-if="!form.processing">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i>
                        {{ isEdit ? t('common.saveChanges') : t('companies.form.createCompany') }}
                    </span>
                    <span v-else style="display: flex; align-items: center; gap: 8px;">
                        <span class="zv-spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;"></span>
                        {{ t('common.saving') }}
                    </span>
                </button>
            </div>

        </form>

    </SuperAdminLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t, locale } = useI18n()
const currentLocale  = computed(() => locale.value)

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    company:          Object, // null = create, object = edit
    business_sectors: Array,  // platform-level sector list from backend
})

const isEdit          = computed(() => !!props.company?.id)
const businessSectors = computed(() => props.business_sectors ?? [])

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    name_en:                 props.company?.name_en                 ?? '',
    name_ar:                 props.company?.name_ar                 ?? '',
    company_type:            props.company?.company_type            ?? [],
    grn_policy:              props.company?.grn_policy              ?? 'po_required',
    business_sector_id:      props.company?.business_sector_id      ?? null,
    legal_structure:         props.company?.legal_structure         ?? '',
    tax_registration_number: props.company?.tax_registration_number ?? '',
    base_currency:           props.company?.base_currency           ?? 'EGP',
    fiscal_year_start_month: props.company?.fiscal_year_start_month ?? 1,
    opening_balance_date:    props.company?.opening_balance_date?.toString().slice(0, 10) ?? '',
    country:                 props.company?.country                 ?? 'Egypt',
    timezone:                props.company?.timezone                ?? 'Africa/Cairo',
    phone:                   props.company?.phone                   ?? '',
    address:                 props.company?.address                 ?? '',
    subscription_tier:       props.company?.subscription_tier       ?? 'Starter',
    additional_users:        props.company?.additional_users        ?? 0,
    subscription_expires_at: props.company?.subscription_expires_at?.toString().slice(0, 10) ?? '',
    is_active:               props.company?.is_active               ?? true,
})

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (isEdit.value) {
        form.put(route('companies.update', props.company.id))
    } else {
        form.post(route('companies.store'))
    }
}

// ── Static data ───────────────────────────────────────────────────
const companyTypes = [
    {
        value: 'Service',
        desc:  'Consulting, professional services, no inventory',
        icon:  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
    },
    {
        value: 'Trading',
        desc:  'Buy and sell goods, full inventory management',
        icon:  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
    },
    {
        value: 'Manufacturing',
        desc:  'Produce goods, BOM, production orders, WIP',
        icon:  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>',
    },
    {
        value: 'Service with Inventory',
        desc:  'Services + products on same invoice (IT, hospitals)',
        icon:  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>',
    },
]
const tiers        = ['Starter', 'Growth', 'Pro', 'Enterprise']

const legalStructures = [
    { value: 'Joint Stock Company',        label: 'Joint Stock Company (JSC) — شركة مساهمة' },
    { value: 'Limited Liability Company',  label: 'Limited Liability Company (LLC) — ش.ذ.م.م' },
    { value: 'Sole Proprietorship',        label: 'Sole Proprietorship — مؤسسة فردية' },
    { value: 'Partnership',               label: 'Partnership — شركة تضامن' },
    { value: 'Limited Partnership',        label: 'Limited Partnership — شركة توصية بسيطة' },
    { value: 'Branch of Foreign Company',  label: 'Branch of Foreign Company — فرع شركة أجنبية' },
    { value: 'Holding Company',            label: 'Holding Company — شركة قابضة' },
    { value: 'Free Zone Company',          label: 'Free Zone Company — شركة منطقة حرة' },
]

const tierLimits = { Starter: 3, Growth: 5, Pro: 10, Enterprise: 20 }

const tierModules = {
    Starter:    'Accounting, Banking',
    Growth:     'Accounting, Banking, Analytics',
    Pro:        'Accounting, Banking, Analytics, Planning',
    Enterprise: 'Accounting, Banking, Analytics, Planning, Valuation',
}

const totalMaxUsers = computed(() =>
    (tierLimits[form.subscription_tier] ?? 3) + (form.additional_users ?? 0)
)

const currencies = [
    { code: 'EGP', name: 'Egyptian Pound'  },
    { code: 'USD', name: 'US Dollar'        },
    { code: 'EUR', name: 'Euro'             },
    { code: 'GBP', name: 'British Pound'    },
    { code: 'SAR', name: 'Saudi Riyal'      },
    { code: 'AED', name: 'UAE Dirham'       },
    { code: 'KWD', name: 'Kuwaiti Dinar'    },
    { code: 'QAR', name: 'Qatari Riyal'     },
]

const months = [
    { value: 1,  label: 'January'   }, { value: 2,  label: 'February'  },
    { value: 3,  label: 'March'     }, { value: 4,  label: 'April'     },
    { value: 5,  label: 'May'       }, { value: 6,  label: 'June'      },
    { value: 7,  label: 'July'      }, { value: 8,  label: 'August'    },
    { value: 9,  label: 'September' }, { value: 10, label: 'October'   },
    { value: 11, label: 'November'  }, { value: 12, label: 'December'  },
]

const timezones = [
    'Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai',
    'Asia/Kuwait',  'Asia/Qatar',  'Europe/London',
    'Europe/Paris', 'UTC',
]
</script>

<style scoped>
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    align-items: start;
}

.form-col {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.form-fields {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
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

.tier-info-box {
    background: var(--zv-bg-surface);
    border: 1px solid var(--zv-border);
    border-radius: var(--zv-radius);
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tier-info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: var(--zv-text-secondary);
}

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-top: 1px solid var(--zv-border);
}

/* Toggle switch */
.toggle-btn {
    width: 44px; height: 24px;
    background: var(--zv-border-md);
    border: none;
    border-radius: 12px;
    cursor: pointer;
    position: relative;
    transition: background 0.2s ease;
    padding: 0;
    flex-shrink: 0;
}

.toggle-btn--on { background: var(--zv-green); }

.toggle-thumb {
    position: absolute;
    top: 3px; left: 3px;
    width: 18px; height: 18px;
    background: #fff;
    border-radius: 50%;
    transition: transform 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

.toggle-btn--on .toggle-thumb { transform: translateX(20px); }

@media (max-width: 900px) {
    .form-grid  { grid-template-columns: 1fr; }
    .form-row-2 { grid-template-columns: 1fr; }
}

/* ── Company type multi-select ────────────────────────────────── */
.type-multiselect {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 4px;
}

.type-option {
    cursor: pointer;
    display: block;
}

.type-checkbox { display: none; }

.type-option-inner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border: 1.5px solid var(--zv-border);
    border-radius: var(--zv-radius-md);
    background: var(--zv-bg-card);
    transition: all 0.12s ease;
    position: relative;
}

.type-option:hover .type-option-inner {
    border-color: var(--zv-blue-border);
    background: var(--zv-blue-light);
}

.type-option--selected .type-option-inner {
    border-color: var(--zv-blue);
    background: var(--zv-blue-light);
}

.type-option-icon {
    width: 32px; height: 32px;
    background: var(--zv-bg-surface);
    border: 1px solid var(--zv-border);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--zv-text-secondary);
    flex-shrink: 0;
    transition: all 0.12s;
}

.type-option--selected .type-option-icon {
    background: var(--zv-blue);
    border-color: var(--zv-blue);
    color: #fff;
}

.type-option-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--zv-text-primary);
    margin-bottom: 2px;
}

.type-option-desc {
    font-size: 11px;
    color: var(--zv-text-muted);
    line-height: 1.4;
}

.type-option-check {
    position: absolute;
    top: 8px;
    inset-inline-end: 8px;
    width: 20px; height: 20px;
    background: var(--zv-blue);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

@media (max-width: 640px) {
    .type-multiselect { grid-template-columns: 1fr; }
}

.field-hint {
    font-size: 0.6875rem;
    color: var(--vero-muted);
    margin-top: 0.25rem;
    font-style: italic;
    line-height: 1.4;
}

input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: var(--vero-input);
}
</style>