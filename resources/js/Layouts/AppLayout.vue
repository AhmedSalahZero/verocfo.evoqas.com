<!--
══════════════════════════════════════════════════════════════════
  AppLayout.vue

  Main authenticated layout for Vero CFO.
  Wraps every authenticated company page.

  Architecture:
    - Top bar: fixed, full width, navy — logo, pillar tabs, actions
    - Sidebar: sticky, collapsible (240px ↔ 60px), navy — section
      grouped items with gold active state and left border accent
    - Main: shifts with sidebar, light surface background

  Navigation is driven entirely from page.props.navigation —
  built by HandleInertiaRequests and filtered by subscription tier.

  Pillar order (v1.5):
    Accounting | Financial Institutions | Projects & Contracts |
    HR | Analytics | Annual Plan | Business Plan & Valuation | Settings

  Design standards (matches ZAVERO quality):
    - SVG inline icons — not icon font
    - Section group labels (ACCOUNTING, FINANCIAL INSTITUTIONS, etc.)
    - Gold active state with left border — impossible to miss
    - Theme toggle: dark / light
    - Dense, professional spacing
    - Impersonation banner in gold when Super Admin is inside company

  Subscription expired: permanent red read-only banner at the top
  (Audit C5, Sept 2026).
  Settings sidebar: the Users item is shown to Company Admins and
  the Super Admin only (Audit C2, Sept 2026).

  @layout    Root layout — used by all authenticated pages
  @depends   HandleInertiaRequests (navigation, auth, flash props)
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="vero-shell" :class="{ 'sidebar-collapsed': sidebarCollapsed }">

        <!-- ══════════════════════════════════════════════════════
             TOP BAR
        ══════════════════════════════════════════════════════ -->
        <nav class="vero-topbar">
            <div class="vero-topbar-inner">

                <!-- LEFT: Collapse + Logo + Pillar tabs -->
                <div class="vero-topbar-left">

                    <!-- Collapse toggle -->
                    <button class="vero-icon-btn" @click="toggleSidebar" :title="sidebarCollapsed ? t('layouts.appLayout.expand') : t('layouts.appLayout.collapse')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>

                    <!-- Logo -->
                    <Link :href="route('dashboard')" class="vero-brand">
                        <img src="/images/logo-mark.png" alt="Vero CFO" class="vero-brand-logo" @error="logoFailed = true" />
                        <div v-if="logoFailed" class="vero-brand-text">
                            Vero <span>CFO</span>
                        </div>
                    </Link>

                    <!-- Divider -->
                    <div class="vero-topbar-divider"></div>

                    <!-- Pillar tabs — from shared props -->
                    <div class="vero-pillar-tabs">
                        <button
                            v-for="pillar in navigation"
                            :key="pillar.key"
                            type="button"
                            class="vero-pillar-tab"
                            :class="{ 'vero-pillar-active': activePillar === pillar.key }"
                            @click="selectPillar(pillar)"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="iconPath(pillar.icon)"></svg>
                            <span>{{ t(pillar.label) }}</span>
                        </button>

                        <!-- Settings — always visible -->
                        <button
                            type="button"
                            class="vero-pillar-tab"
                            :class="{ 'vero-pillar-active': activePillar === 'settings' }"
                            @click="selectPillar({ key: 'settings', route: 'settings.index' })"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>{{ t('layouts.appLayout.settings') }}</span>
                        </button>
                    </div>
                </div>

                <!-- RIGHT: Impersonation + Theme + Lang + User -->
                <div class="vero-topbar-right">

                    <!-- Impersonation badge -->
                    <div v-if="isImpersonating" class="vero-impersonate">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <span>{{ companyName }}</span>
                        <button type="button" class="vero-impersonate-exit" @click="exitImpersonation">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            {{ t('layouts.appLayout.exit') }}
                        </button>
                    </div>

                    <!-- Company name (non-impersonating) -->
                    <div v-else class="vero-company-chip">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>
                        </svg>
                        <span>{{ companyName }}</span>
                    </div>

                    <!-- Theme toggle -->
                    <button type="button" class="vero-icon-btn" @click="toggleTheme" :title="isDark ? 'Switch to Light' : 'Switch to Dark'">
                        <svg v-if="isDark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                    </button>

                    <!-- Language toggle -->
                    <button type="button" class="vero-lang-btn" @click="switchLocale">
                        <span class="vero-lang-active">{{ currentLocale === 'en' ? 'EN' : 'ع' }}</span>
                        <span class="vero-lang-divider">/</span>
                        <span class="vero-lang-inactive">{{ currentLocale === 'en' ? 'ع' : 'EN' }}</span>
                    </button>

                    <!-- Messages -->
                    <Link :href="route('messages.index')" class="vero-icon-btn vero-msg-btn" :title="t('messaging.inbox.title')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <span v-if="unreadMessageCount > 0" class="vero-notif-dot">{{ unreadMessageCount > 9 ? '9+' : unreadMessageCount }}</span>
                    </Link>

                    <!-- Notifications -->
                    <div class="vero-action-wrap" ref="notifRef">
                        <button type="button" class="vero-icon-btn" @click="toggleNotifications">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span v-if="unreadCount > 0" class="vero-notif-dot">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
                        </button>

                        <!-- Notifications dropdown -->
                        <Transition name="vero-drop">
                            <div v-if="showNotifications" class="vero-dropdown vero-notif-panel">
                                <div class="vero-dropdown-header">
                                    <span class="vero-dropdown-title">{{ t('layouts.appLayout.notifications') }}</span>
                                    <button v-if="unreadCount > 0" type="button" class="vero-dropdown-action" @click="markAllRead">
                                        {{ t('layouts.appLayout.markAllRead') }}
                                    </button>
                                </div>
                                <div class="vero-notif-list">
                                    <div v-if="notifications.length === 0" class="vero-notif-empty">
                                        <svg class="w-6 h-6 opacity-30 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                        </svg>
                                        {{ t('layouts.appLayout.noNotifications') }}
                                    </div>
                                    <div v-for="n in notifications" :key="n.id" class="vero-notif-item" :class="{ 'unread': !n.is_read }">
                                        <div class="vero-notif-body">
                                            <div class="vero-notif-title">{{ n.title }}</div>
                                            <div class="vero-notif-msg">{{ n.message }}</div>
                                            <a v-if="n.action_url" :href="n.action_url" class="vero-notif-link">{{ n.action_label || n.created_at }}</a>
                                            <div v-else-if="n.created_at" class="vero-notif-msg">{{ n.created_at }}</div>
                                        </div>
                                        <div v-if="!n.is_read" class="vero-notif-badge"></div>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- User menu -->
                    <div class="vero-action-wrap" ref="userRef">
                        <button type="button" class="vero-user-btn" @click="showUserMenu = !showUserMenu">
                            <div class="vero-user-avatar">{{ userInitials }}</div>
                            <span class="vero-user-name">{{ userName }}</span>
                            <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <Transition name="vero-drop">
                            <div v-if="showUserMenu" class="vero-dropdown vero-user-panel">
                                <div class="vero-user-panel-header">
                                    <div class="vero-user-panel-avatar">{{ userInitials }}</div>
                                    <div>
                                        <div class="vero-user-panel-name">{{ userName }}</div>
                                        <div class="vero-user-panel-email">{{ authUser?.email }}</div>
                                        <div class="vero-user-panel-role">{{ userRoleLabel }}</div>
                                    </div>
                                </div>
                                <div class="vero-dropdown-divider"></div>
                                <Link :href="route('profile.edit')" class="vero-dropdown-item" @click="showUserMenu = false">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    {{ t('layouts.appLayout.profileSettings') }}
                                </Link>
                                <div class="vero-dropdown-divider"></div>
                                <button type="button" class="vero-dropdown-item vero-dropdown-danger" @click="signOut">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    {{ t('layouts.appLayout.signOut') }}
                                </button>
                            </div>
                        </Transition>
                    </div>

                </div>
            </div>
        </nav>

        <!-- ══════════════════════════════════════════════════════
             SUBSCRIPTION EXPIRED — read-only banner (Audit C5)
             Permanent (no close button) while the subscription is
             expired. The server refuses every save; this tells the
             user why before they try.
        ══════════════════════════════════════════════════════ -->
        <div v-if="company?.is_subscription_expired" class="vero-flash vero-flash-error vero-expired-banner" role="alert">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            <span>{{ t('layouts.appLayout.subscriptionExpired', { date: company.subscription_expires_at }) }}</span>
        </div>

        <!-- ══════════════════════════════════════════════════════
             FLASH MESSAGES
        ══════════════════════════════════════════════════════ -->
        <Transition name="vero-flash">
            <div v-if="flash.success" class="vero-flash vero-flash-success" role="alert">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ flash.success }}</span>
                <button type="button" @click="localFlash.success = null" class="vero-flash-close"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
        </Transition>
        <Transition name="vero-flash">
            <div v-if="flash.error" class="vero-flash vero-flash-error" role="alert">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ flash.error }}</span>
                <button type="button" @click="localFlash.error = null" class="vero-flash-close"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
        </Transition>
        <Transition name="vero-flash">
            <div v-if="flash.warning" class="vero-flash vero-flash-warning" role="alert">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span>{{ flash.warning }}</span>
                <button type="button" @click="localFlash.warning = null" class="vero-flash-close"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
        </Transition>

        <ToastStack :toasts="toasts" @dismiss="dismissToast" />

        <!-- ══════════════════════════════════════════════════════
             BODY: Sidebar + Content
        ══════════════════════════════════════════════════════ -->
        <div class="vero-body">

            <!-- SIDEBAR -->
            <aside class="vero-sidebar" :class="{ 'collapsed': sidebarCollapsed }">
                <div class="vero-sidebar-inner">

                    <template v-if="activePillarData">

                        <!-- v1.7: pillars with 'groups' render multiple labeled
                             sections (Financial Institutions, Accounting);
                             pillars still using flat 'items' render exactly as
                             before, one label.
                             v1.9: labeled groups are now collapsible — click
                             the section header to expand/collapse. Ungrouped
                             sections (group.label === null) are never
                             collapsible and always render, same as before. -->
                        <template v-if="activePillarData.groups">
                            <template v-for="(group, gIdx) in activePillarData.groups" :key="'group-' + gIdx">

                                <!-- Ungrouped items — no header, always visible -->
                                <template v-if="!group.label">
                                    <template v-for="item in group.items" :key="item.key">
                                        <Link
                                            :href="routeExists(item.route) ? route(item.route) : '#'"
                                            class="vero-sidebar-link"
                                            :class="{ 'active': isItemActive(item) }"
                                            :title="sidebarCollapsed ? t(item.label) : undefined"
                                        >
                                            <svg class="vero-sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="iconPath(item.icon)"></svg>
                                            <span v-if="!sidebarCollapsed" class="vero-sidebar-label">{{ t(item.label) }}</span>
                                            <div v-if="sidebarCollapsed" class="vero-tooltip">{{ t(item.label) }}</div>
                                        </Link>
                                    </template>
                                </template>

                                <!-- Labeled, collapsible section -->
                                <template v-else>
                                    <button
                                        v-if="!sidebarCollapsed"
                                        type="button"
                                        class="vero-section-label vero-section-toggle"
                                        @click="toggleGroup(activePillarData.key, group)"
                                    >
                                        <span>{{ t(group.label) }}</span>
                                        <svg
                                            class="vero-section-chevron"
                                            :class="{ 'is-collapsed': isGroupCollapsed(activePillarData.key, group) }"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>

                                    <template v-if="sidebarCollapsed || !isGroupCollapsed(activePillarData.key, group)">
                                        <template v-for="item in group.items" :key="item.key">
                                            <Link
                                                :href="routeExists(item.route) ? route(item.route) : '#'"
                                                class="vero-sidebar-link"
                                                :class="{ 'active': isItemActive(item) }"
                                                :title="sidebarCollapsed ? t(item.label) : undefined"
                                            >
                                                <svg class="vero-sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="iconPath(item.icon)"></svg>
                                                <span v-if="!sidebarCollapsed" class="vero-sidebar-label">{{ t(item.label) }}</span>
                                                <div v-if="sidebarCollapsed" class="vero-tooltip">{{ t(item.label) }}</div>
                                            </Link>
                                        </template>
                                    </template>
                                </template>
                            </template>
                        </template>

                        <template v-else>
                            <!-- Section label -->
                            <div v-if="!sidebarCollapsed" class="vero-section-label">
                                {{ t(activePillarData.label) }}
                            </div>

                            <!-- Sidebar items -->
                            <template v-for="item in activePillarData.items" :key="item.key">
                                <Link
                                    :href="routeExists(item.route) ? route(item.route) : '#'"
                                    class="vero-sidebar-link"
                                    :class="{ 'active': isItemActive(item) }"
                                    :title="sidebarCollapsed ? t(item.label) : undefined"
                                >
                                    <svg class="vero-sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="iconPath(item.icon)"></svg>
                                    <span v-if="!sidebarCollapsed" class="vero-sidebar-label">{{ t(item.label) }}</span>
                                    <div v-if="sidebarCollapsed" class="vero-tooltip">{{ t(item.label) }}</div>
                                </Link>
                            </template>
                        </template>

                    </template>

                    <!-- Settings sidebar -->
                    <template v-if="activePillar === 'settings'">
                        <div v-if="!sidebarCollapsed" class="vero-section-label">{{ t('layouts.appLayout.settings') }}</div>
                        <template v-for="item in visibleSettingsItems" :key="item.key">
                            <Link
                                :href="routeExists(item.route) ? route(item.route) : '#'"
                                class="vero-sidebar-link vero-sidebar-settings"
                                :class="{ 'active': isItemActive(item) }"
                                :title="sidebarCollapsed ? t(item.label) : undefined"
                            >
                                <svg class="vero-sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="item.svg"></svg>
                                <span v-if="!sidebarCollapsed" class="vero-sidebar-label">{{ t(item.label) }}</span>
                                <div v-if="sidebarCollapsed" class="vero-tooltip">{{ t(item.label) }}</div>
                            </Link>
                        </template>
                    </template>

                </div>
            </aside>

            <!-- MAIN CONTENT -->
            <main class="vero-main" id="main-content" tabindex="-1">
                <slot />
            </main>

        </div>

        <!-- Click outside overlay -->
        <div v-if="showNotifications || showUserMenu" class="vero-overlay" @click="closeAll"></div>

    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n/index.js'
