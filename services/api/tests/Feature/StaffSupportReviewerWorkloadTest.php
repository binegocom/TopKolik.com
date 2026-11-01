<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportReviewerWorkloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_escalation_prefers_least_loaded_eligible_reviewer(): void
    {
        $staff = [];
        foreach (['assignee', 'busy', 'free'] as $name) {
            $person = StaffMember::create([
                'name' => $name,
                'email' => Str::uuid().'@example.test',
                'password' => 'Staff!SecurePassword2026',
                'role' => 'support',
            ]);
            $person->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            $staff[$name] = $person;
        }

        $assigned = SupportTicket::create([
            'assigned_staff_id' => $staff['assignee']->id,
            'subject' => 'Previous case',
            'message' => 'Case details',
            'status' => 'open',
        ]);
        $assigned->forceFill([
            'status' => 'pending',
            'escalated_at' => now(),
            'escalated_by_staff_id' => $staff['assignee']->id,
            'escalation_reviewer_staff_id' => $staff['busy']->id,
        ])->save();

        $ticket = SupportTicket::create([
            'assigned_staff_id' => $staff['assignee']->id,
            'subject' => 'New case',
            'message' => 'Case details',
            'status' => 'open',
        ]);
        $token = $staff['assignee']->createToken(
            'staff-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk();

        $this->assertSame($staff['free']->id, $ticket->fresh()->escalation_reviewer_staff_id);
    }

    private function eligibleStaff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Reviewer',
            'email' => Str::uuid().'@example.test',
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

    private function supportTicket(StaffMember $assignee, ?StaffMember $reviewer = null): SupportTicket
    {
        $ticket = SupportTicket::create([
            'assigned_staff_id' => $assignee->id,
            'subject' => 'Private support case',
            'message' => 'Restricted content',
            'status' => 'open',
        ]);
        if ($reviewer !== null) {
            $ticket->forceFill([
                'status' => 'pending',
                'escalated_at' => now(),
                'escalated_by_staff_id' => $assignee->id,
                'escalation_reviewer_staff_id' => $reviewer->id,
            ])->save();
        }

        return $ticket;
    }

    private function staffToken(StaffMember $staff): string
    {
        return $staff->createToken(
            'staff-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;
    }

    public function test_relinquishment_chooses_unloaded_independent_reviewer(): void
    {
        $assignee = $this->eligibleStaff();
        $current = $this->eligibleStaff();
        $busy = $this->eligibleStaff();
        $free = $this->eligibleStaff();
        $ticket = $this->supportTicket($assignee, $current);
        $this->supportTicket($assignee, $busy);

        $this->withToken($this->staffToken($current))
            ->postJson('/api/v1/staff/support/escalations/'.$ticket->id.'/relinquish')
            ->assertOk()
            ->assertJsonPath('ticket.reviewer_assigned', true);

        $this->assertSame($free->id, $ticket->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseHas('security_audit_events', [
            'action' => 'support.ticket.escalation_reviewer_relinquished',
            'subject_id' => $ticket->id,
        ]);
    }


    public function test_security_recovery_chooses_unloaded_independent_reviewer(): void
    {
        $assignee = $this->eligibleStaff();
        $admin = $this->eligibleStaff('finance');
        $busy = $this->eligibleStaff();
        $free = $this->eligibleStaff();

        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->id,
            'is_active' => true,
            'authorization_reference' => 'independent-security-authorization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $held = $this->supportTicket($assignee);
        $held->forceFill([
            'status' => 'pending',
            'escalated_at' => now(),
            'escalated_by_staff_id' => $assignee->id,
        ])->save();
        $this->supportTicket($assignee, $busy);

        $this->withToken($this->staffToken($admin))
            ->postJson('/api/v1/staff/security/support/escalations/'.$held->id.'/recover-reviewer')
            ->assertOk()
            ->assertJsonPath('ticket.reviewer_assigned', true);

        $this->assertSame($free->id, $held->fresh()->escalation_reviewer_staff_id);
        $this->assertDatabaseHas('security_audit_events', [
            'action' => 'support.ticket.escalation_reviewer_recovered',
            'subject_id' => $held->id,
        ]);
    }

}
