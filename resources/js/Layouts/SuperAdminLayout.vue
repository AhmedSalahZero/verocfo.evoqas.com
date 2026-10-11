<template>
    <div class="sa-shell" :dir="dir" :lang="currentLocale">

        <!-- ── Top bar ──────────────────────────────────────────── -->
        <header class="sa-topbar" role="banner">

            <!-- Brand -->
            <div class="sa-brand">
                <img
                    src="/images/logo-mark.png"
                    alt="Vero CFO"
                    class="sa-brand-logo"
                    @error="logoError = true"
                />
                <span v-if="logoError" class="sa-brand-text">
                    Vero <span>CFO</span>
                </span>
                <div class="sa-super-badge">
                    <i class="ti ti-shield" aria-hidden="true"></i>
                    {{ t('layouts.superAdminLayout.superAdmin') }}
                </div>
            </div>

            <!-- Right actions -->
            <div class="sa-actions">

                <!-- Language toggle -->
                <button
                    type="button"
                    class="sa-action-btn sa-lang-btn"
                    @click="switchLocale"
                    :aria-label="currentLocale === 'ar' ? 'Switch to English' : 'التبديل إلى العربية'"
                >
                    {{ currentLocale === 'ar' ? 'EN' : 'ع' }}
                </button>

                <!-- User menu -->
                <div class="sa-user-wrap" ref="userRef">
                    <button
                        type="button"
                        class="sa-user-btn"
                        @click="showUserMenu = !showUserMenu"
                        :aria-expanded="showUserMenu"
                    >
                        <div class="sa-avatar">{{ userInitials }}</div>
                        <span class="sa-user-name">{{ userName }}</span>
                        <i class="ti ti-chevron-down" aria-hidden="true"></i>
                    </button>

                    <Transition name="sa-dropdown">
                        <div v-if="showUserMenu" class="sa-dropdown" role="menu">
                            <div class="sa-user-info">
                                <div class="sa-user-info-avatar">{{ userInitials }}</div>
                                <div>
                                    <div class="sa-user-info-name">{{ userName }}</div>
                                    <div class="sa-user-info-email">{{ user?.email }}</div>
                                </div>
                            </div>
                            <div class="sa-dropdown-divider"></div>
                            <Link
                                :href="route('profile.edit')"
                                class="sa-dropdown-item"
                                role="menuitem"
                                @click="showUserMenu = false"
                            >
                                <i class="ti ti-user" aria-hidden="true"></i>
                                {{ t('layouts.superAdminLayout.profileSettings') }}
                            </Link>
                            <div class="sa-dropdown-divider"></div>
                            <button
                                type="button"
                                class="sa-dropdown-item sa-dropdown-item--danger"
                                role="menuitem"
                                @click="signOut"
                            >
                                <i class="ti ti-logout" aria-hidden="true"></i>
                                {{ t('layouts.superAdminLayout.signOut') }}
                            </button>
                        </div>
                    </Transition>
                </div>

            </div>
        </header>

        <!-- ── Main content ─────────────────────────────────────── -->
        <main class="sa-main" role="main">

            <!-- Flash messages -->
            <Transition name="sa-flash">
                <div v-if="flash.success" class="sa-flash sa-flash--success" role="alert">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    <span>{{ flash.success }}</span>
                </div>
            </Transition>

            <Transition name="sa-flash">
                <div v-if="flash.error" class="sa-flash sa-flash--error" role="alert">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                    <span>{{ flash.error }}</span>
                </div>
            </Transition>

            <slot />
        </main>

    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n/index.js'

// ── i18n ──────────────────────────────────────────────────────────
const { t, locale } = useI18n()
const currentLocale  = computed(() => locale.value)
const dir            = computed(() => currentLocale.value === 'ar' ? 'rtl' : 'ltr')

// ── Page props ────────────────────────────────────────────────────
const page  = usePage()
const user  = computed(() => page.props.auth?.user)
const flash = computed(() => page.props.flash ?? {})

// ── User ──────────────────────────────────────────────────────────
const userName = computed(() => {
    if (!user.value) return ''
    if (currentLocale.value === 'ar' && user.value.name_ar) return user.value.name_ar
    return user.value.name || ''
})

const userInitials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .slice(0, 2)
        .map(w => w[0])
        .join('')
        .toUpperCase() || 'SA'
)

// ── Logo ──────────────────────────────────────────────────────────
const logoError = ref(false)

