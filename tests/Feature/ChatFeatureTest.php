<?php

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Coverage for the in-system chat (Feature: floating icon to message the
 * single Admin, and vice versa). No thread table — a conversation is
 * just every ChatMessage row between two users (see ChatMessage::
 * scopeThreadBetween()); for Originator/Approver the other participant
 * is always ChatController's resolved Admin account, never whatever a
 * tampered `with` param names.
 */
test('an originator sending a message always lands with the Admin, regardless of a tampered "with" param', function () {
    $admin = User::factory()->admin()->create();
    $decoy = User::factory()->originator()->create();
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('chat.send'), [
        'with' => $decoy->user_id, // attempted redirection — must be ignored
        'body' => 'Hello Admin, I have a problem.',
    ]);

    $response->assertOk();
    $message = ChatMessage::first();
    expect($message->sender_id)->toBe($originator->user_id)
        ->and($message->recipient_id)->toBe($admin->user_id)
        ->and($message->recipient_id)->not->toBe($decoy->user_id);
});

test('a message needs text or an image — an empty send is rejected', function () {
    User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('chat.send'), []);

    $response->assertStatus(422);
    expect(ChatMessage::count())->toBe(0);
});

test('the admin can send to any specific user by naming them in "with"', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $response = $this->actingAs($admin)->post(route('chat.send'), [
        'with' => $approver->user_id,
        'body' => 'Please check assignment #42.',
    ]);

    $response->assertOk();
    $message = ChatMessage::first();
    expect($message->sender_id)->toBe($admin->user_id)
        ->and($message->recipient_id)->toBe($approver->user_id);
});

test('an image-only message is accepted with no body text', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('chat.send'), [
        'image' => UploadedFile::fake()->image('screenshot.png', 200, 200),
    ]);

    $response->assertOk();
    $message = ChatMessage::first();
    expect($message->body)->toBeNull()
        ->and($message->attachment_path)->not->toBeNull()
        ->and($message->isImage())->toBeTrue();
});

test('the admin refresh with no "with" param shows every active user, whether they have messaged or not', function () {
    $admin = User::factory()->admin()->create();
    $chatted = User::factory()->originator()->create(['full_name' => 'Has Chatted']);
    $neverChatted = User::factory()->approver('Job Order')->create(['full_name' => 'Never Chatted']);

    ChatMessage::create(['sender_id' => $chatted->user_id, 'recipient_id' => $admin->user_id, 'body' => 'hi']);

    $response = $this->actingAs($admin)->get(route('chat.refresh'));

    $response->assertOk()->assertSee('Has Chatted')->assertSee('Never Chatted');
});

test('the admin list stacks Messenger-style — chatted users on top by recency, never-chatted below, alphabetical', function () {
    $admin = User::factory()->admin()->create();
    $aaronNeverChatted = User::factory()->originator()->create(['full_name' => 'Aaron Never Chatted']);
    $zoeNeverChatted = User::factory()->originator()->create(['full_name' => 'Zoe Never Chatted']);
    $olderChat = User::factory()->originator()->create(['full_name' => 'Older Chat']);
    $recentChat = User::factory()->originator()->create(['full_name' => 'Recent Chat']);

    ChatMessage::create(['sender_id' => $olderChat->user_id, 'recipient_id' => $admin->user_id, 'body' => 'first', 'created_at' => now()->subDays(2)]);
    ChatMessage::create(['sender_id' => $recentChat->user_id, 'recipient_id' => $admin->user_id, 'body' => 'second', 'created_at' => now()->subMinutes(5)]);

    $response = $this->actingAs($admin)->get(route('chat.refresh'));
    $html = $response->getContent();

    $positions = [
        'Recent Chat' => strpos($html, 'Recent Chat'),
        'Older Chat' => strpos($html, 'Older Chat'),
        'Aaron Never Chatted' => strpos($html, 'Aaron Never Chatted'),
        'Zoe Never Chatted' => strpos($html, 'Zoe Never Chatted'),
    ];

    expect($positions['Recent Chat'])->toBeLessThan($positions['Older Chat'])
        ->and($positions['Older Chat'])->toBeLessThan($positions['Aaron Never Chatted'])
        ->and($positions['Aaron Never Chatted'])->toBeLessThan($positions['Zoe Never Chatted']);
});

