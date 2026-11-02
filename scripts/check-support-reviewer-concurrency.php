<?php

declare(strict_types=1);

/**
 * Real multi-worker HTTP regression for independent support reviewer dispatch.
 *
 * Runs only in GitHub Actions against the disposable local development DB.
 * No migrations, table resets, production writes, or synthetic sports data.
 * The append-only audit ledger is intentionally not deleted after the test.
 */

use App\Models\StaffMember;
use App\Models\SupportTicket;
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
    fwrite(STDERR, "Refusing concurrency fixture outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $run = bin2hex(random_bytes(8));
    $staff = [];
    foreach (['assignee', 'reviewer-a', 'reviewer-b'] as $role) {
        $member = StaffMember::create([
            'name' => 'Concurrency '.$role,
            'email' => $run.'-'.$role.'@example.test',
            'password' => Str::random(40),
            'role' => 'support',
        ]);
        $member->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $staff[$role] = $member;
    }

    $token = $staff['assignee']->createToken(
        'concurrency-regression',
        ['staff:session', 'staff:step-up'],
        now()->addMinutes(5),
    )->plainTextToken;

    $ticketIds = [];
    for ($i = 0; $i < 4; $i++) {
        $ticketIds[] = (int) SupportTicket::create([
            'assigned_staff_id' => $staff['assignee']->getKey(),
            'subject' => 'Concurrency regression '.$run.' '.$i,
            'message' => 'Isolated HTTP transaction regression',
            'status' => 'open',
        ])->getKey();
    }

    // Reserve a loopback port without assuming another test's fixed port.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to allocate a local HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-http-');
    if ($log === false) {
        throw new RuntimeException('Unable to create an HTTP test log.');
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
        throw new RuntimeException('Unable to start multi-worker Laravel HTTP server.');
    }

    $base = 'http://127.0.0.1:'.$port;
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
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
        throw new RuntimeException('Multi-worker Laravel HTTP server did not become healthy.');
    }

    // Five requests are dispatched concurrently, below the route's 6/min
    // throttle. Two compete for the same ticket; only one may escalate it.
    $targets = [$ticketIds[0], $ticketIds[1], $ticketIds[2], $ticketIds[3], $ticketIds[0]];
    $multi = curl_multi_init();
    $handles = [];
    foreach ($targets as $index => $id) {
        $handle = curl_init($base.'/api/v1/staff/support/tickets/'.$id.'/escalate');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
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
        $handles[$index] = $handle;
    }

    $active = null;
    $deadline = microtime(true) + 40;
    do {
        $result = curl_multi_exec($multi, $active);
        if ($result !== CURLM_OK || microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent HTTP requests did not finish successfully.');
        }
        if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
            usleep(10000);
        }
    } while ($active > 0);

    $responses = [];
    foreach ($handles as $index => $handle) {
        $error = curl_error($handle);
        $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($error !== '') {
            throw new RuntimeException('Concurrent HTTP transport failed: '.$error);
        }
        $responses[$targets[$index]][] = $code;
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    foreach ($ticketIds as $index => $id) {
        $codes = $responses[$id] ?? [];
        sort($codes);
        if ($index === 0) {
            if ($codes !== [200, 403] && $codes !== [200, 409]) {
                throw new RuntimeException('Duplicate escalation was not rejected exactly once: '.json_encode($codes));
            }
        } elseif ($codes !== [200]) {
            throw new RuntimeException('A distinct concurrent escalation failed: '.json_encode($codes));
        }
    }

    DB::disconnect();
    $tickets = SupportTicket::query()->whereIn('id', $ticketIds)->get();
    if ($tickets->count() !== 4
        || $tickets->contains(fn (SupportTicket $ticket): bool => $ticket->status !== 'pending'
            || $ticket->escalated_at === null
            || (int) $ticket->escalated_by_staff_id !== (int) $staff['assignee']->getKey())) {
        throw new RuntimeException('Concurrent HTTP mutations left an invalid ticket state.');
    }

    $counts = $tickets->countBy('escalation_reviewer_staff_id');
    $reviewerA = (int) $staff['reviewer-a']->getKey();
    $reviewerB = (int) $staff['reviewer-b']->getKey();
    if ($counts->count() !== 2 || $counts->get($reviewerA) !== 2 || $counts->get($reviewerB) !== 2) {
        throw new RuntimeException('Concurrent reviewer assignment violated least-loaded balancing.');
    }

    $audit = DB::table('security_audit_events')
        ->where('action', 'support.ticket.escalated')
        ->where('subject_type', 'support_ticket')
        ->whereIn('subject_id', $ticketIds)
        ->get();
    if ($audit->count() !== 4
        || $audit->pluck('subject_id')->unique()->count() !== 4
        || $audit->contains(fn ($event): bool => (int) $event->staff_member_id !== (int) $staff['assignee']->getKey())) {
        throw new RuntimeException('Concurrent escalation did not commit exactly one audit event per ticket.');
    }


    // Exercise real HTTP races across two distinct state transitions. The
    // same-ticket loser must not mutate the ticket or append another audit.
    $parallelPosts = static function (array $requests) use ($base): array {
        $multi = curl_multi_init();
        $handles = [];
        try {
            foreach ($requests as $spec) {
                [$path, $bearer] = $spec;
                $payload = $spec[2] ?? new stdClass();
                $handle = curl_init($base.$path);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$bearer,
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
                    throw new RuntimeException('Reviewer transition HTTP requests timed out.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $codes = [];
            foreach ($handles as $handle) {
                if (curl_error($handle) !== '') {
                    throw new RuntimeException('Reviewer transition HTTP transport failed: '.curl_error($handle));
                }
                $codes[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            }
            sort($codes);

            return $codes;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    };

    $makeStaff = static function (string $role, string $label) use ($run): StaffMember {
        $member = StaffMember::create([
            'name' => 'Concurrency '.$label,
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

    // Isolate a ticket with exactly one eligible independent reviewer. The
    // previous fixture remains intact but cannot receive new assignments.
    foreach ($staff as $member) {
        $member->forceFill(['is_active' => false])->save();
    }
    $caseAssignee = $makeStaff('support', 'transition-assignee');
    $caseReviewer = $makeStaff('support', 'transition-reviewer');
    $caseTicket = SupportTicket::create([
        'assigned_staff_id' => $caseAssignee->getKey(),
        'subject' => 'Transition race '.$run,
        'message' => 'Isolated transition race regression',
        'status' => 'open',
    ]);
    $caseId = (int) $caseTicket->getKey();
    $assigneeToken = $caseAssignee->createToken(
        'transition', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;
    $escalatePath = '/api/v1/staff/support/tickets/'.$caseId.'/escalate';
    if ($parallelPosts([[$escalatePath, $assigneeToken]]) !== [200]
        || (int) $caseTicket->fresh()->escalation_reviewer_staff_id !== (int) $caseReviewer->getKey()) {
        throw new RuntimeException('Transition race setup did not assign the sole independent reviewer.');
    }

    $reviewerToken = $caseReviewer->createToken(
        'transition', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;
    $relinquishPath = '/api/v1/staff/support/escalations/'.$caseId.'/relinquish';
    $relinquishCodes = $parallelPosts([
        [$relinquishPath, $reviewerToken],
        [$relinquishPath, $reviewerToken],
    ]);
    $held = $caseTicket->fresh();
    if ($relinquishCodes !== [200, 404] || $held->status !== 'pending'
        || $held->escalation_reviewer_staff_id !== null || $held->escalation_reviewed_at !== null) {
        throw new RuntimeException(
            'Concurrent reviewer relinquishment did not preserve a single held transition: '.json_encode($relinquishCodes),
        );
    }

    // Recovery must select a *different* currently eligible reviewer. The
    // former reviewer is made ineligible before the concurrent admin requests.
    $caseReviewer->forceFill(['is_active' => false])->save();
    $admin = $makeStaff('finance', 'transition-admin');
    DB::table('staff_security_admin_grants')->insert([
        'staff_member_id' => $admin->getKey(),
        'is_active' => true,
        'authorization_reference' => 'isolated-ci-security-operator',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $replacement = $makeStaff('support', 'transition-replacement');
    $adminToken = $admin->createToken(
        'transition', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
    )->plainTextToken;
    $recoverPath = '/api/v1/staff/security/support/escalations/'.$caseId.'/recover-reviewer';
    $recoveryCodes = $parallelPosts([
        [$recoverPath, $adminToken],
        [$recoverPath, $adminToken],
    ]);
    $recovered = $caseTicket->fresh();
    if ($recoveryCodes !== [200, 404] || $recovered->status !== 'pending'
        || (int) $recovered->escalation_reviewer_staff_id !== (int) $replacement->getKey()
        || $recovered->escalation_reviewed_at !== null) {
        throw new RuntimeException(
            'Concurrent held-reviewer recovery did not commit exactly once: '.json_encode($recoveryCodes),
        );
    }

    $transitionAudit = DB::table('security_audit_events')
        ->where('subject_type', 'support_ticket')
        ->where('subject_id', $caseId)
        ->get(['action', 'staff_member_id', 'request_id']);
    $expectedActors = [
        'support.ticket.escalated' => (int) $caseAssignee->getKey(),
        'support.ticket.escalation_reviewer_relinquished' => (int) $caseReviewer->getKey(),
        'support.ticket.escalation_reviewer_recovered' => (int) $admin->getKey(),
    ];
    if ($transitionAudit->count() !== 3
        || $transitionAudit->pluck('action')->unique()->count() !== 3
        || $transitionAudit->pluck('request_id')->filter()->unique()->count() !== 3
        || $transitionAudit->contains(static fn ($event): bool =>
            ! isset($expectedActors[$event->action])
            || (int) $event->staff_member_id !== $expectedActors[$event->action])) {
        throw new RuntimeException('Concurrent reviewer transitions violated audit atomicity or actor attribution.');
    }

    // The same staff bearer is used for a real four-worker HTTP logout/write
    // race. A mutation may serialize before logout or fail after logout, but
    // a revoked bearer may never commit a later write or duplicate audit.
    foreach (['reply', 'escalate', 'resolve'] as $action) {
        $operator = $makeStaff('support', 'logout-'.$action);
        $initialState = $action === 'resolve' ? 'pending' : 'open';
        $raceTicket = SupportTicket::create([
            'assigned_staff_id' => $operator->getKey(),
            'subject' => 'Logout race '.$run.' '.$action,
            'message' => 'Isolated session revocation regression',
            'status' => $initialState,
        ]);
        $raceId = (int) $raceTicket->getKey();
        $bearer = $operator->createToken(
            'logout-race', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/support/tickets/'.$raceId;
        $payload = match ($action) {
            'reply' => ['idempotency_key' => (string) Str::uuid(), 'message' => 'Atomic support reply.'],
            'resolve' => ['resolution_note' => 'Atomic support resolution.'],
            default => new stdClass(),
        };
        $mutationPath = match ($action) {
            'reply' => $path.'/replies',
            'escalate' => $path.'/escalate',
            'resolve' => $path.'/resolve',
        };
        $codes = $parallelPosts([
            ['/api/v1/staff/auth/logout', $bearer],
            [$mutationPath, $bearer, $payload],
        ]);
        $success = $action === 'reply' ? 201 : 200;
        if ($codes !== [200, $success] && $codes !== [200, 401]) {
            throw new RuntimeException('Logout/'.$action.' HTTP race returned invalid outcomes: '.json_encode($codes));
        }

        $mutationSucceeded = $codes === [200, $success];
        DB::disconnect();
        $fresh = $raceTicket->fresh();
        $expectedState = $mutationSucceeded
            ? match ($action) {
                'reply', 'escalate' => 'pending',
                'resolve' => 'resolved',
            }
            : $initialState;
        if ($fresh->status !== $expectedState
            || ($action === 'escalate' && ($fresh->escalated_at !== null) !== $mutationSucceeded)
            || ($action === 'resolve' && ($fresh->resolved_at !== null) !== $mutationSucceeded)) {
            throw new RuntimeException('Logout/'.$action.' race left inconsistent ticket state.');
        }
        $replyCount = DB::table('support_ticket_replies')
            ->where('support_ticket_id', $raceId)->count();
        if ($replyCount !== (int) ($mutationSucceeded && $action === 'reply')) {
            throw new RuntimeException('Logout/'.$action.' race wrote unexpected support replies.');
        }

        $events = DB::table('security_audit_events')
            ->where('staff_member_id', $operator->getKey())
            ->whereIn('action', ['staff.session.ended', 'support.ticket.replied',
                'support.ticket.escalated', 'support.ticket.resolved'])
            ->get(['action', 'subject_id', 'request_id']);
        $actionName = 'support.ticket.'.match ($action) {
            'reply' => 'replied',
            'escalate' => 'escalated',
            'resolve' => 'resolved',
        };
        if ($events->count() !== ($mutationSucceeded ? 2 : 1)
            || $events->where('action', 'staff.session.ended')->count() !== 1
            || $events->where('action', $actionName)->count() !== (int) $mutationSucceeded
            || $events->pluck('request_id')->filter()->unique()->count() !== $events->count()
            || ($mutationSucceeded && (int) $events->firstWhere('action', $actionName)->subject_id !== $raceId)) {
            throw new RuntimeException('Logout/'.$action.' race violated audit atomicity or attribution.');
        }

        if ($parallelPosts([[$mutationPath, $bearer, $payload]]) !== [401]) {
            throw new RuntimeException('Revoked support bearer remained usable after logout/'.$action.'.');
        }
    }

    // Prove role revocation wins against a request already waiting on the
    // persisted staff row lock. A sequential 403 check alone cannot establish
    // that a real HTTP worker re-authorizes after the lock is released.
    foreach (['reply', 'escalate', 'resolve'] as $action) {
        $operator = $makeStaff('support', 'role-revocation-'.$action);
        $initialState = $action === 'resolve' ? 'pending' : 'open';
        $raceTicket = SupportTicket::create([
            'assigned_staff_id' => $operator->getKey(),
            'subject' => 'Role revocation race '.$run.' '.$action,
            'message' => 'Isolated authorization race regression',
            'status' => $initialState,
        ]);
        $raceId = (int) $raceTicket->getKey();
        $bearer = $operator->createToken(
            'role-revocation-race', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/support/tickets/'.$raceId;
        $payload = match ($action) {
            'reply' => ['idempotency_key' => (string) Str::uuid(), 'message' => 'Must not persist.'],
            'resolve' => ['resolution_note' => 'Must not persist.'],
            default => new stdClass(),
        };
        $mutationPath = match ($action) {
            'reply' => $path.'/replies',
            'escalate' => $path.'/escalate',
            'resolve' => $path.'/resolve',
        };

        $multi = curl_multi_init();
        $handle = curl_init($base.$mutationPath);
        try {
            // Simulate an authorized operator's role being revoked by a
            // separate administrative transaction while a real worker waits.
            DB::beginTransaction();
            StaffMember::query()->whereKey($operator->getKey())
                ->lockForUpdate()->firstOrFail();

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $handle);

            $waiting = false;
            $active = null;
            $deadline = microtime(true) + 10;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK) {
                    throw new RuntimeException('Role-revocation HTTP transport failed to start.');
                }
                // PostgreSQL reports the other connection waiting for our
                // locked staff row; this is a verified overlap, not a sleep
                // based guess about scheduling.
                // PostgreSQL caches statistics snapshots inside a transaction.
                // Clear that snapshot before polling other backends, otherwise
                // the first empty result can remain stale for the entire lock.
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne(
                    "SELECT count(*) AS waiting FROM pg_stat_activity ".
                    "WHERE pid <> pg_backend_pid() AND wait_event_type = 'Lock' ".
                    "AND query ILIKE '%staff_members%' AND query ILIKE '%for update%'"
                )->waiting > 0;
                if (! $waiting && $active > 0) {
                    // Drive libcurl's socket readiness as well as its state
                    // machine; without select the request can remain queued.
                    if (curl_multi_select($multi, 0.1) === -1) {
                        usleep(10000);
                    }
                }
            } while (! $waiting && $active > 0 && microtime(true) < $deadline);

            if (! $waiting) {
                $activity = DB::select(
                    "SELECT pid, state, wait_event_type, wait_event, left(query, 180) AS statement ".
                    "FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND datname = current_database()"
                );
                throw new RuntimeException(
                    'HTTP worker never contended for the locked support role; action='.$action.
                    ', active='.$active.', HTTP='.curl_getinfo($handle, CURLINFO_RESPONSE_CODE).
                    ', curl='.curl_error($handle).', response='.substr((string) curl_multi_getcontent($handle), 0, 200).
                    ', activity='.json_encode($activity, JSON_THROW_ON_ERROR).
                    ', server_log='.substr((string) @file_get_contents($log), -4000),
                );
            }

            StaffMember::query()->whereKey($operator->getKey())->update(['role' => 'editor']);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Role-revocation HTTP request did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($handle) !== '' || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403) {
                throw new RuntimeException(
                    'Revoked support role performed '.$action.' after lock release: HTTP '.
                    curl_getinfo($handle, CURLINFO_RESPONSE_CODE).' '.curl_error($handle),
                );
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            curl_multi_close($multi);
        }

        DB::disconnect();
        $fresh = $raceTicket->fresh();
        if ($operator->fresh()->role !== 'editor' || $fresh->status !== $initialState
            || $fresh->escalated_at !== null || $fresh->resolved_at !== null
            || DB::table('support_ticket_replies')->where('support_ticket_id', $raceId)->exists()
            || DB::table('security_audit_events')->where('staff_member_id', $operator->getKey())->exists()) {
            throw new RuntimeException('Revoked role '.$action.' race changed ticket, reply or audit state.');
        }
        if ($parallelPosts([[$mutationPath, $bearer, $payload]]) !== [403]) {
            throw new RuntimeException('Previously authorized bearer regained '.$action.' access.');
        }
    }

    // The independent escalation reviewer must re-authorize after a real
    // concurrent role revocation, not merely after a sequential role change.
    // Both approval and relinquishment use the same staff -> bearer -> ticket
    // lock order as support writes and logout.
    foreach (['approve', 'relinquish'] as $action) {
        $assignee = $makeStaff('support', 'reviewer-race-assignee-'.$action);
        $reviewer = $makeStaff('support', 'reviewer-race-actor-'.$action);
        $raceTicket = SupportTicket::create([
            'assigned_staff_id' => $assignee->getKey(),
            'subject' => 'Reviewer revocation race '.$run.' '.$action,
            'message' => 'Isolated reviewer authorization regression',
            'status' => 'pending',
            'escalated_at' => now(),
            'escalated_by_staff_id' => $assignee->getKey(),
            'escalation_reviewer_staff_id' => $reviewer->getKey(),
        ]);
        $raceId = (int) $raceTicket->getKey();
        $bearer = $reviewer->createToken(
            'reviewer-role-revocation', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;
        $mutationPath = '/api/v1/staff/support/escalations/'.$raceId.'/'
            .($action === 'approve' ? 'approve' : 'relinquish');
        $payload = $action === 'approve'
            ? ['review_note' => 'Must not persist after revocation.']
            : new stdClass();

        $multi = curl_multi_init();
        $handle = curl_init($base.$mutationPath);
        try {
            DB::beginTransaction();
            StaffMember::query()->whereKey($reviewer->getKey())
                ->lockForUpdate()->firstOrFail();

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $handle);

            $waiting = false;
            $active = null;
            $deadline = microtime(true) + 10;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK) {
                    throw new RuntimeException('Reviewer revocation HTTP transport failed to start.');
                }
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne(
                    "SELECT count(*) AS waiting FROM pg_stat_activity ".
                    "WHERE pid <> pg_backend_pid() AND wait_event_type = 'Lock' ".
                    "AND query ILIKE '%staff_members%' AND query ILIKE '%for update%'"
                )->waiting > 0;
                if (! $waiting && $active > 0 && curl_multi_select($multi, 0.1) === -1) {
                    usleep(10000);
                }
            } while (! $waiting && $active > 0 && microtime(true) < $deadline);

            if (! $waiting) {
                throw new RuntimeException(
                    'Reviewer '.$action.' HTTP worker did not wait for staff lock: '.
                    'HTTP='.curl_getinfo($handle, CURLINFO_RESPONSE_CODE).
                    ', response='.substr((string) curl_multi_getcontent($handle), 0, 200).
                    ', log='.substr((string) @file_get_contents($log), -2000),
                );
            }

            StaffMember::query()->whereKey($reviewer->getKey())->update(['role' => 'editor']);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Reviewer '.$action.' HTTP request timed out.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($handle) !== '' || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403) {
                throw new RuntimeException(
                    'Revoked reviewer performed '.$action.' after lock release: HTTP '.
                    curl_getinfo($handle, CURLINFO_RESPONSE_CODE).' '.curl_error($handle).
                    ', response='.substr((string) curl_multi_getcontent($handle), 0, 500).
                    ', ticket='.json_encode(DB::table('support_tickets')->where('id', $raceId)->first(),
                        JSON_THROW_ON_ERROR).
                    ', server_log='.substr((string) @file_get_contents($log), -3000),
                );
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            curl_multi_close($multi);
        }

        DB::disconnect();
        $fresh = $raceTicket->fresh();
        if ($reviewer->fresh()->role !== 'editor'
            || $fresh->status !== 'pending'
            || (int) $fresh->escalation_reviewer_staff_id !== (int) $reviewer->getKey()
            || $fresh->escalation_reviewed_at !== null
            || $fresh->escalation_reviewed_by_staff_id !== null
            || $fresh->escalation_review_note !== null
            || DB::table('security_audit_events')->where('subject_type', 'support_ticket')
                ->where('subject_id', $raceId)->exists()) {
            throw new RuntimeException('Revoked reviewer '.$action.' changed ticket or audit state.');
        }
        if ($parallelPosts([[$mutationPath, $bearer, $payload]]) !== [403]) {
            throw new RuntimeException('Revoked reviewer regained '.$action.' permission.');
        }
    }

    echo "PASS: real HTTP reviewer approval/relinquishment blocked on staff locks and denied after role revocation.\n";
    echo "PASS: real HTTP workers block on support staff row locks, then reject reply/escalate/resolve after committed role revocation.\n";
    echo "PASS: support logout versus reply, escalation and resolution races; token revocation, audit and state atomicity.\n";
    echo "PASS: escalation, relinquishment and recovery HTTP races; single commits, held-state safety, independent reassignment and atomic audit.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: support reviewer HTTP concurrency: '.$exception->getMessage()."\n");
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
