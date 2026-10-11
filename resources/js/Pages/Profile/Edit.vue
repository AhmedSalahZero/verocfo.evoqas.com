<!--
══════════════════════════════════════════════════════════════════
  Profile/Edit.vue

  User profile page — accessible by all authenticated users.
  Three sections:
    1. Profile Information — name, job titles, language, timezone
    2. Change Password — current + new + confirm
    3. Account Details — read-only email, role, status, sign out

  Both forms submit independently via PATCH.
  Profile update also triggers locale switch if lang_pref changed.

  @layout    AppLayout
  @route     profile.edit
  @props     user: User — the authenticated user
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">
            <div class="zv-profile-wrap">

                <!-- ── Page header ──────────────────────────────── -->
                <div class="zv-profile-header">
                    <div class="zv-profile-avatar">{{ userInitials }}</div>
                    <div>
                        <div class="zv-section-eyebrow">{{ t('profile.edit.yOURACCOUNT') }}</div>
                        <h1 class="zv-profile-title">{{ user.name }}</h1>
                        <div class="zv-profile-meta">
                            {{ user.email }}
                            <span class="zv-profile-role">· {{ roleLabel }}</span>
                        </div>
                    </div>
                </div>

                <div class="zv-profile-grid">

                    <!-- ── Card 1 — Profile Information ──────────── -->
                    <div class="zv-card zv-profile-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">{{ t('profile.edit.profileInformation') }}</div>
                            <div class="zv-card-sub">{{ t('profile.edit.updateYourNameJobTitleAnd') }}</div>
                        </div>

                        <form @submit.prevent="submitProfile" class="zv-profile-form">

                            <div class="zv-field-row">
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.fullName') }}</label>
                                    <input
                                        v-model="profileForm.name"
                                        type="text"
                                        class="zv-input"
                                        :class="{ 'zv-input-error': profileForm.errors.name }"
                                        placeholder="Your full name"
                                    />
                                    <div v-if="profileForm.errors.name" class="zv-field-error">
                                        {{ profileForm.errors.name }}
                                    </div>
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.fullNameArabic') }}</label>
                                    <input
                                        v-model="profileForm.name_ar"
                                        type="text"
                                        class="zv-input"
                                        dir="rtl"
                                        placeholder="الاسم بالعربية"
                                    />
                                </div>
                            </div>

                            <div class="zv-field-row">
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.jobTitleEN') }}</label>
                                    <input
                                        v-model="profileForm.job_title_en"
                                        type="text"
                                        class="zv-input"
                                        placeholder="e.g. Finance Manager"
                                    />
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.jobTitleAR') }}</label>
                                    <input
                                        v-model="profileForm.job_title_ar"
                                        type="text"
                                        class="zv-input"
                                        dir="rtl"
                                        placeholder="مثال: مدير مالي"
                                    />
                                </div>
                            </div>

                            <div class="zv-field-row">
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.languagePreference') }}</label>
                                    <select v-model="profileForm.lang_pref" class="zv-select">
                                        <option value="en">English</option>
                                        <option value="ar">العربية</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="zv-label">{{ t('profile.edit.timezone') }}</label>
                                    <select v-model="profileForm.timezone" class="zv-select">
                                        <option value="Africa/Cairo">Cairo (EET, UTC+2)</option>
                                        <option value="Asia/Riyadh">Riyadh (AST, UTC+3)</option>
                                        <option value="Asia/Dubai">Dubai (GST, UTC+4)</option>
                                        <option value="Asia/Kuwait">Kuwait (AST, UTC+3)</option>
                                        <option value="Asia/Qatar">Qatar (AST, UTC+3)</option>
                                        <option value="Europe/London">London (GMT/BST)</option>
                                        <option value="UTC">UTC</option>
                                    </select>
                                </div>
                            </div>

                            <div class="zv-profile-form-footer">
                                <Transition name="slide-up">
                                    <span v-if="profileSaved" class="zv-profile-saved">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path d="M20 6L9 17l-5-5"/>
                                        </svg>
                                        {{ t('common.saved') }}
                                    </span>
                                </Transition>
                                <button type="submit" class="zv-btn zv-btn-success" :disabled="profileForm.processing">
                                    {{ profileForm.processing ? t('common.saving') : t('common.saveChanges') }}
                                </button>
                            </div>

                        </form>
                    </div>

                    <!-- ── Card 2 — Change Password ───────────────── -->
                    <div class="zv-card zv-profile-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">{{ t('profile.edit.changePassword') }}</div>
                            <div class="zv-card-sub">{{ t('profile.edit.useAStrongPasswordOfAt') }}</div>
                        </div>

                        <form @submit.prevent="submitPassword" class="zv-profile-form">

                            <div>
                                <label class="zv-label">{{ t('profile.edit.currentPassword') }}</label>
                                <input
                                    v-model="passwordForm.current_password"
                                    type="password"
                                    class="zv-input"
                                    :class="{ 'zv-input-error': passwordForm.errors.current_password }"
                                    placeholder="••••••••"
                                />
                                <div v-if="passwordForm.errors.current_password" class="zv-field-error">
                                    {{ passwordForm.errors.current_password }}
                                </div>
                            </div>

                            <div>
                                <label class="zv-label">{{ t('profile.edit.newPassword') }}</label>
                                <input
                                    v-model="passwordForm.password"
                                    type="password"
                                    class="zv-input"
                                    :class="{ 'zv-input-error': passwordForm.errors.password }"
                                    placeholder="Min. 8 characters"
                                />
                                <div v-if="passwordForm.errors.password" class="zv-field-error">
                                    {{ passwordForm.errors.password }}
                                </div>
                            </div>

                            <div>
                                <label class="zv-label">{{ t('profile.edit.confirmNewPassword') }}</label>
                                <input
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    class="zv-input"
                                    placeholder="••••••••"
                                />
                            </div>

                            <div class="zv-profile-form-footer">
                                <Transition name="slide-up">
                                    <span v-if="passwordSaved" class="zv-profile-saved">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path d="M20 6L9 17l-5-5"/>
                                        </svg>
                                        {{ t('profile.edit.passwordUpdated') }}
                                    </span>
                                </Transition>
                                <button type="submit" class="zv-btn zv-btn-success" :disabled="passwordForm.processing">
                                    {{ passwordForm.processing ? t('profile.edit.updating') : t('profile.edit.updatePassword') }}
                                </button>
                            </div>

                        </form>
                    </div>

                    <!-- ── Card 3 — Account Details (read-only) ───── -->
                    <div class="zv-card zv-profile-card">
                        <div class="zv-card-header">
                            <div class="zv-card-title">{{ t('profile.edit.accountDetails') }}</div>
                            <div class="zv-card-sub">{{ t('profile.edit.readOnlyInformationAboutYourAccount') }}</div>
                        </div>

                        <div class="zv-profile-info-rows">
                            <div class="zv-profile-info-row">
                                <span class="zv-profile-info-label">{{ t('profile.edit.email') }}</span>
                                <span class="zv-profile-info-value">{{ user.email }}</span>
                            </div>
                            <div class="zv-profile-info-row">
                                <span class="zv-profile-info-label">{{ t('profile.edit.role') }}</span>
                                <span class="zv-badge zv-badge-blue">{{ roleLabel }}</span>
                            </div>
                            <div class="zv-profile-info-row" v-if="user.job_level">
                                <span class="zv-profile-info-label">{{ t('profile.edit.jobLevel') }}</span>
                                <span class="zv-badge zv-badge-gray" style="text-transform: capitalize;">
                                    {{ user.job_level }}
                                </span>
                            </div>
                            <div class="zv-profile-info-row" v-if="user.company_id">
                                <span class="zv-profile-info-label">{{ t('profile.edit.companyID') }}</span>
                                <span class="zv-profile-info-value zv-num">#{{ user.company_id }}</span>
                            </div>
                            <div class="zv-profile-info-row">
                                <span class="zv-profile-info-label">{{ t('common.status') }}</span>
                                <span class="zv-badge zv-badge-green">{{ t('common.active') }}</span>
                            </div>
                        </div>

                        <!-- Sign out -->
                        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--zv-border);">
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="zv-btn zv-btn-danger zv-btn-sm"
                            >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                                    <polyline points="16 17 21 12 16 7"/>
                                    <line x1="21" y1="12" x2="9" y2="12"/>
                                </svg>
                                {{ t('profile.edit.signOut') }}
                            </Link>
                        </div>
                    </div>

                </div>
            </div>
        </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n/index.js'

