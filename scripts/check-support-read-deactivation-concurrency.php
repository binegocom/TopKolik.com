<?php

declare(strict_types=1);

/**
 * Four-worker HTTP regression for support ticket reads versus staff deactivation and role revocation.
 * Runs only on the isolated GitHub Actions PostgreSQL development database.
 */

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

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
    fwrite(STDERR, "Refusing support read concurrency outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve an HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-support-read-');
    if ($log === false) {
        throw new RuntimeException('Unable to create HTTP server log.');
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
        throw new RuntimeException('Four-worker HTTP server did not become healthy.');
    }

    $read = static function (string $path, string $bearer) use ($base): array {
        $handle = curl_init($base.$path);
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new RuntimeException('Support HTTP transport error: '.curl_error($handle));
            }

            return [
                'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => (string) $body,
            ];
        } finally {
            curl_close($handle);
        }
    };

    $run = bin2hex(random_bytes(8));
    foreach (['deactivation', 'role_revocation'] as $scenario) {
    foreach (['index', 'detail'] as $action) {
        $staff = StaffMember::create([
            'name' => 'Support read '.$scenario.' '.$action,
            'email' => $run.'-support-'.$scenario.'-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'support',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        $other = StaffMember::create([
            'name' => 'Other support owner',
            'email' => $run.'-other-'.$scenario.'-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'support',
        ]);
        $owned = SupportTicket::create([
            'assigned_staff_id' => $staff->getKey(),
            'subject' => 'Private owned ticket '.$action,
            'message' => 'Private support case belonging to the assigned operator.',
            'status' => 'open',
        ]);
        $foreign = SupportTicket::create([
            'assigned_staff_id' => $other->getKey(),
            'subject' => 'Private foreign ticket '.$action,
            'message' => 'Private support case belonging to another operator.',
            'status' => 'open',
        ]);
        $bearer = $staff->createToken(
            'support-read-'.$scenario.'-race',
            ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/support/tickets';
        if ($action === 'detail') {
            $path .= '/'.$owned->getKey();
        }

        $multi = curl_multi_init();
        $handle = curl_init($base.$path);
        try {
            DB::beginTransaction();
            StaffMember::query()->whereKey($staff->getKey())
                ->lockForUpdate()->firstOrFail();

            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
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
                    throw new RuntimeException('Support read HTTP request failed to start.');
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
                throw new RuntimeException('Support '.$action.' read did not wait on staff lock.');
            }

            // Commit the revocation while the live HTTP worker is blocked on
            // the staff row. Its subsequent authorization must use this state.
            StaffMember::query()->whereKey($staff->getKey())
                ->update($scenario === 'deactivation'
                    ? ['is_active' => false]
                    : ['role' => 'ads']);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Support '.$scenario.' read did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $body = (string) curl_multi_getcontent($handle);
            if (curl_error($handle) !== ''
                || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403
                || str_contains($body, $owned->subject)
                || str_contains($body, $owned->message)
                || str_contains($body, $foreign->subject)
                || str_contains($body, $foreign->message)) {
                throw new RuntimeException(
                    'Revoked support read disclosed data: HTTP '.
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
        $persistedStaff = $staff->fresh();
        if (($scenario === 'deactivation' && $persistedStaff->is_active)
            || ($scenario === 'role_revocation' && (! $persistedStaff->is_active || $persistedStaff->role !== 'ads'))
            || PersonalAccessToken::findToken($bearer) === null
            || $owned->fresh()->status !== 'open'
            || $foreign->fresh()->status !== 'open'
            || DB::table('security_audit_events')->where('staff_member_id', $staff->getKey())->exists()) {
            throw new RuntimeException('Support read race changed token, ticket or audit state.');
        }

        foreach ([
            '/api/v1/staff/support/tickets',
            '/api/v1/staff/support/tickets/'.$owned->getKey(),
            '/api/v1/staff/support/tickets/'.$foreign->getKey(),
        ] as $replayPath) {
            $response = $read($replayPath, $bearer);
            // A foreign ticket remains concealed as 404 after role revocation;
            // deactivated staff is rejected by the session middleware first.
            $expected = $scenario === 'role_revocation'
                && $replayPath === '/api/v1/staff/support/tickets/'.$foreign->getKey()
                ? 404 : 403;
            if ($response['code'] !== $expected
                || str_contains($response['body'], $owned->subject)
                || str_contains($response['body'], $foreign->subject)) {
                throw new RuntimeException('Revoked support staff retained private read access ('.$scenario.', HTTP '.$response['code'].').');
            }
        }

        PersonalAccessToken::findToken($bearer)->delete();
        if ($read($path, $bearer)['code'] !== 401) {
            throw new RuntimeException('Revoked support bearer retained access.');
        }

        echo 'PASS support read/'.$scenario.'/'.$action." (HTTP 403 after SQL lock, 401 after token revocation)\n";
    }
    }
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, $error->getMessage()."\n");
    if ($log !== null) {
        fwrite(STDERR, substr((string) @file_get_contents($log), -4000)."\n");
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