import ToastStack from '@/Components/ToastStack.vue'
import { useToasts } from '@/composables/useToasts'
import { useUnreadMessages } from '@/composables/useUnreadMessages'

const { t, locale } = useI18n()
const currentLocale  = computed(() => locale.value)
const page           = usePage()

// ── Props ─────────────────────────────────────────────────────────
const navigation      = computed(() => page.props.navigation ?? [])
const isImpersonating = computed(() => page.props.auth?.is_impersonating ?? false)
const authUser        = computed(() => page.props.auth?.user)
const company         = computed(() => page.props.auth?.company)

// ── User ──────────────────────────────────────────────────────────
const userName = computed(() => {
    if (!authUser.value) return ''
    return (currentLocale.value === 'ar' && authUser.value.name_ar)
        ? authUser.value.name_ar
        : authUser.value.name ?? ''
})

const userInitials = computed(() =>
    (authUser.value?.name ?? '').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase() || 'U'
)

const userRoleLabel = computed(() => {
    const r = authUser.value?.role
    if (r === 'super_admin')   return 'Super Admin'
    if (r === 'company_admin') return 'Company Admin'
    return 'User'
})

const companyName = computed(() => {
    if (!company.value) return ''
    return (currentLocale.value === 'ar' && company.value.name_ar)
        ? company.value.name_ar
        : company.value.name_en ?? ''
})