const { t } = useI18n()
const page   = usePage()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    user: { type: Object, required: true },
})

// ── Computed ──────────────────────────────────────────────────────
const userInitials = computed(() =>
    (props.user.name ?? '').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
)

const roleLabel = computed(() => {
    const role = props.user.role
    if (role === 'super_admin')   return 'Super Admin'
    if (role === 'company_admin') return 'Company Admin'
    return 'User'
})

// ── Profile form ──────────────────────────────────────────────────
const profileSaved = ref(false)

const profileForm = useForm({
    name:         props.user.name         ?? '',
    name_ar:      props.user.name_ar      ?? '',
    job_title_en: props.user.job_title_en ?? '',
    job_title_ar: props.user.job_title_ar ?? '',
    lang_pref:    props.user.lang_pref    ?? 'en',
    timezone:     props.user.timezone     ?? 'Africa/Cairo',
})

function submitProfile() {
    profileForm.patch(route('profile.update'), {
        onSuccess: () => {
            // Apply locale change immediately if changed
            setLocale(profileForm.lang_pref)
            window.axios.post(route('lang.update'), { lang: profileForm.lang_pref }).catch(() => {})
            profileSaved.value = true
            setTimeout(() => { profileSaved.value = false }, 3000)
        },
    })
}

// ── Password form ─────────────────────────────────────────────────
const passwordSaved = ref(false)