test('the thread header shows the other participant\'s name and role', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Vinz Lessur']);

    $asApprover = $this->actingAs($approver)->get(route('chat.refresh'));
    $asApprover->assertOk()->assertSee($admin->full_name.' (Admin)');

    $asAdmin = $this->actingAs($admin)->get(route('chat.refresh', ['with' => $approver->user_id]));
    $asAdmin->assertOk()->assertSee('Vinz Lessur (Staff Approver)');
});

test('a document tracker for one thread only ever shows messages between those two users', function () {
    $admin = User::factory()->admin()->create();
    $originatorA = User::factory()->originator()->create();
    $originatorB = User::factory()->originator()->create();

    ChatMessage::create(['sender_id' => $originatorA->user_id, 'recipient_id' => $admin->user_id, 'body' => 'From A']);
    ChatMessage::create(['sender_id' => $originatorB->user_id, 'recipient_id' => $admin->user_id, 'body' => 'From B']);

    $response = $this->actingAs($admin)->get(route('chat.refresh', ['with' => $originatorA->user_id]));

    $response->assertOk()->assertSee('From A')->assertDontSee('From B');
});

test('keyword search filters a thread to matching messages only', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    ChatMessage::create(['sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id, 'body' => 'The printer is broken']);
    ChatMessage::create(['sender_id' => $admin->user_id, 'recipient_id' => $originator->user_id, 'body' => 'Noted, will check the printer']);
    ChatMessage::create(['sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id, 'body' => 'Unrelated question about due dates']);

    $response = $this->actingAs($originator)->get(route('chat.refresh', ['q' => 'printer']));

    $response->assertOk()->assertSee('printer is broken')->assertSee('will check the printer')->assertDontSee('due dates');
});

test('the media gallery only shows image attachments from that thread', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $withImage = ChatMessage::create([
        'sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id,
        'attachment_path' => 'chat-images/fake.png', 'attachment_mime' => 'image/png',
    ]);
    ChatMessage::create(['sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id, 'body' => 'no image here']);

    $response = $this->actingAs($originator)->get(route('chat.refresh', ['view' => 'media']));

    $response->assertOk()->assertSee(route('chat.image', $withImage));
});

test('a non-admin cannot mark someone else\'s unrelated thread read', function () {
    $admin = User::factory()->admin()->create();
    $originatorA = User::factory()->originator()->create();
    $originatorB = User::factory()->originator()->create();

    $response = $this->actingAs($originatorA)->post(route('chat.thread.read', $originatorB));

    $response->assertStatus(403);
});

test('opening a thread marks unread messages from that participant as read', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    ChatMessage::create(['sender_id' => $admin->user_id, 'recipient_id' => $originator->user_id, 'body' => 'hi', 'is_read' => false]);

    $this->actingAs($originator)->post(route('chat.thread.read', $admin))->assertNoContent();

    expect(ChatMessage::first()->is_read)->toBeTrue();
});

test('the unread poll count reflects only messages addressed to me', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    ChatMessage::create(['sender_id' => $admin->user_id, 'recipient_id' => $originator->user_id, 'body' => 'one', 'is_read' => false]);
    ChatMessage::create(['sender_id' => $admin->user_id, 'recipient_id' => $originator->user_id, 'body' => 'two', 'is_read' => false]);
    ChatMessage::create(['sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id, 'body' => 'sent by me, not unread to me']);

    $response = $this->actingAs($originator)->get(route('chat.poll'));

    $response->assertOk()->assertJson(['unread_count' => 2]);
});

test('a chat image is only viewable by the two participants of that message', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stranger = User::factory()->originator()->create();

    $message = ChatMessage::create([
        'sender_id' => $originator->user_id, 'recipient_id' => $admin->user_id,
        'attachment_path' => 'chat-images/does-not-need-to-exist-for-this-check.png', 'attachment_mime' => 'image/png',
    ]);

    $this->actingAs($stranger)->get(route('chat.image', $message))->assertStatus(403);
    $this->actingAs($admin)->get(route('chat.image', $message))->assertStatus(404); // authorized, but the file itself doesn't exist in this test
});

test('the busy-toggle route no longer exists', function () {
    $approver = User::factory()->approver('Job Order')->create();

    expect(fn () => route('approver.availability.toggle'))->toThrow(RouteNotFoundException::class);
});