// ── Logo ──────────────────────────────────────────────────────────
const logoFailed = ref(false)

// ── Theme ─────────────────────────────────────────────────────────
// Fixed August 2026: this previously defaulted to dark-first
// (`!== 'light'`), contradicting onMounted's own light-first default
// (`|| 'light'`) a few lines below — a real, if narrow, inconsistency
// between the ref's initial value and the value applyTheme() sets a
// moment later on mount. Building Bible Rule 24 is explicit: "Light
// default, dark toggle persisted in localStorage" — light-first is
// correct, matching onMounted, not the other way around.
const isDark = ref(localStorage.getItem('vero_theme') === 'dark')

function applyTheme(dark) {
    isDark.value = dark
    const root = document.documentElement
    if (dark) {
        root.setAttribute('data-theme', 'dark')
        root.style.setProperty('--vero-bg',      '#0C1829')
        root.style.setProperty('--vero-nav',     '#0E1E34')
        root.style.setProperty('--vero-sidebar', '#0B1A30')
        root.style.setProperty('--vero-card',    '#112240')
        root.style.setProperty('--vero-border',  '#1B3558')
        root.style.setProperty('--vero-text',    '#E2E8F0')
        root.style.setProperty('--vero-muted',   '#6B96B8')
        root.style.setProperty('--vero-input',   '#0D1E38')
    } else {
        root.setAttribute('data-theme', 'light')
        root.style.setProperty('--vero-bg',      '#F0F6FF')
        root.style.setProperty('--vero-nav',     '#0F2554')
        root.style.setProperty('--vero-sidebar', '#0F2554')
        root.style.setProperty('--vero-card',    '#FFFFFF')
        root.style.setProperty('--vero-border',  '#E2E8F0')
        root.style.setProperty('--vero-text',    '#0F172A')
        root.style.setProperty('--vero-muted',   '#64748B')
        root.style.setProperty('--vero-input',   '#F8FAFC')
    }
}

function toggleTheme() {
    const next = !isDark.value
    applyTheme(next)
    localStorage.setItem('vero_theme', next ? 'dark' : 'light')
}

// ── Sidebar ───────────────────────────────────────────────────────
const sidebarCollapsed = ref(localStorage.getItem('vero_sidebar') === 'collapsed')

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
    localStorage.setItem('vero_sidebar', sidebarCollapsed.value ? 'collapsed' : 'expanded')
}

// ── Pillar state ──────────────────────────────────────────────────
//
// v1.8 — pillarFromUrl() no longer hardcodes a list of URL prefixes.
// It derives each pillar's Laravel route-name prefix (e.g. 'fi.' for
// Financial Institutions, 'accounting.' for Accounting) directly
// from that pillar's own route data — the same data
// buildNavigation() (HandleInertiaRequests.php) already sends — and
// asks Ziggy which prefix the current route belongs to.
//
// This deliberately matches on the route NAME prefix, not on
// whether the current route is one of the specific routes listed in
// the navigation menu. The menu only lists index/dashboard-level
// routes (e.g. 'fi.facilities.index') — it was never meant to list
// every route in the app. A person two clicks deep into e.g.
// 'fi.facilities.clean-od.edit' still needs this to resolve to the
// Financial Institutions pillar, even though that exact route name
// never appears anywhere in the navigation data.
//
// A new pillar added on the backend is detected automatically here
// as long as its own routes share one consistent name prefix (true
// for all 7 pillars today — confirmed by checking every route name
// in buildNavigation() shares exactly one first-segment prefix per
// pillar, with no overlaps between pillars).
function pillarRoutePrefix(pillar) {
    const anyRoute = pillar.route
        ?? pillar.items?.[0]?.route
        ?? pillar.groups?.[0]?.items?.[0]?.route
    if (!anyRoute) return null
    return anyRoute.split('.')[0] + '.*'
}

// Fix (August 2026) — SECOND, deeper pass. The first fix (check each
// pillar's own items before falling back to the coarse prefix) was
// still wrong: it stopped at the FIRST pillar with ANY matching item,
// without checking how SPECIFIC that match was. Every pillar's own
// "Dashboard" item (route name 'accounting.dashboard', 'fi.dashboard'
// — only 2 segments) collapses to the exact same broad prefix as the
// whole pillar ('accounting.*') the moment you drop its last segment.
// Since Accounting is checked first, ITS OWN Dashboard item alone was
// enough to out-match Financial Institutions' correctly-placed
// Customer Collection / Supplier Payment / Cheques Receivable /
// Cheques Payable items on every single click — not just Customer
// Collection as first thought, all four relocated items, and it
// would have kept happening for any future item moved the same way.
//
// Real fix: don't stop at the first pillar with any match. Collect
// EVERY matching item across ALL pillars, and pick whichever one has
// the MOST SPECIFIC (longest) route prefix — that's the item that
// actually describes where you are, regardless of which pillar
// happens to be listed first or how broad its own Dashboard link is.
function collectPillarItems(pillar) {
    const items = []
    if (pillar.items) items.push(...pillar.items)
    if (pillar.groups) {
        for (const group of pillar.groups) {
            if (group.items) items.push(...group.items)
        }
    }
    return items
}

