<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportTicketActionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Agent',
            'email' => 'staff-'.Str::uuid().'@example.test',
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

    private function ticket(?StaffMember $assigned, string $state = 'open'): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assigned?->id,
            'subject' => 'Confidential support question',
            'message' => 'Please review the account request.',
            'status' => $state,
        ]);
    }

    private function token(StaffMember $staff, bool $stepUp = false, int $minutes = 15): string
    {
        return $staff->createToken(
            'staff-session',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function replyData(?string $key = null, string $message = 'Your request is under review.'): array
    {
        return [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'message' => $message,
        ];
    }

    private function replyUrl(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/tickets/'.$ticket->id.'/replies';
    }

    private function escalationUrl(SupportTicket $ticket): string
    {
        return '/api/v1/staff/support/tickets/'.$ticket->id.'/escalate';
    }

    public function test_assigned_support_can_reply_and_read_encrypted_conversation_history(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);

        $response = $this->withToken($this->token($support))
            ->postJson($this->replyUrl($ticket), $this->replyData())
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonMissingPath('reply.message');

        $reply = SupportTicketReply::firstOrFail();
        $raw = DB::table('support_ticket_replies')->where('id', $reply->id)->first();
        $this->assertNotSame($reply->message, $raw->message);
        $this->assertStringNotContainsString('Your request is under review.', $raw->message);
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertDatabaseCount('support_ticket_replies', 1);
        $this->assertSame($reply->id, $response->json('reply.id'));

        $show = $this->getJson('/api/v1/staff/support/tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('ticket.replies.0.message', $reply->message)
            ->assertJsonPath('ticket.status', 'pending');
        $this->assertStringContainsString('no-store', $show->headers->get('Cache-Control', ''));
    }

    public function test_same_idempotency_key_and_message_replays_without_duplicate(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $key = (string) Str::uuid();
        $data = $this->replyData($key);

        $first = $this->withToken($this->token($staff))
            ->postJson($this->replyUrl($ticket), $data)->assertCreated();
        $second = $this->postJson($this->replyUrl($ticket), $data)
            ->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('reply.id'), $second->json('reply.id'));
        $this->assertDatabaseCount('support_ticket_replies', 1);
    }

    public function test_reused_idempotency_key_with_different_payload_is_rejected(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $key = (string) Str::uuid();

        $this->withToken($this->token($staff))
            ->postJson($this->replyUrl($ticket), $this->replyData($key))
            ->assertCreated();
        $this->postJson($this->replyUrl($ticket), $this->replyData($key, 'A different support reply.'))
            ->assertStatus(409);
        $this->assertDatabaseCount('support_ticket_replies', 1);
    }

    public function test_other_assigned_support_and_unassigned_tickets_are_invisible(): void
    {
        $assigned = $this->staff();
        $other = $this->staff();
        $mine = $this->ticket($assigned);
        $unassigned = $this->ticket(null);

        $this->withToken($this->token($other))
            ->postJson($this->replyUrl($mine), $this->replyData())->assertNotFound();
        $this->postJson($this->replyUrl($unassigned), $this->replyData())->assertNotFound();
        $this->postJson($this->escalationUrl($mine))->assertNotFound();
        $this->assertDatabaseCount('support_ticket_replies', 0);
    }

    public function test_reader_and_editor_can_never_write_or_escalate_tickets(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);
        $this->postJson($this->replyUrl($ticket), $this->replyData())->assertUnauthorized();

        $reader = User::factory()->create();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->postJson($this->replyUrl($ticket), $this->replyData())->assertForbidden();
        app('auth')->forgetGuards();

        $editor = $this->staff('editor');
        $editorTicket = $this->ticket($editor);
        $this->withToken($this->token($editor, true))
            ->postJson($this->replyUrl($editorTicket), $this->replyData())->assertForbidden();
        $this->postJson($this->escalationUrl($editorTicket))->assertForbidden();
        $this->assertDatabaseCount('support_ticket_replies', 0);
    }

    public function test_resolved_ticket_cannot_be_mutated(): void
    {
        $staff = $this->staff();
        $resolved = $this->ticket($staff, 'resolved');

        $this->withToken($this->token($staff, true))
            ->postJson($this->replyUrl($resolved), $this->replyData())->assertForbidden();
        $this->postJson($this->escalationUrl($resolved))->assertForbidden();
        $this->assertSame('resolved', $resolved->fresh()->status);
    }

    public function test_escalation_requires_step_up_and_persists_actor_and_transition(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        $this->withToken($this->token($staff))
            ->postJson($this->escalationUrl($ticket))->assertForbidden();
        $this->assertNull($ticket->fresh()->escalated_at);

        app('auth')->forgetGuards();
        $this->withToken($this->token($staff, true))
            ->postJson($this->escalationUrl($ticket))
            ->assertOk()
            ->assertJsonPath('ticket.status', 'pending');
        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh->escalated_at);
        $this->assertSame($staff->id, $fresh->escalated_by_staff_id);

        $this->postJson($this->escalationUrl($ticket))->assertForbidden();
    }

    public function test_role_revocation_and_staff_deactivation_are_effective_immediately(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $token = $this->token($staff, true);

        StaffMember::query()->whereKey($staff->id)->update(['role' => 'ads']);
        $this->withToken($token)
            ->postJson($this->replyUrl($ticket), $this->replyData())->assertForbidden();

        StaffMember::query()->whereKey($staff->id)->update(['role' => 'support', 'is_active' => false]);
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson($this->escalationUrl($ticket))->assertForbidden();
        $this->assertDatabaseCount('support_ticket_replies', 0);
    }

    public function test_mutation_payloads_cannot_choose_ticket_owner_or_state(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);
        $this->withToken($this->token($support, true));

        foreach (['support_ticket_id' => 777, 'staff_member_id' => 999,
            'assigned_staff_id' => 999, 'status' => 'resolved'] as $field => $value) {
            $this->postJson($this->replyUrl($ticket), [
                ...$this->replyData(), $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->replyUrl($ticket), [
            'idempotency_key' => 'not-uuid', 'message' => 'Reply',
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($this->replyUrl($ticket), [
            'idempotency_key' => (string) Str::uuid(), 'message' => str_repeat('x', 4001),
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson($this->escalationUrl($ticket), [
            'status' => 'resolved', 'assigned_staff_id' => $support->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'assigned_staff_id']);
        $this->assertDatabaseCount('support_ticket_replies', 0);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_expired_staff_session_does_not_mutate_tickets(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);

        $this->withToken($this->token($staff, false, -1))
            ->postJson($this->replyUrl($ticket), $this->replyData())->assertUnauthorized();
        $this->assertDatabaseCount('support_ticket_replies', 0);
    }
}
