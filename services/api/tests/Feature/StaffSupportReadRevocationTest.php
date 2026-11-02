<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class StaffSupportReadRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Support Read Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
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
            'assigned_staff_id' => $staff->getKey(),
            'subject' => 'Private support case',
            'message' => 'Confidential account information must stay private.',
            'status' => 'open',
        ]);
    }

    private function bearer(StaffMember $staff): string
    {
        return $staff->createToken(
            'support-read',
            ['staff:session'],
            now()->addMinutes(15),
        )->plainTextToken;
    }

    public function test_index_and_detail_lock_staff_token_and_scoped_ticket_before_serialization(): void
    {
        $staff = $this->operator();
        $ticket = $this->ticket($staff);
        $bearer = $this->bearer($staff);
        $locks = ['staff' => 0, 'token' => 0, 'ticket' => 0];

        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'staff_members')) {
                $locks['staff']++;
            }
            if (str_contains($sql, 'personal_access_tokens')) {
                $locks['token']++;
            }
            if (str_contains($sql, 'support_tickets')) {
                $locks['ticket']++;
            }
        });

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/support/tickets')
            ->assertOk()->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ticket->id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/staff/support/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('ticket.id', $ticket->id)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['staff' => 2, 'token' => 2, 'ticket' => 2], $locks);
    }

    public function test_deactivation_denies_both_reads_with_live_bearer_then_revocation_returns_401(): void
    {
        $staff = $this->operator();
        $other = $this->operator();
        $owned = $this->ticket($staff);
        $foreign = $this->ticket($other);
        $bearer = $this->bearer($staff);

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/support/tickets')
            ->assertOk()->assertJsonCount(1, 'tickets');
        $this->getJson('/api/v1/staff/support/tickets/'.$owned->id)
            ->assertOk()->assertJsonPath('ticket.id', $owned->id);
        $this->getJson('/api/v1/staff/support/tickets/'.$foreign->id)->assertNotFound();

        StaffMember::query()->whereKey($staff->getKey())->update(['is_active' => false]);
        $this->assertNotNull(PersonalAccessToken::findToken($bearer));
        app('auth')->forgetGuards();

        $this->withToken($bearer)
            ->getJson('/api/v1/staff/support/tickets')
            ->assertForbidden()->assertJsonMissingPath('tickets');
        $this->getJson('/api/v1/staff/support/tickets/'.$owned->id)
            ->assertForbidden()->assertJsonMissingPath('ticket');
        $this->getJson('/api/v1/staff/support/tickets/'.$foreign->id)
            ->assertForbidden()->assertJsonMissingPath('ticket');

        PersonalAccessToken::findToken($bearer)->delete();
        app('auth')->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/support/tickets')->assertUnauthorized();
        $this->getJson('/api/v1/staff/support/tickets/'.$owned->id)->assertUnauthorized();

        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertSame('open', $owned->fresh()->status);
        $this->assertSame('open', $foreign->fresh()->status);
    }

    public function test_role_revocation_preserves_foreign_ticket_concealment(): void
    {
        $staff = $this->operator();
        $other = $this->operator();
        $owned = $this->ticket($staff);
        $foreign = $this->ticket($other);
        $bearer = $this->bearer($staff);

        StaffMember::query()->whereKey($staff->getKey())->update(['role' => 'ads']);
        app('auth')->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/staff/support/tickets')->assertForbidden();
        $this->getJson('/api/v1/staff/support/tickets/'.$owned->id)->assertForbidden();
        $this->getJson('/api/v1/staff/support/tickets/'.$foreign->id)->assertNotFound();
    }
}
