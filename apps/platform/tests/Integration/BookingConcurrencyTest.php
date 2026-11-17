<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('proves booking exclusivity across simultaneous application instances and direct PostgreSQL writers', function () {
    $db = config('database.connections.pgsql');
    $admin = new PDO('pgsql:host='.$db['host'].';port='.$db['port'].';dbname=postgres', $db['username'], $db['password']);
    $admin->exec("SELECT pg_advisory_lock(hashtext('logistics_booking_race_test'))");
    try {
        if (! $admin->query("SELECT 1 FROM pg_database WHERE datname='logistics_booking_race_test'")->fetchColumn()) {
            $admin->exec('CREATE DATABASE logistics_booking_race_test');
        }
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'logistics_booking_race_test', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'];
        $worker = base_path('tests/Support/booking-race-worker.php');
        $run = function ($mode, $args = []) use ($env, $worker) {
            $p = new Process([PHP_BINARY, $worker, $mode, json_encode($args, JSON_THROW_ON_ERROR)], base_path(), $env, null, 120);
            $p->mustRun();

            return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        };
        $fixture = $run('prepare');
        config(['database.connections.booking_race' => [...$db, 'database' => 'logistics_booking_race_test']]);
        DB::purge('booking_race');
        $connection = DB::connection('booking_race');
        $race = function ($mode, $inputs) use ($env, $worker) {
            $barrier = tempnam(sys_get_temp_dir(), 'booking_barrier_');
            unlink($barrier);
            $processes = [];
            try {
                foreach ($inputs as $input) {
                    $p = new Process([PHP_BINARY, $worker, $mode, json_encode([...$input, 'barrier' => $barrier], JSON_THROW_ON_ERROR)], base_path(), $env, null, 90);
                    $p->start();
                    $processes[] = $p;
                }
                $deadline = microtime(true) + 40;
                do {
                    $ready = true;
                    foreach ($processes as $p) {
                        if (! str_contains($p->getOutput(), 'READY')) {
                            $ready = false;
                        }if (! $p->isRunning()) {
                            throw new RuntimeException($p->getErrorOutput().$p->getOutput());
                        }
                    }if (microtime(true) > $deadline) {
                        throw new RuntimeException('Workers failed to reach the concurrency barrier');
                    }usleep(10000);
                } while (! $ready);
                file_put_contents($barrier, 'go');
                $results = [];
                foreach ($processes as $p) {
                    $p->wait();
                    if (! $p->isSuccessful()) {
                        throw new RuntimeException($p->getErrorOutput().$p->getOutput());
                    }
                    if (preg_match('/RESULT (.+)/', $p->getOutput(), $match) !== 1) {
                        throw new RuntimeException('Missing '.$mode.' worker result: '.$p->getOutput().' STDERR: '.$p->getErrorOutput());
                    }
                    $results[] = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                }

                return $results;
            } finally {
                foreach ($processes as $p) {
                    if ($p->isRunning()) {
                        $p->stop(1);
                    }
                }if (file_exists($barrier)) {
                    unlink($barrier);
                }
            }
        };
        $noOverlap = function () use ($connection) {
            expect((int) $connection->selectOne("SELECT count(*) AS n FROM resource_allocations a JOIN resource_allocations b ON a.tenant_id=b.tenant_id AND a.resource_id=b.resource_id AND a.id<b.id AND tstzrange(a.starts_at,a.ends_at,'[)') && tstzrange(b.starts_at,b.ends_at,'[)') WHERE a.status IN ('held','confirmed') AND b.status IN ('held','confirmed')")->n)->toBe(0);
        };
        $requests = array_map(fn ($q) => ['quote' => $q, 'start' => $fixture['start'], 'key' => (string) Str::uuid()], array_slice($fixture['quotes'], 0, 8));
        $results = $race('hold', $requests);
        expect(array_count_values(array_column($results, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 7]);
        $noOverlap();
        $results = $race('hold', array_map(fn ($r) => [...$r, 'key' => (string) Str::uuid(), 'advance_minutes' => 16], $requests));
        expect(array_count_values(array_column($results, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 7]);
        $noOverlap();
        $winner = collect($results)->firstWhere('outcome', 'ok');
        $key = (string) Str::uuid();
        $confirmed = $race('confirm', array_fill(0, 4, ['hold' => $winner['public_id'], 'key' => $key, 'advance_minutes' => 16]));
        expect(array_unique(array_column($confirmed, 'public_id')))->toHaveCount(1)->and($connection->table('bookings')->where('status', 'confirmed')->count())->toBe(1)->and($connection->table('outbox_messages')->where('event_type', 'booking.confirmed')->count())->toBe(1);
        $noOverlap();
        $booking = $confirmed[0];
        $replacements = $race('hold', array_map(fn ($hours) => ['quote' => $winner['quote'], 'start' => CarbonImmutable::parse($fixture['start'])->addHours($hours)->toIso8601String(), 'key' => (string) Str::uuid(), 'booking' => $booking['public_id'], 'advance_minutes' => 16], [2, 3]));
        expect(array_count_values(array_column($replacements, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 1]);
        $noOverlap();
        $replacement = collect($replacements)->firstWhere('outcome', 'ok');
        $key = (string) Str::uuid();
        $moved = $race('reschedule', array_fill(0, 3, ['booking' => $booking['public_id'], 'hold' => $replacement['public_id'], 'key' => $key, 'version' => $booking['version'], 'advance_minutes' => 16]));
        expect(array_unique(array_column($moved, 'public_id')))->toHaveCount(1)->and($connection->table('resource_allocations')->where('status', 'confirmed')->count())->toBe(3);
        $noOverlap();
        $second = $run('add-fleet', ['owner_id' => $fixture['owner_id']]);
        $free = array_values(array_diff($fixture['quotes'], [$winner['quote']]));
        $separate = $race('hold', [
            ['quote' => $free[0], 'start' => CarbonImmutable::parse($fixture['start'])->addHours(6)->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => $fixture['resources'], 'owner_id' => $fixture['owner_id']],
            ['quote' => $free[1], 'start' => CarbonImmutable::parse($fixture['start'])->addHours(6)->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => $second['resources'], 'owner_id' => $fixture['owner_id']],
        ]);
        expect(array_column($separate, 'outcome'))->toBe(['ok', 'ok']);
        $noOverlap();
        $reversed = $race('hold', array_map(fn ($n) => ['quote' => $free[$n], 'start' => CarbonImmutable::parse($fixture['start'])->addHours(9)->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => $n === 2 ? $fixture['resources'] : array_reverse($fixture['resources']), 'owner_id' => $fixture['owner_id']], [2, 3]));
        expect(array_count_values(array_column($reversed, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 1]);
        $noOverlap();
        $sharedDriver = $race('hold', [
            ['quote' => $free[6], 'start' => CarbonImmutable::parse($fixture['start'])->addHours(11)->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => $fixture['resources'], 'owner_id' => $fixture['owner_id']],
            ['quote' => $free[7], 'start' => CarbonImmutable::parse($fixture['start'])->addHours(11)->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => [$second['resources'][0], $fixture['resources'][1], $second['resources'][2]], 'owner_id' => $fixture['owner_id']],
        ]);
        expect(array_count_values(array_column($sharedDriver, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 1]);
        $noOverlap();
        $direct = $race('raw', array_map(fn ($n) => ['quote' => $free[$n], 'start' => CarbonImmutable::parse($fixture['start'])->addDay()->toIso8601String(), 'key' => (string) Str::uuid(), 'preferred' => $fixture['resources']], [4, 5]));
        expect(array_count_values(array_column($direct, 'outcome')))->toEqual(['ok' => 1, 'conflict' => 1])->and(collect($direct)->firstWhere('outcome', 'conflict')['codes'])->toBe(['23P01']);
        $noOverlap();
    } finally {
        DB::disconnect('booking_race');
        $admin->exec("SELECT pg_advisory_unlock(hashtext('logistics_booking_race_test'))");
    }
});
