<?php

namespace App\Support\JobFreshness\Demo;

use Illuminate\Support\Facades\Http;
use PDO;
use Throwable;

/**
 * The safeguards of the isolated freshness UI demo. The demo runs in its OWN process, on a throwaway SQLite file, with an explicit marker; it refuses to migrate or seed
 * anything that could be the production database, and its process cannot reach a provider or start background work.
 *
 *   marker       FRESHNESS_DEMO=1 in that process's environment (`job_freshness.demo`)
 *   path         absolute, ends `.sqlite`, file name starts `freshness-demo`, is not the production file by path, symlink, hard link or directory
 *   active DB    the process's own default connection IS that file (so `migrate` and the seeder can only touch it)
 *   queue        `QUEUE_CONNECTION=discard`: a dispatched inspection, check or resume job is discarded, not run and not stored
 *   network      Http::preventStrayRequests(): any outbound request throws
 *   not reused   a file that already holds job rows is seeded only if it carries this demo's marker (PRAGMA user_version)
 */
final class DemoGuard
{
    public const USER_VERSION_MARKER = 20261124;

    /**
     * @param  array{demo?: bool, active_database?: string|null, active_driver?: string|null, queue?: string|null, production?: list<string>}|null  $ctx  overridable for tests
     *
     * @throws DemoRefused
     */
    public static function assertSafeToSeed(string $candidate, ?array $ctx = null): void
    {
        $ctx ??= self::context();

        if (($ctx['demo'] ?? false) !== true) {
            throw new DemoRefused('demo_marker_missing', 'FRESHNESS_DEMO=1 must be set in this process: the demo needs its explicit marker.');
        }
        if ($candidate === '' || $candidate === ':memory:' || str_starts_with($candidate, 'file:') || ! str_starts_with($candidate, '/') || ! str_ends_with($candidate, '.sqlite') || ! str_starts_with(basename($candidate), 'freshness-demo')) {
            throw new DemoRefused('not_a_demo_path', 'The database must be an absolute path to a file named freshness-demo*.sqlite.');
        }
        self::assertNotProduction($candidate, $ctx['production'] ?? self::productionPaths());

        if (($ctx['active_driver'] ?? null) !== 'sqlite' || self::resolve((string) ($ctx['active_database'] ?? '')) !== self::resolve($candidate)) {
            throw new DemoRefused('active_database_mismatch', 'This process\'s database is not the demo file: start it with DB_CONNECTION=sqlite and DB_DATABASE set to the same path.');
        }
        if (($ctx['queue'] ?? null) !== 'discard') {
            throw new DemoRefused('queue_not_discard', 'QUEUE_CONNECTION=discard is required so no inspection, check or resume job can be queued or run.');
        }
        if (is_file($candidate) && filesize($candidate) > 0 && self::userVersion($candidate) !== self::USER_VERSION_MARKER && self::hasJobRows($candidate)) {
            throw new DemoRefused('not_a_demo_database', 'The file already holds job rows and does not carry the demo marker: refusing to touch it.');
        }
    }

    /**
     * The checks every process started with FRESHNESS_DEMO=1 passes at boot, whatever it then does (`artisan serve`, `migrate`, tinker): never the production file, never a real queue,
     * never the network.
     *
     * @throws DemoRefused
     */
    public static function assertProcessIsolated(?array $ctx = null): void
    {
        $ctx ??= self::context();

        if (($ctx['active_driver'] ?? null) === 'sqlite' && ($ctx['active_database'] ?? '') !== ':memory:') {
            self::assertNotProduction((string) ($ctx['active_database'] ?? ''), $ctx['production'] ?? self::productionPaths());
        } elseif (! in_array($ctx['active_driver'] ?? null, ['sqlite'], true)) {
            throw new DemoRefused('database_not_sqlite', 'The demo only runs on a SQLite file.');
        }
        if (($ctx['queue'] ?? null) !== 'discard') {
            throw new DemoRefused('queue_not_discard', 'QUEUE_CONNECTION=discard is required in a demo process.');
        }
        Http::preventStrayRequests();
    }

    /** @return array{demo: bool, active_database: string|null, active_driver: string|null, queue: string|null, production: list<string>} */
    public static function context(): array
    {
        $default = (string) config('database.default');

        return [
            'demo' => config('job_freshness.demo') === true,
            'active_database' => config("database.connections.{$default}.database"),
            'active_driver' => config("database.connections.{$default}.driver"),
            'queue' => (string) config('queue.default'),
            'production' => self::productionPaths(),
        ];
    }

    /** @return list<string> every file this project could treat as THE database: the default file and whatever `.env` names */
    public static function productionPaths(): array
    {
        $paths = [database_path('database.sqlite')];
        $env = base_path('.env');
        if (is_file($env) && preg_match('/^\s*DB_DATABASE\s*=\s*["\']?([^"\'\r\n#]+?)["\']?\s*$/m', (string) file_get_contents($env), $m) === 1) {
            $paths[] = str_starts_with($m[1], '/') ? $m[1] : base_path($m[1]);
        }

        return $paths;
    }

    /** @param list<string> $production @throws DemoRefused */
    private static function assertNotProduction(string $candidate, array $production): void
    {
        $resolved = self::resolve($candidate);
        $databaseDir = self::resolve(database_path());
        foreach ($production as $p) {
            $prod = self::resolve($p);
            $same = $resolved === $prod;
            if (! $same && is_file($candidate) && is_file($p)) {
                $a = stat($candidate);
                $b = stat($p);
                $same = $a !== false && $b !== false && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];   // a hard link is the same file
            }
            if ($same) {
                throw new DemoRefused('production_database', 'That is the production database (by path, symlink or hard link): refused.');
            }
        }
        if (str_starts_with($resolved, $databaseDir.'/')) {
            throw new DemoRefused('production_database', 'The project\'s database directory is not a place for a demo file: refused.');
        }
    }

    /** The real path of a file that may not exist yet: its directory's real path plus its name. */
    private static function resolve(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $dir = realpath(dirname($path));

        return ($dir === false ? dirname($path) : $dir).'/'.basename($path);
    }

    public static function userVersion(string $file): int
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        } catch (Throwable) {
            return -1;
        }
    }

    private static function hasJobRows(string $file): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $table = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='job_postings'")->fetchColumn();

            return $table !== false && (int) $pdo->query('SELECT COUNT(*) FROM job_postings')->fetchColumn() > 0;
        } catch (Throwable) {
            return true;   // unreadable: treat as not ours
        }
    }
}
