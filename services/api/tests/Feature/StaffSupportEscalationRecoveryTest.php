<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportEscalationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support', bool $active = true): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Recovery Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Staff2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => $active,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function admin(string $role = 'finance'): StaffMember
    {
        $staff = $this->staff($role);
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $staff->getKey(),
            'is_active' => true,
            'authorization_reference' => 'independent-security-operator-authorization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staff;
    }

    private function token(StaffMember $staff, bool $stepUp = true, int $minutes = 5): string
    {
        RateLimiter::clear(sha1((string) $staff->getKey()));

        return $staff->createToken(
            'security-escalation-recovery',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true, int $minutes = 5): void
    {
        app('auth')->forgetGuards();
        $this->withToken($this->token($staff, $stepUp, $minutes));
    }

    private function ticket(StaffMember $assignee): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assignee->getKey(),
            'subject' => 'Confidential support escalation',
            'message' => 'Restricted user account matter awaiting an independent reviewer.',
            'status' => 'open',
        ]);
    }

    private function recoverUrl(SupportTicket $ticket): string
    {
        return '/api/v1/staff/security/support/escalations/'.$ticket->id.'/recover-reviewer';
    }

    private function held(StaffMember $assignee): SupportTicket
    {
        $ticket = $this->ticket($assignee);
        $this->asStaff($assignee);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk();
        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertSame('pending', $ticket->fresh()->status);

        return $ticket;
    }

    public function test_admin_recovers_held_escalation_with_independent_reviewer_and_atomic_audit(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $reviewer = $this->staff();

        $this->asStaff($admin);
        $response = $this->postJson($this->recoverUrl($ticket))
            ->assertOk()->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonPath('ticket.status', 'pending')
            ->assertJsonPath('ticket.reviewer_assigned', true)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('ticket.escalation_reviewer_staff_id')
            ->assertJsonMissingPath('ticket.message');

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertNull($ticket->fresh()->escalation_reviewed_at);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $admin->id,
            'action' => 'support.ticket.escalation_reviewer_recovered',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);

        $this->asStaff($reviewer);
        $this->postJson('/api/v1/staff/support/escalations/'.$ticket->id.'/approve', [
            'review_note' => 'Independently confirmed the support escalation evidence.',
        ])->assertOk();

        $this->asStaff($assignee);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
            'resolution_note' => 'Ticket closed after independent approval.',
        ])->assertOk()->assertJsonPath('ticket.status', 'resolved');

        $this->assertDatabaseCount('security_audit_events', 4);
    }

    public function test_admin_with_support_role_is_never_auto_selected_as_reviewer(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin('support');
        $ticket = $this->ticket($assignee);

        // Exact legacy/unassigned persisted state; the resolver cannot
        // nominate themselves even when their ordinary role is support.
        $ticket->forceFill([
            'status' => 'pending',
            'escalated_at' => now(),
            'escalated_by_staff_id' => $assignee->id,
        ])->save();

        $this->asStaff($admin);
        $this->postJson($this->recoverUrl($ticket))->assertStatus(409);
        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 0);

        $reviewer = $this->staff();
        $this->postJson($this->recoverUrl($ticket))->assertOk();
        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertNotSame($admin->id, $ticket->fresh()->escalation_reviewer_staff_id);
    }

    public function test_no_eligible_support_reviewer_preserves_the_hold_without_extra_audit(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $this->staff('editor');
        $this->staff('support', false);
        $inactiveMfa = $this->staff();
        $inactiveMfa->forceFill(['mfa_enrolled_at' => null])->save();

        $this->asStaff($admin);
        $this->postJson($this->recoverUrl($ticket))->assertStatus(409);
        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertSame('pending', $ticket->fresh()->status);

        $this->asStaff($assignee);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
            'resolution_note' => 'Cannot bypass missing independent review.',
        ])->assertStatus(409);
    }

    public function test_replay_or_already_reviewed_or_non_escalated_tickets_cannot_be_reassigned(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $reviewer = $this->staff();
        $this->asStaff($admin);

        $this->postJson($this->recoverUrl($ticket))->assertOk();
        $this->postJson($this->recoverUrl($ticket))->assertNotFound();
        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 2);

        $plain = $this->ticket($assignee);
        $this->postJson($this->recoverUrl($plain))->assertNotFound();

        $ticket->forceFill([
            'escalation_reviewed_at' => now(),
            'escalation_reviewed_by_staff_id' => $reviewer->id,
            'escalation_review_note' => 'Reviewed and approved.',
        ])->save();

        $this->postJson($this->recoverUrl($ticket))->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_support_staff_and_wrong_principal_types_cannot_recover_any_held_ticket(): void
    {
        $assignee = $this->staff();
        $ticket = $this->held($assignee);

        foreach ([$assignee, $this->staff('support'), $this->staff('finance')] as $ungranted) {
            $this->asStaff($ungranted);
            $this->postJson($this->recoverUrl($ticket))->assertForbidden();
        }

        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!Advertiser2026',
        ]);

        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)
                ->postJson($this->recoverUrl($ticket))->assertForbidden();
        }

        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_current_step_up_and_grant_are_required_at_mutation_time(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $reviewer = $this->staff();

        $this->asStaff($admin, false);
        $this->postJson($this->recoverUrl($ticket))->assertForbidden();
        $this->asStaff($admin, true, -1);
        $this->postJson($this->recoverUrl($ticket))->assertUnauthorized();

        $this->asStaff($admin);
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->id)
            ->update(['is_active' => false]);
        $this->postJson($this->recoverUrl($ticket))->assertForbidden();

        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->id)
            ->update(['is_active' => true]);
        $admin->forceFill(['email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson($this->recoverUrl($ticket))->assertForbidden();

        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_client_may_not_choose_reviewer_or_rewrite_ticket_fields(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $reviewer = $this->staff();
        $this->asStaff($admin);

        foreach ([
            'escalation_reviewer_staff_id' => $reviewer->id,
            'assigned_staff_id' => $admin->id,
            'escalated_by_staff_id' => $admin->id,
            'escalation_reviewed_by_staff_id' => $admin->id,
            'escalation_reviewed_at' => now()->toIso8601String(),
            'status' => 'resolved',
            'review_note' => 'Forged closure',
            'staff_member_id' => $reviewer->id,
        ] as $field => $value) {
            $this->postJson($this->recoverUrl($ticket), [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_audit_failure_rolls_back_recovery_and_preserves_the_hold(): void
    {
        $assignee = $this->staff();
        $admin = $this->admin();
        $ticket = $this->held($assignee);
        $this->staff();

        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_recovery_test ".
            "CHECK (action <> 'support.ticket.escalation_reviewer_recovered')"
        );

        $this->asStaff($admin);
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->recoverUrl($ticket));
            $this->fail('Audit failure must roll back the reviewer assignment.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
