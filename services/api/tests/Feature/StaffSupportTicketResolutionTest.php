<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportTicketResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Ticket Resolver',
            'email' => 'resolver-'.Str::uuid().'@example.test',
            'password' => 'Staff!SecurePassword2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function ticket(?StaffMember $assignee, string $status = 'pending'): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assignee?->id,
            'subject' => 'Private support question',
            'message' => 'Private conversation body',
            'status' => $status,
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

    private function url(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/tickets/'.$ticket->id.'/resolve';
    }

    public function test_assigned_support_resolves_pending_ticket_with_encrypted_note_and_atomic_audit(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $note = 'Verified the issue and informed the requester.';

        $response = $this->withToken($this->token($staff))
            ->postJson($this->url($ticket), ['resolution_note' => $note])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'resolved')
            ->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonMissingPath('ticket.resolution_note')
            ->assertJsonMissingPath('ticket.resolved_by_staff_id');

        $fresh = $ticket->fresh();
        $this->assertSame($staff->id, $fresh->resolved_by_staff_id);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame($note, $fresh->resolution_note);
        $this->assertNotNull($response->json('ticket.resolved_at'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));

        $raw = DB::table('support_tickets')->where('id', $ticket->id)->value('resolution_note');
        $this->assertNotSame($note, $raw);
        $this->assertStringNotContainsString($note, $raw);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'support.ticket.resolved',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_resolution_requires_fresh_mfa_step_up(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        $this->withToken($this->token($staff, false))
            ->postJson($this->url($ticket), ['resolution_note' => 'Closed after review.'])
            ->assertForbidden();

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_open_and_already_resolved_tickets_cannot_be_resolved(): void
    {
        $staff = $this->staff();
        $open = $this->ticket($staff, 'open');
        $resolved = $this->ticket($staff, 'resolved');

        $this->withToken($this->token($staff));
        foreach ([$open, $resolved] as $ticket) {
            $this->postJson($this->url($ticket), ['resolution_note' => 'No transition allowed.'])
                ->assertForbidden();
        }

        $this->assertSame('open', $open->fresh()->status);
        $this->assertSame('resolved', $resolved->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_escalated_pending_ticket_requires_separate_review(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $ticket->forceFill([
            'escalated_at' => now(),
            'escalated_by_staff_id' => $staff->id,
        ])->save();

        $this->withToken($this->token($staff))
            ->postJson($this->url($ticket), ['resolution_note' => 'Premature resolution.'])
            ->assertStatus(409);

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_other_assignees_and_unassigned_tickets_are_invisible(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $mine = $this->ticket($owner);
        $unassigned = $this->ticket(null);

        $this->withToken($this->token($other));
        foreach ([$mine, $unassigned] as $ticket) {
            $this->postJson($this->url($ticket), ['resolution_note' => 'Unauthorized.'])
                ->assertNotFound();
        }
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reader_editor_and_anonymous_principals_are_denied(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);
        $this->postJson($this->url($ticket), ['resolution_note' => 'No session.'])
            ->assertUnauthorized();

        $reader = User::factory()->create();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->postJson($this->url($ticket), ['resolution_note' => 'Reader.'])
            ->assertForbidden();

        app('auth')->forgetGuards();
        $editor = $this->staff('editor');
        $editorTicket = $this->ticket($editor);
        $this->withToken($this->token($editor))
            ->postJson($this->url($editorTicket), ['resolution_note' => 'Editor.'])
            ->assertForbidden();

        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_role_revocation_deactivation_and_removed_mfa_take_effect_immediately(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $token = $this->token($staff);

        $staff->forceFill(['role' => 'finance'])->save();
        $this->withToken($token)
            ->postJson($this->url($ticket), ['resolution_note' => 'Role revoked.'])
            ->assertForbidden();

        $staff->forceFill(['role' => 'support', 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson($this->url($ticket), ['resolution_note' => 'Inactive.'])
            ->assertForbidden();

        $staff->forceFill(['is_active' => true, 'mfa_enrolled_at' => null])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson($this->url($ticket), ['resolution_note' => 'MFA removed.'])
            ->assertForbidden();

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_invalid_payload_and_mass_assignment_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $this->withToken($this->token($staff));

        foreach ([
            ['resolution_note' => 'Valid', 'status' => 'resolved'],
            ['resolution_note' => 'Valid', 'assigned_staff_id' => 999],
            ['resolution_note' => 'Valid', 'resolved_by_staff_id' => 999],
            ['resolution_note' => 'Valid', 'resolved_at' => now()->toIso8601String()],
            ['resolution_note' => 'Valid', 'escalated_at' => now()->toIso8601String()],
            ['resolution_note' => ''],
            ['resolution_note' => str_repeat('x', 2001)],
            ['resolution_note' => ['invalid']],
            [],
        ] as $payload) {
            $this->postJson($this->url($ticket), $payload)->assertUnprocessable();
        }

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolution_note);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_expired_staff_session_cannot_resolve_ticket(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        $this->withToken($this->token($staff, true, -1))
            ->postJson($this->url($ticket), ['resolution_note' => 'Expired session.'])
            ->assertUnauthorized();

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_repeated_resolution_cannot_overwrite_the_original_note_or_duplicate_audit(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $this->withToken($this->token($staff));

        $this->postJson($this->url($ticket), ['resolution_note' => 'First verified decision.'])
            ->assertOk();
        $first = $ticket->fresh();
        $resolvedAt = $first->resolved_at?->toIso8601String();

        $this->postJson($this->url($ticket), ['resolution_note' => 'Conflicting retry.'])
            ->assertForbidden();

        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('First verified decision.', $fresh->resolution_note);
        $this->assertSame($resolvedAt, $fresh->resolved_at?->toIso8601String());
        $this->assertSame($staff->id, $fresh->resolved_by_staff_id);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'support.ticket.resolved',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_failed_audit_insert_rolls_back_the_entire_resolution(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        // Disposable PostgreSQL test transaction only: force the audit INSERT
        // to fail after the ticket UPDATE, proving that both writes roll back.
        // RefreshDatabase removes this test-only constraint on teardown.
        DB::statement(
            "ALTER TABLE security_audit_events ADD CONSTRAINT reject_support_resolution_test ".
            "CHECK (action <> 'support.ticket.resolved')"
        );

        $this->withToken($this->token($staff))
            ->postJson($this->url($ticket), ['resolution_note' => 'Must not persist.'])
            ->assertStatus(500);

        $fresh = $ticket->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolved_by_staff_id);
        $this->assertNull($fresh->resolution_note);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
