<?php

use Illuminate\Support\Facades\Broadcast;

/**
 * قنوات البث .
 *
 * * البث نفسه معطّل حاليا (BROADCAST_CONNECTION=log) و التحديث الحيّ
 * * شغّال بالـ polling ، بس التفويض ده لازم يكون موجود من دلوقتي :
 * * لحظة ما حد يظبط Pusher ، القناة تبقى محميّة من أول يوم مش مفتوحة .
 */
Broadcast::channel('App.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * المراسلة / تذاكر الدعم .
 * المستخدم يسمع قناة محادثة فقط لو مسموحله يشوفها أصلا — نفس قاعدة
 * Conversation::isVisibleTo() بالظبط .
 */
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = \App\Models\Conversation::find($id);

    return $conversation ? $conversation->isVisibleTo($user) : false;
});
