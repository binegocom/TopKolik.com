<?php

declare(strict_types=1);

/**
 * Four-worker HTTP regression for self-only advertiser assignment inventory.
 * Uses only the disposable GitHub Actions PostgreSQL database.
 */

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
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
    fwrite(STDERR, "Refusing assignment read race outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve HTTP port: '.$error);
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-ads-inventory-');
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
        throw new RuntimeException('Unable to start HTTP workers.');
    }

    $base = 'http://127.0.0.1:'.$port;
    $request = static function (string $path, string $bearer, bool $post = false) use ($base): array {
        $handle = curl_init($base.$path);
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$bearer,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => true,
            ]);
            if ($post) {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, '{}');
            }
            $response = curl_exec($handle);
            if ($response === false) {
                throw new RuntimeException('HTTP transport failed: '.curl_error($handle));
            }
            $headerLength = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

            return [
                'code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'headers' => substr($response, 0, $headerLength),
                'body' => substr($response, $headerLength),
            ];
        } finally {
            curl_close($handle);
        }
    };
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        try {
            $ready = $request('/health', '')['code'] === 200;
        } catch (Throwable) {
            $ready = false;
        }
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('HTTP worker pool did not become healthy.');
    }

    $run = bin2hex(random_bytes(8));
    $path = '/api/v1/staff/ads/assignments';
    foreach (['deactivation', 'role_revocation', 'assignment_revocation', 'organization_deactivation', 'logout'] as $scenario) {
        $staff = StaffMember::create([
            'name' => 'Assignment race '.$scenario,
            'email' => $run.'-'.$scenario.'@example.test',
            'password' => Str::random(40),
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $organization = AdvertiserOrganization::create([
            'name' => 'Confidential assignment '.$run.' '.$scenario,
        ]);
        $organization->forceFill(['is_active' => true])->save();
        $assignmentId = (int) DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->getKey(),
            'advertiser_organization_id' => $organization->getKey(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignOrganization = AdvertiserOrganization::create([
            'name' => 'Foreign confidential assignment '.$run.' '.$scenario,
        ]);
        $foreignOrganization->forceFill(['is_active' => true])->save();
        $foreign = StaffMember::create([
            'name' => 'Foreign ads '.$scenario,
            'email' => $run.'-foreign-'.$scenario.'@example.test',
            'password' => Str::random(40),
            'role' => 'ads',
        ]);
        $foreign->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        DB::table('staff_advertiser_assignments')->insert([
            'staff_member_id' => $foreign->getKey(),
            'advertiser_organization_id' => $foreignOrganization->getKey(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bearer = $staff->createToken('ads-inventory-race', ['staff:session'], now()->addMinutes(5))
            ->plainTextToken;

        $baseline = $request($path, $bearer);
        if ($baseline['code'] !== 200
            || ! str_contains($baseline['body'], $organization->name)
            || str_contains($baseline['body'], $foreignOrganization->name)) {
            throw new RuntimeException('Assignment baseline failed for '.$scenario);
        }

        $multi = curl_multi_init();
        $readHandle = curl_init($base.$path);
        $logoutHandle = null;
        try {
            DB::beginTransaction();
            $table = match ($scenario) {
                'assignment_revocation' => 'staff_advertiser_assignments',
                'organization_deactivation' => 'advertiser_organizations',
                default => 'staff_members',
            };
            if ($table === 'staff_members') {
                StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            } else {
                DB::table($table)
                    ->where('id', $table === 'staff_advertiser_assignments' ? $assignmentId : $organization->getKey())
                    ->lockForUpdate()->first();
            }

            curl_setopt_array($readHandle, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$bearer, 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => true,
            ]);
            curl_multi_add_handle($multi, $readHandle);

            $active = null;
            $waiting = false;
            $deadline = microtime(true) + 10;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK) {
                    throw new RuntimeException('Assignment HTTP read failed to start.');
                }
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne(
                    "SELECT count(*) AS waiting FROM pg_stat_activity ".
                    "WHERE pid <> pg_backend_pid() AND wait_event_type = 'Lock' ".
                    "AND query ILIKE ? AND query ILIKE '%for update%'",
                    ['%'.$table.'%'],
                )->waiting > 0;
                if (! $waiting && $active > 0 && curl_multi_select($multi, 0.1) === -1) {
                    usleep(10000);
                }
            } while (! $waiting && $active > 0 && microtime(true) < $deadline);
            if (! $waiting) {
                throw new RuntimeException('Assignment '.$scenario.' did not wait on '.$table.' lock.');
            }

            if ($scenario === 'deactivation') {
                StaffMember::query()->whereKey($staff->getKey())->update(['is_active' => false]);
            } elseif ($scenario === 'role_revocation') {
                StaffMember::query()->whereKey($staff->getKey())->update(['role' => 'support']);
            } elseif ($scenario === 'assignment_revocation') {
                DB::table('staff_advertiser_assignments')->where('id', $assignmentId)
                    ->update(['is_active' => false]);
            } elseif ($scenario === 'organization_deactivation') {
                DB::table('advertiser_organizations')->where('id', $organization->getKey())
                    ->update(['is_active' => false]);
            } else {
                $logoutHandle = curl_init($base.'/api/v1/staff/auth/logout');
                curl_setopt_array($logoutHandle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '{}',
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$bearer,
                        'Accept: application/json',
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 30,
                ]);
                curl_multi_add_handle($multi, $logoutHandle);
            }
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Assignment race did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($readHandle) !== '') {
                throw new RuntimeException('Assignment read transport: '.curl_error($readHandle));
            }
            $responseCode = (int) curl_getinfo($readHandle, CURLINFO_RESPONSE_CODE);
            $raw = (string) curl_multi_getcontent($readHandle);
            $body = substr($raw, curl_getinfo($readHandle, CURLINFO_HEADER_SIZE));
            $expected = match ($scenario) {
                'deactivation', 'role_revocation' => 403,
                'logout' => null,
                default => 200,
            };
            if (($expected !== null && $responseCode !== $expected)
                || ($scenario === 'logout' && ! in_array($responseCode, [200, 401], true))
                || str_contains($body, $foreignOrganization->name)
                || ($scenario !== 'logout' && str_contains($body, $organization->name))) {
                throw new RuntimeException('Assignment inventory leaked after '.$scenario.' HTTP '.$responseCode);
            }
            if ($scenario === 'logout') {
                if (curl_error($logoutHandle) !== ''
                    || (int) curl_getinfo($logoutHandle, CURLINFO_RESPONSE_CODE) !== 200) {
                    throw new RuntimeException('Concurrent logout did not complete.');
                }
                if ($responseCode === 200 && ! str_contains($body, $organization->name)) {
                    throw new RuntimeException('Authorized pre-logout read returned incorrect inventory.');
                }
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            curl_multi_remove_handle($multi, $readHandle);
            curl_close($readHandle);
            if ($logoutHandle !== null) {
                curl_multi_remove_handle($multi, $logoutHandle);
                curl_close($logoutHandle);
            }
            curl_multi_close($multi);
        }

        DB::disconnect();
        $replay = $request($path, $bearer);
        $expectedReplay = match ($scenario) {
            'deactivation', 'role_revocation' => 403,
            'logout' => 401,
            default => 200,
        };
        $decoded = json_decode($replay['body'], true);
        if ($replay['code'] !== $expectedReplay
            || str_contains($replay['body'], $organization->name)
            || str_contains($replay['body'], $foreignOrganization->name)
            || ($expectedReplay === 200 && ($decoded['assignments'] ?? null) !== [])) {
            throw new RuntimeException('Post-revocation assignment replay failed for '.$scenario);
        }
        $events = DB::table('security_audit_events')
            ->where('staff_member_id', $staff->getKey())
            ->where('action', 'staff.session.ended')->count();
        if ($events !== ($scenario === 'logout' ? 1 : 0)
            || DB::table('staff_advertiser_assignments')
                ->where('staff_member_id', $foreign->getKey())->where('is_active', true)->count() !== 1
            || (PersonalAccessToken::findToken($bearer) === null) !== ($scenario === 'logout')) {
            throw new RuntimeException('Assignment race mutated unrelated auth or audit state.');
        }
        echo 'PASS ads assignment index/'.$scenario." (locked read, isolation, replay)\n";
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
