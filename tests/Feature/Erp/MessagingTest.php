<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  MessagingTest
//
//  The Messages feature end to end, driven over HTTP the way the Vue
//  inbox drives it — axios JSON calls against the same routes, not
//  service calls. That matters here: almost everything this feature
//  has to get right is an authorization or an isolation rule, and
//  those live in the controller and the route, not in a service.
//
//  What is actually under test:
//    · isolation   — you cannot read, write to, or delete inside a
//                    conversation you are not part of, and you cannot
//                    open a chat with someone in another company
//    · tickets     — a Super Message reaches the Super Admin without
//                    anyone adding them as a participant, and only
//                    the Super Admin can move its status
//    · unread      — the badge counts conversations with something
//                    new in them, not messages, and reading clears it
//    · deltas      — /updates returns only what the client does not
//                    already hold, and /poll answers "nothing changed"
//                    cheaply when the fingerprint matches
//    · deletion    — soft, sender-only, and the body stops being
//                    served the moment it happens
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Tests\Erp\ErpTestCase;

class MessagingTest extends ErpTestCase
{
    private Company $companyA;
    private Company $companyB;
    private User $alice;   // company A
    private User $bob;     // company A
    private User $carol;   // company B
    private User $super;   // no company

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('Messaging Co. A');
        $this->companyB = $this->makeCompany('Messaging Co. B');

