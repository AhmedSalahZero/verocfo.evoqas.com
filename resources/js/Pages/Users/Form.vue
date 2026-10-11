<!--
══════════════════════════════════════════════════════════════════
  Users/Form.vue

  Create and Edit User — single page for both modes.

  Mode detection:
    - props.user === null  → Create mode (POST to users.store)
    - props.user has id    → Edit mode   (PUT  to users.update)

  Password behavior:
    - Create mode: password field is required and always visible
    - Edit mode: password section is hidden by default
      User clicks "Change Password" to reveal it
      If left blank on edit → password is not changed

  Role restriction:
    - Only company_admin and user can be assigned
    - super_admin is never shown as an option

  Fields:
    - Identity: name EN/AR, email
    - Role & Level: role, job_level
    - Job Titles: job_title_en, job_title_ar
    - Preferences: lang_pref, timezone
    - Access: is_active toggle
    - Password: password + confirmation (create required, edit optional)

  @layout    AppLayout
  @route     users.create | users.edit
  @props     user: User|null — null for create, model for edit
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ── Page header ────────────────────────────────── -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route('users.index')">{{ t('users.form.users') }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ isEdit ? t('users.form.editUser') : t('users.form.newUser') }}</span>
                    </div>
                    <div class="zv-page-title">
                        {{ isEdit ? t('users.form.editUser') : t('users.form.newUser') }}
                    </div>
                    <div class="zv-page-subtitle">
                        {{ isEdit ? user.name : t('users.form.createANewUserForThis') }}
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit">
                <div class="form-grid">

                    <!-- ── Left column ──────────────────────── -->
                    <div class="form-col">

                        <!-- Identity -->
                        <div class="zv-card">
                            <div class="zv-card-header">
                                <div class="zv-card-title">
                                    <i class="ti ti-user" aria-hidden="true"></i>
                                    {{ t('users.form.userIdentity') }}
                                </div>
                            </div>
                            <div class="zv-card-body form-fields">

                                <div class="form-row-2">
                                    <div>
                                        <label class="zv-label zv-label-required">{{ t('users.form.fullName') }}</label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="zv-input"
                                            :class="{ 'zv-input-error': form.errors.name }"
                                            placeholder="e.g. Ahmed Ali"
                                        />
                                        <div v-if="form.errors.name" class="zv-field-error">
                                            <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                            {{ form.errors.name }}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="zv-label">{{ t('users.form.fullNameArabic') }}</label>
                                        <input
                                            v-model="form.name_ar"
                                            type="text"
                                            class="zv-input zv-input-ar"
                                            dir="rtl"
                                            placeholder="مثال: أحمد علي"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label class="zv-label zv-label-required">{{ t('users.form.emailAddress') }}</label>
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        class="zv-input"
                                        :class="{ 'zv-input-error': form.errors.email }"
                                        placeholder="ahmed@company.com"
                                        :disabled="isEdit"
                                    />
                                    <div v-if="isEdit" style="font-size: 11px; color: var(--zv-text-muted); margin-top: 4px;">
                                        {{ t('users.form.emailCannotBeChangedAfterCreation') }}
                                    </div>
                                    <div v-if="form.errors.email" class="zv-field-error">
                                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                        {{ form.errors.email }}
                                    </div>
                                </div>

                                <div class="form-row-2">
                                    <div>
                                        <label class="zv-label">{{ t('users.form.jobTitleEnglish') }}</label>
                                        <input v-model="form.job_title_en" type="text" class="zv-input"
                                               placeholder="e.g. Senior Accountant" />
                                    </div>
                                    <div>
                                        <label class="zv-label">{{ t('users.form.jobTitleArabic') }}</label>
                                        <input v-model="form.job_title_ar" type="text" class="zv-input zv-input-ar"
                                               dir="rtl" placeholder="مثال: محاسب أول" />
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Password -->
                        <div class="zv-card">
                            <div class="zv-card-header">
                                <div class="zv-card-title">
                                    <i class="ti ti-lock" aria-hidden="true"></i>
                                    {{ isEdit ? t('users.form.changePassword') : t('users.form.password') }}
                                </div>
                                <button
                                    v-if="isEdit && !showPassword"
                                    type="button"
                                    class="zv-btn zv-btn-secondary zv-btn-sm"
                                    @click="showPassword = true"
                                >
                                    {{ t('users.form.changePassword') }}
                                </button>
                            </div>
                            <div class="zv-card-body form-fields" v-if="!isEdit || showPassword">

                                <!-- Password requirements notice -->
                                <div class="password-rules">
                                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                                    {{ t('users.form.min8CharactersUppercaseLowercaseNumber') }}
                                </div>

                                <div>
                                    <label class="zv-label" :class="{ 'zv-label-required': !isEdit }">
                                        {{ t('users.form.password') }}
                                    </label>
                                    <div class="zv-input-group">
                                        <input
                                            v-model="form.password"
                                            :type="showPwd ? 'text' : 'password'"
                                            class="zv-input zv-input-has-suffix"
                                            :class="{ 'zv-input-error': form.errors.password }"
                                            :placeholder="isEdit ? t('users.form.leaveBlankToKeepCurrentPassword') : t('users.form.enterPassword')"
                                        />
                                        <button type="button" class="zv-input-suffix pwd-eye"
                                                @click="showPwd = !showPwd"
                                                style="pointer-events: all; cursor: pointer; background: none; border: none;">
                                            <i :class="showPwd ? 'ti ti-eye-off' : 'ti ti-eye'" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div v-if="form.errors.password" class="zv-field-error">
                                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                                        {{ form.errors.password }}
                                    </div>
                                </div>

                                <div>
                                    <label class="zv-label" :class="{ 'zv-label-required': !isEdit }">
                                        {{ t('users.form.confirmPassword') }}
                                    </label>
                                    <input
                                        v-model="form.password_confirmation"
                                        :type="showPwd ? 'text' : 'password'"
                                        class="zv-input"
                                        :placeholder="t('users.form.repeatPassword')"
                                    />
                                </div>

                            </div>
                            <div v-else class="zv-card-body" style="color: var(--zv-text-muted); font-size: 13px;">
                                {{ t('users.form.passwordIsSetClickChangePassword') }}
                            </div>
                        </div>

                    </div>

                    <!-- ── Right column ─────────────────────── -->
                    <div class="form-col">

                        <!-- Role & Access -->
                        <div class="zv-card">
                            <div class="zv-card-header">
                                <div class="zv-card-title">
                                    <i class="ti ti-shield" aria-hidden="true"></i>
                                    {{ t('users.form.roleAccess') }}
                                </div>
                            </div>
                            <div class="zv-card-body form-fields">

                                <div class="form-row-2">
                                    <div>
                                        <label class="zv-label zv-label-required">{{ t('users.form.role') }}</label>
                                        <select
                                            v-model="form.role"
                                            class="zv-select"
                                            :class="{ 'zv-input-error': form.errors.role }"
                                        >
                                            <option value="user">{{ t('users.form.user') }}</option>
                                            <option value="company_admin">{{ t('users.form.companyAdmin') }}</option>
                                        </select>
                                        <div v-if="form.role === 'company_admin'"
                                             style="font-size: 11px; color: var(--zv-blue-text); margin-top: 4px;">
                                            {{ t('users.form.companyAdminHasFullAccessTo') }}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="zv-label">{{ t('users.form.jobLevel') }}</label>
                                        <select v-model="form.job_level" class="zv-select">
                                            <option value="">{{ t('users.form.selectLevel') }}</option>
                                            <option v-for="level in jobLevels" :key="level.value" :value="level.value">
                                                {{ t(level.label) }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Active toggle -->
                                <div class="toggle-row">
                                    <div>
                                        <div class="zv-label" style="margin: 0;">{{ t('users.form.userActive') }}</div>
                                        <div style="font-size: 11px; color: var(--zv-text-muted); margin-top: 2px;">
                                            {{ form.is_active ? t('users.form.userCanLogInAndAccess') : t('users.form.userCannotLogIn') }}
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

                        <!-- Preferences -->
                        <div class="zv-card">
                            <div class="zv-card-header">
                                <div class="zv-card-title">
                                    <i class="ti ti-settings" aria-hidden="true"></i>
                                    {{ t('users.form.preferences') }}
                                </div>
                            </div>
                            <div class="zv-card-body form-fields">

                                <div>
                                    <label class="zv-label zv-label-required">{{ t('users.form.languagePreference') }}</label>
                                    <div class="lang-toggle">
                                        <button
                                            type="button"
                                            class="lang-option"
                                            :class="{ 'lang-option--active': form.lang_pref === 'en' }"
                                            @click="form.lang_pref = 'en'"
                                        >
                                            🇬🇧 English
                                        </button>
                                        <button
                                            type="button"
                                            class="lang-option"
                                            :class="{ 'lang-option--active': form.lang_pref === 'ar' }"
                                            @click="form.lang_pref = 'ar'"
                                        >
                                            🇸🇦 العربية
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label class="zv-label">{{ t('users.form.timezone') }}</label>
                                    <select v-model="form.timezone" class="zv-select">
                                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                                    </select>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Form actions ─────────────────────────────── -->
                <div class="form-actions">
                    <Link :href="route('users.index')" class="zv-btn zv-btn-secondary">
                        {{ t('common.cancel') }}
                    </Link>
                    <button type="submit" class="zv-btn zv-btn-success" :disabled="form.processing">
                        <span v-if="!form.processing">
                            <i class="ti ti-device-floppy" aria-hidden="true"></i>
                            {{ isEdit ? t('common.saveChanges') : t('users.form.createUser') }}
                        </span>
                        <span v-else style="display: flex; align-items: center; gap: 8px;">
                            <span class="zv-spinner"
                                  style="width:14px;height:14px;border-width:2px;display:inline-block;"></span>
                            {{ t('common.saving') }}
                        </span>
                    </button>
                </div>

            </form>
        </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    user: Object, // null = create, object = edit
})

