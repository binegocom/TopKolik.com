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

    echo "PASS: five concurrent HTTP requests, four distinct escalations, one duplicate denied, balanced independent reviewers and four atomic audit records.\n";
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
