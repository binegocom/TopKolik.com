<?php

declare(strict_types=1);

/**
 * Four-worker HTTP race: advertiser assignment approval vs requester grant revocation.
 * This test creates fixtures only in the disposable GitHub Actions database.
 * It does not migrate, truncate or delete any database tables.
 */

use App\Models\AdvertiserOrganization;
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
    fwrite(STDERR, "Refusing ads approval race outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $run = bin2hex(random_bytes(8));
    $staff = static function (string $role, string $label) use ($run): StaffMember {
        $member = StaffMember::create([
            'name' => 'Ads race '.$label,
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

    $requester = $staff('support', 'requester');
    $approver = $staff('editor', 'approver');
    $revoker = $staff('finance', 'revoker');
    $target = $staff('ads', 'target');
    $organization = AdvertiserOrganization::create(['name' => 'Ads race '.$run]);
    $organization->forceFill(['is_active' => true])->save();

    foreach ([$requester, $approver, $revoker] as $admin) {
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->getKey(),
            'is_active' => true,
            'authorization_reference' => 'isolated-ci-ads-approval-race',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $tokens = [];
    foreach (['requester' => $requester, 'approver' => $approver, 'revoker' => $revoker] as $key => $admin) {
        $tokens[$key] = $admin->createToken(
            'ads-race', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
    }
    $targetToken = $target->createToken(
        'ads-race-target', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to allocate loopback port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);
    $log = tempnam(sys_get_temp_dir(), 'topkolik-ads-race-');
    if ($log === false) {
        throw new RuntimeException('Unable to allocate HTTP log.');
    }

    putenv('PHP_CLI_SERVER_WORKERS=4');
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', 'public', 'public/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
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
        throw new RuntimeException('Ads HTTP worker pool did not become healthy.');
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
                    throw new RuntimeException('Ads HTTP race timed out.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $responses = [];
            foreach ($handles as $handle) {
                if (curl_error($handle) !== '') {
                    throw new RuntimeException('Ads HTTP transport failed: '.curl_error($handle));
                }
                $json = json_decode(curl_multi_getcontent($handle), true);
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

    $path = '/api/v1/staff/security/ads/assignment-approvals';
    $created = $postTogether([[$path, $tokens['requester'], [
        'target_staff_member_id' => $target->getKey(),
        'advertiser_organization_id' => $organization->getKey(),
    ]]]);
    if ($created[0]['code'] !== 201 || ! isset($created[0]['json']['request']['id'])) {
        throw new RuntimeException('Ads assignment request setup failed: '.json_encode($created));
    }
    $approvalId = (int) $created[0]['json']['request']['id'];
    $approvePath = $path.'/'.$approvalId.'/approve';
    $revokePath = '/api/v1/staff/security/admin-grants/'.$requester->getKey().'/revoke';

    // Approval must serialize with revocation of the ORIGINAL requester.
    // If revocation commits first, both approvals fail closed; otherwise
    // exactly one approval may commit before the revocation.
    $race = $postTogether([
        [$approvePath, $tokens['approver'], []],
        [$approvePath, $tokens['approver'], []],
        [$revokePath, $tokens['revoker'], []],
    ]);
    $approvalCodes = [$race[0]['code'], $race[1]['code']];
    sort($approvalCodes);
    $approved = $approvalCodes === [200, 409];
    if ($race[2]['code'] !== 200
        || (! $approved && $approvalCodes !== [409, 409])) {
        throw new RuntimeException('Ads approval/revocation race returned invalid outcomes: '.json_encode($race));
    }

    DB::disconnect();
    $decision = DB::table('staff_ads_assignment_approvals')->where('id', $approvalId)->first();
    $activeAssignment = DB::table('staff_advertiser_assignments')
        ->where('staff_member_id', $target->getKey())
        ->where('advertiser_organization_id', $organization->getKey())
        ->where('is_active', true)->exists();
    $activeRequesterGrant = DB::table('staff_security_admin_grants')
        ->where('staff_member_id', $requester->getKey())
        ->where('is_active', true)->exists();
    if (! $decision || $decision->status !== ($approved ? 'approved' : 'pending')
        || $activeAssignment !== $approved || $activeRequesterGrant
        || ($approved && (int) $decision->reviewed_by_staff_member_id !== (int) $approver->getKey())) {
        throw new RuntimeException('Ads race persisted inconsistent approval or privilege state.');
    }

    $events = DB::table('security_audit_events')
        ->where(function ($query) use ($organization, $requester): void {
            $query->where(function ($inner) use ($organization): void {
                $inner->where('subject_type', 'advertiser_organization')
                    ->where('subject_id', $organization->getKey());
            })->orWhere(function ($inner) use ($requester): void {
                $inner->where('subject_type', 'staff_member')
                    ->where('subject_id', $requester->getKey());
            });
        })
        ->whereIn('action', [
            'staff.advertiser_assignment.requested',
            'staff.advertiser_assignment.approved',
            'staff.security_admin_grant.revoked',
        ])->get(['action', 'staff_member_id', 'request_id']);
    $expected = [
        'staff.advertiser_assignment.requested' => (int) $requester->getKey(),
        'staff.security_admin_grant.revoked' => (int) $revoker->getKey(),
    ];
    if ($approved) {
        $expected['staff.advertiser_assignment.approved'] = (int) $approver->getKey();
    }
    if ($events->count() !== count($expected)
        || $events->pluck('action')->unique()->count() !== count($expected)
        || $events->pluck('request_id')->filter()->unique()->count() !== count($expected)
        || $events->contains(static fn ($event): bool =>
            ! isset($expected[$event->action])
            || (int) $event->staff_member_id !== $expected[$event->action])) {
        throw new RuntimeException('Ads race audit missing, duplicated or misattributed.');
    }

    $replay = $postTogether([
        [$approvePath, $tokens['approver'], []],
        [$revokePath, $tokens['revoker'], []],
    ]);
    if ($replay[0]['code'] !== 409 || $replay[1]['code'] !== 404) {
        throw new RuntimeException('Ads approval or grant revocation replay changed state: '.json_encode($replay));
    }

    $handle = curl_init($base.'/api/v1/staff/ads/organizations/'.$organization->getKey().'/campaigns');
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$targetToken, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    try {
        curl_exec($handle);
        if (curl_errno($handle) !== 0
            || (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== ($approved ? 200 : 404)) {
            throw new RuntimeException('Ads target session did not reflect the committed assignment.');
        }
    } finally {
        curl_close($handle);
    }

    // A separate pending request races with revocation of the ACTING approver.
    // A revoked approver cannot use a stale MFA token to approve or reject.
    $secondRequester = $staff('support', 'second-requester');
    $secondApprover = $staff('editor', 'second-approver');
    $secondTarget = $staff('ads', 'second-target');
    $secondOrganization = AdvertiserOrganization::create(['name' => 'Ads approver race '.$run]);
    $secondOrganization->forceFill(['is_active' => true])->save();

    foreach ([$secondRequester, $secondApprover] as $admin) {
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->getKey(),
            'is_active' => true,
            'authorization_reference' => 'isolated-ci-ads-approver-race',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $secondRequesterToken = $secondRequester->createToken(
        'ads-approver-race-requester', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;
    $secondApproverToken = $secondApprover->createToken(
        'ads-approver-race-approver', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;
    $secondTargetToken = $secondTarget->createToken(
        'ads-approver-race-target', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;

    $secondCreated = $postTogether([[$path, $secondRequesterToken, [
        'target_staff_member_id' => $secondTarget->getKey(),
        'advertiser_organization_id' => $secondOrganization->getKey(),
    ]]]);
    if ($secondCreated[0]['code'] !== 201 || ! isset($secondCreated[0]['json']['request']['id'])) {
        throw new RuntimeException('Approver revocation race setup failed: '.json_encode($secondCreated));
    }
    $secondApprovalId = (int) $secondCreated[0]['json']['request']['id'];
    $secondApprovePath = $path.'/'.$secondApprovalId.'/approve';
    $secondRevokePath = '/api/v1/staff/security/admin-grants/'.$secondApprover->getKey().'/revoke';

    // Revocation first: both approvals must return 403. Approval first:
    // exactly one 200, with the other request rejected by 403 or 409.
    $secondRace = $postTogether([
        [$secondApprovePath, $secondApproverToken, []],
        [$secondApprovePath, $secondApproverToken, []],
        [$secondRevokePath, $tokens['revoker'], []],
    ]);
    $secondCodes = [$secondRace[0]['code'], $secondRace[1]['code']];
    sort($secondCodes);
    $secondApproved = in_array(200, $secondCodes, true);
    if ($secondRace[2]['code'] !== 200
        || ($secondApproved && $secondCodes !== [200, 403] && $secondCodes !== [200, 409])
        || (! $secondApproved && $secondCodes !== [403, 403])) {
        throw new RuntimeException('Approver revocation race returned invalid outcomes: '.json_encode($secondRace));
    }

    DB::disconnect();
    $secondDecision = DB::table('staff_ads_assignment_approvals')
        ->where('id', $secondApprovalId)->first();
    $secondAssignment = DB::table('staff_advertiser_assignments')
        ->where('staff_member_id', $secondTarget->getKey())
        ->where('advertiser_organization_id', $secondOrganization->getKey())
        ->where('is_active', true)->exists();
    $secondApproverGrant = DB::table('staff_security_admin_grants')
        ->where('staff_member_id', $secondApprover->getKey())
        ->where('is_active', true)->exists();
    if (! $secondDecision || $secondDecision->status !== ($secondApproved ? 'approved' : 'pending')
        || $secondAssignment !== $secondApproved || $secondApproverGrant
        || ($secondApproved && (int) $secondDecision->reviewed_by_staff_member_id !== (int) $secondApprover->getKey())) {
        throw new RuntimeException('Approver race persisted inconsistent assignment or grant state.');
    }

    $secondEvents = DB::table('security_audit_events')
        ->where(function ($query) use ($secondOrganization, $secondApprover): void {
            $query->where(function ($inner) use ($secondOrganization): void {
                $inner->where('subject_type', 'advertiser_organization')
                    ->where('subject_id', $secondOrganization->getKey());
            })->orWhere(function ($inner) use ($secondApprover): void {
                $inner->where('subject_type', 'staff_member')
                    ->where('subject_id', $secondApprover->getKey());
            });
        })
        ->whereIn('action', [
            'staff.advertiser_assignment.requested',
            'staff.advertiser_assignment.approved',
            'staff.security_admin_grant.revoked',
        ])->get(['action', 'staff_member_id', 'request_id']);
    $secondExpected = [
        'staff.advertiser_assignment.requested' => (int) $secondRequester->getKey(),
        'staff.security_admin_grant.revoked' => (int) $revoker->getKey(),
    ];
    if ($secondApproved) {
        $secondExpected['staff.advertiser_assignment.approved'] = (int) $secondApprover->getKey();
    }
    if ($secondEvents->count() !== count($secondExpected)
        || $secondEvents->pluck('action')->unique()->count() !== count($secondExpected)
        || $secondEvents->pluck('request_id')->filter()->unique()->count() !== count($secondExpected)
        || $secondEvents->contains(static fn ($event): bool =>
            ! isset($secondExpected[$event->action])
            || (int) $event->staff_member_id !== $secondExpected[$event->action])) {
        throw new RuntimeException('Approver race audit missing, duplicated or misattributed.');
    }

    $secondReplay = $postTogether([
        [$secondApprovePath, $secondApproverToken, []],
        [$path.'/'.$secondApprovalId.'/reject', $secondApproverToken, []],
        [$secondRevokePath, $tokens['revoker'], []],
    ]);
    if ($secondReplay[0]['code'] !== 403 || $secondReplay[1]['code'] !== 403
        || $secondReplay[2]['code'] !== 404) {
        throw new RuntimeException('Revoked approver or grant replay unexpectedly succeeded: '.json_encode($secondReplay));
    }

    $secondHandle = curl_init($base.'/api/v1/staff/ads/organizations/'.$secondOrganization->getKey().'/campaigns');
    curl_setopt_array($secondHandle, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$secondTargetToken, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    try {
        curl_exec($secondHandle);
        if (curl_errno($secondHandle) !== 0
            || (int) curl_getinfo($secondHandle, CURLINFO_RESPONSE_CODE) !== ($secondApproved ? 200 : 404)) {
            throw new RuntimeException('Ads target session did not reflect approver race outcome.');
        }
    } finally {
        curl_close($secondHandle);
    }

    fwrite(STDOUT, "Ads approval/requester and approver revocation four-worker HTTP races passed.\n");
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, $error->getMessage()."\n");
    if ($log !== null && is_file($log)) {
        fwrite(STDERR, substr((string) file_get_contents($log), -5000)."\n");
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($log !== null && is_file($log)) {
        unlink($log);
    }
}

exit($exit);
