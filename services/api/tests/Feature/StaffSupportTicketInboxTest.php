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

final class StaffSupportTicketInboxTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/staff/support/tickets';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Inbox Operator',
            'email' => 'support-'.Str::uuid().'@example.test',
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

    private function ticket(?StaffMember $assignee, string $status = 'open'): SupportTicket
    {
        return SupportTicket::create([
            'assigned_staff_id' => $assignee?->id,
            'subject' => 'Private support subject '.Str::uuid(),
            'message' => 'Sensitive message must not appear in the inbox.',
            'status' => $status,
        ]);
    }

    private function token(StaffMember $staff, int $minutes = 15): string
    {
        return $staff->createToken(
            'staff-session',
            ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    public function test_assigned_support_inbox_is_scoped_and_excludes_conversation_bodies(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $first = $this->ticket($owner, 'open');
        $second = $this->ticket($owner, 'pending');
        $this->ticket($other);
        $this->ticket(null);

        $response = $this->withToken($this->token($owner))
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(2, 'tickets')
            ->assertJsonPath('tickets.0.id', $second->id)
            ->assertJsonPath('tickets.1.id', $first->id)
            ->assertJsonPath('tickets.0.subject', $second->subject)
            ->assertJsonPath('tickets.0.status', 'pending')
            ->assertJsonPath('next_before_id', null)
            ->assertJsonMissingPath('tickets.0.message')
            ->assertJsonMissingPath('tickets.0.replies')
            ->assertJsonMissingPath('tickets.0.assigned_staff_id');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertStringNotContainsString('Sensitive message', $response->getContent());
        $this->assertNotSame(
            $second->subject,
            DB::table('support_tickets')->where('id', $second->id)->value('subject'),
        );
    }

    public function test_status_filter_and_keyset_pagination_are_bounded_and_stable(): void
    {
        $staff = $this->staff();
        $old = $this->ticket($staff, 'open');
        $pending = $this->ticket($staff, 'pending');
        $new = $this->ticket($staff, 'open');
        $this->withToken($this->token($staff));

        $first = $this->getJson(self::ENDPOINT.'?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $new->id)
            ->assertJsonPath('next_before_id', $new->id);

        $this->getJson(self::ENDPOINT.'?limit=1&before_id='.$first->json('next_before_id'))
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $pending->id)
            ->assertJsonPath('next_before_id', $pending->id);

        $this->getJson(self::ENDPOINT.'?limit=1&before_id='.$pending->id)
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $old->id)
            ->assertJsonPath('next_before_id', null);

        $this->getJson(self::ENDPOINT.'?status=open')
            ->assertOk()->assertJsonCount(2, 'tickets')
            ->assertJsonPath('tickets.0.id', $new->id)
            ->assertJsonPath('tickets.1.id', $old->id);

        $this->getJson(self::ENDPOINT.'?status=resolved')
            ->assertOk()->assertJsonCount(0, 'tickets');
    }

    public function test_an_empty_inbox_is_available_only_to_current_support_staff(): void
    {
        $support = $this->staff();
        $this->withToken($this->token($support))
            ->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(0, 'tickets');

        app('auth')->forgetGuards();
        $editor = $this->staff('editor');
        $this->withToken($this->token($editor))
            ->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_anonymous_reader_and_other_staff_roles_cannot_list_tickets(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $reader = User::factory()->create();
        $this->withToken($reader->createToken('reader', ['reader:read'])->plainTextToken)
            ->getJson(self::ENDPOINT)->assertForbidden();

        app('auth')->forgetGuards();
        $ads = $this->staff('ads');
        $this->ticket($ads);
        $this->withToken($this->token($ads))
            ->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_role_change_and_deactivation_immediately_revoke_inbox_access(): void
    {
        $support = $this->staff();
        $this->ticket($support);
        $token = $this->token($support);

        $this->withToken($token)->getJson(self::ENDPOINT)->assertOk();

        $support->forceFill(['role' => 'finance'])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::ENDPOINT)->assertForbidden();

        $support->forceFill(['role' => 'support', 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_expired_session_and_removed_mfa_are_denied(): void
    {
        $support = $this->staff();
        $this->ticket($support);

        $this->withToken($this->token($support, -1))
            ->getJson(self::ENDPOINT)->assertUnauthorized();

        $support->forceFill(['mfa_enrolled_at' => null])->save();
        app('auth')->forgetGuards();
        $this->withToken($this->token($support))
            ->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_query_filters_cannot_select_another_staff_or_expand_scope(): void
    {
        $support = $this->staff();
        $other = $this->staff();
        $this->ticket($support);
        $this->ticket($other);
        $this->withToken($this->token($support));

        foreach ([
            '?assigned_staff_id='.$other->id => 'assigned_staff_id',
            '?staff_member_id='.$other->id => 'staff_member_id',
            '?include_unassigned=1' => 'include_unassigned',
            '?include_all=1' => 'include_all',
            '?with_replies=1' => 'with_replies',
            '?limit=51' => 'limit',
            '?limit=0' => 'limit',
            '?before_id=-1' => 'before_id',
            '?status=archived' => 'status',
        ] as $query => $field) {
            $this->getJson(self::ENDPOINT.$query)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }
}
