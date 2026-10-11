<template>
    <div class="login-shell">

        <div class="login-story">
            <div class="login-story-panel">
                <div class="login-brand">
                    <div class="login-brand-icon">
                        <img
                            src="/images/logo-mark.png"
                            alt="Vero CFO"
                            class="login-brand-img"
                            @error="logoError = true"
                        />
                        <svg
                            v-if="logoError"
                            width="26" height="26"
                            viewBox="0 0 24 24"
                            fill="none"
                            aria-hidden="true"
                        >
                            <path d="M3 19 L12 4 L16 12 L20 7" stroke="#60A5FA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M16 12 L20 7 L22 9" stroke="#D4A017" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="18" cy="17" r="4" stroke="#60A5FA" stroke-width="1.5" fill="none"/>
                        </svg>
                    </div>
                    <div class="login-brand-name">
                        Vero <span>CFO</span>
                    </div>
                </div>

                <p class="login-story-eyebrow">{{ t('auth.login.productEyebrow') }}</p>
                <h1 class="login-story-title">{{ t('auth.login.productTitle') }}</h1>
                <p class="login-story-body">{{ t('auth.login.productBody') }}</p>
            </div>
        </div>

        <div class="login-card">
            <div class="login-card-story">
                <p class="login-card-eyebrow">{{ t('auth.login.productEyebrow') }}</p>
                <h1 class="login-card-title">{{ t('auth.login.productTitle') }}</h1>
                <p class="login-card-body">{{ t('auth.login.productBody') }}</p>
            </div>

            <div class="login-form-header">
                <div class="login-form-eyebrow">
                    <i class="ti ti-shield-lock" aria-hidden="true"></i>
                    {{ t('auth.login.secureAccess') }}
                </div>
                <h2 class="login-form-title">{{ t('auth.login.welcomeBack') }}</h2>
                <p class="login-form-sub">
                    {{ t('auth.login.signInToYourVeroCFO') }}
                </p>
            </div>

            <form @submit.prevent="submit" novalidate>
                <div class="login-field">
                    <label for="email" class="login-label">
                        {{ t('auth.login.emailAddress') }}
                    </label>
                    <div class="login-input-wrap" :class="{ 'login-input-wrap--error': form.errors.email }">
                        <i class="ti ti-mail login-input-icon" aria-hidden="true"></i>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="login-input"
                            placeholder="you@company.com"
                            autocomplete="email"
                            autofocus
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.email"
                            aria-describedby="email-error"
                        />
                    </div>
                    <div
                        v-if="form.errors.email"
                        id="email-error"
                        class="login-field-error"
                        role="alert"
                    >
                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                        {{ form.errors.email }}
                    </div>
                </div>

                <div class="login-field">
                    <label for="password" class="login-label">
                        {{ t('auth.login.password') }}
                    </label>
                    <div class="login-input-wrap" :class="{ 'login-input-wrap--error': form.errors.password }">
                        <i class="ti ti-lock login-input-icon" aria-hidden="true"></i>
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            class="login-input login-input--password"
                            :placeholder="t('auth.login.enterYourPassword')"
                            autocomplete="current-password"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.password"
                            aria-describedby="password-error"
                        />
                        <button
                            type="button"
                            class="login-eye-btn"
                            @click="showPassword = !showPassword"
                            :aria-label="showPassword ? t('auth.login.hidePassword') : t('auth.login.showPassword')"
                        >
                            <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div
                        v-if="form.errors.password"
                        id="password-error"
                        class="login-field-error"
                        role="alert"
                    >
                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                        {{ form.errors.password }}
                    </div>
                </div>

                <div class="login-options">
                    <label class="login-remember">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="login-checkbox"
                        />
                        {{ t('auth.login.rememberMe') }}
                    </label>
                    <Link
                        :href="route('password.request')"
                        class="login-forgot"
                    >
                        {{ t('auth.login.forgotPassword') }}
                    </Link>
                </div>

                <button
                    type="submit"
                    class="login-btn"
                    :class="{ 'login-btn--loading': form.processing }"
                    :disabled="form.processing"
                >
                    <span v-if="!form.processing" class="login-btn-inner">
                        <i class="ti ti-login" aria-hidden="true"></i>
                        {{ t('auth.login.signInToVeroCFO') }}
                    </span>
                    <span v-else class="login-btn-inner">
                        <span class="login-spinner" aria-hidden="true"></span>
                        {{ t('auth.login.signingIn') }}
                    </span>
                </button>
            </form>

            <div class="login-lang-divider" aria-hidden="true">
                <div class="login-lang-line"></div>
                <span>{{ t('auth.login.language') }}</span>
                <div class="login-lang-line"></div>
            </div>

            <div class="login-lang-row" role="group" :aria-label="t('auth.login.languageSelection')">
                <button
                    type="button"
                    class="login-lang-btn"
                    :class="{ 'login-lang-btn--active': currentLocale === 'en' }"
                    @click="switchLocale('en')"
                >
                    English
                </button>
                <button
                    type="button"
                    class="login-lang-btn"
                    :class="{ 'login-lang-btn--active': currentLocale === 'ar' }"
                    @click="switchLocale('ar')"
                >
                    العربية
                </button>
            </div>

            <div class="login-trust">
                <i class="ti ti-shield-check" aria-hidden="true"></i>
                {{ t('auth.login.256BitSSLEncryptedYourData') }}
            </div>

            <div class="login-copyright">
                © {{ currentYear }} Vero CFO · {{ t('auth.login.allRightsReserved') }}
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n/index.js'