// ── User menu ─────────────────────────────────────────────────────
const showUserMenu = ref(false)

function signOut() {
    router.post(route('logout'))
}

// ── Language ──────────────────────────────────────────────────────
function switchLocale() {
    const next = currentLocale.value === 'ar' ? 'en' : 'ar'
    setLocale(next)
    window.axios.post(route('lang.update'), { lang: next }).catch(() => {})
}

// ── Keyboard close ────────────────────────────────────────────────
function handleKeydown(e) {
    if (e.key === 'Escape') showUserMenu.value = false
}

onMounted(()  => document.addEventListener('keydown', handleKeydown))
onUnmounted(() => document.removeEventListener('keydown', handleKeydown))
</script>

<style scoped>
.sa-shell {
    min-height: 100vh;
    background: #F8FAFC;
    display: flex;
    flex-direction: column;
}

/* Top bar */
.sa-topbar {
    height: 56px;
    background: #0F2554;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    position: sticky;
    top: 0;
    z-index: 200;
    box-shadow: 0 1px 0 rgba(255,255,255,0.06), 0 2px 12px rgba(11,28,62,0.22);
}

/* Brand */
.sa-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sa-brand-logo {
    width: 32px; height: 32px;
    object-fit: contain;
}

.sa-brand-text {
    font-size: 16px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: -0.02em;
}

.sa-brand-text span { color: #D4A017; }

.sa-super-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    background: rgba(212,160,23,0.15);
    border: 1px solid rgba(212,160,23,0.30);
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #D4A017;
}

.sa-super-badge i { font-size: 11px; }

/* Actions */
.sa-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

.sa-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.58);
    border-radius: 8px;
    cursor: pointer;
    font-size: 18px;
    transition: background 0.12s, color 0.12s;
}

.sa-action-btn:hover {
    background: rgba(255,255,255,0.07);
    color: #FFFFFF;
}

.sa-lang-btn {
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    width: auto;
    padding: 0 10px;
}

/* User button */
.sa-user-wrap { position: relative; }

.sa-user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.58);
    border-radius: 8px;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s, color 0.12s;
}

.sa-user-btn:hover {
    background: rgba(255,255,255,0.07);
    color: #FFFFFF;
}

.sa-avatar {
    width: 30px; height: 30px;
    background: #1E4DB7;
    color: #fff;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}

.sa-user-name {
    font-size: 13px;
    font-weight: 500;
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Dropdown */
.sa-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    inset-inline-end: 0;
    width: 220px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(15,23,42,0.14);
    overflow: hidden;
    z-index: 300;
}

.sa-user-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
}

.sa-user-info-avatar {
    width: 36px; height: 36px;
    background: #0F2554;
    color: #fff;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}

.sa-user-info-name  { font-size: 13px; font-weight: 600; color: #0F172A; }
.sa-user-info-email { font-size: 11px; color: #94A3B8; margin-top: 1px; }

.sa-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    font-size: 13px;
    color: #0F172A;
    text-decoration: none;
    cursor: pointer;
    background: transparent;
    border: none;
    width: 100%;
    text-align: start;
    font-family: inherit;
    transition: background 0.1s;
}

.sa-dropdown-item:hover     { background: #F8FAFC; }
.sa-dropdown-item i         { font-size: 16px; color: #64748B; }
.sa-dropdown-divider        { height: 1px; background: #F1F5F9; margin: 4px 0; }
.sa-dropdown-item--danger   { color: #DC2626; }
.sa-dropdown-item--danger i { color: #DC2626; }
.sa-dropdown-item--danger:hover { background: #FEF2F2; }

/* Main */
.sa-main {
    flex: 1;
    padding: 2rem 2.5rem;
    max-width: 1400px;
    width: 100%;
    margin: 0 auto;
}

/* Flash */
.sa-flash {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 500;
    margin-bottom: 1.5rem;
}

.sa-flash i         { font-size: 17px; flex-shrink: 0; }
.sa-flash--success  { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.sa-flash--error    { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }

/* Transitions */
.sa-dropdown-enter-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.sa-dropdown-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.sa-dropdown-enter-from, .sa-dropdown-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }

.sa-flash-enter-active { transition: opacity 0.2s ease; }
.sa-flash-leave-active { transition: opacity 0.15s ease; }
.sa-flash-enter-from, .sa-flash-leave-to { opacity: 0; }

/* RTL */
[dir="rtl"] .sa-dropdown-item { text-align: end; }
</style>
