<!--
══════════════════════════════════════════════════════════════════
  Dashboard.vue

  Company dashboard — landing page after entering a company.
  Currently a placeholder — will be replaced with the full
  CFO Dashboard when we build the Analytics module.

  Shows basic company info and quick navigation links
  to confirm the impersonation and routing are working correctly.
  The Users card is shown to Company Admins and the Super Admin only
  (Audit C2, Sept 2026 — Users section closed to regular users).

  @layout    AppLayout
  @route     dashboard
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ── Page header ────────────────────────────────── -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-page-title">{{ t('dashboard.dashboard') }}</div>
                    <div class="zv-page-subtitle">
                        {{ t('dashboard.welcomeTo') }} {{ companyName }}
                    </div>
                </div>
            </div>

            <!-- ── Quick navigation cards ─────────────────────── -->
            <div class="nav-grid">

                <!-- Users card — admins only (Audit C2): the Users
                     section is closed to regular users. -->
                <Link v-if="isAdmin" :href="route('users.index')" class="nav-card">
                    <div class="nav-card-icon" style="background: var(--zv-blue-light); color: var(--zv-blue-text);">
                        <i class="ti ti-users" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="nav-card-title">{{ t('dashboard.users') }}</div>
                        <div class="nav-card-sub">{{ t('dashboard.manageCompanyUsersAndPermissions') }}</div>
                    </div>
                    <i class="ti ti-arrow-right nav-card-arrow" aria-hidden="true"></i>
                </Link>

            </div>

            <!-- ── Company info ───────────────────────────────── -->
            <div class="zv-card" style="margin-top: 1.5rem;">
                <div class="zv-card-header">
                    <div class="zv-card-title">
                        <i class="ti ti-building" aria-hidden="true"></i>
                        {{ t('dashboard.companyInformation') }}
                    </div>
                </div>
                <div class="zv-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">{{ t('dashboard.companyName') }}</div>
                            <div class="info-value">{{ company?.name_en }}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">{{ t('dashboard.companyType') }}</div>
                            <div class="info-value">{{ company?.company_type }}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">{{ t('dashboard.subscriptionTier') }}</div>
                            <div class="info-value">
                                <span class="zv-badge zv-badge-blue">{{ company?.subscription_tier }}</span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">{{ t('dashboard.baseCurrency') }}</div>
                            <div class="info-value">{{ company?.base_currency }}</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const page           = usePage()

const company = computed(() => page.props.auth?.company)

// Users section is admin-only (Audit C2) — Company Admin or Super Admin.
const isAdmin = computed(() => ['super_admin', 'company_admin'].includes(page.props.auth?.user?.role))

const companyName = computed(() => {
    if (!company.value) return ''
    if (locale.value === 'ar' && company.value.name_ar) return company.value.name_ar
    return company.value.name_en ?? ''
})
</script>

<style scoped>
.nav-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.nav-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    background: var(--zv-bg-card);
    border: 1px solid var(--zv-border);
    border-radius: var(--zv-radius-lg);
    text-decoration: none;
    transition: box-shadow 0.15s, border-color 0.15s;
    cursor: pointer;
}

.nav-card:hover {
    border-color: var(--zv-blue-border);
    box-shadow: var(--zv-shadow);
}

.nav-card-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.nav-card-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--zv-text-primary);
}

.nav-card-sub {
    font-size: 12px;
    color: var(--zv-text-muted);
    margin-top: 2px;
}

.nav-card-arrow {
    margin-inline-start: auto;
    color: var(--zv-text-muted);
    font-size: 16px;
    flex-shrink: 0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1.25rem;
}

.info-item {}
.info-label { font-size: 11px; font-weight: 600; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
.info-value { font-size: 14px; font-weight: 500; color: var(--zv-text-primary); }
</style>