const isEdit      = computed(() => !!props.user?.id)
const showPassword = ref(!isEdit.value) // always show on create
const showPwd      = ref(false)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    name:                  props.user?.name         ?? '',
    name_ar:               props.user?.name_ar      ?? '',
    email:                 props.user?.email         ?? '',
    password:              '',
    password_confirmation: '',
    role:                  props.user?.role          ?? 'user',
    job_title_en:          props.user?.job_title_en  ?? '',
    job_title_ar:          props.user?.job_title_ar  ?? '',
    job_level:             props.user?.job_level     ?? '',
    lang_pref:             props.user?.lang_pref     ?? 'en',
    timezone:              props.user?.timezone      ?? 'Africa/Cairo',
    is_active:             props.user?.is_active     ?? true,
})

function submit() {
    if (isEdit.value) {
        form.put(route('users.update', props.user.id))
    } else {
        form.post(route('users.store'))
    }
}

// ── Static data ───────────────────────────────────────────────────
const jobLevels = [
    { value: 'shareholder', label: 'Shareholder' },
    { value: 'director',    label: 'Director'    },
    { value: 'manager',     label: 'Manager'     },
    { value: 'senior',      label: 'Senior'      },
    { value: 'junior',      label: 'Junior'      },
    { value: 'staff',       label: 'Staff'       },
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

/* Password rules notice */
.password-rules {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: var(--zv-text-muted);
    background: var(--zv-bg-surface);
    border: 1px solid var(--zv-border);
    border-radius: var(--zv-radius);
    padding: 8px 12px;
}

.password-rules i { color: var(--zv-blue-text); font-size: 13px; flex-shrink: 0; }

/* Language toggle */
.lang-toggle {
    display: flex;
    gap: 8px;
}

.lang-option {
    flex: 1;
    padding: 8px 12px;
    border: 1.5px solid var(--zv-border-md);
    border-radius: var(--zv-radius);
    background: var(--zv-bg-surface);
    color: var(--zv-text-secondary);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.12s;
    text-align: center;
}

.lang-option--active {
    background: var(--zv-blue-light);
    border-color: var(--zv-blue-border);
    color: var(--zv-blue-text);
    font-weight: 600;
}

/* Toggle switch */
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-top: 1px solid var(--zv-border);
}

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
</style>
