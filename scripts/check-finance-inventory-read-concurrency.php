<?php

declare(strict_types=1);

/**
 * Deterministic four-worker HTTP regression for finance metadata reads
 * against administrator grant, role, account and bearer revocation.
 * Refuses to run outside the disposable GitHub Actions PostgreSQL database.
 */

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
    fwrite(STDERR, "Refusing finance inventory concurrency outside isolated CI PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);
    $log = tempnam(sys_get_temp_dir(), 'topkolik-finance-read-');
    if ($log === false) {
        throw new RuntimeException('Cannot create server log.');
    }

    putenv('PHP_CLI_SERVER_WORKERS=4');
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', 'public', 'public/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $apiRoot,
    );
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start four-worker HTTP server.');
    }

    $base = 'http://127.0.0.1:'.$port;
    $request = static function (string $path, string $token) use ($base): array {
        $handle = curl_init($base.$path);
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$token,
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new RuntimeException('HTTP transport error: '.curl_error($handle));
            }
            return ['code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => (string) $body];
        } finally {
            curl_close($handle);
        }
    };

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            $ready = $request('/health', '')['code'] === 200;
        } catch (RuntimeException) {
            // The worker pool is still starting.
        }
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('HTTP workers did not become healthy.');
    }

    $run = bin2hex(random_bytes(8));
    $makeStaff = static function (string $role, string $label) use ($run): StaffMember {
        $staff = StaffMember::create([
            'name' => 'Finance metadata race '.$label,
            'email' => $run.'-'.$label.'@example.test',
            'password' => Str::random(40),
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        return $staff;
    };

    foreach (['grant_revocation', 'role_revocation', 'deactivation', 'logout'] as $scenario) {
        foreach (['entitlements', 'history'] as $view) {
            $admin = $makeStaff('support', $scenario.'-'.$view.'-admin');
            $requester = $makeStaff('support', $scenario.'-'.$view.'-requester');
            $finance = $makeStaff('finance', $scenario.'-'.$view.'-finance');

            DB::table('staff_security_admin_grants')->insert([
                'staff_member_id' => $admin->getKey(),
                'is_active' => true,
                'authorization_reference' => 'isolated-ci-metadata-read',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('staff_finance_entitlements')->insert([
                'staff_member_id' => $finance->getKey(),
                'is_active' => true,
                'last_approved_by_staff_id' => $admin->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('staff_finance_access_requests')->insert([
                'target_staff_member_id' => $finance->getKey(),
                'requested_by_staff_member_id' => $requester->getKey(),
                'reviewed_by_staff_member_id' => $admin->getKey(),
                'status' => 'approved',
                'expires_at' => now()->addDay(),
                'decided_at' => now(),
                'created_at' => now(),
            ]);
            $bearer = $admin->createToken(
                'finance-metadata-read', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
            )->plainTextToken;
            $path = $view === 'entitlements'
                ? '/api/v1/staff/security/finance/entitlements'
                : '/api/v1/staff/security/finance/access-requests/history';

            if ($request($path, $bearer)['code'] !== 200) {
                throw new RuntimeException('Authorized finance metadata read failed.');
            }

            $multi = curl_multi_init();
            $handle = curl_init($base.$path);
            try {
                DB::beginTransaction();
                if ($scenario === 'grant_revocation') {
                    DB::table('staff_security_admin_grants')
                        ->where('staff_member_id', $admin->getKey())
                        ->lockForUpdate()->first();
                } else {
                    StaffMember::query()->whereKey($admin->getKey())
                        ->lockForUpdate()->firstOrFail();
                }

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
                $deadline = microtime(true) + 12;
                do {
                    if (curl_multi_exec($multi, $active) !== CURLM_OK) {
                        throw new RuntimeException('HTTP request failed to start.');
                    }
                    DB::selectOne('SELECT pg_stat_clear_snapshot()');
                    $table = $scenario === 'grant_revocation'
                        ? 'staff_security_admin_grants' : 'staff_members';
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
                    throw new RuntimeException('Finance metadata read did not wait on '.$table.' lock.');
                }

                match ($scenario) {
                    'grant_revocation' => DB::table('staff_security_admin_grants')
                        ->where('staff_member_id', $admin->getKey())
                        ->update(['is_active' => false, 'updated_at' => now()]),
                    'role_revocation' => StaffMember::query()->whereKey($admin->getKey())
                        ->update(['role' => 'ads']),
                    'deactivation' => StaffMember::query()->whereKey($admin->getKey())
                        ->update(['is_active' => false]),
                    'logout' => PersonalAccessToken::findToken($bearer)->delete(),
                };
                DB::commit();

                $deadline = microtime(true) + 30;
                do {
                    if (curl_multi_exec($multi, $active) !== CURLM_OK
                        || microtime(true) > $deadline) {
                        throw new RuntimeException('Blocked HTTP read did not finish.');
                    }
                    if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                        usleep(10000);
                    }
                } while ($active > 0);

                $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = (string) curl_multi_getcontent($handle);
                $expected = $scenario === 'logout' ? 401 : 403;
                if (curl_error($handle) !== '' || $code !== $expected
                    || str_contains($body, 'entitlements')
                    || str_contains($body, 'decisions')
                    || str_contains($body, 'staff_member_id')) {
                    throw new RuntimeException(
                        'Finance metadata disclosure after '.$scenario.'/'.$view.
                        ': HTTP '.$code.' '.curl_error($handle),
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
            if ($request($path, $bearer)['code'] !== ($scenario === 'logout' ? 401 : 403)
                || DB::table('staff_finance_entitlements')
                    ->where('staff_member_id', $finance->getKey())->where('is_active', true)->count() !== 1
                || DB::table('staff_finance_access_requests')
                    ->where('target_staff_member_id', $finance->getKey())->where('status', 'approved')->count() !== 1
                || DB::table('security_audit_events')
                    ->where('staff_member_id', $admin->getKey())->exists()) {
                throw new RuntimeException('Finance metadata race changed protected state.');
            }

            echo 'PASS finance metadata/'.$scenario.'/'.$view.
                ' (HTTP '.($scenario === 'logout' ? '401' : '403').")\n";
        }
    }
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'FAIL finance metadata race: '.$error->getMessage()."\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($log !== null && is_file($log)) {
        if ($exit !== 0) {
            fwrite(STDERR, substr((string) file_get_contents($log), -8000));
        }
        unlink($log);
    }
}
exit($exit);
