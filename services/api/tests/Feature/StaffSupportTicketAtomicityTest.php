<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportTicketAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Operator',
            'email' => 'atomic-'.Str::uuid().'@example.test',
            'password' => 'Staff!SecurePassword2026',
            'role' => 'support',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function ticket(StaffMember $staff): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $staff->id,
            'subject' => 'Confidential question',
            'message' => 'Private account details',
            'status' => 'open',
        ]);
    }

    private function token(StaffMember $staff): string
    {
        return $staff->createToken(
            'staff-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(15),
        )->plainTextToken;
    }

    public function test_failed_reply_audit_rolls_back_reply_and_ticket_transition(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        // This constraint exists only inside the disposable PostgreSQL test
        // transaction. Reject the audit after the reply and state update.
        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_support_reply_test ".
            "CHECK (action <> 'support.ticket.replied')"
        );

        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->token($staff))
                ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/replies', [
                    'idempotency_key' => (string) Str::uuid(),
                    'message' => 'Must roll back with the audit.',
                ]);
            $this->fail('A rejected audit insert must abort the reply transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('support_ticket_replies', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_failed_escalation_audit_rolls_back_state_and_actor(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_support_escalation_test ".
            "CHECK (action <> 'support.ticket.escalated')"
        );

        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->token($staff))
                ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate');
            $this->fail('A rejected audit insert must abort the escalation transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $fresh = $ticket->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->escalated_at);
        $this->assertNull($fresh->escalated_by_staff_id);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_repeated_escalation_preserves_first_transition_and_single_audit(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $this->withToken($this->token($staff));

        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk()->assertJsonPath('ticket.status', 'pending');
        $first = $ticket->fresh();
        $this->assertNotNull($first->escalated_at);

        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertForbidden();

        $fresh = $ticket->fresh();
        $this->assertSame($first->escalated_at?->toIso8601String(), $fresh->escalated_at?->toIso8601String());
        $this->assertSame($staff->id, $fresh->escalated_by_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'support.ticket.escalated',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_idempotent_reply_replay_does_not_append_another_audit(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $key = (string) Str::uuid();
        $this->withToken($this->token($staff));

        $payload = ['idempotency_key' => $key, 'message' => 'We are investigating.'];
        $url = '/api/v1/staff/support/tickets/'.$ticket->id.'/replies';
        $first = $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('reply.id', $first->json('reply.id'));

        $this->assertDatabaseCount('support_ticket_replies', 1);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseHas('security_audit_events', [
            'action' => 'support.ticket.replied',
            'subject_id' => $ticket->id,
        ]);
    }
}
