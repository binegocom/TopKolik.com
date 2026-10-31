<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StaffSupportTicketAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support'): StaffMember
    {
        $member = StaffMember::create([
            'name' => 'Support Operator',
            'email' => 'operator-'.uniqid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
            'role' => $role,
        ]);

        $member->forceFill([
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
            'is_active' => true,
        ])->save();

        return $member;
    }

    private function ticket(?StaffMember $assignee, string $status = 'open'): SupportTicket
    {
        return SupportTicket::query()->create([
            'assigned_staff_id' => $assignee?->getKey(),
            'subject' => 'Account support request',
            'message' => 'A confidential support message',
            'status' => $status,
        ]);
    }

    private function token(StaffMember $member, int $minutes = 15): string
    {
        return $member->createToken(
            'staff-session',
            ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    public function test_assigned_support_can_read_persisted_ticket_without_cacheable_response(): void
    {
        $staff = $this->staff();
        $ticket = $this->ticket($staff);
        $raw = DB::table('support_tickets')->where('id', $ticket->id)->first();
        $this->assertNotSame($ticket->subject, $raw->subject);
        $this->assertNotSame($ticket->message, $raw->message);

        $response = $this->withToken($this->token($staff))
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonPath('ticket.subject', $ticket->subject)
            ->assertJsonPath('ticket.message', $ticket->message)
            ->assertJsonPath('ticket.status', 'open')
            ->assertJsonMissingPath('ticket.assigned_staff_id');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
    }

    public function test_other_operator_and_unassigned_ticket_are_not_discoverable(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $owned = $this->ticket($owner);
        $unassigned = $this->ticket(null);

        $this->withToken($this->token($other))
            ->getJson('/api/v1/staff/support/tickets/'.$owned->id)->assertNotFound();
        $this->getJson('/api/v1/staff/support/tickets/'.$unassigned->id)->assertNotFound();
        $this->getJson('/api/v1/staff/support/tickets/99999999')->assertNotFound();
    }

    public function test_editor_cannot_read_ticket_even_if_assigned_in_database(): void
    {
        $editor = $this->staff('editor');
        $ticket = $this->ticket($editor);

        $this->withToken($this->token($editor))
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertForbidden();
    }

    public function test_reader_token_and_missing_token_never_reach_support_data(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);

        $this->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertUnauthorized();

        $reader = User::factory()->create();
        $readerToken = $reader->createToken('reader', ['reader:read'])->plainTextToken;
        app('auth')->forgetGuards();

        $this->withToken($readerToken)
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertForbidden();
    }

    public function test_role_change_or_deactivation_revokes_ticket_access_immediately(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support);
        $token = $this->token($support);

        $this->withToken($token)
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertOk();

        $support->forceFill(['role' => 'ads'])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertForbidden();

        $support->forceFill(['role' => 'support', 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertForbidden();
    }

    public function test_expired_session_cannot_read_ticket_and_resolved_ticket_is_read_only(): void
    {
        $support = $this->staff();
        $ticket = $this->ticket($support, 'resolved');

        $this->withToken($this->token($support, -1))
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)->assertUnauthorized();

        app('auth')->forgetGuards();
        $this->withToken($this->token($support))
            ->getJson('/api/v1/staff/support/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('ticket.status', 'resolved');
    }
}
