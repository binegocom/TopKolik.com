<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportMutationLockTest extends TestCase
{
    use RefreshDatabase;

    private function support(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Mutation Operator',
            'email' => 'support-lock-'.Str::uuid().'@example.test',
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

    private function ticket(StaffMember $staff, string $status): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $staff->getKey(),
            'subject' => 'Private ticket',
            'message' => 'Confidential support conversation.',
            'status' => $status,
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

    public function test_all_support_mutations_lock_actor_before_ticket_and_commit_audit(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $staff = $this->support();
        $replyTicket = $this->ticket($staff, 'open');
        $escalationTicket = $this->ticket($staff, 'open');
        $resolutionTicket = $this->ticket($staff, 'pending');
        $this->withToken($this->token($staff));

        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'from "staff_members"')) {
                $locks[] = 'staff';
            } elseif (str_contains($sql, 'from "support_tickets"')) {
                $locks[] = 'ticket';
            }
        });

        $this->postJson('/api/v1/staff/support/tickets/'.$replyTicket->id.'/replies', [
            'idempotency_key' => (string) Str::uuid(),
            'message' => 'We are reviewing the request.',
        ])->assertCreated();
        $this->assertSame(['staff', 'ticket'], array_slice($locks, 0, 2));
        $this->assertSame('pending', $replyTicket->fresh()->status);

        $locks = [];
        $this->postJson('/api/v1/staff/support/tickets/'.$escalationTicket->id.'/escalate')
            ->assertOk();
        $this->assertSame(['staff', 'ticket'], array_slice($locks, 0, 2));
        $this->assertNotNull($escalationTicket->fresh()->escalated_at);

        $locks = [];
        $this->postJson('/api/v1/staff/support/tickets/'.$resolutionTicket->id.'/resolve', [
            'resolution_note' => 'Verified resolution.',
        ])->assertOk();
        $this->assertSame(['staff', 'ticket'], array_slice($locks, 0, 2));
        $this->assertSame('resolved', $resolutionTicket->fresh()->status);

        $this->assertDatabaseCount('support_ticket_replies', 1);
        $this->assertDatabaseCount('security_audit_events', 3);
    }

    public function test_revoked_support_role_cannot_mutate_any_ticket_with_existing_mfa_bearer(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $staff = $this->support();
        $open = $this->ticket($staff, 'open');
        $pending = $this->ticket($staff, 'pending');
        $token = $this->token($staff);

        StaffMember::query()->whereKey($staff->getKey())->update(['role' => 'editor']);
        $this->withToken($token)->postJson('/api/v1/staff/support/tickets/'.$open->id.'/replies', [
            'idempotency_key' => (string) Str::uuid(),
            'message' => 'Must be denied.',
        ])->assertForbidden();
        $this->postJson('/api/v1/staff/support/tickets/'.$open->id.'/escalate')
            ->assertForbidden();
        $this->postJson('/api/v1/staff/support/tickets/'.$pending->id.'/resolve', [
            'resolution_note' => 'Must be denied.',
        ])->assertForbidden();

        $this->assertSame('open', $open->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('support_ticket_replies', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
