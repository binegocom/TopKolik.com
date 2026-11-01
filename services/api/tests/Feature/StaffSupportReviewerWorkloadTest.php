<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
