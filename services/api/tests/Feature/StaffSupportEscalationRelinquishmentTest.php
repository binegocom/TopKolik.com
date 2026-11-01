<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportEscalationRelinquishmentTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support', bool $active = true): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Operator',
            'email' => 'support-'.Str::uuid().'@example.test',
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

    private function ticket(StaffMember $assignee): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assignee->id,
            'subject' => 'Private escalation',
            'message' => 'Sensitive account details',
            'status' => 'open',
        ]);
    }

    private function token(StaffMember $staff, bool $stepUp = true): string
    {
        return $staff->createToken(
            'staff-session',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(15),
        )->plainTextToken;
    }

    private function escalation(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/escalations/'.$ticket->id;
    }

    private function escalate(StaffMember $assignee, SupportTicket $ticket): void
    {
        $this->withToken($this->token($assignee))
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk();
        app('auth')->forgetGuards();
    }

    public function test_relinquishment_reassigns_only_to_a_distinct_eligible_reviewer(): void
    {
        $assignee = $this->staff();
        $original = $this->staff();
        $this->staff('finance');
        $this->staff('support', false);
        $replacement = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->escalate($assignee, $ticket);

        $this->assertSame($original->id, $ticket->fresh()->escalation_reviewer_staff_id);

        $response = $this->withToken($this->token($original))
            ->postJson($this->escalation($ticket).'/relinquish')
            ->assertOk()
            ->assertJsonPath('ticket.status', 'pending')
            ->assertJsonPath('ticket.reviewer_assigned', true)
            ->assertJsonMissingPath('ticket.escalation_reviewer_staff_id');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));

        $fresh = $ticket->fresh();
        $this->assertSame($replacement->id, $fresh->escalation_reviewer_staff_id);
        $this->assertNull($fresh->escalation_reviewed_at);
        $this->assertNull($fresh->escalation_review_note);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $original->id,
            'action' => 'support.ticket.escalation_reviewer_relinquished',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
        ]);

        $this->postJson($this->escalation($ticket).'/approve', [
            'review_note' => 'Stale reviewer approval',
        ])->assertNotFound();
        $this->postJson($this->escalation($ticket).'/relinquish')->assertNotFound();

        app('auth')->forgetGuards();
        $this->withToken($this->token($replacement))
            ->postJson($this->escalation($ticket).'/approve', [
                'review_note' => 'Independent replacement review',
            ])->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($this->token($assignee))
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
                'resolution_note' => 'Closed after independent review',
            ])->assertOk();

        $this->assertDatabaseCount('security_audit_events', 4);
    }

    public function test_without_an_eligible_replacement_escalation_remains_held(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->escalate($assignee, $ticket);

        $this->withToken($this->token($reviewer))
            ->postJson($this->escalation($ticket).'/relinquish')
            ->assertOk()
            ->assertJsonPath('ticket.reviewer_assigned', false);

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->escalation_reviewer_staff_id);
        $this->assertNull($fresh->escalation_reviewed_at);
        $this->assertSame('pending', $fresh->status);

        $this->postJson($this->escalation($ticket).'/approve', [
            'review_note' => 'No reviewer',
        ])->assertNotFound();

        app('auth')->forgetGuards();
        $this->withToken($this->token($assignee))
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/resolve', [
                'resolution_note' => 'Cannot bypass review',
            ])->assertStatus(409);

        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_only_current_mfa_reviewer_can_relinquish(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $other = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->escalate($assignee, $ticket);

        foreach ([$assignee, $other] as $unauthorized) {
            app('auth')->forgetGuards();
            $this->withToken($this->token($unauthorized))
                ->postJson($this->escalation($ticket).'/relinquish')
                ->assertNotFound();
        }

        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer, false))
            ->postJson($this->escalation($ticket).'/relinquish')
            ->assertForbidden();

        $reader = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->postJson($this->escalation($ticket).'/relinquish')
            ->assertForbidden();

        $reviewer->forceFill(['role' => 'editor'])->save();
        app('auth')->forgetGuards();
        $this->withToken($this->token($reviewer))
            ->postJson($this->escalation($ticket).'/relinquish')
            ->assertForbidden();

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_audit_insert_failure_rolls_back_reassignment(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $replacement = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->escalate($assignee, $ticket);

        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_relinquishment_test ".
            "CHECK (action <> 'support.ticket.escalation_reviewer_relinquished')"
        );

        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->token($reviewer))
                ->postJson($this->escalation($ticket).'/relinquish');
            $this->fail('Audit insertion failure must abort the reassignment.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertNotSame($replacement->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_client_cannot_select_reviewer_or_mutate_ticket_state(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $replacement = $this->staff();
        $ticket = $this->ticket($assignee);
        $this->escalate($assignee, $ticket);

        $this->withToken($this->token($reviewer));
        foreach ([
            ['escalation_reviewer_staff_id' => $replacement->id],
            ['assigned_staff_id' => $replacement->id],
            ['escalation_reviewed_at' => now()->toIso8601String()],
            ['escalation_reviewed_by_staff_id' => $reviewer->id],
            ['status' => 'resolved'],
            ['review_note' => 'Self approval'],
        ] as $payload) {
            $this->postJson($this->escalation($ticket).'/relinquish', $payload)
                ->assertUnprocessable();
        }

        $this->assertSame($reviewer->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
