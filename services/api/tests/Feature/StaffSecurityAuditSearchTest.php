<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSecurityAuditSearchTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/staff/security/audit-events';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Audit Test Staff',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function token(StaffMember $staff, bool $stepUp = true, int $minutes = 5): string
    {
        return $staff->createToken(
            'staff-audit-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function event(StaffMember $actor, string $action, string $subject = 'staff_member', ?int $subjectId = null, ?string $requestId = null): int
    {
        app(SecurityAuditRecorder::class)->record(
            $actor, $action, $subject, $subjectId ?? $actor->getKey(), $requestId,
        );

        return (int) DB::table('security_audit_events')->max('id');
    }

    public function test_only_own_event_metadata_is_returned_and_never_cached(): void
    {
        $one = $this->staff();
        $two = $this->staff('finance');
        $requestId = (string) Str::uuid();
        $ownId = $this->event($one, 'staff.session.started', requestId: $requestId);
        $this->event($two, 'staff.session.ended');

        $this->withToken($this->token($one))
            ->getJson(self::URI)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $ownId)
            ->assertJsonPath('events.0.request_id', $requestId)
            ->assertJsonMissingPath('events.0.staff_member_id')
            ->assertJsonMissingPath('events.0.email')
            ->assertJsonPath('next_before_id', null);

        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_filtering_and_cursor_are_bounded_deterministic_and_stay_actor_scoped(): void
    {
        $one = $this->staff();
        $other = $this->staff('editor');
        $requestId = (string) Str::uuid();
        $first = $this->event($one, 'staff.session.started');
        $second = $this->event($one, 'support.ticket.replied', 'support_ticket', 55, $requestId);
        $third = $this->event($one, 'support.ticket.replied', 'support_ticket', 56);
        $fourth = $this->event($one, 'staff.session.ended');
        $this->event($other, 'support.ticket.replied', 'support_ticket', 55, $requestId);

        $this->withToken($this->token($one));
        $this->getJson(self::URI.'?limit=2')->assertOk()
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.id', $fourth)
            ->assertJsonPath('events.1.id', $third)
            ->assertJsonPath('next_before_id', $third);

        $this->getJson(self::URI.'?limit=2&before_id='.$third)->assertOk()
            ->assertJsonPath('events.0.id', $second)
            ->assertJsonPath('events.1.id', $first)
            ->assertJsonPath('next_before_id', null);

        $this->getJson(self::URI.'?action=support.ticket.replied&subject_type=support_ticket')
            ->assertOk()->assertJsonCount(2, 'events')->assertJsonPath('events.0.id', $third);
        $this->getJson(self::URI.'?request_id='.$requestId.'&subject_id=55')
            ->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('events.0.id', $second);
        $this->getJson(self::URI.'?request_id='.(string) Str::uuid())
            ->assertOk()->assertJsonCount(0, 'events');
    }

    public function test_ordinary_staff_session_and_missing_session_have_no_audit_access(): void
    {
        $staff = $this->staff();
        $this->event($staff, 'staff.session.started');
        $this->getJson(self::URI)->assertUnauthorized();
        $this->withToken($this->token($staff, false))
            ->getJson(self::URI)->assertForbidden();
    }

    public function test_expired_revoked_and_inactive_sessions_fail_closed(): void
    {
        $staff = $this->staff();
        $this->event($staff, 'staff.session.started');
        $expired = $this->token($staff, true, -1);
        $this->withToken($expired)->getJson(self::URI)->assertUnauthorized();

        $valid = $this->token($staff);
        app('auth')->forgetGuards();
        $this->withToken($valid)->getJson(self::URI)->assertOk();

        $staff->tokens()->delete();
        app('auth')->forgetGuards();
        $this->withToken($valid)->getJson(self::URI)->assertUnauthorized();

        $active = $this->token($staff);
        $staff->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($active)->getJson(self::URI)->assertForbidden();
    }

    public function test_missing_mfa_or_email_proof_revokes_access_immediately(): void
    {
        $staff = $this->staff();
        $token = $this->token($staff);
        $staff->forceFill(['mfa_enrolled_at' => null])->save();
        $this->withToken($token)->getJson(self::URI)->assertForbidden();

        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::URI)->assertForbidden();
    }

    public function test_reader_and_advertiser_tokens_never_authorize_staff_audit(): void
    {
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Advertiser2026',
        ]);
        $advertiser->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
        $tokens = [
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ];

        foreach ($tokens as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson(self::URI)->assertForbidden();
        }
    }

    public function test_request_cannot_choose_other_staff_or_expand_unbounded_search(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $this->event($other, 'staff.session.started');
        $this->withToken($this->token($staff));

        foreach ([
            ['staff_member_id' => $other->id],
            ['actor_id' => $other->id],
            ['limit' => 51],
            ['limit' => 0],
            ['before_id' => -1],
            ['action' => 'finance.payment.issued'],
            ['subject_type' => 'reader'],
            ['subject_id' => 0],
            ['request_id' => 'not-a-uuid'],
        ] as $filters) {
            $field = array_key_first($filters);
            $this->getJson(self::URI.'?'.http_build_query($filters))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson(self::URI)->assertOk()->assertJsonCount(0, 'events');
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_all_existing_staff_roles_can_view_only_their_own_events_with_step_up(): void
    {
        $members = [];
        foreach (['editor', 'support', 'ads', 'finance'] as $role) {
            $members[] = $this->staff($role);
        }
        foreach ($members as $staff) {
            $this->event($staff, 'staff.session.started');
            app('auth')->forgetGuards();
            $this->withToken($this->token($staff))->getJson(self::URI)
                ->assertOk()->assertJsonCount(1, 'events');
        }
    }
}
