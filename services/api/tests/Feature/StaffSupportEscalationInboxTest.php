<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSupportEscalationInboxTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Escalation Inbox Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Staff2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true): void
    {
        app('auth')->forgetGuards();
        $this->withToken($staff->createToken(
            'reviewer-inbox',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken);
    }

    private function escalated(StaffMember $assignee): SupportTicket
    {
        $ticket = SupportTicket::create([
            'assigned_staff_id' => $assignee->id,
            'subject' => 'Confidential subject',
            'message' => 'Private message for the assigned operator',
            'status' => 'open',
        ]);

        $this->asStaff($assignee);
        $this->postJson('/api/v1/staff/support/tickets/'.$ticket->id.'/escalate')
            ->assertOk();

        return $ticket->fresh();
    }

    private function url(): string
    {
        return '/api/v1/staff/support/escalations';
    }

    public function test_reviewer_sees_only_own_pending_assignments_without_sensitive_content(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $otherReviewer = $this->staff();
        $first = $this->escalated($assignee);
        $second = $this->escalated($assignee);

        // Construct an explicit inbox ownership fixture: load balancing now
        // distributes new cases, but the inbox must remain assignment-scoped.
        $third = $this->escalated($assignee);
        $second->forceFill(['escalation_reviewer_staff_id' => $reviewer->id])->save();
        $third->forceFill(['escalation_reviewer_staff_id' => $otherReviewer->id])->save();

        $this->asStaff($reviewer);
        $response = $this->getJson($this->url())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('next_before_id', null)
            ->assertJsonCount(2, 'escalations')
            ->assertJsonMissingPath('escalations.0.subject')
            ->assertJsonMissingPath('escalations.0.message')
            ->assertJsonMissingPath('escalations.0.assigned_staff_id')
            ->assertJsonMissingPath('escalations.0.escalation_reviewer_staff_id');

        $this->assertSame(
            [$second->id, $first->id],
            array_column($response->json('escalations'), 'id'),
        );
        $this->assertNotContains($third->id, array_column($response->json('escalations'), 'id'));
        $this->assertSame(
            ['id', 'escalated_at'],
            array_keys($response->json('escalations.0')),
        );

        $this->asStaff($otherReviewer);
        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'escalations')
            ->assertJsonPath('escalations.0.id', $third->id);

        // Inbox reads must never append security audit events.
        $this->assertDatabaseCount('security_audit_events', 3);
    }

    public function test_bounded_keyset_pagination_and_reviewed_tickets_disappear(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $first = $this->escalated($assignee);
        $second = $this->escalated($assignee);
        $third = $this->escalated($assignee);

        $this->asStaff($reviewer);
        $page = $this->getJson($this->url().'?limit=2')->assertOk()
            ->assertJsonCount(2, 'escalations')
            ->assertJsonPath('escalations.0.id', $third->id)
            ->assertJsonPath('escalations.1.id', $second->id)
            ->assertJsonPath('next_before_id', $second->id);

        $this->getJson($this->url().'?limit=2&before_id='.$page->json('next_before_id'))
            ->assertOk()
            ->assertJsonCount(1, 'escalations')
            ->assertJsonPath('escalations.0.id', $first->id)
            ->assertJsonPath('next_before_id', null);

        $this->postJson($this->url().'/'.$third->id.'/approve', [
            'review_note' => 'Independent review completed.',
        ])->assertOk();

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(2, 'escalations')
            ->assertJsonPath('escalations.0.id', $second->id);
    }

    public function test_only_active_support_staff_with_fresh_mfa_step_up_can_list(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $this->escalated($assignee);

        $this->asStaff($reviewer, false);
        $this->getJson($this->url())->assertForbidden();

        $editor = $this->staff('editor');
        $this->asStaff($editor);
        $this->getJson($this->url())->assertForbidden();

        $reader = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken);
        $this->getJson($this->url())->assertForbidden();

        $this->asStaff($reviewer);
        $this->getJson($this->url())->assertOk();

        $reviewer->forceFill(['role' => 'finance'])->save();
        $this->getJson($this->url())->assertForbidden();

        $reviewer->forceFill(['role' => 'support', 'is_active' => false])->save();
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_client_cannot_expand_assignment_scope_or_query_unbounded_pages(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $this->escalated($assignee);
        $this->asStaff($reviewer);

        foreach ([
            '?limit=0',
            '?limit=51',
            '?limit=abc',
            '?before_id=0',
            '?before_id=1%20OR%201%3D1',
            '?staff_member_id='.$assignee->id,
            '?escalation_reviewer_staff_id='.$assignee->id,
            '?assigned_staff_id='.$assignee->id,
            '?include_all=1',
            '?with_replies=1',
            '?status=resolved',
        ] as $query) {
            $this->getJson($this->url().$query)->assertUnprocessable();
        }

        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertSame(1, DB::table('support_tickets')->count());
    }

    public function test_held_and_resolved_escalations_are_not_listed(): void
    {
        $assignee = $this->staff();
        $reviewer = $this->staff();
        $held = $this->escalated($assignee);
        $resolved = $this->escalated($assignee);

        $held->forceFill(['escalation_reviewer_staff_id' => null])->save();
        $resolved->forceFill(['status' => 'resolved'])->save();

        $this->asStaff($reviewer);
        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(0, 'escalations')
            ->assertJsonPath('next_before_id', null);
    }
}