        $this->alice = $this->makeUser('alice', User::ROLE_COMPANY_ADMIN, $this->companyA->id);
        $this->bob   = $this->makeUser('bob',   User::ROLE_USER, $this->companyA->id);
        $this->carol = $this->makeUser('carol', User::ROLE_COMPANY_ADMIN, $this->companyB->id);
        $this->super = $this->makeUser('super', User::ROLE_SUPER_ADMIN, null);
    }

    private function makeCompany(string $name): Company
    {
        return Company::withoutGlobalScopes()->create([
            'name_en'           => $name.' '.bin2hex(random_bytes(3)),
            'name_ar'           => $name,
            'base_currency'     => 'EGP',
            'subscription_tier' => 'Enterprise',
            'is_active'         => true,
        ]);
    }

    private function makeUser(string $handle, string $role, ?int $companyId): User
    {
        return User::withoutGlobalScopes()->create([
            'name'       => ucfirst($handle),
            'email'      => $handle.'-'.bin2hex(random_bytes(4)).'@messaging.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => $role,
            'company_id' => $companyId,
            'lang_pref'  => 'en',
            'is_active'  => true,
        ]);
    }

    /** A direct thread with one message in it from $from. */
    private function directThread(User $from, User $to, string $body = 'hello'): Conversation
    {
        $response = $this->actingAs($from)
            ->postJson(route('messages.start'), ['user_id' => $to->id, 'body' => $body]);

        $response->assertOk();

        return Conversation::findOrFail($response->json('conversation_id'));
    }

    // ── The inbox page ────────────────────────────────────────────

    public function test_the_inbox_page_renders_with_everything_the_vue_component_needs(): void
    {
        $this->actingAs($this->alice)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Messaging/Inbox')
                ->where('isSuperAdmin', false)
                ->has('conversations.direct')
                ->has('conversations.support')
                // Every URL the component builds from — a missing one is a
                // silent "undefined" in a template literal, not an error.
                ->has('routes.start')->has('routes.support')->has('routes.poll')
                ->has('routes.show')->has('routes.updates')->has('routes.send')
                ->has('routes.read')->has('routes.status')->has('routes.deleteMessage')
            );
    }

    public function test_contacts_are_colleagues_only_and_never_yourself(): void
    {
        $this->actingAs($this->alice)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $ids = collect($page->toArray()['props']['contacts'])->pluck('id')->all();

                $this->assertContains($this->bob->id, $ids, 'A colleague in the same company should be reachable.');
                $this->assertNotContains($this->carol->id, $ids, 'Someone in another company must not be listed.');
                $this->assertNotContains($this->alice->id, $ids, 'You are not your own contact.');
            });
    }

    public function test_a_super_admin_sees_the_support_inbox_flag(): void
    {
        $this->actingAs($this->super)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isSuperAdmin', true));
    }

    public function test_the_unread_badge_is_shared_with_every_page_not_just_the_inbox(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob);

        // Any authenticated page carries the badge — that is the whole
        // point of sharing it from HandleInertiaRequests.
        $this->actingAs($this->bob)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unreadMessages', 1));

        $this->actingAs($this->bob)->postJson(route('messages.read', $conversation))->assertOk();

        $this->actingAs($this->bob)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unreadMessages', 0));
    }

    // ── Starting conversations ────────────────────────────────────

    public function test_saying_hello_again_reuses_the_same_thread_in_both_directions(): void
    {
        $first = $this->directThread($this->alice, $this->bob, 'first');

        $second = $this->directThread($this->alice, $this->bob, 'second');
        $this->assertSame($first->id, $second->id, 'A second message must not open a second thread.');

        // ...and the same is true when the other person starts it.
        $reverse = $this->directThread($this->bob, $this->alice, 'third');
        $this->assertSame($first->id, $reverse->id, 'Direction must not create a duplicate thread.');

        $this->assertSame(3, Message::where('conversation_id', $first->id)->count());
    }

    public function test_you_cannot_open_a_chat_with_someone_in_another_company(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('messages.start'), ['user_id' => $this->carol->id, 'body' => 'hi'])
            ->assertForbidden();

        $this->assertSame(0, Conversation::count(), 'A refused start must not leave a thread behind.');
    }

    public function test_anyone_may_open_a_chat_with_a_super_admin(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('messages.start'), ['user_id' => $this->super->id, 'body' => 'hi'])
            ->assertOk();
    }

    /**
     * REGRESSION GUARD. The rule here was 'different:' . Auth::id(),
     * which takes a FIELD NAME, not a value — so it compared against a
     * request field literally named "7" and always passed. Messaging
     * yourself then reached findOrCreateDirect($me, $me), which tried
     * to attach the same participant twice and died on the unique key
     * with a 500. This must be a 422.
     */
    public function test_you_cannot_start_a_conversation_with_yourself(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('messages.start'), ['user_id' => $this->alice->id, 'body' => 'talking to myself'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertSame(0, Conversation::count());
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob);

        $this->actingAs($this->alice)
            ->postJson(route('messages.send', $conversation), ['body' => '   '])
            ->assertStatus(422);
    }

    // ── Isolation ─────────────────────────────────────────────────

    public function test_an_outsider_cannot_read_write_or_delete_inside_someone_elses_thread(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'private');
        $message = Message::where('conversation_id', $conversation->id)->firstOrFail();

        $this->actingAs($this->carol)->getJson(route('messages.show', $conversation))->assertForbidden();
        $this->actingAs($this->carol)->getJson(route('messages.updates', $conversation))->assertForbidden();
        $this->actingAs($this->carol)->postJson(route('messages.send', $conversation), ['body' => 'butting in'])->assertForbidden();
        $this->actingAs($this->carol)->postJson(route('messages.read', $conversation))->assertForbidden();
        $this->actingAs($this->carol)->deleteJson(route('messages.delete', [$conversation, $message]))->assertForbidden();

        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->count());
        $this->assertNull($message->fresh()->deleted_at);
    }

    public function test_a_super_admin_does_not_get_to_read_private_chats(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'not for you');

        // Being a Super Admin opens support tickets, not everyone's mail.
        $this->actingAs($this->super)
            ->getJson(route('messages.show', $conversation))
            ->assertForbidden();
    }

    public function test_someone_elses_thread_never_appears_in_your_inbox(): void
    {
        $this->directThread($this->alice, $this->bob, 'private');

        $this->actingAs($this->carol)
            ->getJson(route('messages.poll'))
            ->assertOk()
            ->assertJsonPath('conversations.direct', [])
            ->assertJsonPath('unread_total', 0);
    }

    // ── Support tickets ───────────────────────────────────────────

    public function test_a_support_ticket_reaches_the_super_admin_without_being_a_participant(): void
    {
        $response = $this->actingAs($this->alice)
            ->postJson(route('messages.support.start'), ['subject' => 'Cannot upload', 'body' => 'It fails'])
            ->assertOk();

        $ticket = Conversation::findOrFail($response->json('conversation_id'));
        $this->assertSame('support', $ticket->type);
        $this->assertSame('open', $ticket->status);

        // Visible and answerable even though nobody added them.
        $this->actingAs($this->super)->getJson(route('messages.show', $ticket))->assertOk();
        $this->actingAs($this->super)->postJson(route('messages.send', $ticket), ['body' => 'Looking into it'])->assertOk();

        $this->actingAs($this->super)
            ->getJson(route('messages.poll'))
            ->assertOk()
            ->assertJsonPath('conversations.support.0.id', $ticket->id);
    }

    public function test_only_a_super_admin_can_move_a_ticket_status(): void
    {
        $response = $this->actingAs($this->alice)
            ->postJson(route('messages.support.start'), ['subject' => 'Broken', 'body' => 'Halp']);
        $ticket = Conversation::findOrFail($response->json('conversation_id'));

        $this->actingAs($this->alice)
            ->patchJson(route('messages.status', $ticket), ['status' => 'resolved'])
            ->assertForbidden();
        $this->assertSame('open', $ticket->fresh()->status, 'The reporter must not be able to close their own ticket.');

        $this->actingAs($this->super)
            ->patchJson(route('messages.status', $ticket), ['status' => 'resolved'])
            ->assertOk();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_a_direct_thread_has_no_status_to_change(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob);

        $this->actingAs($this->super)
            ->patchJson(route('messages.status', $conversation), ['status' => 'resolved'])
            ->assertForbidden();
    }

    // ── Unread counting ───────────────────────────────────────────

    public function test_unread_counts_threads_with_something_new_not_messages(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'one');
        $this->actingAs($this->alice)->postJson(route('messages.send', $conversation), ['body' => 'two'])->assertOk();
        $this->actingAs($this->alice)->postJson(route('messages.send', $conversation), ['body' => 'three'])->assertOk();

        // Three messages, one thread — the badge is a thread count.
        $this->assertSame(1, Conversation::unreadCountFor($this->bob));
        $this->assertSame(0, Conversation::unreadCountFor($this->alice), 'Your own messages are not unread mail.');
    }

    public function test_a_deleted_message_stops_keeping_a_thread_unread(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'oops');
        $message = Message::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame(1, Conversation::unreadCountFor($this->bob));

        $this->actingAs($this->alice)
            ->deleteJson(route('messages.delete', [$conversation, $message]))
            ->assertOk();

        $this->assertSame(0, Conversation::unreadCountFor($this->bob));
    }

    // ── Deletion ──────────────────────────────────────────────────

    public function test_deleting_is_soft_sender_only_and_stops_serving_the_body(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'delete me');
        $message = Message::where('conversation_id', $conversation->id)->firstOrFail();

        // The recipient cannot delete what they did not write.
        $this->actingAs($this->bob)
            ->deleteJson(route('messages.delete', [$conversation, $message]))
            ->assertForbidden();
        $this->assertNull($message->fresh()->deleted_at);

        $this->actingAs($this->alice)
            ->deleteJson(route('messages.delete', [$conversation, $message]))
            ->assertOk();

        // The row stays (the thread keeps its shape) but the text is gone
        // from every payload, for both sides.
        $this->assertNotNull($message->fresh()->deleted_at);
        $this->assertNotNull(Message::find($message->id), 'Deletion is soft — the row must survive.');

        foreach ([$this->alice, $this->bob] as $viewer) {
            $shown = collect($this->actingAs($viewer)->getJson(route('messages.show', $conversation))->json('messages'))
                ->firstWhere('id', $message->id);

            $this->assertTrue($shown['deleted'], 'The message must be flagged deleted.');
            $this->assertNull($shown['body'], 'A deleted message must not still carry its text.');
        }
    }

    public function test_a_message_cannot_be_deleted_through_a_different_conversation(): void
    {
        $mine  = $this->directThread($this->alice, $this->bob, 'mine');
        $other = $this->directThread($this->alice, $this->super, 'other');
        $message = Message::where('conversation_id', $other->id)->firstOrFail();

        // Alice wrote it and is in both threads — the only thing wrong
        // here is the pairing, which is exactly what is being guarded.
        $this->actingAs($this->alice)
            ->deleteJson(route('messages.delete', [$mine, $message]))
            ->assertForbidden();

        $this->assertNull($message->fresh()->deleted_at);
    }

    // ── The polling contract ──────────────────────────────────────

    public function test_updates_returns_only_what_the_client_does_not_already_hold(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'first');

        $opened = $this->actingAs($this->bob)->getJson(route('messages.show', $conversation))->assertOk();
        $lastId = $opened->json('last_id');
        $since  = $opened->json('server_time');

        // Nothing has happened since: an empty delta, not the history again.
        $this->actingAs($this->bob)
            ->getJson(route('messages.updates', $conversation).'?'.http_build_query(['after_id' => $lastId, 'since' => $since]))
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->actingAs($this->alice)->postJson(route('messages.send', $conversation), ['body' => 'second'])->assertOk();

        $delta = $this->actingAs($this->bob)
            ->getJson(route('messages.updates', $conversation).'?'.http_build_query(['after_id' => $lastId, 'since' => $since]))
            ->assertOk();

        $delta->assertJsonCount(1, 'messages');
        $this->assertSame('second', $delta->json('messages.0.body'), 'Only the new message, not the whole thread.');
    }

    public function test_updates_reports_a_deletion_of_a_message_the_client_already_holds(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'will vanish');
        $message = Message::where('conversation_id', $conversation->id)->firstOrFail();

        $opened = $this->actingAs($this->bob)->getJson(route('messages.show', $conversation))->assertOk();
        $lastId = $opened->json('last_id');
        $since  = $opened->json('server_time');

        // The clock has one-second resolution in MySQL; without this the
        // deletion can land inside the same second as `since` and be
        // filtered out, which would make this test pass for the wrong reason.
        sleep(1);

        $this->actingAs($this->alice)->deleteJson(route('messages.delete', [$conversation, $message]))->assertOk();

        $delta = $this->actingAs($this->bob)
            ->getJson(route('messages.updates', $conversation).'?'.http_build_query(['after_id' => $lastId, 'since' => $since]))
            ->assertOk();

        $this->assertSame([$message->id], collect($delta->json('changed'))->pluck('id')->all());
        $this->assertTrue($delta->json('changed.0.deleted'));
        $this->assertNull($delta->json('changed.0.body'));
    }

    public function test_updates_with_mark_read_clears_the_badge_in_the_same_trip(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob, 'unread');

        $this->assertSame(1, Conversation::unreadCountFor($this->bob));

        $this->actingAs($this->bob)
            ->getJson(route('messages.updates', $conversation).'?'.http_build_query(['after_id' => 0, 'mark_read' => 1]))
            ->assertOk();

        $this->assertSame(0, Conversation::unreadCountFor($this->bob));
    }

    public function test_poll_answers_nothing_changed_when_the_fingerprint_matches(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob);

        $first = $this->actingAs($this->bob)->getJson(route('messages.poll'))->assertOk();
        $version = $first->json('version');
        $this->assertNotNull($version);
        $this->assertTrue($first->json('changed'), 'The first poll must return the full snapshot.');
        $this->assertNotNull($first->json('conversations'));

        $this->actingAs($this->bob)
            ->getJson(route('messages.poll').'?'.http_build_query(['version' => $version]))
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonMissingPath('conversations');

        // ...and a new message has to break that fingerprint, or the
        // inbox would sit there forever telling the client nothing moved.
        $this->actingAs($this->alice)->postJson(route('messages.send', $conversation), ['body' => 'wake up'])->assertOk();

        $this->actingAs($this->bob)
            ->getJson(route('messages.poll').'?'.http_build_query(['version' => $version]))
            ->assertOk()
            ->assertJsonPath('unread_total', 1)
            ->assertJsonPath('changed', true);
    }

    // ── Broadcast channels ────────────────────────────────────────

    /**
     * Broadcasting is dormant (BROADCAST_CONNECTION is not a real
     * driver, and the inbox updates itself by polling), so nothing in
     * normal use ever reaches these callbacks. That is exactly why they
     * are worth a test: the day someone configures Pusher, these become
     * the only thing standing between a user and everyone else's
     * messages, and a mistake in them would go unnoticed until then.
     */
    private function channelCallback(string $name): callable
    {
        $broadcaster = \Illuminate\Support\Facades\Broadcast::driver();

        $property = new \ReflectionProperty(
            (new \ReflectionClass($broadcaster))->hasProperty('channels')
                ? $broadcaster
                : get_parent_class($broadcaster),
            'channels'
        );
        $property->setAccessible(true);

        $channels = $property->getValue($broadcaster);
        $this->assertArrayHasKey($name, $channels, "Channel [{$name}] is not registered — is routes/channels.php wired up?");

        return $channels[$name];
    }

    public function test_the_private_user_channel_only_authorizes_its_owner(): void
    {
        $authorize = $this->channelCallback('App.User.{id}');

        $this->assertTrue((bool) $authorize($this->alice, $this->alice->id));
        $this->assertFalse((bool) $authorize($this->alice, $this->bob->id));
    }

    public function test_the_conversation_channel_follows_the_same_visibility_rule_as_the_http_routes(): void
    {
        $authorize = $this->channelCallback('conversation.{id}');
        $conversation = $this->directThread($this->alice, $this->bob);

        $this->assertTrue((bool) $authorize($this->alice, $conversation->id));
        $this->assertTrue((bool) $authorize($this->bob, $conversation->id));
        $this->assertFalse((bool) $authorize($this->carol, $conversation->id), 'An outsider must not be able to listen in.');
        $this->assertFalse((bool) $authorize($this->super, $conversation->id), 'Nor a Super Admin, on a private chat.');
        $this->assertFalse((bool) $authorize($this->alice, 999999), 'A missing conversation must refuse, not throw.');
    }

    public function test_guests_get_nowhere_near_any_of_it(): void
    {
        $conversation = $this->directThread($this->alice, $this->bob);
        $this->app['auth']->forgetGuards();

        $this->get(route('messages.index'))->assertRedirect(route('login'));
        $this->getJson(route('messages.poll'))->assertUnauthorized();
        $this->getJson(route('messages.show', $conversation))->assertUnauthorized();
        $this->postJson(route('messages.start'), ['user_id' => $this->bob->id, 'body' => 'x'])->assertUnauthorized();
    }
}
