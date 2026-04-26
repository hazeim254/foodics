<?php

use App\Mail\ContactMessageSubmitted;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('redirects unauthenticated users from GET /contact to login', function () {
    $this->get('/contact')->assertRedirect('/login');
});

it('redirects unauthenticated users from POST /contact to login', function () {
    $this->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ])->assertRedirect('/login');
});

it('shows the contact form to authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/contact')
        ->assertOk()
        ->assertSee('Contact Us')
        ->assertSee('Send Message');
});

it('creates a contact message for an authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test Subject',
        'message' => 'Hello, this is a test message.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Your message has been sent successfully.');

    $contactMessage = ContactMessage::first();
    expect($contactMessage)->not->toBeNull();
    expect($contactMessage->user_id)->toBe($user->id);
    expect($contactMessage->name)->toBe('John Doe');
    expect($contactMessage->email)->toBe('john@example.com');
    expect($contactMessage->type->value)->toBe('inquiry');
    expect($contactMessage->subject)->toBe('Test Subject');
    expect($contactMessage->message)->toBe('Hello, this is a test message.');
});

it('stores a nullable phone number', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'type' => 'suggestion',
        'subject' => 'Suggestion',
        'message' => 'My suggestion',
    ]);

    expect(ContactMessage::first()->phone)->toBeNull();
});

it('stores a phone number when provided', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'phone' => '+1 555-1234',
        'type' => 'complaint',
        'subject' => 'Issue',
        'message' => 'My complaint',
    ]);

    expect(ContactMessage::first()->phone)->toBe('+1 555-1234');
});

it('dispatches email to the configured contact address', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ]);

    Mail::assertQueued(ContactMessageSubmitted::class, function ($mail) {
        return $mail->hasTo(config('mail.contact_to'));
    });
});

it('pushes the mailable to the contact queue', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ]);

    Mail::assertQueued(ContactMessageSubmitted::class, function ($mail) {
        return $mail->queue === 'contact';
    });
});

it('passes the contact message model to the mailable', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ]);

    $contactMessage = ContactMessage::first();

    Mail::assertQueued(ContactMessageSubmitted::class, function ($mail) use ($contactMessage) {
        return $mail->contactMessage->is($contactMessage);
    });
});

it('validates required fields', function (string $field, mixed $value) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
        $field => $value,
    ])->assertSessionHasErrors($field);
})->with([
    'name' => ['name', ''],
    'email' => ['email', ''],
    'type' => ['type', ''],
    'subject' => ['subject', ''],
    'message' => ['message', ''],
]);

it('rejects invalid email format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John',
        'email' => 'not-an-email',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ])->assertSessionHasErrors('email');
});

it('rejects invalid type values', function (string $type) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John',
        'email' => 'john@example.com',
        'type' => $type,
        'subject' => 'Test',
        'message' => 'Hello',
    ])->assertSessionHasErrors('type');
})->with(['feedback', 'bug', 'other', '']);

it('rejects strings exceeding max length', function (string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => str_repeat('a', 256),
        'email' => str_repeat('a', 256).'@example.com',
        'type' => 'inquiry',
        'subject' => str_repeat('a', 256),
        'message' => 'Hello',
    ])->assertSessionHasErrors($field);
})->with(['name', 'email', 'subject']);

it('rate limits contact form submissions', function () {
    $user = User::factory()->create();
    $payload = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->post('/contact', $payload)->assertRedirect();
    }

    $this->actingAs($user)
        ->post('/contact', $payload)
        ->assertTooManyRequests();
});

it('displays the contact us link in the sidebar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/contact')
        ->assertSee('Contact Us');
});

it('displays the flash message after successful submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'type' => 'inquiry',
        'subject' => 'Test',
        'message' => 'Hello',
    ]);

    $this->actingAs($user)
        ->get('/contact')
        ->assertSee('Your message has been sent successfully.');
});