function itemRoutePrefix(item) {
    if (!item.route) return null
    const parts = item.route.split('.')
    parts.pop() // drop the action segment (index/create/edit/dashboard/etc.)
    return parts.length ? parts.join('.') + '.*' : null
}

function pillarFromUrl() {
    // Settings is deliberately NOT part of navigation.value — it's
    // a separate, always-visible section regardless of subscription
    // tier (confirmed with Mahmoud), not one of the tier-gated
    // pillars buildNavigation() returns.
    if (isActive('settings.*')) return 'settings'

    // Fix (September 2026) — same exact-match-first rule as
    // computeActiveItemRoute() below, applied one level up: an item
    // whose own route is the exact current route unambiguously
    // identifies its pillar, with no risk of a same-length prefix
    // tie between two items (sibling or not) ever picking the wrong
    // one. Prefix matching remains the fallback for sub-pages.
    const currentRouteName = route().current()
    for (const pillar of navigation.value) {
        for (const item of collectPillarItems(pillar)) {
            if (item.route === currentRouteName) return pillar.key
        }
    }

    let bestMatch = null // { pillarKey, prefix }

    for (const pillar of navigation.value) {
        for (const item of collectPillarItems(pillar)) {
            const prefix = itemRoutePrefix(item)
            if (!prefix || !isActive(prefix)) continue
            if (!bestMatch || prefix.length > bestMatch.prefix.length) {
                bestMatch = { pillarKey: pillar.key, prefix }
            }
        }
    }
    if (bestMatch) return bestMatch.pillarKey

    // Fallback, unchanged — only reached when NOTHING in any pillar's
    // actual item list matched at all (e.g. a route with no nav item
    // of its own, deep-linked directly).
    for (const pillar of navigation.value) {
        const prefix = pillarRoutePrefix(pillar)
        if (prefix && isActive(prefix)) return pillar.key
    }
    return navigation.value[0]?.key ?? null
}

const activePillar     = ref(pillarFromUrl())
const activePillarData = computed(() => navigation.value.find(p => p.key === activePillar.value) ?? null)

function selectPillar(pillar) {
    activePillar.value = pillar.key
    if (pillar.route && routeExists(pillar.route)) {
        router.visit(route(pillar.route))
    }
}

router.on('navigate', () => { activePillar.value = pillarFromUrl() })

// ── Collapsible sidebar groups (v1.9) ───────────────────────────────
//
// Labeled groups inside a pillar's 'groups' array (Financial
// Institutions, Accounting) can be individually collapsed. State is
// keyed per pillar+group-label and persisted so it survives reloads
// and navigation. On first-ever visit (nothing in localStorage yet),
// every group starts collapsed EXCEPT the one containing the
// currently active route — this is what actually solves the "too
// long, not well ordered" problem instead of just moving it behind
// one extra click on every page load.
const GROUP_COLLAPSE_KEY = 'vero_sidebar_collapsed_groups'

function groupStateKey(pillarKey, group) {
    return `${pillarKey}::${group.label ?? '__ungrouped'}`
}

function loadStoredCollapsedGroups() {
    try {
        const raw = localStorage.getItem(GROUP_COLLAPSE_KEY)
        return raw ? new Set(JSON.parse(raw)) : null
    } catch {
        return null
    }
}

// Fix (August 2026, found during full audit after the pillar-tab bug):
// same root cause, different spot. isActive(item.route) only matches
// the EXACT route — 'accounting.customer-payments.index' — never a
// sub-page like '...edit'. Landing directly on an edit/create page
// (a bookmark, a link from elsewhere) meant this always concluded
// "nothing active here" and collapsed the group hiding the very item
// that got you there — for ANY group, not just Customer Collection's.
// Reuses itemRoutePrefix() (already fixed for the same reason in
// pillarFromUrl() above) so a sub-page correctly still counts.
function defaultCollapsedGroups() {
    const collapsed = new Set()
    for (const pillar of navigation.value) {
        if (!pillar.groups) continue
        for (const group of pillar.groups) {
            if (!group.label) continue
            const hasActiveItem = group.items.some(item => {
                const prefix = itemRoutePrefix(item)
                return prefix && isActive(prefix)
            })
            if (!hasActiveItem) collapsed.add(groupStateKey(pillar.key, group))
        }
    }
    return collapsed
}

const collapsedGroups = ref(loadStoredCollapsedGroups() ?? defaultCollapsedGroups())

function persistCollapsedGroups() {
    localStorage.setItem(GROUP_COLLAPSE_KEY, JSON.stringify([...collapsedGroups.value]))
}

function isGroupCollapsed(pillarKey, group) {
    if (!group.label) return false
    return collapsedGroups.value.has(groupStateKey(pillarKey, group))
}

function toggleGroup(pillarKey, group) {
    if (!group.label) return
    const key = groupStateKey(pillarKey, group)
    const next = new Set(collapsedGroups.value)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    collapsedGroups.value = next
    persistCollapsedGroups()
}

// When navigating lands on a route inside a currently-collapsed group,
// auto-expand that group so the active item is never hidden.
router.on('navigate', () => {
    const pillar = activePillarData.value
    if (!pillar?.groups) return
    let changed = false
    const next = new Set(collapsedGroups.value)
    for (const group of pillar.groups) {
        if (!group.label) continue
        const key = groupStateKey(pillar.key, group)
        if (!next.has(key)) continue
        const hasActiveItem = group.items.some(item => {
            const prefix = itemRoutePrefix(item)
            return prefix && isActive(prefix)
        })
        if (hasActiveItem) { next.delete(key); changed = true }
    }
    if (changed) { collapsedGroups.value = next; persistCollapsedGroups() }
})

