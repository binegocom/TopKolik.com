<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\StaffInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class StaffInviteOnlyEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('staff.accept_url', 'https://staff.example.test/accept-invitation');
        config()->set('mail.default', 'array');
        Mail::fake();
        RateLimiter::clear('staff-accept-ip:127.0.0.1');
    }

    private function issue(string $role = 'editor', string $email = 'invited@example.test'): array
    {
        $invite = app(StaffInvitationService::class)->issue($email, $role, 'test-operator');

        $url = null;
        Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail) use (&$url): bool {
            $url = $mail->acceptUrl;

            return true;
        });
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return [$invite, $query['token'], $url];
    }

    private function accept(StaffInvitation $invitation, string $token, array $changes = []): array
    {
        return array_replace([
            'invitation' => $invitation->id,
            'token' => $token,
            'name' => 'Staff Candidate',
            'password' => 'New!StaffPassword2026',
            'password_confirmation' => 'New!StaffPassword2026',
        ], $changes);
    }

    public function test_public_staff_registration_and_role_grant_routes_do_not_exist(): void
    {
        $this->postJson('/api/v1/staff/register', [
            'email' => 'reader@example.test', 'role' => 'editor',
        ])->assertNotFound();
        $this->postJson('/api/v1/staff/invitations', [
            'email' => 'reader@example.test', 'role' => 'editor',
        ])->assertNotFound();
        $this->postJson('/api/v1/staff/login', [
            'email' => 'reader@example.test', 'password' => 'Anything',
        ])->assertNotFound();
    }

    public function test_reader_cannot_self_assign_staff_role_using_public_registration(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Reader User',
            'email' => 'reader@example.test',
            'password' => 'Strong!Password2026',
            'password_confirmation' => 'Strong!Password2026',
            'role' => 'admin',
        ])->assertStatus(202);
        $this->assertDatabaseCount('staff_members', 0);
        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_issued_invitation_is_single_use_hashed_and_expires_in_one_day(): void
    {
        [$invite, $token, $url] = $this->issue();
        $this->assertSame('invited@example.test', $invite->email);
        $this->assertSame('editor', $invite->role);
        $this->assertSame('test-operator', $invite->issued_by);
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertNotSame($token, $invite->token_hash);
        $this->assertTrue($invite->expires_at->betweenIncluded(now()->addHours(23), now()->addHours(25)));
        $this->assertStringContainsString('/accept-invitation?', $url);
        $this->assertDatabaseCount('staff_members', 0);
    }

    public function test_valid_invitation_creates_verified_but_inactive_staff_identity(): void
    {
        [$invite, $token] = $this->issue();
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token))
            ->assertStatus(202)
            ->assertJsonMissingPath('access_token');

        $staff = StaffMember::query()->where('email', 'invited@example.test')->firstOrFail();
        $this->assertSame('editor', $staff->role);
        $this->assertFalse($staff->is_active);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertNull($staff->mfa_enrolled_at);
        $this->assertTrue(Hash::check('New!StaffPassword2026', $staff->password));
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_replayed_and_invalid_tokens_are_rejected_without_duplicate_staff(): void
    {
        [$invite, $token] = $this->issue();
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, Str::random(64)))
            ->assertUnprocessable();
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token))
            ->assertStatus(202);
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token))
            ->assertUnprocessable();
        $this->assertDatabaseCount('staff_members', 1);
    }

    public function test_expired_invitation_is_rejected_without_staff_creation(): void
    {
        [$invite, $token] = $this->issue();
        $invite->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token))
            ->assertUnprocessable();
        $this->assertDatabaseCount('staff_members', 0);
    }

    public function test_staff_role_and_email_are_not_controlled_by_acceptance_payload(): void
    {
        [$invite, $token] = $this->issue('support');

        foreach ([
            ['role' => 'admin'],
            ['email' => 'attacker@example.test'],
            ['is_active' => true],
            ['mfa_enrolled_at' => now()->toIso8601String()],
            ['user_id' => 1234],
        ] as $payload) {
            $field = array_key_first($payload);
            $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token, $payload))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('staff_members', 0);
    }

    public function test_password_complexity_is_enforced_before_invitation_consumption(): void
    {
        [$invite, $token] = $this->issue();
        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token, [
            'password' => 'weak', 'password_confirmation' => 'weak',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($invite->fresh()->accepted_at);
    }

    public function test_invitation_role_is_restricted_and_no_superadmin_role_can_be_issued(): void
    {
        foreach (['admin', 'superadmin', 'owner', 'reader', ''] as $role) {
            try {
                app(StaffInvitationService::class)->issue('new@example.test', $role, 'operator');
                $this->fail('Issued forbidden role '.$role);
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('staff_invitations', 0);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_missing_or_untrusted_staff_url_fails_closed(): void
    {
        foreach ([null, 'http://other.example.test/invite', 'https://user@other.example.test/invite',
            'https://other.example.test/invite?next=x', 'https://other.example.test/'] as $url) {
            config()->set('staff.accept_url', $url);
            try {
                app(StaffInvitationService::class)->issue('new@example.test', 'editor', 'operator');
                $this->fail('Unsafe URL accepted');
            } catch (LogicException) {
                $this->assertDatabaseCount('staff_invitations', 0);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_logging_mail_transport_refuses_to_emit_raw_invitation_token(): void
    {
        config()->set('mail.default', 'log');
        try {
            app(StaffInvitationService::class)->issue('new@example.test', 'editor', 'operator');
            $this->fail('Logging transport accepted');
        } catch (LogicException) {
            $this->assertDatabaseCount('staff_invitations', 0);
        }
    }

    public function test_cli_is_privileged_issuance_path_and_never_prints_token(): void
    {
        $status = Artisan::call('staff:invite', [
            'email' => 'EDITOR@example.test',
            '--role' => 'finance',
            '--operator' => 'secure-operator',
        ]);
        $this->assertSame(0, $status);
        $this->assertDatabaseHas('staff_invitations', [
            'email' => 'editor@example.test', 'role' => 'finance', 'issued_by' => 'secure-operator',
        ]);
        $output = Artisan::output();
        $invite = StaffInvitation::query()->firstOrFail();
        $this->assertStringNotContainsString($invite->token_hash, $output);
        $this->assertStringNotContainsString('accept-invitation?', $output);
    }

    public function test_reader_with_same_email_has_no_implicit_staff_privileges(): void
    {
        $reader = User::factory()->create(['email' => 'invited@example.test']);
        [$invite, $token] = $this->issue();

        $this->postJson('/api/v1/staff/invitations/accept', $this->accept($invite, $token))
            ->assertStatus(202);
        $this->assertDatabaseCount('staff_members', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(StaffMember::query()->firstOrFail()->is_active);

        $this->actingAs($reader)
            ->postJson('/api/v1/staff/invitations')->assertNotFound();
        $this->assertDatabaseCount('staff_invitations', 1);
    }

    public function test_token_is_not_used_in_url_or_get_to_activate_account(): void
    {
        [$invite, $token] = $this->issue();
        $this->getJson('/api/v1/staff/invitations/accept?invitation='.$invite->id.'&token='.$token)
            ->assertStatus(405);
        $this->assertDatabaseCount('staff_members', 0);
    }
}
