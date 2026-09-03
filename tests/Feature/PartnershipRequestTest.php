<?php

namespace Tests\Feature;

use App\Mail\PartnershipRequestNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PartnershipRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'name' => 'Marie Kabasele',
        'organization' => 'Muntu Lab',
        'email' => 'marie@example.com',
        'phone' => '+243 81 234 5678',
        'partnership_type' => 'collaborative_project',
        'message' => 'Nous souhaitons construire un projet commun.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.notification_address' => 'equipe@renaissance-muntu.test']);
    }

    public function test_valid_partnership_request_returns_201_and_persists_with_new_status_before_notification(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/partnerships', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Votre demande de partenariat a été envoyée avec succès.',
            ])
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('partnership_requests', [
            'name' => 'Marie Kabasele',
            'organization' => 'Muntu Lab',
            'email' => 'marie@example.com',
            'phone' => '+243 81 234 5678',
            'partnership_type' => 'collaborative_project',
            'message' => 'Nous souhaitons construire un projet commun.',
            'status' => 'notified',
        ]);
    }

    public function test_name_is_required(): void
    {
        $payload = $this->validPayload;
        unset($payload['name']);

        $this->postJson('/api/partnerships', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_email_is_required(): void
    {
        $payload = $this->validPayload;
        unset($payload['email']);

        $this->postJson('/api/partnerships', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_email_must_be_valid(): void
    {
        $payload = array_merge($this->validPayload, ['email' => 'pas-une-adresse']);

        $this->postJson('/api/partnerships', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_partnership_type_must_be_an_allowed_value(): void
    {
        $payload = array_merge($this->validPayload, ['partnership_type' => 'sujet-invente']);

        $this->postJson('/api/partnerships', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['partnership_type']);
    }

    public function test_optional_fields_are_allowed(): void
    {
        Mail::fake();
        $payload = $this->validPayload;
        unset($payload['organization'], $payload['phone'], $payload['message']);

        $this->postJson('/api/partnerships', $payload)
            ->assertStatus(201)
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('partnership_requests', [
            'email' => 'marie@example.com',
            'organization' => null,
            'phone' => null,
            'message' => null,
        ]);
    }

    public function test_initial_status_is_new_when_no_notification_address_is_configured(): void
    {
        config(['mail.notification_address' => null]);

        $this->postJson('/api/partnerships', $this->validPayload)
            ->assertStatus(201);

        $this->assertDatabaseHas('partnership_requests', [
            'email' => 'marie@example.com',
            'status' => 'new',
        ]);
    }

    public function test_partnership_notification_email_is_sent(): void
    {
        Mail::fake();

        $this->postJson('/api/partnerships', $this->validPayload)->assertStatus(201);

        Mail::assertSent(PartnershipRequestNotificationMail::class, function (PartnershipRequestNotificationMail $mail) {
            return $mail->hasTo('equipe@renaissance-muntu.test')
                && $mail->partnershipRequest->name === 'Marie Kabasele';
        });
    }

    public function test_request_is_kept_and_returns_201_when_mail_fails(): void
    {
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturnSelf();
        $mailer->shouldReceive('send')->andThrow(new TransportException('SMTP authentication failed'));
        Mail::swap($mailer);

        $response = $this->postJson('/api/partnerships', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('partnership_requests', [
            'email' => 'marie@example.com',
            'status' => 'email_failed',
        ]);
    }
}