// ── Settings items ────────────────────────────────────────────────
const settingsItems = [
    { key: 'users',          label: 'Users',                  route: 'users.index',  adminOnly: true,                    svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>' },
    { key: 'coa',            label: 'Chart of Accounts',      route: 'settings.coa.index',             svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>' },
    { key: 'partners',       label: 'Partners',               route: 'settings.partners.index',        svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>' },
    { key: 'payment-terms',  label: 'Payment Terms',          route: 'settings.payment-terms.index',   svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7l2 2 4-4"/>' },
    { key: 'currencies',     label: 'Currencies',             route: 'settings.currencies.index',      svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>' },
    { key: 'cost-centers',   label: 'Cost Centers',           route: 'settings.cost-centers.index',   svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>' },
    { key: 'fiscal-years',   label: 'Fiscal Periods',         route: 'settings.fiscal-years.index',   svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>' },
    { key: 'products',           label: 'Products & Services',    route: 'settings.products.index',            svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>' },
    { key: 'fixed-asset-types',  label: 'Fixed Asset Types',       route: 'settings.fixed-asset-types.index',   svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m9-14h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M9 7h.01M15 7h.01"/>' },
    { key: 'product-categories', label: 'Product Categories',      route: 'settings.product-categories.index', svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>' },
    { key: 'locations',          label: 'Inventory Locations',    route: 'settings.locations.index',           svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>' },
    { key: 'opening-balances',   label: 'Opening Balances',   route: 'settings.opening-balances.index', svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>' },
    { key: 'taxes',              label: 'Tax Settings',        route: 'settings.taxes.index',            svg: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z"/>' },
]

// Audit C2 (Sept 2026): items flagged adminOnly (the Users screen)
// are hidden from regular users — the server refuses them anyway,
// this just avoids showing a link that would lead to "forbidden".
const visibleSettingsItems = computed(() => {
    const isAdmin = ['super_admin', 'company_admin'].includes(authUser.value?.role)
    return settingsItems.filter(item => !item.adminOnly || isAdmin)
})

// ── Route helpers ─────────────────────────────────────────────────
function routeExists(name) { try { route(name); return true } catch { return false } }
function isActive(r) { try { return route().current(r) } catch { return false } }

// Fix (August 2026, found during full audit): every sidebar link's
// gold 'active' highlight used isActive(item.route) directly — an
// EXACT match against the item's own index/list route. The highlight
// silently disappeared the moment someone was on an Edit, Create, or
// View sub-page instead — the most visible instance of the same root
// cause already fixed twice above (pillar tab, group auto-expand).
// A person deep inside a specific record had no sidebar highlight
// telling them where they were, for every module in the app.
//
// Fix (Sept 2026) — THIRD instance of the same root cause, one level
// down. Each item's highlight was still computed in ISOLATION —
// isActive(itemRoutePrefix(item)) per item, with no awareness of its
// siblings. A pillar's own 2-segment "Dashboard" item (route
// 'analytics.dashboard', 'accounting.dashboard', 'fi.dashboard',
// 'hr.dashboard', 'planning.dashboard', 'valuation.dashboard' — every
// pillar has one) drops its last segment down to the prefix of the
// ENTIRE PILLAR ('analytics.*') the moment itemRoutePrefix() computes
// it — so that Dashboard link matched, and stayed gold, on every
// single page inside its own pillar, at the same time as whichever
// item was actually current (e.g. 'Sales Reports'). Same fix as
// pillarFromUrl() above: don't judge each item alone — collect every
// item whose prefix matches across the whole app (all pillars +
// Settings), and only the SINGLE one with the most specific (longest)
// prefix counts as active. Comparing across pillars that aren't even
// rendered right now is harmless — only the active pillar's items
// ever reach the template.
function allSidebarItems() {
    const items = []
    for (const pillar of navigation.value) items.push(...collectPillarItems(pillar))
    items.push(...settingsItems)
    return items
}

// Fix (September 2026, per Mahmoud — bug report: clicking "Projects
// & Contracts" left "Contract Dashboard" highlighted gold instead):
// a FOURTH instance of the same root cause, one level deeper still.
// itemRoutePrefix() drops an item's last route segment to build its
// matching prefix — but for two SIBLING 2-segment routes that share
// a pillar, dropping the last segment can produce the exact SAME
// prefix string. Here: 'projects.dashboard' → 'projects.*' and
// 'projects.index' → 'projects.*' — identical, same length. The
// previous code only replaced `best` on a strict `>` comparison, so
// a tie was never broken — whichever item happened to be listed
// FIRST in buildNavigation() (contract-dashboard, before projects)
// silently won forever, regardless of which page was actually open.
//
// Fix: an item whose OWN route name is an EXACT match for the
// current route is unambiguous and always wins, checked before any
// prefix comparison at all — no tie is even possible for the page
// you're really on. Prefix matching (unchanged) remains the
// fallback for sub-pages (Create/Edit/Show) that have no sidebar
// item of their own.
function computeActiveItemRoute() {
    const currentRouteName = route().current()

    for (const item of allSidebarItems()) {
        if (item.route === currentRouteName) return item.route
    }

    let best = null // { route, prefixLength }
    for (const item of allSidebarItems()) {
        const prefix = itemRoutePrefix(item)
        if (!prefix || !isActive(prefix)) continue
        if (!best || prefix.length > best.prefixLength) {
            best = { route: item.route, prefixLength: prefix.length }
        }
    }
    return best?.route ?? null
}

const activeItemRoute = ref(computeActiveItemRoute())
router.on('navigate', () => { activeItemRoute.value = computeActiveItemRoute() })

function isItemActive(item) {
    return !!item.route && item.route === activeItemRoute.value
}


// ── Icons — one dictionary, keyed by icon NAME ─────────────────────
//
// v1.8 — Replaces the old pillarIcon(key) / sidebarIcon(key) pair,
// which looked up an SVG by guessing from the item's `key` string
// (e.g. 'dashboard', 'ar', 'ap') via two separate ~250-line maps.
// The backend (buildNavigation() in HandleInertiaRequests.php)
// already sends a proper icon NAME on every pillar and item —
// e.g. 'ti-layout-dashboard', 'ti-receipt' — using the Tabler Icons
// naming convention, but the frontend never used it until now.
//
// What this means going forward: adding a new pillar or menu item
// that reuses an icon name already in this dictionary needs ZERO
// changes to this file. Only a genuinely new icon concept needs one
// new entry here, added once — not once per item, the way the old
// per-key maps required.
//
// Most paths below were carried over unchanged from the old maps
// (the same shape, just re-keyed by icon name instead of item key).
// A handful were re-picked because the old shape didn't actually
// match the new name semantically (e.g. the old 'valuation' pillar
// icon was a trending-line chart, not a diamond) — those are marked
// NEW below. Worth a quick visual check after deploying, since I
// can't render these to confirm how they look.
const ICON_PATHS = {
    // ── Dashboards, generic ─────────────────────────────────────
    'ti-layout-dashboard': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',

    // ── Accounting ───────────────────────────────────────────────
    'ti-book':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
    'ti-book-2':           '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
    'ti-clipboard-list':   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
    'ti-receipt':          '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
    'ti-receipt-2':        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z"/>',
    'ti-building':         '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/>',
    'ti-chart-bar':        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
    'ti-scale':            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>',
    'ti-arrows-exchange':  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
    'ti-chart-line':       '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>',
    'ti-box':              '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
    'ti-shopping-cart':    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>',
    'ti-list':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 10h16M4 14h16M4 18h7"/>',
    'ti-settings-2':       '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>',
    // NEW (v1.9) — previously missing, these 4 Manufacturing/Inventory
    // items silently fell back to the generic hamburger icon.
    'ti-stack-2':          '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4l8 4-8 4-8-4 8-4zm-8 8l8 4 8-4m-16 4l8 4 8-4"/>',
    'ti-percentage':       '<circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 7L7 17"/>',
    'ti-template':         '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h5a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 17a1 1 0 011-1h5a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1v-2zM13 13a1 1 0 011-1h5a1 1 0 011 1v6a1 1 0 01-1 1h-5a1 1 0 01-1-1v-6zM13 5a1 1 0 011-1h5a1 1 0 011 1v2a1 1 0 01-1 1h-5a1 1 0 01-1-1V5z"/>',
    'ti-adjustments':      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 21v-7m0-4V3m8 18v-9m0-4V3m8 18v-5m0-4V3M1 14h6m2-6h6m2 8h6"/>',
    'ti-report-analytics': '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6m4 6v-10m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
    'ti-stack':            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4l8 4-8 4-8-4 8-4zm-8 8l8 4 8-4m-16 4l8 4 8-4"/>',
    'ti-lock':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
    // NEW (v1.10) — Goods Receipt Notes nav item needs a delivery/truck
    // shape distinct from ti-shopping-cart (Purchase Orders) and ti-box
    // (Inventory) — without this it would silently fall back to the
    // generic hamburger icon, same trap v1.9's note above already
    // documented for four other items.
    'ti-truck-return':     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14L4 9l5-5M4 9h10.5a5.5 5.5 0 010 11H11"/>',
    'ti-truck-delivery':   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 17h1a2 2 0 002-2V7a2 2 0 00-2-2H3v12zm4 0h7V7H7v10zm7 0h1.5l2.5-4h-4v4zM7 19a2 2 0 104 0m6 0a2 2 0 104 0"/>',
    // NEW (Sept 2026, Manufacturing Costing Redesign) — three more
    // items were about to repeat the exact same silent-fallback trap
    // v1.9 and v1.10's notes above already flagged twice: the Labor
    // Categories nav item (added earlier this same engagement) used
    // 'ti-users-group' without ever adding it here, and Processes /
    // Machines were about to do the same with 'ti-route' / 'ti-engine'.
    // All three added now, together, so none of them silently fall
    // back to the generic hamburger icon.
    'ti-users-group':      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584a6.062 6.062 0 01-.037-.666l.001-.03m11.998 0A6.995 6.995 0 0012 12.75a6.995 6.995 0 00-6-3.363m0 0A5.995 5.995 0 006.94 15.7M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>',
    'ti-route':            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>',
    'ti-engine':           '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25z"/>',

    // ── Financial Institutions ───────────────────────────────────
    'ti-building-bank':    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>',
    'ti-credit-card':      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>',
    'ti-file-text':        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
    'ti-briefcase':        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
    'ti-file-analytics':   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
    'ti-wallet':           '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7a2 2 0 012-2h12a2 2 0 012 2v1h1a1 1 0 011 1v3a1 1 0 01-1 1h-1v3a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/><circle cx="16" cy="12" r="1"/>', // NEW — old 'cash-position' shape was a dollar-in-circle, not a wallet
    'ti-shield-check':     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    'ti-trending-up':      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>',

    // ── Projects & Contracts ──────────────────────────────────────
    'ti-file-invoice':     '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
    'ti-chart-pie':        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>',

    // ── HR ─────────────────────────────────────────────────────────
    'ti-users':            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
    'ti-user':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5z"/>', // NEW — old maps had no separate single-user icon (only the two-person 'ti-users' shape)
    'ti-cash':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
    'ti-calendar':         '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',

    // ── Analytics / Planning / Valuation ─────────────────────────
    'ti-trending-down':    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-4"/>',
    'ti-math':             '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
    'ti-target':           '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0-4a5 5 0 100-10 5 5 0 000 10zm0-3a2 2 0 100-4 2 2 0 000 4z"/>', // NEW — old 'planning' pillar shape was a clipboard-checkmark, not a target
    'ti-arrows-split-2':   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>',
    'ti-diamond':          '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 3h12l4 6-10 12L2 9l4-6z"/>', // NEW — old 'valuation' pillar shape was a trending-line chart, not a diamond
}

const FALLBACK_ICON_PATH = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16"/>'

function iconPath(name) {
    return ICON_PATHS[name] ?? FALLBACK_ICON_PATH
}

// ── Notifications ─────────────────────────────────────────────────
const showNotifications = ref(false)
const notifications     = ref([])
const unreadCount       = ref(0)

function toggleNotifications() {
    showNotifications.value = !showNotifications.value
    showUserMenu.value = false
    if (showNotifications.value) fetchNotifications()
}

async function fetchNotifications() {
    try {
        const r = await window.axios.get(route('notifications.index'))
        notifications.value = r.data.notifications ?? []
        unreadCount.value   = r.data.unread_count  ?? 0
    } catch {}
}

async function markAllRead() {
    try {
        await window.axios.post(route('notifications.mark-all-read'))
        notifications.value = notifications.value.map(n => ({ ...n, is_read: true }))
        unreadCount.value = 0
    } catch {}
}

// ── Client-side toasts ────────────────────────────────────────────
// Pages that talk to the server over axios (the Messages inbox) have no
// Inertia response to carry a flash message, so they push here instead.
const { toasts, dismissToast } = useToasts()

// ── Messages badge ────────────────────────────────────────────────
// page.props.unreadMessages is server-rendered and therefore only as
// fresh as the last Inertia response. While the inbox is open it polls
// and writes the live number into useUnreadMessages, which wins.
const { unreadMessages } = useUnreadMessages()
const unreadMessageCount = computed(
    () => unreadMessages.value ?? page.props.unreadMessages ?? 0,
)

// ── User menu ─────────────────────────────────────────────────────
const showUserMenu = ref(false)

function signOut() { router.post(route('logout')) }
function exitImpersonation() { router.post(route('impersonation.exit')) }
function closeAll() { showNotifications.value = false; showUserMenu.value = false }

// ── Flash ─────────────────────────────────────────────────────────
const localFlash = ref({ success: null, error: null, warning: null })

watch(() => page.props.flash, (f) => {
    localFlash.value = { ...f }
    if (f?.success || f?.warning) {
        setTimeout(() => { localFlash.value.success = null; localFlash.value.warning = null }, 5000)
    }
}, { immediate: true, deep: true })

const flash = computed(() => localFlash.value)

// ── Language ──────────────────────────────────────────────────────
function switchLocale() {
    const next = currentLocale.value === 'ar' ? 'en' : 'ar'
    setLocale(next)
    window.axios.post(route('lang.update'), { lang: next }).catch(() => {})
}

// ── Keyboard ──────────────────────────────────────────────────────
function handleKey(e) { if (e.key === 'Escape') closeAll() }

onMounted(() => {
    document.addEventListener('keydown', handleKey)
    const theme = localStorage.getItem('vero_theme') || 'light'
    applyTheme(theme === 'dark')
    const lang = page.props.locale ?? 'en'
    setLocale(lang)
    if (authUser.value) {
        window.axios.get(route('notifications.unread-count'))
            .then(r => { unreadCount.value = r.data.count ?? 0 })
            .catch(() => {})
    }
})

onUnmounted(() => document.removeEventListener('keydown', handleKey))
</script>

<style scoped>
/* ══════════════════════════════════════════════════════════════════
   CSS VARIABLES — set by applyTheme() on mount
   Light defaults shown here; dark overrides applied via JS
══════════════════════════════════════════════════════════════════ */
.vero-shell {
    --vero-bg:        #F0F6FF;
    --vero-nav:       #0F2554;
    --vero-sidebar:   #0F2554;
    --vero-card:      #FFFFFF;
    --vero-border:    #E2E8F0;
    --vero-text:      #0F172A;
    --vero-muted:     #64748B;
    --vero-input:     #F8FAFC;
    --vero-teal:      #1490A8;
    --vero-gold:      #FFBF00;
    --vero-gold-dim:  rgba(186,117,23,0.12);
    --vero-gold-border: rgba(186,117,23,0.30);
    --vero-teal-dim:  rgba(20,144,168,0.10);
    --vero-teal-border: rgba(20,144,168,0.20);
    min-height: 100vh;
    background-color: var(--vero-bg);
    color: var(--vero-text);
}

/* ══════════════════════════════════════════════════════════════════
   TOP BAR
══════════════════════════════════════════════════════════════════ */
.vero-topbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    background-color: var(--vero-nav);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    box-shadow: 0 2px 16px rgba(0,0,0,0.25);
}

.vero-topbar-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 56px;
    padding: 0 16px;
}

.vero-topbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    overflow: hidden;
}

.vero-topbar-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

/* Brand */
.vero-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    flex-shrink: 0;
}

.vero-brand-logo {
    width: 28px;
    height: 28px;
    object-fit: contain;
}

.vero-brand-text {
    font-size: 16px;
    font-weight: 800;
    color: #48C4D8;
    letter-spacing: -0.02em;
}

.vero-brand-text span { color: #FFBF00; }

.vero-topbar-divider {
    width: 1px;
    height: 24px;
    background: rgba(255,255,255,0.10);
    flex-shrink: 0;
    margin: 0 4px;
}

/* Pillar tabs */
.vero-pillar-tabs {
    display: flex;
    align-items: center;
    gap: 2px;
    overflow-x: auto;
    scrollbar-width: none;
}
.vero-pillar-tabs::-webkit-scrollbar { display: none; }

.vero-pillar-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 500;
    color: rgba(255,255,255,0.55);
    background: transparent;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
    font-family: inherit;
}

.vero-pillar-tab:hover {
    color: rgba(255,255,255,0.90);
    background: rgba(255,255,255,0.07);
}

.vero-pillar-active {
    color: #48C4D8 !important;
    background: var(--vero-teal-dim) !important;
    font-weight: 600;
}

/* Icon button */
.vero-icon-btn {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.65);
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
}
.vero-icon-btn:hover {
    color: #48C4D8;
    border-color: rgba(20,144,168,0.40);
    background: rgba(20,144,168,0.08);
}

/* Impersonation badge */
.vero-impersonate {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: var(--vero-gold-dim);
    border: 1px solid var(--vero-gold-border);
    border-radius: 20px;
    font-size: 12px;
    color: #FFBF00;
    font-weight: 600;
}

.vero-impersonate-exit {
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    font-weight: 700;
    color: #FFBF00;
    background: rgba(186,117,23,0.20);
    border: none;
    padding: 2px 8px;
    border-radius: 10px;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s;
}

.vero-impersonate-exit:hover { background: rgba(186,117,23,0.35); }

/* Company chip */
.vero-company-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 20px;
    font-size: 12px;
    color: rgba(255,255,255,0.65);
    max-width: 180px;
    overflow: hidden;
}

.vero-company-chip span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Lang button */
.vero-lang-btn {
    display: flex;
    align-items: center;
    gap: 3px;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.05);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
}
.vero-lang-btn:hover { border-color: #48C4D8; }
.vero-lang-active    { color: #48C4D8; }
.vero-lang-divider   { color: rgba(255,255,255,0.20); }
.vero-lang-inactive  { color: rgba(255,255,255,0.35); }

/* Notifications dot */
.vero-notif-dot {
    position: absolute;
    top: 4px;
    right: 4px;
    min-width: 15px;
    height: 15px;
    background: #ef4444;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
    line-height: 1;
}

.vero-action-wrap { position: relative; }

/* The Messages link carries its own unread dot, and .vero-notif-dot is
   absolutely positioned — the bell gets its containing block from
   .vero-action-wrap, this one has to provide its own. */
.vero-msg-btn { position: relative; text-decoration: none; }

/* User button */
.vero-user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.05);
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
}
.vero-user-btn:hover { border-color: #48C4D8; }

.vero-user-avatar {
    width: 26px;
    height: 26px;
    border-radius: 9999px;
    background: linear-gradient(135deg, #1490A8, #0C447C);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.vero-user-name {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255,255,255,0.85);
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ══════════════════════════════════════════════════════════════════
   DROPDOWNS
══════════════════════════════════════════════════════════════════ */
.vero-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    inset-inline-end: 0;
    background: #0E1E34;
    border: 1px solid #1B3558;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    z-index: 2000;
    overflow: hidden;
}

[data-theme="light"] .vero-dropdown {
    background: #FFFFFF;
    border-color: #E2E8F0;
    box-shadow: 0 8px 32px rgba(15,23,42,0.15);
}

.vero-dropdown-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid #1B3558;
}

[data-theme="light"] .vero-dropdown-header { border-color: #F1F5F9; }

.vero-dropdown-title {
    font-size: 12px;
    font-weight: 700;
    color: #E2E8F0;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

[data-theme="light"] .vero-dropdown-title { color: #0F172A; }

.vero-dropdown-action {
    font-size: 11px;
    color: #48C4D8;
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-weight: 500;
}

.vero-dropdown-divider { height: 1px; background: #1B3558; margin: 4px 0; }
[data-theme="light"] .vero-dropdown-divider { background: #F1F5F9; }

.vero-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    font-size: 13px;
    color: #E2E8F0;
    text-decoration: none;
    cursor: pointer;
    background: transparent;
    border: none;
    width: 100%;
    text-align: start;
    font-family: inherit;
    transition: background 0.1s;
}

.vero-dropdown-item:hover { background: rgba(20,144,168,0.08); color: #48C4D8; }
[data-theme="light"] .vero-dropdown-item { color: #0F172A; }
[data-theme="light"] .vero-dropdown-item:hover { background: #F8FAFC; color: #0F172A; }

.vero-dropdown-danger { color: #f87171 !important; }
.vero-dropdown-danger:hover { background: rgba(239,68,68,0.08) !important; }

/* Notification panel */
.vero-notif-link { font-size: 0.75rem; color: var(--zv-blue-text, #1490A8); text-decoration: underline; }
.vero-notif-panel { width: 340px; }

.vero-notif-list { max-height: 320px; overflow-y: auto; }

.vero-notif-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 32px 16px;
    color: #6B96B8;
    font-size: 13px;
}

.vero-notif-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 14px;
    border-bottom: 1px solid #1B3558;
    position: relative;
}
[data-theme="light"] .vero-notif-item { border-color: #F1F5F9; }
.vero-notif-item.unread { background: rgba(20,144,168,0.04); }
.vero-notif-item:last-child { border-bottom: none; }
.vero-notif-title { font-size: 12.5px; font-weight: 600; color: #E2E8F0; margin-bottom: 2px; }
.vero-notif-msg   { font-size: 12px; color: #6B96B8; }
[data-theme="light"] .vero-notif-title { color: #0F172A; }
.vero-notif-badge { width: 7px; height: 7px; background: #48C4D8; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }

/* User panel */
.vero-user-panel { width: 240px; }

.vero-user-panel-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
}

.vero-user-panel-avatar {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1490A8, #0C447C);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

.vero-user-panel-name  { font-size: 13px; font-weight: 600; color: #E2E8F0; }
.vero-user-panel-email { font-size: 11px; color: #6B96B8; margin-top: 1px; }
.vero-user-panel-role  { font-size: 10.5px; color: #48C4D8; font-weight: 600; margin-top: 2px; }
[data-theme="light"] .vero-user-panel-name  { color: #0F172A; }
[data-theme="light"] .vero-user-panel-email { color: #64748B; }

/* ══════════════════════════════════════════════════════════════════
   FLASH MESSAGES
══════════════════════════════════════════════════════════════════ */
.vero-flash {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    font-size: 13px;
    font-weight: 500;
    position: relative;
    z-index: 999;
}

.vero-expired-banner { font-weight: 600; }

.vero-flash-close {
    margin-inline-start: auto;
    background: none;
    border: none;
    cursor: pointer;
    color: inherit;
    opacity: 0.6;
    display: flex;
    align-items: center;
    padding: 2px;
    border-radius: 4px;
    flex-shrink: 0;
    transition: opacity 0.12s;
}
.vero-flash-close:hover { opacity: 1; }

.vero-flash-success { background: #064E3B; color: #6EE7B7; border-bottom: 1px solid rgba(110,231,183,0.2); }
.vero-flash-error   { background: #7F1D1D; color: #FCA5A5; border-bottom: 1px solid rgba(252,165,165,0.2); }
.vero-flash-warning { background: #78350F; color: #FCD34D; border-bottom: 1px solid rgba(252,211,77,0.2); }

[data-theme="light"] .vero-flash-success { background: #ECFDF5; color: #065F46; border-color: #A7F3D0; }
[data-theme="light"] .vero-flash-error   { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }
[data-theme="light"] .vero-flash-warning { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }

/* ══════════════════════════════════════════════════════════════════
   BODY + SIDEBAR
══════════════════════════════════════════════════════════════════ */
.vero-body {
    display: flex;
    min-height: calc(100vh - 56px);
}

.vero-sidebar {
    width: 220px;
    flex-shrink: 0;
    background-color: var(--vero-sidebar);
    border-inline-end: 1px solid rgba(255,255,255,0.06);
    position: sticky;
    top: 56px;
    height: calc(100vh - 56px);
    overflow-y: auto;
    overflow-x: hidden;
    transition: width 0.25s ease;
    scrollbar-width: none;
}

.vero-sidebar::-webkit-scrollbar { display: none; }
.vero-sidebar.collapsed { width: 60px; }

.vero-sidebar-inner {
    padding: 16px 8px 24px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

/* Section label */
.vero-section-label {
    padding: 8px 12px 5px;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #1490A8;
    white-space: nowrap;
    overflow: hidden;
}

/* Section label as a collapsible toggle (v1.9) */
.vero-section-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-top: 6px;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: color 0.15s ease;
}

.vero-section-toggle:hover {
    color: #48C4D8;
}

.vero-section-chevron {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
    stroke: currentColor;
    transition: transform 0.18s ease;
}

.vero-section-chevron.is-collapsed {
    transform: rotate(-90deg);
}

/* Sidebar link */
.vero-sidebar-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #E2E8F0;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    border-inline-start: 3px solid transparent;
    transition: all 0.15s ease;
    position: relative;
}

.vero-sidebar-link:hover {
    color: #48C4D8;
    background: rgba(20,144,168,0.07);
}

/* Gold active state — the signature ZAVERO-quality active indicator */
.vero-sidebar-link.active {
    color: #FFBF00 !important;
    background: rgba(186,117,23,0.10) !important;
    border-inline-start-color: #FFBF00 !important;
    font-weight: 700;
}

/* Settings link active — teal instead of gold */
.vero-sidebar-settings.active {
    color: #48C4D8 !important;
    background: rgba(20,144,168,0.10) !important;
    border-inline-start-color: #48C4D8 !important;
}

.vero-sidebar-settings:hover {
    color: #FFBF00 !important;
    background: rgba(186,117,23,0.08) !important;
}

.vero-sidebar-icon {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
    stroke: currentColor;
}

.vero-sidebar-label {
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

/* Tooltip when collapsed */
.vero-tooltip {
    display: none;
    position: absolute;
    inset-inline-start: calc(100% + 10px);
    top: 50%;
    transform: translateY(-50%);
    background: #0E1E34;
    border: 1px solid #1B3558;
    color: #E2E8F0;
    font-size: 12px;
    font-weight: 500;
    padding: 5px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 500;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.vero-sidebar.collapsed .vero-sidebar-link:hover .vero-tooltip { display: block; }

/* ══════════════════════════════════════════════════════════════════
   MAIN CONTENT
══════════════════════════════════════════════════════════════════ */
.vero-main {
    flex: 1;
    min-width: 0;
    background-color: var(--vero-bg);
    outline: none;
    transition: background-color 0.2s ease;
}

/* ══════════════════════════════════════════════════════════════════
   TRANSITIONS
══════════════════════════════════════════════════════════════════ */
.vero-drop-enter-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.vero-drop-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.vero-drop-enter-from, .vero-drop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.97); }

.vero-flash-enter-active { transition: opacity 0.2s ease, max-height 0.2s ease; }
.vero-flash-leave-active { transition: opacity 0.15s ease, max-height 0.15s ease; }
.vero-flash-enter-from, .vero-flash-leave-to { opacity: 0; max-height: 0; }

/* ══════════════════════════════════════════════════════════════════
   OVERLAY
══════════════════════════════════════════════════════════════════ */
.vero-overlay {
    position: fixed;
    inset: 0;
    z-index: 150;
    background: transparent;
}

/* ══════════════════════════════════════════════════════════════════
   RTL
══════════════════════════════════════════════════════════════════ */
[dir="rtl"] .vero-sidebar-link {
    border-inline-start: none;
    border-inline-end: 3px solid transparent;
}
[dir="rtl"] .vero-sidebar-link.active {
    border-inline-end-color: #FFBF00 !important;
}

/* ══════════════════════════════════════════════════════════════════
   LIGHT MODE OVERRIDES
══════════════════════════════════════════════════════════════════ */
[data-theme="light"] .vero-sidebar-link  { color: rgba(255,255,255,0.70); }
[data-theme="light"] .vero-sidebar-link:hover { color: #FFFFFF; background: rgba(255,255,255,0.10); }
[data-theme="light"] .vero-sidebar-link.active { color: #FFBF00 !important; background: rgba(186,117,23,0.15) !important; border-inline-start-color: #FFBF00 !important; }
</style>