const passwordForm = useForm({
    current_password:      '',
    password:              '',
    password_confirmation: '',
})

function submitPassword() {
    passwordForm.patch(route('profile.password'), {
        onSuccess: () => {
            passwordSaved.value = true
            setTimeout(() => { passwordSaved.value = false }, 3000)
        },
        onFinish: () => passwordForm.reset('current_password', 'password', 'password_confirmation'),
    })
}
</script>

<style scoped>
.zv-profile-wrap { max-width: 960px; }

/* ── Header ──────────────────────────────────────────────────── */
.zv-profile-header {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.zv-profile-avatar {
    width: 3.5rem;
    height: 3.5rem;
    background: linear-gradient(135deg, #1490A8, #0F2554);
    color: #fff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 700;
    flex-shrink: 0;
}

.zv-profile-title {
    font-size: 1.375rem;
    font-weight: 700;
    color: var(--zv-text-primary);
    margin: 0.125rem 0 0.25rem;
}

.zv-profile-meta { font-size: 0.8125rem; color: var(--zv-text-muted); }
.zv-profile-role { color: var(--zv-text-secondary); }

/* ── Grid ────────────────────────────────────────────────────── */
.zv-profile-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.25rem;
    align-items: start;
}

/* ── Card ────────────────────────────────────────────────────── */
.zv-profile-card { padding: 1.5rem; }

.zv-card-header  { margin-bottom: 1.25rem; }
.zv-card-title   { font-size: 0.9375rem; font-weight: 600; color: var(--zv-text-primary); }
.zv-card-sub     { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 0.25rem; }

/* ── Form ────────────────────────────────────────────────────── */
.zv-profile-form  { display: flex; flex-direction: column; gap: 1rem; }

.zv-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.zv-profile-form-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--zv-border);
    margin-top: 0.25rem;
}

.zv-profile-saved {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--zv-green-text);
}

/* ── Info rows ───────────────────────────────────────────────── */
.zv-profile-info-rows  { display: flex; flex-direction: column; }

.zv-profile-info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.625rem 0;
    border-bottom: 1px solid var(--zv-border);
}

.zv-profile-info-row:last-child { border-bottom: none; }
.zv-profile-info-label { font-size: 0.8125rem; color: var(--zv-text-muted); }
.zv-profile-info-value { font-size: 0.8125rem; color: var(--zv-text-primary); font-weight: 500; }

/* ── Transitions ─────────────────────────────────────────────── */
.slide-up-enter-active { transition: transform 0.2s ease, opacity 0.2s ease; }
.slide-up-leave-active { transition: transform 0.15s ease, opacity 0.15s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(6px); opacity: 0; }

@media (max-width: 768px) {
    .zv-profile-grid  { grid-template-columns: 1fr; }
    .zv-field-row     { grid-template-columns: 1fr; }
}
</style>