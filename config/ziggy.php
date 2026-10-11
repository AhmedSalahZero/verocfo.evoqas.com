<?php

// ══════════════════════════════════════════════════════════════════
//  ziggy.php
//
//  Audit L3. Ziggy publishes the application's route names and URLs to
//  the browser so Vue can call route('...'). Until now every visitor —
//  including someone on the login page — received the complete list,
//  i.e. a map of every admin and finance screen.
//
//  The "guest" group below is all the signed-out screens need
//  (login, password reset, e-mail verification, logout, language
//  switch). resources/views/app.blade.php sends this short list to
//  visitors and the full list only to signed-in users. Signing in and
//  out does a full page load (see AuthenticatedSessionController) so the
//  browser always holds the list that matches who is using it.
//
//  If a new signed-out screen needs route('x'), add 'x' here.
//
//  @layer    Config
// ══════════════════════════════════════════════════════════════════

return [
    'groups' => [
        'guest' => [
            'login',
            'logout',
            'password.*',
            'verification.*',
            'lang.update',
        ],
    ],
];
