<?php

namespace Tests\Feature;

use App\Mail\ContactNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'name' => 'Marie Kabasele',
        'email' => 'marie@example.com',
        'phone' => '+243 81 234 5678',
        'subject' => 'information',
        'message' => 'Bonjour, je souhaite en savoir plus sur le mouvement Renaissance Muntu.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('contact');
        config(['mail.notification_address' => 'equipe@renaissance-muntu.test']);
    }

    public function test_valid_contact_submission_returns_201_and_persists_record(): void
    {
        $response = $this->postJson('/api/contact', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Votre message a été envoyé avec succès.',
            ])
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Marie Kabasele',
            'email' => 'marie@example.com',
            'phone' => '+243 81 234 5678',
            'subject' => 'information',
            'message' => 'Bonjour, je souhaite en savoir plus sur le mouvement Renaissance Muntu.',
            'status' => 'notified',
        ]);
    }

    public function test_name_is_required(): void
    {
        $payload = $this->validPayload;
        unset($payload['name']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_email_must_be_valid(): void
    {
        $payload = array_merge($this->validPayload, ['email' => 'pas-une-adresse']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_subject_is_required(): void
    {
        $payload = $this->validPayload;
        unset($payload['subject']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_subject_must_be_an_allowed_value(): void
    {
        $payload = array_merge($this->validPayload, ['subject' => 'sujet-invente']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_message_is_required(): void
    {
        $payload = $this->validPayload;
        unset($payload['message']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_phone_is_optional(): void
    {
        $payload = $this->validPayload;
        unset($payload['phone']);

        $this->postJson('/api/contact', $payload)
            ->assertStatus(201)
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Marie Kabasele',
            'phone' => null,
        ]);
    }

    public function test_contact_notification_email_is_sent(): void
    {
        $this->postJson('/api/contact', $this->validPayload)->assertStatus(201);

        Mail::assertSent(ContactNotificationMail::class, function (ContactNotificationMail $mail) {
            return $mail->hasTo('equipe@renaissance-muntu.test')
                && $mail->contact->name === 'Marie Kabasele';
        });
    }

    public function test_contact_returns_201_when_mail_fails(): void
    {
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturnSelf();
        $mailer->shouldReceive('send')->andThrow(new TransportException('SMTP authentication failed'));
        Mail::swap($mailer);

        $response = $this->postJson('/api/contact', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Marie Kabasele',
            'status' => 'email_failed',
        ]);
    }

    public function test_rate_limiting_returns_429_after_excessive_requests(): void
    {
        RateLimiter::clear('contact');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/contact', $this->validPayload)->assertStatus(201);
        }

        $this->postJson('/api/contact', $this->validPayload)->assertStatus(429);
    }

    public function test_honeypot_filled_submission_is_discarded(): void
    {
        $payload = array_merge($this->validPayload, ['website' => 'http://spam.example.com']);

        $response = $this->postJson('/api/contact', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('contacts', 0);
    }
}
