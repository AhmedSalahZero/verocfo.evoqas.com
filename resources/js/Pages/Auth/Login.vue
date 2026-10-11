<template>
    <div class="login-shell">
        <img
            src="/images/logo-mark.png"
            alt="Vero CFO"
            class="login-logo"
        />

        <div class="login-story">
            <p class="login-kicker">{{ t('auth.login.storyKicker') }}</p>
            <h1 class="login-headline">{{ t('auth.login.storyHeadline') }}</h1>
            <p class="login-lead">{{ t('auth.login.storyLead') }}</p>
            <p class="login-pill">{{ t('auth.login.storyPill') }}</p>
            <div class="login-features">
                <div v-for="item in features" :key="item.title" class="login-feature">
                    <i :class="item.icon" aria-hidden="true"></i>
                    <strong>{{ t(item.title) }}</strong>
                    <span>{{ t(item.text) }}</span>
                </div>
            </div>
            <p class="login-closer">{{ t('auth.login.storyCloser') }}</p>
        </div>

        <div class="login-column">
            <div class="login-card">
            <div class="login-form-header">
                <h2 class="login-form-title">{{ t('auth.login.welcomeBack') }}</h2>
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

const features = [
    { icon: 'ti ti-file-invoice', title: 'auth.login.featureInvoices', text: 'auth.login.featureInvoicesText' },
    { icon: 'ti ti-packages', title: 'auth.login.featureStock', text: 'auth.login.featureStockText' },
    { icon: 'ti ti-building-bank', title: 'auth.login.featureTreasury', text: 'auth.login.featureTreasuryText' },
    { icon: 'ti ti-chart-line', title: 'auth.login.featurePlan', text: 'auth.login.featurePlanText' },
]

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
    position: relative;
    box-sizing: border-box;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(300px, 380px);
    align-items: end;
    column-gap: clamp(20px, 3vw, 48px);
    padding: 28px clamp(20px, 3vw, 48px) clamp(20px, 3vh, 32px);
    background-color: #0B1C3E;
    background-image: url('/images/auth.jpeg');
    background-repeat: no-repeat;
    background-position: center center;
    background-size: cover;
    overflow: auto;
}

.login-story {
    position: relative;
    width: auto;
    max-width: 560px;
    justify-self: start;
    z-index: 2;
    color: #FFFFFF;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.55);
}

.login-kicker {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.82);
}

.login-headline {
    margin: 0 0 10px;
    font-size: clamp(26px, 2.4vw, 32px);
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.03em;
    color: #FFFFFF;
    overflow-wrap: anywhere;
}

.login-lead {
    margin: 0 0 14px;
    max-width: 46ch;
    font-size: 14px;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.88);
}

.login-pill {
    display: inline-block;
    margin: 0 0 14px;
    padding: 5px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.35);
    background: rgba(15, 23, 42, 0.35);
    font-size: 12px;
    font-weight: 600;
    color: #FFFFFF;
}

.login-features {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 12px;
}

.login-feature {
    display: grid;
    grid-template-columns: 28px 1fr;
    column-gap: 8px;
    row-gap: 2px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    background: rgba(15, 23, 42, 0.38);
    backdrop-filter: blur(8px);
    text-shadow: none;
}

.login-feature i {
    grid-row: span 2;
    align-self: center;
    font-size: 18px;
    color: #FFFFFF;
}

.login-feature strong {
    min-width: 0;
    font-size: 13px;
    font-weight: 700;
    color: #FFFFFF;
    overflow-wrap: anywhere;
}

.login-feature span {
    min-width: 0;
    font-size: 12px;
    line-height: 1.4;
    color: rgba(255, 255, 255, 0.78);
    overflow-wrap: anywhere;
}

.login-closer {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #FFFFFF;
}

.login-column {
    position: relative;
    top: auto;
    left: auto;
    transform: none;
    width: 100%;
    max-width: 380px;
    justify-self: end;
    align-self: center;
    margin: 0;
    padding-top: 140px;
    z-index: 3;
}

.login-logo {
    position: absolute;
    top: 16px;
    right: 24px;
    left: auto;
    z-index: 5;
    width: auto;
    height: auto;
    max-width: min(200px, 22vw);
    max-height: 112px;
    object-fit: contain;
    filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.35));
}

.login-card {
    position: relative;
    width: 100%;
    overflow: visible;
    padding: 18px 20px 14px;
    background: rgba(15, 23, 42, 0.42);
    border: 1px solid rgba(255, 255, 255, 0.28);
    border-radius: 18px;
    box-shadow: 0 18px 48px rgba(8, 18, 40, 0.28);
    backdrop-filter: blur(16px);
}

