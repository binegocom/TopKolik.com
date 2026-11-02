<?php

declare(strict_types=1);

/**
 * Isolated four-worker HTTP regressions for private advertiser review reads.
 * Never run against production or a non-disposable PostgreSQL database.
 */

use App\Models\AdvertiserCampaign;
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
    fwrite(STDERR, "Refusing advertiser read race outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve advertiser HTTP port: '.$error);
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-ads-read-');
    if ($log === false) {
        throw new RuntimeException('Unable to create advertiser HTTP log.');
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
        throw new RuntimeException('Unable to start four-worker advertiser HTTP server.');
    }

    $base = 'http://127.0.0.1:'.$port;
    $read = static function (string $path, string $bearer) use ($base): array {
        $handle = curl_init($base.$path);
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$bearer, 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new RuntimeException('Advertiser read transport error: '.curl_error($handle));
            }

            return [
                'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => (string) $body,
            ];
        } finally {
            curl_close($handle);
        }
    };

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            $ready = $read('/health', '')['code'] === 200;
        } catch (Throwable) {
            $ready = false;
        }
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Advertiser HTTP server did not become healthy.');
    }

    $run = bin2hex(random_bytes(8));
    foreach (['staff_deactivation', 'role_revocation', 'assignment_revocation'] as $scenario) {
        foreach (['index', 'detail'] as $action) {
            $staff = StaffMember::create([
                'name' => 'Ads review '.$scenario.' '.$action,
                'email' => $run.'-'.$scenario.'-'.$action.'@example.test',
                'password' => Str::random(40),
                'role' => 'ads',
            ]);
            $staff->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            $organization = AdvertiserOrganization::create([
                'name' => 'Assigned advertiser '.$run.' '.$scenario.' '.$action,
            ]);
            $organization->forceFill(['is_active' => true])->save();
            $foreignOrg = AdvertiserOrganization::create([
                'name' => 'Foreign advertiser '.$run.' '.$scenario.' '.$action,
            ]);
            $foreignOrg->forceFill(['is_active' => true])->save();

            $assignmentId = (int) DB::table('staff_advertiser_assignments')->insertGetId([
                'staff_member_id' => $staff->getKey(),
                'advertiser_organization_id' => $organization->getKey(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $owned = AdvertiserCampaign::create([
                'advertiser_organization_id' => $organization->getKey(),
                'title' => 'Owned private creative '.$run,
                'body' => 'Confidential assigned campaign '.$run,
            ]);
            $owned->forceFill(['status' => 'in_review'])->save();
            $foreign = AdvertiserCampaign::create([
                'advertiser_organization_id' => $foreignOrg->getKey(),
                'title' => 'Foreign private creative '.$run,
                'body' => 'Confidential foreign campaign '.$run,
            ]);
            $foreign->forceFill(['status' => 'in_review'])->save();
            $bearer = $staff->createToken(
                'ads-review-read-race',
                ['staff:session', 'staff:step-up'],
                now()->addMinutes(5),
            )->plainTextToken;

            $path = '/api/v1/staff/ads/organizations/'.$organization->getKey().'/campaigns';
            if ($action === 'detail') {
                $path .= '/'.$owned->getKey();
            }
            $multi = curl_multi_init();
            $handle = curl_init($base.$path);
            try {
                DB::beginTransaction();
                if ($scenario === 'assignment_revocation') {
                    DB::table('staff_advertiser_assignments')
                        ->where('id', $assignmentId)->lockForUpdate()->first();
                } else {
                    StaffMember::query()->whereKey($staff->getKey())
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

                $table = $scenario === 'assignment_revocation'
                    ? 'staff_advertiser_assignments' : 'staff_members';
                $waiting = false;
                $active = null;
                $deadline = microtime(true) + 10;
                do {
                    if (curl_multi_exec($multi, $active) !== CURLM_OK) {
                        throw new RuntimeException('Advertiser read HTTP request did not start.');
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
                    throw new RuntimeException('Advertiser '.$scenario.'/'.$action.' did not wait on '.$table.' lock.');
                }

                if ($scenario === 'staff_deactivation') {
                    StaffMember::query()->whereKey($staff->getKey())
                        ->update(['is_active' => false]);
                } elseif ($scenario === 'role_revocation') {
                    StaffMember::query()->whereKey($staff->getKey())
                        ->update(['role' => 'support']);
                } else {
                    DB::table('staff_advertiser_assignments')
                        ->where('id', $assignmentId)->update(['is_active' => false]);
                }
                DB::commit();

                $deadline = microtime(true) + 30;
                do {
                    if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                        throw new RuntimeException('Advertiser read did not finish after revocation.');
                    }
                    if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                        usleep(10000);
                    }
                } while ($active > 0);

                $responseCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = (string) curl_multi_getcontent($handle);
                $expected = $scenario === 'staff_deactivation' ? 403 : 404;
                if (curl_error($handle) !== '' || $responseCode !== $expected
                    || str_contains($body, $owned->body)
                    || str_contains($body, $foreign->body)
                    || str_contains($body, $owned->title)
                    || str_contains($body, $foreign->title)) {
                    throw new RuntimeException('Revoked advertiser read leaked private data: '.
                        $scenario.'/'.$action.' HTTP '.$responseCode.' '.curl_error($handle));
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
            $expected = $scenario === 'staff_deactivation' ? 403 : 404;
            foreach ([
                '/api/v1/staff/ads/organizations/'.$organization->getKey().'/campaigns',
                '/api/v1/staff/ads/organizations/'.$organization->getKey().'/campaigns/'.$owned->getKey(),
                '/api/v1/staff/ads/organizations/'.$foreignOrg->getKey().'/campaigns/'.$foreign->getKey(),
            ] as $replayPath) {
                $replay = $read($replayPath, $bearer);
                if ($replay['code'] !== $expected
                    || str_contains($replay['body'], $owned->body)
                    || str_contains($replay['body'], $foreign->body)) {
                    throw new RuntimeException('Revoked advertiser reviewer retained private access.');
                }
            }

            if (PersonalAccessToken::findToken($bearer) === null
                || $owned->fresh()->status !== 'in_review'
                || $foreign->fresh()->status !== 'in_review'
                || DB::table('security_audit_events')
                    ->where('staff_member_id', $staff->getKey())->exists()) {
                throw new RuntimeException('Advertiser read race modified campaign, token or audit state.');
            }
            PersonalAccessToken::findToken($bearer)->delete();
            if ($read($path, $bearer)['code'] !== 401) {
                throw new RuntimeException('Revoked advertiser bearer retained review access.');
            }
            echo 'PASS advertiser read/'.$scenario.'/'.$action.
                " (committed revocation, no private disclosure, token 401)\n";
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
