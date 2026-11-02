<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSecurityAuditReadLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_self_only_audit_reads_lock_the_staff_and_bearer_until_serialization(): void
    {
        $staff = StaffMember::create([
            'name' => 'Audit Read Lock Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!AuditRead2026',
            'role' => 'support',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        $audit = app(SecurityAuditRecorder::class);
        $audit->record($staff, 'staff.session.started', 'staff_member', (int) $staff->getKey());
        $audit->recordFailure('staff.login.failed', $staff->email, $staff);

        $token = $staff->createToken(
            'audit-read-lock',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $statements[] = strtolower($query->sql);
            }
        });

        foreach ([
            '/api/v1/staff/security/audit-events' => 'events',
            '/api/v1/staff/security/authentication-failures' => 'failures',
        ] as $uri => $key) {
            $statements = [];
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($uri)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonCount(1, $key);

            $this->assertTrue(
                collect($statements)->contains(static fn (string $sql): bool =>
                    str_contains($sql, 'staff_members') && str_contains($sql, 'for update')),
                "Missing persisted staff row lock for {$uri}",
            );
            $this->assertTrue(
                collect($statements)->contains(static fn (string $sql): bool =>
                    str_contains($sql, 'personal_access_tokens') && str_contains($sql, 'for update')),
                "Missing presented bearer row lock for {$uri}",
            );
        }

        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseCount('security_auth_failures', 1);

        $staff->tokens()->delete();
        foreach ([
            '/api/v1/staff/security/audit-events',
            '/api/v1/staff/security/authentication-failures',
        ] as $uri) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($uri)->assertUnauthorized();
        }
    }
}
