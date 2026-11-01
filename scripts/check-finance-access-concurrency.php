<?php

declare(strict_types=1);

/**
 * Real HTTP race regression for finance access approval versus emergency revocation.
 * This script refuses to write outside the disposable GitHub Actions PostgreSQL DB.
 * It never migrates, truncates or deletes tables.
 */

use App\Models\StaffMember;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__);
$apiRoot = $root.'/services/api';
require $apiRoot.'/vendor/autoload.php';
$app = require $apiRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.connections.pgsql');
if (getenv('CI') !== 'true'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('TOPKOLIK_CONCURRENCY_TEST') !== '1'
    || app()->environment('production')
    || config('database.default') !== 'pgsql'
    || ($connection['host'] ?? null) !== '127.0.0.1'
    || ($connection['database'] ?? null) !== 'topkolik_dev'
    || ($connection['username'] ?? null) !== 'topkolik_dev'
    || PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDERR, "Refusing finance HTTP fixture outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $run = bin2hex(random_bytes(8));
    $makeStaff = static function (string $role, string $label) use ($run): StaffMember {
        $member = StaffMember::create([
            'name' => 'Finance race '.$label,
            'email' => $run.'-'.$label.'@example.test',
            'password' => Str::random(40),
            'role' => $role,
        ]);
        $member->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $member;
    };

    $requester = $makeStaff('support', 'requester');
    $approver = $makeStaff('editor', 'approver');
    $revoker = $makeStaff('support', 'revoker');
    $finance = $makeStaff('finance', 'target');
    foreach ([$requester, $approver, $revoker] as $admin) {
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->getKey(),
            'is_active' => true,
            'authorization_reference' => 'isolated-ci-finance-race',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $tokens = [];
    foreach (['requester' => $requester, 'approver' => $approver, 'revoker' => $revoker] as $key => $admin) {
        $tokens[$key] = $admin->createToken(
            'finance-race', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
    }

    // This MFA session predates activation and must be denied immediately
    // after any successful revocation, without requiring a fresh login.
    $financeToken = $finance->createToken(
        'finance-race-existing-session',
        ['staff:session', 'staff:step-up'],
        now()->addMinutes(5),
    )->plainTextToken;

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to allocate loopback port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);
    $log = tempnam(sys_get_temp_dir(), 'topkolik-finance-http-');
    if ($log === false) {
        throw new RuntimeException('Unable to allocate HTTP log.');
    }

    putenv('PHP_CLI_SERVER_WORKERS=4');
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', 'public', 'public/index.php'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
        $apiRoot,
    );
    if (! is_resource($server)) {
        throw new RuntimeException('Unable to start four-worker HTTP server.');
    }
    $base = 'http://127.0.0.1:'.$port;
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = curl_init($base.'/health');
        curl_setopt_array($probe, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
        ]);
        curl_exec($probe);
        $ready = curl_getinfo($probe, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($probe);
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Finance HTTP worker pool did not become healthy.');
    }

    /** @return list<array{code: int, json: array}> */
    $postTogether = static function (array $requests) use ($base): array {
        $multi = curl_multi_init();
        $handles = [];
        try {
            foreach ($requests as [$path, $token, $payload]) {
                $handle = curl_init($base.$path);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$token,
                        'Accept: application/json',
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 30,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            $active = null;
            $deadline = microtime(true) + 40;
            do {
                $result = curl_multi_exec($multi, $active);
                if ($result !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Finance HTTP race timed out.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $responses = [];
            foreach ($handles as $handle) {
                if (curl_error($handle) !== '') {
                    throw new RuntimeException('Finance HTTP transport failed: '.curl_error($handle));
                }
                $body = curl_multi_getcontent($handle);
                $json = json_decode($body, true);
                $responses[] = [
                    'code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'json' => is_array($json) ? $json : [],
                ];
            }

            return $responses;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    };

    $getFinanceAccess = static function () use ($base, $financeToken): int {
        $handle = curl_init($base.'/api/v1/staff/finance/access');
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$financeToken,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        try {
            curl_exec($handle);
            if (curl_errno($handle) !== 0) {
                throw new RuntimeException('Finance session HTTP check failed: '.curl_error($handle));
            }
            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
    };

    $path = '/api/v1/staff/security/finance/access-requests';
    $created = $postTogether([[$path, $tokens['requester'], [
        'target_staff_member_id' => $finance->getKey(),
    ]]]);
    if ($created[0]['code'] !== 201 || ! isset($created[0]['json']['request']['id'])) {
        throw new RuntimeException('Two-person finance request setup failed: '.json_encode($created));
    }
    $approvalId = (int) $created[0]['json']['request']['id'];
    $approvalPath = $path.'/'.$approvalId.'/approve';
    $revokePath = '/api/v1/staff/security/finance/staff/'.$finance->getKey().'/revoke';

    // Two approvers race on the same pending row while an independent
    // administrator races to revoke any entitlement that becomes active.
    // Both serial outcomes are valid: revoke-before-activation => 404,
    // activate-before-revoke => 200. No unapproved activation is valid.
    $responses = $postTogether([
        [$approvalPath, $tokens['approver'], []],
        [$approvalPath, $tokens['approver'], []],
        [$revokePath, $tokens['revoker'], []],
    ]);
    $approvalCodes = [$responses[0]['code'], $responses[1]['code']];
    sort($approvalCodes);
    $revokeCode = $responses[2]['code'];
    if ($approvalCodes !== [200, 409] || ! in_array($revokeCode, [200, 404], true)) {
        throw new RuntimeException('Finance approval/revocation race rejected expected serial results: '.json_encode($responses));
    }

    DB::disconnect();
    $decision = DB::table('staff_finance_access_requests')->where('id', $approvalId)->first();
    $entitlement = DB::table('staff_finance_entitlements')
        ->where('staff_member_id', $finance->getKey())->first();
    if (! $decision || $decision->status !== 'approved'
        || (int) $decision->reviewed_by_staff_member_id !== (int) $approver->getKey()
        || ! $entitlement || (bool) $entitlement->is_active !== ($revokeCode === 404)) {
        throw new RuntimeException('Finance race committed a non-serializable privilege state.');
    }

    $expectedAccessCode = $revokeCode === 404 ? 200 : 403;
    if ($getFinanceAccess() !== $expectedAccessCode) {
        throw new RuntimeException('Existing finance MFA session did not reflect committed revocation state.');
    }

    $events = DB::table('security_audit_events')
        ->where('subject_type', 'staff_member')
        ->where('subject_id', $finance->getKey())
        ->whereIn('action', [
            'staff.finance_access.requested',
            'staff.finance_access.approved',
            'staff.finance_access.revoked',
        ])->get(['action', 'staff_member_id', 'request_id']);
    $expected = [
        'staff.finance_access.requested' => (int) $requester->getKey(),
        'staff.finance_access.approved' => (int) $approver->getKey(),
    ];
    if ($revokeCode === 200) {
        $expected['staff.finance_access.revoked'] = (int) $revoker->getKey();
    }
    if ($events->count() !== count($expected)
        || $events->pluck('action')->unique()->count() !== count($expected)
        || $events->pluck('request_id')->filter()->unique()->count() !== count($expected)
        || $events->contains(static fn ($event): bool =>
            ! isset($expected[$event->action])
            || (int) $event->staff_member_id !== $expected[$event->action])) {
        throw new RuntimeException('Finance race audit is missing, duplicated or misattributed.');
    }

    // A rejected early revocation must not silently succeed. Explicit retry
    // after activation must revoke the entitlement, with a single audit.
    if ($revokeCode === 404) {
        $retry = $postTogether([[$revokePath, $tokens['revoker'], []]]);
        if ($retry[0]['code'] !== 200) {
            throw new RuntimeException('Explicit post-activation revocation failed: '.json_encode($retry));
        }
        DB::disconnect();
    }
    $replay = $postTogether([[$revokePath, $tokens['revoker'], []]]);
    if ($replay[0]['code'] !== 404
        || DB::table('staff_finance_entitlements')
            ->where('staff_member_id', $finance->getKey())->value('is_active') !== false
        || DB::table('security_audit_events')
            ->where('action', 'staff.finance_access.revoked')
            ->where('subject_id', $finance->getKey())->count() !== 1) {
        throw new RuntimeException('Finance revocation replay changed access or duplicated audit.');
    }

    if ($getFinanceAccess() !== 403) {
        throw new RuntimeException('Previously issued finance MFA token retained access after revocation.');
    }


    // Race two approvals against one-way staff deactivation using real workers.
    $second = $postTogether([[$path, $tokens['requester'], [
        'target_staff_member_id' => $finance->getKey(),
    ]]]);
    if ($second[0]['code'] !== 201 || ! isset($second[0]['json']['request']['id'])) {
        throw new RuntimeException('Pre-offboarding finance request setup failed: '.json_encode($second));
    }
    $secondId = (int) $second[0]['json']['request']['id'];
    $deactivatePath = '/api/v1/staff/security/finance/staff/'.$finance->getKey().'/deactivate';
    $offboarding = $postTogether([
        [$path.'/'.$secondId.'/approve', $tokens['approver'], []],
        [$path.'/'.$secondId.'/approve', $tokens['approver'], []],
        [$deactivatePath, $tokens['revoker'], []],
    ]);
    $offboardingApprovals = [$offboarding[0]['code'], $offboarding[1]['code']];
    sort($offboardingApprovals);
    if ($offboarding[2]['code'] !== 200
        || ! in_array($offboardingApprovals, [[200, 409], [409, 409]], true)) {
        throw new RuntimeException('Finance approval/offboarding race violated serial outcomes: '.json_encode($offboarding));
    }

    DB::disconnect();
    $finance->refresh();
    $secondDecision = DB::table('staff_finance_access_requests')->where('id', $secondId)->first();
    $approvedBeforeDeactivation = $offboardingApprovals === [200, 409];
    if ($finance->is_active || ! $secondDecision
        || $secondDecision->status !== ($approvedBeforeDeactivation ? 'approved' : 'pending')
        || (bool) DB::table('staff_finance_entitlements')
            ->where('staff_member_id', $finance->getKey())->value('is_active')
        || DB::table('personal_access_tokens')
            ->where('tokenable_type', $finance->getMorphClass())
            ->where('tokenable_id', $finance->getKey())->exists()
        || DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $finance->getKey())->where('is_active', true)->exists()
        || DB::table('staff_advertiser_assignments')
            ->where('staff_member_id', $finance->getKey())->where('is_active', true)->exists()) {
        throw new RuntimeException('Finance offboarding race left an active account, entitlement or token.');
    }

    $offboardingEvents = DB::table('security_audit_events')
        ->where('subject_type', 'staff_member')
        ->where('subject_id', $finance->getKey())
        ->whereIn('action', [
            'staff.finance_access.requested',
            'staff.finance_access.approved',
            'staff.finance_access.revoked',
            'staff.finance_access.staff_deactivated',
        ])->get(['action', 'staff_member_id', 'request_id']);
    $expectedCounts = [
        'staff.finance_access.requested' => 2,
        'staff.finance_access.approved' => $approvedBeforeDeactivation ? 2 : 1,
        'staff.finance_access.revoked' => 1,
        'staff.finance_access.staff_deactivated' => 1,
    ];
    $actualCounts = $offboardingEvents->countBy('action')->all();
    // PostgreSQL does not guarantee audit event arrival order across workers.
    // Compare counts by action, not associative insertion order.
    ksort($actualCounts);
    ksort($expectedCounts);
    if ($actualCounts !== $expectedCounts
        || $offboardingEvents->pluck('request_id')->filter()->unique()->count() !== $offboardingEvents->count()
        || $offboardingEvents->contains(static fn ($event): bool =>
            (int) $event->staff_member_id !== (
                $event->action === 'staff.finance_access.requested' ? (int) $requester->getKey()
                : ($event->action === 'staff.finance_access.approved' ? (int) $approver->getKey()
                : (int) $revoker->getKey())
            ))) {
        throw new RuntimeException('Finance offboarding race audit was duplicated or misattributed: '.json_encode($actualCounts));
    }

    $deactivationReplay = $postTogether([[$deactivatePath, $tokens['revoker'], []]]);
    if ($deactivationReplay[0]['code'] !== 404
        || DB::table('security_audit_events')
            ->where('action', 'staff.finance_access.staff_deactivated')
            ->where('subject_id', $finance->getKey())->count() !== 1
        || $getFinanceAccess() !== 401) {
        throw new RuntimeException('Offboarding replay or stale finance session was not denied.');
    }

    echo "PASS: four-worker finance approval/revocation and approval/offboarding races, stale MFA denial, audit and replay.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: finance HTTP concurrency: '.$exception->getMessage()."\n");
    $exit = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_string($log) && is_file($log)) {
        unlink($log);
    }
}

exit($exit);
