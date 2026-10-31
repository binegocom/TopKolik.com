<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Services\SecurityAuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class SecurityAuditEventsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Audit Operator',
            'email' => 'audit-'.Str::uuid().'@example.test',
            'password' => 'Secure!StaffPassword2026',
            'role' => 'support',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function assertDatabaseMutationRejected(callable $mutation): void
    {
        try {
            // Nested transaction is a PostgreSQL savepoint, allowing the
            // outer RefreshDatabase transaction to survive trigger errors.
            DB::transaction($mutation);
            $this->fail('Audit ledger mutation unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_staff_audit_event_is_minimal_and_database_append_only(): void
    {
        $staff = $this->staff();
        $requestId = (string) Str::uuid();
        app(SecurityAuditRecorder::class)->record(
            $staff, 'staff.session.started', 'staff_member', $staff->id, $requestId,
        );

        $event = DB::table('security_audit_events')->first();
        $this->assertSame($staff->id, $event->staff_member_id);
        $this->assertSame('staff.session.started', $event->action);
        $this->assertSame('staff_member', $event->subject_type);
        $this->assertSame($staff->id, $event->subject_id);
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame(
            ['id', 'staff_member_id', 'action', 'subject_type', 'subject_id', 'request_id', 'created_at'],
            array_keys((array) $event),
        );

        $this->assertDatabaseMutationRejected(fn () => DB::table('security_audit_events')
            ->where('id', $event->id)->update(['action' => 'staff.session.ended']));
        $this->assertDatabaseMutationRejected(fn () => DB::table('security_audit_events')
            ->where('id', $event->id)->delete());
        $this->assertDatabaseMutationRejected(fn () => DB::statement('TRUNCATE security_audit_events'));
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_unknown_action_subject_and_request_identifier_fail_closed(): void
    {
        $staff = $this->staff();
        foreach ([
            ['staff.password.exposed', 'staff_member', $staff->id, null],
            ['staff.session.started', 'reader', $staff->id, null],
            ['staff.session.started', 'staff_member', 0, null],
            ['staff.session.started', 'staff_member', $staff->id, 'client-provided'],
        ] as [$action, $type, $id, $requestId]) {
            try {
                app(SecurityAuditRecorder::class)->record($staff, $action, $type, $id, $requestId);
                $this->fail('Unapproved audit event was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('security_audit_events', 0);
            }
        }
    }

    public function test_security_audit_insert_rolls_back_with_business_transaction(): void
    {
        $staff = $this->staff();
        $ticket = SupportTicket::create([
            'assigned_staff_id' => $staff->id,
            'subject' => 'Private support question',
            'message' => 'Confidential message',
            'status' => 'open',
        ]);

        try {
            DB::transaction(function () use ($staff, $ticket): void {
                $ticket->forceFill(['status' => 'pending'])->save();
                app(SecurityAuditRecorder::class)->record(
                    $staff, 'support.ticket.escalated', 'support_ticket', $ticket->id,
                );
                throw new RuntimeException('Rollback both writes.');
            });
            $this->fail('Transaction unexpectedly committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback both writes.', $exception->getMessage());
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_support_reply_replay_and_escalation_write_one_event_per_change(): void
    {
        $staff = $this->staff();
        $ticket = SupportTicket::create([
            'assigned_staff_id' => $staff->id,
            'subject' => 'Account question',
            'message' => 'Please help',
            'status' => 'open',
        ]);
        $key = (string) Str::uuid();
        $token = $staff->createToken('staff', ['staff:session'], now()->addMinutes(15))->plainTextToken;
        $url = '/api/v1/staff/support/tickets/'.$ticket->id.'/replies';
        $this->withToken($token)->postJson($url, [
            'idempotency_key' => $key, 'message' => 'A confidential reply.',
        ])->assertCreated();
        $this->postJson($url, [
            'idempotency_key' => $key, 'message' => 'A confidential reply.',
        ])->assertOk()->assertJsonPath('replayed', true);

        app('auth')->forgetGuards();
        $stepUp = $staff->createToken(
            'staff-step-up', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
        $this->withToken($stepUp)
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk();

        $this->assertDatabaseCount('security_audit_events', 2);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'support.ticket.replied',
            'subject_type' => 'support_ticket',
            'subject_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'support.ticket.escalated',
            'subject_id' => $ticket->id,
        ]);
        $serialized = json_encode(DB::table('security_audit_events')->get()->all());
        $this->assertStringNotContainsString('A confidential reply.', $serialized);
        $this->assertStringNotContainsString($token, $serialized);
        $this->assertStringNotContainsString($stepUp, $serialized);
    }
}