const { t, locale } = useI18n()
const currentLocale  = computed(() => locale.value)
const currentYear    = new Date().getFullYear()

const showPassword = ref(false)
const logoError    = ref(false)

const form = useForm({
    email:    '',
    password: '',
    remember: false,
})

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}

function switchLocale(lang) {
    setLocale(lang)
    window.axios.post(route('lang.update'), { lang })
        .catch(() => {})
}
</script>

<style scoped>
.login-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 40px 48px;
    background-color: #0B1C3E;
    background-image: url('/images/auth.jpeg');
    background-repeat: no-repeat;
    background-position: center;
    background-size: cover;
}

.login-story {
    position: absolute;
    left: 48px;
    bottom: 40px;
    width: min(440px, calc(100% - 560px));
    z-index: 1;
}

.login-story-panel {
    padding: 22px 24px 20px;
    border-radius: 16px;
    background: rgba(8, 18, 40, 0.62);
    border: 1px solid rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(10px);
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.login-brand-icon {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}

.login-brand-img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.login-brand-name {
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: -0.025em;
    line-height: 1;
}

.login-brand-name span { color: #E8C56B; }

.login-story-eyebrow,
.login-card-eyebrow {
    margin: 0 0 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.login-story-eyebrow { color: #E8C56B; }

.login-story-title {
    margin: 0 0 10px;
    font-size: 28px;
    font-weight: 700;
    line-height: 1.25;
    letter-spacing: -0.03em;
    color: #FFFFFF;
}

.login-story-body {
    margin: 0;
    font-size: 14px;
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.82);
}

.login-card {
    position: relative;
    z-index: 2;
    width: min(420px, 100%);
    max-height: calc(100vh - 48px);
    overflow: auto;
    padding: 32px 32px 24px;
    background: #FFFFFF;
    border-radius: 18px;
    box-shadow: 0 24px 64px rgba(8, 18, 40, 0.28);
}

.login-card-story { display: none; }

.login-card-eyebrow { color: #2563EB; }

.login-card-title {
    margin: 0 0 8px;
    font-size: 20px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.02em;
    color: #0F172A;
}

.login-card-body {
    margin: 0 0 18px;
    font-size: 13px;
    line-height: 1.6;
    color: #64748B;
}

.login-form-header { margin-bottom: 22px; }

.login-form-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #2563EB;
    margin-bottom: 8px;
}

.login-form-eyebrow i { font-size: 13px; }

.login-form-title {
    font-size: 24px;
    font-weight: 700;
    color: #0F172A;
    letter-spacing: -0.025em;
    margin: 0 0 6px;
}

.login-form-sub {
    margin: 0;
    font-size: 13px;
    color: #64748B;
    line-height: 1.6;
}

.login-field { margin-bottom: 16px; }

.login-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #475569;
    margin-bottom: 7px;
}

.login-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.login-input-icon {
    position: absolute;
    inset-inline-start: 12px;
    color: #94A3B8;
    font-size: 16px;
    pointer-events: none;
}

.login-input {
    width: 100%;
    padding: 11px 12px 11px 38px;
    border: 1.5px solid #E2E8F0;
    border-radius: 9px;
    font-size: 13px;
    color: #0F172A;
    outline: none;
    font-family: inherit;
    background: #F8FAFC;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}

.login-input--password { padding-inline-end: 42px; }

.login-input:focus {
    border-color: #2563EB;
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
}

.login-input::placeholder { color: #CBD5E1; }
.login-input:disabled { opacity: 0.6; cursor: not-allowed; }

.login-input-wrap--error .login-input {
    border-color: #DC2626;
    background: #FFF8F8;
}

.login-input-wrap--error .login-input:focus {
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.10);
}

.login-eye-btn {
    position: absolute;
    inset-inline-end: 10px;
    background: none;
    border: none;
    color: #94A3B8;
    cursor: pointer;
    padding: 4px;
    font-size: 16px;
    display: flex;
    align-items: center;
    border-radius: 4px;
}

.login-eye-btn:hover { color: #475569; }

.login-field-error {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: #DC2626;
    margin-top: 6px;
}

.login-options {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 4px 0 18px;
}

.login-remember {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    color: #475569;
    cursor: pointer;
    user-select: none;
}

.login-checkbox {
    width: 15px;
    height: 15px;
    cursor: pointer;
    accent-color: #2563EB;
}

.login-forgot {
    font-size: 12.5px;
    color: #2563EB;
    text-decoration: none;
    font-weight: 500;
}

.login-forgot:hover { color: #1D4ED8; text-decoration: underline; }

.login-btn {
    width: 100%;
    padding: 12.5px;
    background: #0F2554;
    color: #FFFFFF;
    border: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}

.login-btn:hover:not(:disabled) { background: #1A3A6B; }
.login-btn:disabled { opacity: 0.65; cursor: not-allowed; }

.login-btn-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.login-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.30);
    border-top-color: #FFFFFF;
    border-radius: 50%;
    animation: login-spin 0.7s linear infinite;
}

@keyframes login-spin {
    to { transform: rotate(360deg); }
}

.login-lang-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 20px 0 12px;
}

.login-lang-line {
    flex: 1;
    height: 1px;
    background: #F1F5F9;
}

.login-lang-divider span {
    font-size: 11px;
    color: #CBD5E1;
    white-space: nowrap;
}

.login-lang-row {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.login-lang-btn {
    padding: 7px 20px;
    border-radius: 7px;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    border: 1.5px solid #E2E8F0;
    background: #F8FAFC;
    color: #64748B;
}

.login-lang-btn--active,
.login-lang-btn:hover {
    background: #EFF6FF;
    border-color: #BFDBFE;
    color: #1D4ED8;
}

.login-lang-btn--active { font-weight: 600; }

.login-trust {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 11.5px;
    color: #94A3B8;
    margin-top: 16px;
}

.login-trust i { color: #059669; font-size: 14px; }

.login-copyright {
    font-size: 11px;
    color: #CBD5E1;
    text-align: center;
    margin-top: 12px;
}

[dir="rtl"] .login-shell {
    justify-content: flex-start;
}

[dir="rtl"] .login-story {
    left: 48px;
    right: auto;
}

[dir="rtl"] .login-input {
    padding-inline-start: 38px;
    padding-inline-end: 12px;
    text-align: end;
}

[dir="rtl"] .login-input--password {
    padding-inline-end: 42px;
}

[dir="rtl"] .login-story-eyebrow,
[dir="rtl"] .login-card-eyebrow,
[dir="rtl"] .login-form-eyebrow {
    letter-spacing: 0;
}

@media (max-width: 900px) {
    .login-shell {
        justify-content: center;
        padding: 20px 16px;
        background-image:
            linear-gradient(rgba(11, 28, 62, 0.62), rgba(11, 28, 62, 0.62)),
            url('/images/auth.jpeg');
    }

    [dir="rtl"] .login-shell {
        justify-content: center;
    }

    .login-story { display: none; }

    .login-card {
        width: min(460px, 100%);
        max-height: calc(100vh - 32px);
    }

    .login-card-story { display: block; }
}

@media (max-width: 480px) {
    .login-card { padding: 24px 18px 18px; }
    .login-form-title { font-size: 20px; }
}
</style>
