<?php

declare(strict_types=1);

/**
 * Real four-worker HTTP regression for editorial reads/writes racing staff logout, role revocation and account deactivation.
 *
 * Runs exclusively against the disposable GitHub Actions PostgreSQL instance.
 * No schema changes, production data, external sports feeds or fake runtime data.
 */

use App\Models\EditorialArticle;
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
    fwrite(STDERR, "Refusing editorial HTTP race outside isolated GitHub Actions PostgreSQL.\n");
    exit(2);
}

$server = null;
$log = null;
$exit = 0;

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a loopback HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);

    $log = tempnam(sys_get_temp_dir(), 'topkolik-editorial-http-');
    if ($log === false) {
        throw new RuntimeException('Unable to create the HTTP server log.');
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

    $parallel = static function (array $requests) use ($base): array {
        $multi = curl_multi_init();
        $handles = [];
        try {
            foreach ($requests as $name => $request) {
                [$method, $path, $bearer, $payload] = $request;
                $handle = curl_init($base.$path);
                curl_setopt_array($handle, [
                    CURLOPT_CUSTOMREQUEST => $method,
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
                $handles[$name] = $handle;
            }
            $active = null;
            $deadline = microtime(true) + 40;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Editorial HTTP race did not complete.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            $responses = [];
            foreach ($handles as $name => $handle) {
                if (curl_error($handle) !== '') {
                    throw new RuntimeException('HTTP transport error: '.curl_error($handle));
                }
                $responses[$name] = [
                    'code' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($handle),
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

    $run = bin2hex(random_bytes(8));
    foreach (['create', 'update', 'submit'] as $action) {
        $editor = StaffMember::create([
            'name' => 'Editorial logout concurrency '.$action,
            'email' => $run.'-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $editor->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $bearer = $editor->createToken(
            'editorial-logout-race',
            ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;

        $draft = $action === 'create' ? null : EditorialArticle::create([
            'owner_staff_id' => $editor->getKey(),
            'title' => 'Original editorial review draft',
            'body' => 'This draft must be verified against licensed evidence before review.',
        ]);
        $path = '/api/v1/staff/editorial/drafts';
        $method = 'POST';
        $payload = [
            'title' => 'New editorial verification draft',
            'body' => 'This new editorial draft must not be created after logout completes.',
        ];
        if ($action === 'update') {
            $path .= '/'.$draft->getKey();
            $method = 'PATCH';
            $payload = ['revision' => 1, 'title' => 'Updated editorial verification draft'];
        } elseif ($action === 'submit') {
            $path .= '/'.$draft->getKey().'/submit';
            $payload = ['revision' => 1];
        }

        $responses = $parallel([
            'logout' => ['POST', '/api/v1/staff/auth/logout', $bearer, new stdClass()],
            'mutation' => [$method, $path, $bearer, $payload],
        ]);
        $success = $action === 'create' ? 201 : 200;
        if ($responses['logout']['code'] !== 200
            || ! in_array($responses['mutation']['code'], [401, $success], true)) {
            throw new RuntimeException(
                'Unexpected '.$action.' logout race: '.json_encode($responses, JSON_THROW_ON_ERROR),
            );
        }

        $mutated = $responses['mutation']['code'] === $success;
        DB::disconnect();
        $records = EditorialArticle::query()
            ->where('owner_staff_id', $editor->getKey())->get();
        if ($action === 'create') {
            if ($records->count() !== (int) $mutated
                || ($mutated && ($records->first()->status !== 'draft'
                    || (int) $records->first()->revision !== 1))) {
                throw new RuntimeException('Logout/create race changed an unexpected draft.');
            }
        } else {
            $fresh = $records->sole();
            if ($fresh->getKey() !== $draft->getKey()
                || (int) $fresh->revision !== ($mutated ? 2 : 1)
                || ($action === 'update' && $fresh->title !==
                    ($mutated ? 'Updated editorial verification draft' : 'Original editorial review draft'))
                || ($action === 'submit' && $fresh->status !== ($mutated ? 'in_review' : 'draft'))
                || ($action === 'update' && $fresh->status !== 'draft')
                || ($action === 'submit' && ($fresh->submitted_at !== null) !== $mutated)) {
                throw new RuntimeException('Logout/'.$action.' race left inconsistent editorial state.');
            }
        }

        if (PersonalAccessToken::findToken($bearer) !== null) {
            throw new RuntimeException('Logout did not revoke the presented editor bearer.');
        }
        $events = DB::table('security_audit_events')
            ->where('staff_member_id', $editor->getKey())->get();
        if ($events->count() !== 1
            || $events->first()->action !== 'staff.session.ended'
            || $events->first()->request_id === null) {
            throw new RuntimeException('Logout/'.$action.' did not produce one attributed audit event.');
        }

        $replay = $parallel(['replay' => [$method, $path, $bearer, $payload]]);
        if ($replay['replay']['code'] !== 401) {
            throw new RuntimeException('Revoked editor bearer was accepted on '.$action.' replay.');
        }
        DB::disconnect();
        if (EditorialArticle::query()->where('owner_staff_id', $editor->getKey())->count()
            !== $records->count()
            || DB::table('security_audit_events')
                ->where('staff_member_id', $editor->getKey())->count() !== 1) {
            throw new RuntimeException('Revoked editor bearer changed records or audit on replay.');
        }
        echo 'PASS editorial logout/'.$action.' (mutation HTTP '.$responses['mutation']['code'].")\n";
    }

    // A role downgrade must win over an editor write already waiting for the
    // persisted actor row. Verify real SQL lock contention rather than relying
    // on timing or a sequential denial test.
    foreach (['create', 'update', 'submit'] as $action) {
        $editor = StaffMember::create([
            'name' => 'Editorial role revocation '.$action,
            'email' => $run.'-role-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $editor->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $bearer = $editor->createToken(
            'editorial-role-revocation-race',
            ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;

        $draft = $action === 'create' ? null : EditorialArticle::create([
            'owner_staff_id' => $editor->getKey(),
            'title' => 'Original editorial role race draft',
            'body' => 'A role downgrade must prevent a pending editorial mutation.',
        ]);
        $path = '/api/v1/staff/editorial/drafts';
        $method = 'POST';
        $payload = [
            'title' => 'Forbidden role-revoked draft',
            'body' => 'This editorial creation must be denied after the role downgrade.',
        ];
        if ($action === 'update') {
            $path .= '/'.$draft->getKey();
            $method = 'PATCH';
            $payload = ['revision' => 1, 'title' => 'Forbidden role-revoked update'];
        } elseif ($action === 'submit') {
            $path .= '/'.$draft->getKey().'/submit';
            $payload = ['revision' => 1];
        }

        $multi = curl_multi_init();
        $handle = curl_init($base.$path);
        try {
            DB::beginTransaction();
            StaffMember::query()->whereKey($editor->getKey())
                ->lockForUpdate()->firstOrFail();

            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
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
                    throw new RuntimeException('Editorial role-revocation HTTP request failed to start.');
                }
                // Clear the cached PostgreSQL statistics snapshot on each
                // iteration while the local revocation transaction is open.
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
                $activity = DB::select(
                    "SELECT pid, state, wait_event_type, wait_event, left(query, 180) AS statement ".
                    "FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND datname = current_database()"
                );
                throw new RuntimeException(
                    'Editorial '.$action.' worker did not wait on the locked staff row: '.
                    json_encode($activity, JSON_THROW_ON_ERROR).
                    ', HTTP='.curl_getinfo($handle, CURLINFO_RESPONSE_CODE).
                    ', response='.substr((string) curl_multi_getcontent($handle), 0, 300),
                );
            }

            StaffMember::query()->whereKey($editor->getKey())->update(['role' => 'support']);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Editorial role-revocation HTTP request did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($handle) !== '' || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403) {
                throw new RuntimeException(
                    'Revoked editor role performed '.$action.': HTTP '.
                    curl_getinfo($handle, CURLINFO_RESPONSE_CODE).' '.curl_error($handle).
                    ' '.substr((string) curl_multi_getcontent($handle), 0, 300),
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
        $records = EditorialArticle::query()->where('owner_staff_id', $editor->getKey())->get();
        if ($editor->fresh()->role !== 'support'
            || $records->count() !== ($action === 'create' ? 0 : 1)
            || ($draft !== null && ($records->sole()->getKey() !== $draft->getKey()
                || $records->sole()->status !== 'draft'
                || (int) $records->sole()->revision !== 1
                || $records->sole()->title !== 'Original editorial role race draft'
                || $records->sole()->submitted_at !== null))
            || DB::table('security_audit_events')->where('staff_member_id', $editor->getKey())->exists()
            || PersonalAccessToken::findToken($bearer) === null) {
            throw new RuntimeException('Revoked editor '.$action.' changed drafts, audit or bearer unexpectedly.');
        }

        $replay = $parallel(['replay' => [$method, $path, $bearer, $payload]]);
        if ($replay['replay']['code'] !== 403) {
            throw new RuntimeException('Downgraded editor regained '.$action.' permission on replay.');
        }
        DB::disconnect();
        if (EditorialArticle::query()->where('owner_staff_id', $editor->getKey())->count()
            !== $records->count()
            || DB::table('security_audit_events')->where('staff_member_id', $editor->getKey())->exists()) {
            throw new RuntimeException('Downgraded editor replay mutated drafts or audit.');
        }
        echo 'PASS editorial role-revocation/'.$action." (HTTP 403 after SQL lock)\n";
    }

    // Read endpoints must obey the same staff -> bearer serialization as writes.
    // Race each private response against a real logout; only a read completed
    // before revocation may return the owner's data.
    foreach (['index', 'detail'] as $action) {
        $editor = StaffMember::create([
            'name' => 'Editorial read logout '.$action,
            'email' => $run.'-read-logout-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $editor->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $other = StaffMember::create([
            'name' => 'Foreign editorial owner',
            'email' => $run.'-foreign-logout-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $owned = EditorialArticle::create([
            'owner_staff_id' => $editor->getKey(),
            'title' => 'Private owner read draft',
            'body' => 'Owner-only editorial content must remain private during logout.',
        ]);
        $foreign = EditorialArticle::create([
            'owner_staff_id' => $other->getKey(),
            'title' => 'Private foreign read draft',
            'body' => 'Another editor must never see this confidential draft.',
        ]);
        $bearer = $editor->createToken(
            'editorial-read-logout-race', ['staff:session'], now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/editorial/drafts';
        if ($action === 'detail') {
            $path .= '/'.$owned->getKey();
        }

        $responses = $parallel([
            'logout' => ['POST', '/api/v1/staff/auth/logout', $bearer, new stdClass()],
            'read' => ['GET', $path, $bearer, new stdClass()],
        ]);
        if ($responses['logout']['code'] !== 200
            || ! in_array($responses['read']['code'], [200, 401], true)) {
            throw new RuntimeException(
                'Unexpected editorial read/logout race: '.json_encode($responses, JSON_THROW_ON_ERROR),
            );
        }
        $body = json_decode($responses['read']['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($responses['read']['code'] === 200) {
            if ($action === 'index') {
                if (count($body['drafts'] ?? []) !== 1
                    || ($body['drafts'][0]['id'] ?? null) !== $owned->getKey()
                    || array_key_exists('body', $body['drafts'][0])) {
                    throw new RuntimeException('Editorial list disclosed foreign or full draft data.');
                }
            } elseif (($body['draft']['id'] ?? null) !== $owned->getKey()
                || ($body['draft']['body'] ?? null) !== $owned->body) {
                throw new RuntimeException('Editorial detail returned the wrong owner content.');
            }
        } elseif (str_contains($responses['read']['body'], $owned->body)
            || str_contains($responses['read']['body'], $foreign->body)) {
            throw new RuntimeException('Revoked editorial read leaked private content.');
        }

        DB::disconnect();
        if (PersonalAccessToken::findToken($bearer) !== null
            || EditorialArticle::query()->whereKey($owned->getKey())->firstOrFail()->revision !== 1
            || EditorialArticle::query()->whereKey($foreign->getKey())->firstOrFail()->revision !== 1) {
            throw new RuntimeException('Editorial read/logout altered token or draft state.');
        }
        $events = DB::table('security_audit_events')
            ->where('staff_member_id', $editor->getKey())->get();
        if ($events->count() !== 1
            || $events->first()->action !== 'staff.session.ended'
            || $events->first()->request_id === null) {
            throw new RuntimeException('Editorial read/logout audit was not unique and attributed.');
        }
        $replay = $parallel([
            'index' => ['GET', '/api/v1/staff/editorial/drafts', $bearer, new stdClass()],
            'owned' => ['GET', '/api/v1/staff/editorial/drafts/'.$owned->getKey(), $bearer, new stdClass()],
            'foreign' => ['GET', '/api/v1/staff/editorial/drafts/'.$foreign->getKey(), $bearer, new stdClass()],
        ]);
        foreach ($replay as $response) {
            if ($response['code'] !== 401) {
                throw new RuntimeException('Logged-out editor retained editorial read access.');
            }
        }
        DB::disconnect();
        if (DB::table('security_audit_events')
            ->where('staff_member_id', $editor->getKey())->count() !== 1) {
            throw new RuntimeException('Read replay changed editorial security audit.');
        }
        echo 'PASS editorial read/logout/'.$action.' (read HTTP '.$responses['read']['code'].")\n";
    }

    // Deterministically block a real read worker on the staff row, commit
    // a role downgrade, and require the worker to fail closed after release.
    foreach (['index', 'detail'] as $action) {
        $editor = StaffMember::create([
            'name' => 'Editorial read role revocation '.$action,
            'email' => $run.'-read-role-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $editor->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $other = StaffMember::create([
            'name' => 'Other read role owner',
            'email' => $run.'-foreign-role-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $owned = EditorialArticle::create([
            'owner_staff_id' => $editor->getKey(),
            'title' => 'Role revocation owner draft',
            'body' => 'An editor role downgrade must deny this confidential content.',
        ]);
        $foreign = EditorialArticle::create([
            'owner_staff_id' => $other->getKey(),
            'title' => 'Role revocation foreign draft',
            'body' => 'Cross-editor private content is not accessible after downgrade.',
        ]);
        $bearer = $editor->createToken(
            'editorial-read-role-race', ['staff:session'], now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/editorial/drafts';
        if ($action === 'detail') {
            $path .= '/'.$owned->getKey();
        }

        $multi = curl_multi_init();
        $handle = curl_init($base.$path);
        try {
            DB::beginTransaction();
            StaffMember::query()->whereKey($editor->getKey())
                ->lockForUpdate()->firstOrFail();
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'GET',
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
                    throw new RuntimeException('Editorial read role HTTP request failed to start.');
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
                throw new RuntimeException('Editorial '.$action.' read did not wait on staff lock.');
            }

            StaffMember::query()->whereKey($editor->getKey())->update(['role' => 'support']);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Editorial role-revoked read did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($handle) !== ''
                || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403
                || str_contains((string) curl_multi_getcontent($handle), $owned->body)) {
                throw new RuntimeException(
                    'Role-revoked editor read private data: HTTP '.
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
        if ($editor->fresh()->role !== 'support'
            || PersonalAccessToken::findToken($bearer) === null
            || EditorialArticle::query()->whereKey($owned->getKey())->firstOrFail()->revision !== 1
            || EditorialArticle::query()->whereKey($foreign->getKey())->firstOrFail()->revision !== 1
            || DB::table('security_audit_events')->where('staff_member_id', $editor->getKey())->exists()) {
            throw new RuntimeException('Editorial read role race altered token, draft or audit.');
        }
        $replay = $parallel([
            'index' => ['GET', '/api/v1/staff/editorial/drafts', $bearer, new stdClass()],
            'owned' => ['GET', '/api/v1/staff/editorial/drafts/'.$owned->getKey(), $bearer, new stdClass()],
            'foreign' => ['GET', '/api/v1/staff/editorial/drafts/'.$foreign->getKey(), $bearer, new stdClass()],
        ]);
        if ($replay['index']['code'] !== 403
            || $replay['owned']['code'] !== 403
            || $replay['foreign']['code'] !== 404) {
            throw new RuntimeException(
                'Editorial role-revoked read replay broke RBAC/BOLA: '.
                json_encode($replay, JSON_THROW_ON_ERROR),
            );
        }
        echo 'PASS editorial read/role-revocation/'.$action." (HTTP 403 after SQL lock)\n";
    }

    // An editor deactivated while a read is waiting on the persisted actor
    // row must never receive private data, even if bearer deletion is delayed.
    foreach (['index', 'detail'] as $action) {
        $editor = StaffMember::create([
            'name' => 'Editorial read deactivation '.$action,
            'email' => $run.'-read-deactivated-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $editor->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $other = StaffMember::create([
            'name' => 'Foreign owner during deactivation',
            'email' => $run.'-foreign-deactivated-'.$action.'@example.test',
            'password' => Str::random(40),
            'role' => 'editor',
        ]);
        $owned = EditorialArticle::create([
            'owner_staff_id' => $editor->getKey(),
            'title' => 'Private deactivation owner draft',
            'body' => 'Deactivated staff must not receive confidential editorial content.',
        ]);
        $foreign = EditorialArticle::create([
            'owner_staff_id' => $other->getKey(),
            'title' => 'Private deactivation foreign draft',
            'body' => 'Cross-editor confidentiality survives account deactivation.',
        ]);
        $bearer = $editor->createToken(
            'editorial-read-deactivation-race', ['staff:session'], now()->addMinutes(5),
        )->plainTextToken;
        $path = '/api/v1/staff/editorial/drafts';
        if ($action === 'detail') {
            $path .= '/'.$owned->getKey();
        }

        $multi = curl_multi_init();
        $handle = curl_init($base.$path);
        try {
            DB::beginTransaction();
            StaffMember::query()->whereKey($editor->getKey())
                ->lockForUpdate()->firstOrFail();

            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'GET',
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
                    throw new RuntimeException('Editorial deactivation read failed to start.');
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
                    'Editorial '.$action.' read did not wait on the deactivated staff row.',
                );
            }

            // Keep the bearer valid until after denial to prove that the
            // persisted is_active flag alone closes the authorization gate.
            StaffMember::query()->whereKey($editor->getKey())
                ->update(['is_active' => false]);
            DB::commit();

            $deadline = microtime(true) + 30;
            do {
                if (curl_multi_exec($multi, $active) !== CURLM_OK || microtime(true) > $deadline) {
                    throw new RuntimeException('Editorial deactivated read did not finish.');
                }
                if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($active > 0);

            if (curl_error($handle) !== ''
                || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 403
                || str_contains((string) curl_multi_getcontent($handle), $owned->body)
                || str_contains((string) curl_multi_getcontent($handle), $foreign->body)) {
                throw new RuntimeException(
                    'Deactivated editor read private data: HTTP '.
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
        if ($editor->fresh()->is_active
            || PersonalAccessToken::findToken($bearer) === null
            || EditorialArticle::query()->whereKey($owned->getKey())->firstOrFail()->revision !== 1
            || EditorialArticle::query()->whereKey($foreign->getKey())->firstOrFail()->revision !== 1
            || DB::table('security_audit_events')->where('staff_member_id', $editor->getKey())->exists()) {
            throw new RuntimeException('Editorial deactivation race altered bearer, drafts or audit.');
        }

        $replay = $parallel([
            'index' => ['GET', '/api/v1/staff/editorial/drafts', $bearer, new stdClass()],
            'owned' => ['GET', '/api/v1/staff/editorial/drafts/'.$owned->getKey(), $bearer, new stdClass()],
            'foreign' => ['GET', '/api/v1/staff/editorial/drafts/'.$foreign->getKey(), $bearer, new stdClass()],
        ]);
        foreach ($replay as $response) {
            if ($response['code'] !== 403
                || str_contains($response['body'], $owned->body)
                || str_contains($response['body'], $foreign->body)) {
                throw new RuntimeException('Deactivated editor retained private read access.');
            }
        }

        // A subsequent explicit bearer revocation must also deny replay.
        PersonalAccessToken::findToken($bearer)->delete();
        $revoked = $parallel([
            'index' => ['GET', '/api/v1/staff/editorial/drafts', $bearer, new stdClass()],
            'detail' => ['GET', '/api/v1/staff/editorial/drafts/'.$owned->getKey(), $bearer, new stdClass()],
        ]);
        if ($revoked['index']['code'] !== 401 || $revoked['detail']['code'] !== 401
            || DB::table('security_audit_events')->where('staff_member_id', $editor->getKey())->exists()) {
            throw new RuntimeException('Deactivated editor bearer revocation was not enforced.');
        }
        echo 'PASS editorial read/deactivation/'.$action." (HTTP 403 after SQL lock, 401 after token revocation)\n";
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
