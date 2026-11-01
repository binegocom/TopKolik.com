<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportEscalationReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support', bool $active = true): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Reviewer',
            'email' => 'reviewer-'.Str::uuid().'@example.test',
            'password' => 'Staff!SecurePassword2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => $active,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function ticket(StaffMember $assigned): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assigned->id,
            'subject' => 'Confidential support case',
            'message' => 'Restricted account history',
            'status' => 'open',
        ]);
    }

    private function token(StaffMember $staff, bool $stepUp = true, int $minutes = 15): string
    {
        return $staff->createToken(
            'staff-session',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function escalate(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/tickets/'.$ticket->id.'/escalate';
    }

    private function review(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/escalations/'.$ticket->id;
    }

    private function approve(SupportTicket $ticket): string
    {
        return $this->review($ticket).'/approve';
    }

    public function test_independent_reviewer_approves_before_assignee_resolves(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);

        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))
            ->assertOk()->assertJsonPath('ticket.status', 'pending');

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
            'resolution_note' => 'Premature close',
        ])->assertStatus(409);

        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer));
        $show = $this->getJson($this->review($ticket))->assertOk()
            ->assertJsonPath('ticket.subject', 'Confidential support case')
            ->assertJsonMissingPath('ticket.escalation_review_note');
        $this->assertStringContainsString('no-store', $show->headers->get('Cache-Control', ''));

        $note = 'Independently reviewed evidence and approved closure.';
        $this->postJson($this->approve($ticket), ['review_note' => $note])
            ->assertOk()->assertJsonPath('ticket.status', 'pending')
            ->assertJsonMissingPath('ticket.review_note');

        $fresh = $ticket->fresh();
        $this->assertSame($reviewer->id, $fresh->escalation_reviewed_by_staff_id);
        $this->assertSame($note, $fresh->escalation_review_note);
        $this->assertNotNull($fresh->escalation_reviewed_at);
        $raw = DB::table('support_tickets')->where('id', $ticket->id)->value('escalation_review_note');
        $this->assertNotSame($note, $raw);
        $this->assertStringNotContainsString($note, $raw);
        $this->assertDatabaseHas('security_audit_events', [
            'action' => 'support.ticket.escalation_reviewed',
            'staff_member_id' => $reviewer->id,
            'subject_id' => $ticket->id,
        ]);

        app('auth')->forgetGuards();
        $this->withToken($this->token($assignee))
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
                'resolution_note' => 'Resolved following independent approval.',
            ])->assertOk()->assertJsonPath('ticket.status', 'resolved');
        $this->assertDatabaseCount('security_audit_events', 3);
    }

    public function test_no_second_active_support_keeps_escalation_held(): void
    {
        $assignee = $this->staff();
        $ticket = $this->ticket($assignee);

        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        $this->assertNull($ticket->fresh()->escalation_reviewer_staff_id);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
            'resolution_note' => 'Cannot bypass missing reviewer.',
        ])->assertStatus(409);
        $this->postJson($this->approve($ticket), ['review_note' => 'Self approval.'])
            ->assertNotFound();
    }

    public function test_only_active_support_reviewer_is_selected(): void
    {
        $assignee = $this->staff();
        $this->staff('editor');
        $this->staff('support', false);
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);

        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
    }

    public function test_other_staff_and_original_assignee_cannot_approve_or_read(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $other = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        foreach ([$assignee, $other] as $unauthorized) {
            app('auth')->forgetGuards();
            $this->withToken($this->token($unauthorized));
            $this->getJson($this->review($ticket))->assertNotFound();
            $this->postJson($this->approve($ticket), ['review_note' => 'Unauthorized.'])
                ->assertNotFound();
        }

        $reader = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->postJson($this->approve($ticket), ['review_note' => 'Reader.'])
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->escalation_reviewed_at);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_review_requires_step_up_and_current_staff_role(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer, false))
            ->getJson($this->review($ticket))->assertForbidden();
        $this->postJson($this->approve($ticket), ['review_note' => 'No MFA.'])
            ->assertForbidden();

        $validToken = $this->token($reviewer);
        $reviewer->forceFill(['role' => 'finance'])->save();
        app('auth')->forgetGuards();
        $this->withToken($validToken)
            ->postJson($this->approve($ticket), ['review_note' => 'Wrong role.'])
            ->assertForbidden();

        $reviewer->forceFill(['role' => 'support', 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($validToken)
            ->postJson($this->approve($ticket), ['review_note' => 'Deactivated.'])
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->escalation_reviewed_at);
    }

    public function test_replay_cannot_overwrite_approval_or_append_audit(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer))
            ->postJson($this->approve($ticket), ['review_note' => 'Original approval.'])->assertOk();
        $first = $ticket->fresh()->escalation_reviewed_at?->toIso8601String();

        $this->postJson($this->approve($ticket), ['review_note' => 'Overwritten.'])
            ->assertForbidden();
        $this->assertSame('Original approval.', $ticket->fresh()->escalation_review_note);
        $this->assertSame($first, $ticket->fresh()->escalation_reviewed_at?->toIso8601String());
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_rejected_audit_insert_rolls_back_approval(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_escalation_review_test ".
            "CHECK (action <> 'support.ticket.escalation_reviewed')"
        );

        app('auth')->forgetGuards();
        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->token($reviewer))
                ->postJson($this->approve($ticket), ['review_note' => 'Must roll back.']);
            $this->fail('Audit failure must abort the approval.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->escalation_reviewed_at);
        $this->assertNull($fresh->escalation_reviewed_by_staff_id);
        $this->assertNull($fresh->escalation_review_note);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_invalid_review_payload_and_mass_assignment_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->withToken($this->token($assignee))
            ->postJson($this->escalate($ticket))->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer));
        foreach ([
            [],
            ['review_note' => ''],
            ['review_note' => str_repeat('x', 2001)],
            ['review_note' => 'Valid', 'status' => 'resolved'],
            ['review_note' => 'Valid', 'assigned_staff_id' => $reviewer->id],
            ['review_note' => 'Valid', 'escalation_reviewer_staff_id' => $reviewer->id],
            ['review_note' => 'Valid', 'escalation_reviewed_by_staff_id' => $reviewer->id],
            ['review_note' => 'Valid', 'resolution_note' => 'Unauthorized'],
        ] as $payload) {
            $this->postJson($this->approve($ticket), $payload)->assertUnprocessable();
        }

        $this->assertNull($ticket->fresh()->escalation_reviewed_at);
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
