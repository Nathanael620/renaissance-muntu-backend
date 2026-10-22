<?php

namespace Tests\Feature;

use App\Models\AdminInvitation;
use App\Models\Contact;
use App\Models\LibraryResource;
use App\Models\PartnershipRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_active_admin_can_log_in_and_read_their_session_user(): void
    {
        $user = User::factory()->create([
            'password' => 'SecurePass123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->postJson('/api/admin/v1/login', [
            'email' => $user->email,
            'password' => 'SecurePass123!',
            'remember' => false,
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'super_admin')
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'permissions']])
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/admin/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_invalid_or_inactive_accounts_cannot_log_in(): void
    {
        $inactive = User::factory()->create([
            'password' => 'SecurePass123!',
            'role' => 'admin',
            'is_active' => false,
        ]);

        $this->postJson('/api/admin/v1/login', [
            'email' => $inactive->email,
            'password' => 'SecurePass123!',
        ])->assertUnauthorized();

        $this->postJson('/api/admin/v1/login', [
            'email' => $inactive->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->getJson('/api/admin/v1/me')->assertUnauthorized();
    }

    public function test_login_rotates_the_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create([
            'password' => 'SecurePass123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->withSession(['before_login' => 'value']);
        $previousSessionId = $this->app['session']->getId();

        $this->postJson('/api/admin/v1/login', [
            'email' => $user->email,
            'password' => 'SecurePass123!',
        ])->assertOk();

        $this->assertNotSame($previousSessionId, $this->app['session']->getId());
        $this->postJson('/api/admin/v1/logout')->assertNoContent();
        $this->getJson('/api/admin/v1/me')->assertUnauthorized();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/v1/login', [
                'email' => 'unknown@example.test',
                'password' => 'not-the-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/admin/v1/login', [
            'email' => 'unknown@example.test',
            'password' => 'not-the-password',
        ])->assertTooManyRequests();
    }

    public function test_ordinary_users_and_viewers_cannot_use_admin_routes_without_permission(): void
    {
        $this->getJson('/api/admin/v1/messages')->assertUnauthorized();

        $ordinaryUser = User::factory()->create([
            'role' => null,
            'is_active' => false,
        ]);
        Sanctum::actingAs($ordinaryUser);

        $this->getJson('/api/admin/v1/me')->assertForbidden();
        $this->postJson('/api/admin/v1/logout')->assertForbidden();

        $viewer = User::factory()->create([
            'role' => 'viewer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/admin/v1/messages')->assertOk();
        $this->patchJson('/api/admin/v1/messages/1', ['workflow_status' => 'archived'])
            ->assertForbidden();
        $this->getJson('/api/admin/v1/admins')->assertForbidden();
    }

    public function test_admin_can_update_workflow_status_without_overwriting_notification_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $contact = Contact::query()->create([
            'name' => 'Test Contact',
            'email' => 'contact@example.test',
            'subject' => 'information',
            'message' => 'Message de test',
            'status' => 'email_failed',
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/v1/messages/{$contact->id}", [
            'workflow_status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.workflow_status', 'in_progress');

        $this->assertSame('email_failed', $contact->fresh()->status);
        $this->assertSame('in_progress', $contact->fresh()->workflow_status);
    }

    public function test_partnership_review_status_is_independent_from_notification_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $partnership = PartnershipRequest::query()->create([
            'name' => 'Partner',
            'organization' => 'Example Org',
            'email' => 'partner@example.test',
            'partnership_type' => 'other',
            'status' => 'notified',
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/v1/partnerships/{$partnership->id}", [
            'review_status' => 'accepted',
        ])->assertOk()
            ->assertJsonPath('data.review_status', 'accepted');

        $this->assertSame('notified', $partnership->fresh()->status);
        $this->assertSame('accepted', $partnership->fresh()->review_status);
    }

    public function test_published_resources_are_the_only_resources_returned_to_public_catalogue(): void
    {
        LibraryResource::query()->create([
            'title' => 'Published title',
            'category' => 'livre',
            'type' => 'livre',
            'status' => 'published',
        ]);
        LibraryResource::query()->create([
            'title' => 'Draft title',
            'category' => 'livre',
            'type' => 'livre',
            'status' => 'draft',
            'metadata' => ['internal_note' => 'must not be public'],
        ]);

        $this->getJson('/api/resources')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published title')
            ->assertJsonMissingPath('data.0.metadata');
    }

    public function test_super_admin_can_issue_single_use_invitation_and_invitee_activates_account(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/admin/v1/admin-invitations', [
            'name' => 'Invited Editor',
            'email' => 'editor@example.test',
            'role' => 'editor',
        ])->assertCreated()
            ->assertJsonPath('invitation.role', 'editor');

        $token = $response->json('token');
        $invitation = AdminInvitation::query()->firstOrFail();
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        $this->postJson('/api/admin/v1/invitations/accept', [
            'token' => $token,
            'password' => 'EditorPass123!',
            'password_confirmation' => 'EditorPass123!',
        ])->assertCreated()
            ->assertJsonPath('email', 'editor@example.test');

        $this->assertDatabaseHas('users', [
            'email' => 'editor@example.test',
            'role' => 'editor',
            'is_active' => true,
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->postJson('/api/admin/v1/invitations/accept', [
            'token' => $token,
            'password' => 'EditorPass123!',
            'password_confirmation' => 'EditorPass123!',
        ])->assertUnprocessable();
    }

    public function test_last_active_super_admin_cannot_be_disabled_or_demoted(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/admin/v1/admins/{$superAdmin->id}", ['is_active' => false])
            ->assertUnprocessable();
        $this->patchJson("/api/admin/v1/admins/{$superAdmin->id}", ['role' => 'admin'])
            ->assertUnprocessable();
        $this->assertTrue($superAdmin->fresh()->is_active);
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_first_super_admin_command_requires_confirmation_and_refuses_duplicate_creation(): void
    {
        $this->assertDatabaseHas('admin_account_locks', ['id' => 1]);

        $this->artisan('admin:create-super-admin')
            ->expectsQuestion('Administrator name', 'First Admin')
            ->expectsQuestion('Administrator email', 'first-admin@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'SecurePass123!')
            ->expectsQuestion('Confirm password', 'SecurePass123!')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'first-admin@example.test',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->artisan('admin:create-super-admin')->assertExitCode(1);
        $this->assertSame(1, User::query()->where('role', 'super_admin')->where('is_active', true)->count());
    }
}
