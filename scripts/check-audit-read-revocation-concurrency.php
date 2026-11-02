<?php

declare(strict_types=1);

/**
 * Isolated four-worker HTTP races for self-only audit reads versus
 * committed staff deactivation and concurrent bearer logout.
 * Test fixtures exist only in the disposable CI PostgreSQL database.
 */

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
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
    fwrite(STDERR, "Refusing audit concurrency outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve audit HTTP port: '.$error);
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-audit-read-');
    if ($log === false) {
        throw new RuntimeException('Unable to create HTTP log.');
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
        throw new RuntimeException('Unable to start four-worker audit server.');
    }
    $base = 'http://127.0.0.1:'.$port;

    $request = static function (string $method, string $path, string $bearer) use ($base): array {
        $handle = curl_init($base.$path);
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $method === 'POST' ? '{}' : null,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new RuntimeException('Audit HTTP transport failed: '.curl_error($handle));
            }

            return [
                'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => (string) $body,
                'cache' => curl_getinfo($handle, CURLINFO_HEADER_SIZE),
            ];
        } finally {
            curl_close($handle);
        }
    };

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            $ready = $request('GET', '/health', '')['code'] === 200;
        } catch (Throwable) {
            $ready = false;
        }
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Audit HTTP server did not become healthy.');
    }

    $parallel = static function (array $jobs) use ($base): array {
        $multi = curl_multi_init();
        $handles = [];
        try {
            foreach ($jobs as $name => [$method, $path, $bearer]) {
                $handle = curl_init($base.$path);
                curl_setopt_array($handle, [
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$bearer,
                        'Accept: application/json',
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => $method === 'POST' ? '{}' : null,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 30,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[$name] = $handle;
            }
            $active = null;
            $deadline = microtime(true) + 40;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Audit parallel HTTP race timed out.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $results = [];
            foreach ($handles as $name => $handle) {
                if (curl_error($handle) !== '') {
                    throw new RuntimeException('Audit HTTP transport error: '.curl_error($handle));
                }
                $results[$name] = [
                    'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($handle),
                ];
            }

            return $results;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    };

    $run = bin2hex(random_bytes(8));
    $audit = app(SecurityAuditRecorder::class);
    foreach ([
        'events' => ['/api/v1/staff/security/audit-events', 'security_audit_events'],
        'failures' => ['/api/v1/staff/security/authentication-failures', 'security_auth_failures'],
    ] as $kind => [$path, $table]) {
        foreach (['deactivation', 'logout'] as $scenario) {
            $staff = StaffMember::create([
                'name' => 'Audit race '.$kind.' '.$scenario,
                'email' => $run.'-'.$kind.'-'.$scenario.'@example.test',
                'password' => Str::random(40),
                'role' => 'support',
            ]);
            $staff->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            $foreign = StaffMember::create([
                'name' => 'Foreign audit operator',
                'email' => $run.'-foreign-'.$kind.'-'.$scenario.'@example.test',
                'password' => Str::random(40),
                'role' => 'finance',
            ]);
            $ownRequestId = (string) Str::uuid();
            $foreignRequestId = (string) Str::uuid();
            if ($kind === 'events') {
                $audit->record($staff, 'staff.session.started', 'staff_member', (int) $staff->getKey(), $ownRequestId);
                $audit->record($foreign, 'staff.session.started', 'staff_member', (int) $foreign->getKey(), $foreignRequestId);
            } else {
                $audit->recordFailure('staff.login.failed', $staff->email, $staff, $ownRequestId);
                $audit->recordFailure('staff.login.failed', $foreign->email, $foreign, $foreignRequestId);
            }
            $bearer = $staff->createToken(
                'audit-read-'.$scenario, ['staff:session', 'staff:step-up'], now()->addMinutes(5),
            )->plainTextToken;
            $initialCount = DB::table($table)->where('staff_member_id', $staff->getKey())->count();

            if ($scenario === 'deactivation') {
                $multi = curl_multi_init();
                $handle = curl_init($base.$path);
                try {
                    DB::beginTransaction();
                    StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
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
                            throw new RuntimeException('Audit read worker did not start.');
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
                        throw new RuntimeException('Audit '.$kind.' read did not wait on persisted staff lock.');
                    }
                    StaffMember::query()->whereKey($staff->getKey())->update(['is_active' => false]);
                    DB::commit();

                    $deadline = microtime(true) + 30;
                    do {
                        if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                            throw new RuntimeException('Audit deactivation HTTP read timed out.');
                        }
                        if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                            usleep(10000);
                        }
                    } while ($active > 0);
                    $response = [
                        'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                        'body' => (string) curl_multi_getcontent($handle),
                    ];
                    if (curl_error($handle) !== '' || $response['code'] !== 403) {
                        throw new RuntimeException('Audit deactivation read did not fail closed: '.json_encode($response, JSON_THROW_ON_ERROR).' '.curl_error($handle));
                    }
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    curl_multi_close($multi);
                }
                if ($request('GET', $path, $bearer)['code'] !== 403) {
                    throw new RuntimeException('Deactivated audit principal retained read access.');
                }
            } else {
                $responses = $parallel([
                    'read' => ['GET', $path, $bearer],
                    'logout' => ['POST', '/api/v1/staff/auth/logout', $bearer],
                ]);
                if ($responses['logout']['code'] !== 200
                    || ! in_array($responses['read']['code'], [200, 401], true)) {
                    throw new RuntimeException('Unexpected audit read/logout serialization: '.json_encode($responses, JSON_THROW_ON_ERROR));
                }
                if ($responses['read']['code'] === 200) {
                    $payload = json_decode($responses['read']['body'], true, 512, JSON_THROW_ON_ERROR);
                    $key = $kind === 'events' ? 'events' : 'failures';
                    if (count($payload[$key] ?? []) !== 1
                        || ($payload[$key][0]['request_id'] ?? null) !== $ownRequestId) {
                        throw new RuntimeException('Audit read returned non-owned or incomplete metadata.');
                    }
                }
                if (PersonalAccessToken::findToken($bearer) !== null
                    || $request('GET', $path, $bearer)['code'] !== 401) {
                    throw new RuntimeException('Logged-out audit bearer retained access.');
                }
            }

            DB::disconnect();
            if (str_contains($scenario === 'logout' ? $responses['read']['body'] : $response['body'], $foreignRequestId)
                || ($scenario === 'deactivation' && str_contains($response['body'], $ownRequestId))
                || DB::table($table)->where('staff_member_id', $staff->getKey())->count()
                    !== $initialCount + ($scenario === 'logout' && $kind === 'events' ? 1 : 0)
                || DB::table($table)->where('staff_member_id', $foreign->getKey())->count() !== 1
                || PersonalAccessToken::findToken($bearer) === null && $scenario === 'deactivation') {
                throw new RuntimeException('Audit race leaked another principal or mutated an append-only ledger.');
            }
            if ($scenario === 'logout') {
                $events = DB::table('security_audit_events')
                    ->where('staff_member_id', $staff->getKey())
                    ->where('action', 'staff.session.ended')->get();
                if ($events->count() !== 1 || $events->first()->request_id === null) {
                    throw new RuntimeException('Audit read/logout did not persist exactly one attributed logout event.');
                }
            } else {
                PersonalAccessToken::findToken($bearer)->delete();
                if ($request('GET', $path, $bearer)['code'] !== 401) {
                    throw new RuntimeException('Revoked audit bearer was accepted after deactivation.');
                }
            }
            echo 'PASS audit '.$kind.'/'.$scenario." (self-only metadata and revocation)\n";
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