.login-form-header { margin-bottom: 14px; }

.login-form-title {
    font-size: 22px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: -0.025em;
    margin: 0;
}

.login-field { margin-bottom: 12px; }

.login-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.78);
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
    color: rgba(255, 255, 255, 0.7);
    font-size: 16px;
    pointer-events: none;
}

.login-input {
    width: 100%;
    padding: 11px 12px 11px 38px;
    border: 1.5px solid rgba(255, 255, 255, 0.28);
    border-radius: 9px;
    font-size: 13px;
    color: #FFFFFF;
    outline: none;
    font-family: inherit;
    background: rgba(255, 255, 255, 0.12);
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}

.login-input--password { padding-inline-end: 42px; }

.login-input:focus {
    border-color: rgba(255, 255, 255, 0.7);
    background: rgba(255, 255, 255, 0.18);
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.12);
}

.login-input::placeholder { color: rgba(255, 255, 255, 0.5); }
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
    color: rgba(255, 255, 255, 0.7);
    cursor: pointer;
    padding: 4px;
    font-size: 16px;
    display: flex;
    align-items: center;
    border-radius: 4px;
}

.login-eye-btn:hover { color: #FFFFFF; }

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
    flex-wrap: wrap;
    gap: 8px;
    margin: 2px 0 12px;
}

.login-remember {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    color: rgba(255, 255, 255, 0.86);
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
    color: #BFDBFE;
    text-decoration: none;
    font-weight: 500;
}

.login-forgot:hover { color: #FFFFFF; text-decoration: underline; }

.login-btn {
    width: 100%;
    padding: 12.5px;
    background: #2563EB;
    color: #FFFFFF;
    border: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}

.login-btn:hover:not(:disabled) { background: #1D4ED8; }
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
    margin: 14px 0 10px;
}

.login-lang-line {
    flex: 1;
    height: 1px;
    background: rgba(255, 255, 255, 0.2);
}

.login-lang-divider span {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.65);
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
    border: 1.5px solid rgba(255, 255, 255, 0.28);
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.86);
}

.login-lang-btn--active,
.login-lang-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.55);
    color: #FFFFFF;
}

.login-lang-btn--active { font-weight: 600; }

.login-trust {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 11.5px;
    color: rgba(255, 255, 255, 0.72);
    margin-top: 16px;
}

.login-trust i { color: #059669; font-size: 14px; }

.login-copyright {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.55);
    text-align: center;
    margin-top: 12px;
}

/* The photograph keeps the glass panel on the left, so the columns
   stay in that order even when the page is Arabic. */
[dir="rtl"] .login-shell {
    direction: ltr;
}

[dir="rtl"] .login-story,
[dir="rtl"] .login-column {
    direction: rtl;
}

[dir="rtl"] .login-kicker,
[dir="rtl"] .login-label {
    letter-spacing: 0;
}

[dir="rtl"] .login-logo {
    right: 24px;
    left: auto;
}

[dir="rtl"] .login-input {
    padding-inline-start: 38px;
    padding-inline-end: 12px;
    text-align: end;
}

[dir="rtl"] .login-input--password {
    padding-inline-end: 42px;
}

@media (max-width: 1100px), (max-height: 680px) {
    .login-shell {
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: stretch;
        gap: 16px;
        padding: 120px 16px 24px;
        background-image:
            linear-gradient(rgba(11, 28, 62, 0.78), rgba(11, 28, 62, 0.82)),
            url('/images/auth.jpeg');
    }

    .login-column { order: 1; }
    .login-story { order: 2; }

    .login-story,
    .login-column {
        position: relative;
        left: auto;
        right: auto;
        top: auto;
        bottom: auto;
        transform: none;
        width: min(520px, 100%);
        max-width: 520px;
        margin: 0 auto;
        padding-top: 0;
        justify-self: stretch;
        align-self: stretch;
    }

    [dir="rtl"] .login-story,
    [dir="rtl"] .login-column {
        left: auto;
        right: auto;
        transform: none;
    }

    .login-headline { font-size: 26px; }

    .login-logo {
        top: 12px;
        right: 12px;
        max-width: 120px;
        max-height: 96px;
    }

    [dir="rtl"] .login-logo {
        right: 12px;
        left: auto;
    }
}

@media (max-width: 480px) {
    .login-card { padding: 20px 16px 16px; }
    .login-form-title { font-size: 20px; }
    .login-features { grid-template-columns: 1fr; }
    .login-options { gap: 8px; flex-wrap: wrap; }
}
</style>
