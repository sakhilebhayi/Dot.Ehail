<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Safety\Sms\SmsGateway;
use App\Domain\Verification\DriverEligibility;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that this host and this .env can run Dot.Ehail, and says plainly what is wrong and what it costs.
 *
 * Statuses: PASS (fine), INFO (a fact worth knowing, not a problem), OFF (an optional provider that is switched off,
 * never an error), WARN (works, but something people will notice: often an owner step), FAIL (broken or unsafe).
 * Exit code 1 when anything FAILS (with --strict, when anything WARNS too), otherwise 0. --json prints the rows.
 *
 * deploy.yml runs it on the host after the migrations, the reference installs and the caches, before the site leaves
 * maintenance mode: a FAIL keeps the site down with the reason. The limits it reads are those of the PHP binary that
 * runs this command (the CLI); the website's PHP can differ (cPanel MultiPHP INI Editor), which the row says.
 */
class EhailPreflight extends Command
{
    public const PASS = 'PASS';

    public const INFO = 'INFO';

    public const OFF = 'OFF';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** Smallest PHP the product runs on (composer.json "php": "^8.3"). */
    private const MIN_PHP_ID = 80300;

    /** Every task the product schedules (routes/console.php and the domain providers). A missing one is a FAIL. */
    public const EXPECTED_SCHEDULE = [
        'ehail:expire-rides',
        'ehail:dispatch-tick',
        'ehail:publish-metrics',
        'ehail:pricing:prune-quotes',
        'ehail:geo-purge',
        'ehail:safety-sweep',
        'ehail:support-sla',
        'ehail:cancellation-patterns',
        'ehail:verification-expiry',
        'money:sweep',
        'money:evaluate-incentives',
        'money:draft-payouts',
        'money:reconcile',
        'business:sweep',
        'business:statements',
        'ehail:comms-deliver',
        'ehail:comms-prune',
        'ehail:privacy-purge',
    ];

    protected $signature = 'ehail:preflight
        {--json : Print the results as JSON}
        {--strict : Exit 1 on warnings as well as failures}';

    protected $description = 'Check PHP, limits, folders, configuration, providers and reference data; exit 1 if anything required is wrong';

    /** @var list<array{status: string, area: string, check: string, detail: string}> */
    private array $results = [];

    /** @var list<string> what stops a passenger from booking right now, filled while the checks run */
    private array $bookingBlockers = [];

    public function handle(): int
    {
        $this->results = [];
        $this->bookingBlockers = [];

        $this->php();
        $this->limits();
        $this->application();
        $databaseUp = $this->database();
        $this->storage();
        $this->frontend();
        $this->queueAndSchedule($databaseUp);
        $this->providers();
        $this->operatorIdentity();

        if ($databaseUp) {
            $this->referenceData();
            $this->drivers();
        } else {
            $this->bookingBlockers[] = 'the database is unreachable or not migrated';
        }

        $this->readiness();

        return $this->report();
    }

    // ------------------------------------------------------------------ PHP

    private function php(): void
    {
        if (PHP_VERSION_ID >= self::MIN_PHP_ID) {
            $this->add(self::PASS, 'PHP', 'Version', PHP_VERSION.' (8.3 or newer is required; CI runs 8.4)');
        } else {
            $this->add(self::FAIL, 'PHP', 'Version', PHP_VERSION.' is too old; composer.json needs PHP 8.3 or newer.');
        }

        $required = self::requiredExtensions((string) config('database.default'));
        $missing = array_values(array_filter($required, fn (string $e) => ! extension_loaded($e)));

        if ($missing === []) {
            $this->add(self::PASS, 'PHP', 'Extensions', implode(', ', $required));
        } else {
            $this->add(self::FAIL, 'PHP', 'Extensions', 'Missing: '.implode(', ', $missing).'. Enable them for this PHP version in cPanel (Select PHP Version, Extensions).');
        }

        if (extension_loaded('gd')) {
            $info = gd_info();
            $lacking = [];

            foreach (['JPEG Support' => 'JPEG', 'PNG Support' => 'PNG', 'WebP Support' => 'WebP'] as $key => $label) {
                if (empty($info[$key])) {
                    $lacking[] = $label;
                }
            }

            $this->add(
                $lacking === [] ? self::PASS : self::FAIL,
                'PHP',
                'GD formats',
                $lacking === []
                    ? 'JPEG, PNG and WebP: driver document photos are re-encoded, which strips their location metadata.'
                    : 'GD lacks '.implode(', ', $lacking).' support: driver document photos in that format are refused.',
            );
        }

        // Web Push signs with ECDSA P-256 and encrypts with AES-128-GCM (RFC 8291); both come from OpenSSL.
        $curves = function_exists('openssl_get_curve_names') ? (openssl_get_curve_names() ?: []) : [];
        $ciphers = function_exists('openssl_get_cipher_methods') ? array_map('strtolower', openssl_get_cipher_methods()) : [];
        $pushReady = in_array('prime256v1', $curves, true) && in_array('aes-128-gcm', $ciphers, true);
        $this->add(
            $pushReady ? self::PASS : (app(VapidKeys::class)->configured() ? self::FAIL : self::WARN),
            'PHP',
            'Web Push crypto',
            $pushReady ? 'OpenSSL has P-256 and AES-128-GCM' : 'OpenSSL lacks the P-256 curve or AES-128-GCM: push notifications cannot be signed or encrypted.',
        );

        $opcache = extension_loaded('Zend OPcache');
        $this->add($opcache ? self::PASS : self::WARN, 'PHP', 'OPcache', $opcache ? 'loaded' : 'not loaded; every request recompiles the code (and the 2-3 s ride polls multiply that). Enable it in cPanel.');
    }

    /**
     * @return list<string>
     */
    public static function requiredExtensions(string $driver): array
    {
        $required = ['mbstring', 'intl', 'curl', 'fileinfo', 'gd', 'openssl', 'ctype', 'json', 'pdo', 'tokenizer', 'dom', 'xml'];
        $dbExtension = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'mysql', 'mariadb' => 'pdo_mysql',
            default => null,
        };

        if ($dbExtension !== null) {
            $required[] = $dbExtension;
        }

        return $required;
    }

    private function limits(): void
    {
        $this->addAll(self::evaluateLimits([
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ], self::largestUploadBytes()));

        $this->add(self::INFO, 'PHP limits', 'Which PHP', 'These are the limits of the PHP running this command ('.PHP_SAPI.'). The website can run with different ones: compare them in cPanel, MultiPHP INI Editor.');
    }

    /** The largest file the product accepts: a driver document PDF or photo, or a support attachment. */
    public static function largestUploadBytes(): int
    {
        return max(
            (int) config('ehail_safety.uploads.max_image_kb', 8192),
            (int) config('ehail_safety.uploads.max_pdf_kb', 10240),
        ) * 1024;
    }

    /**
     * Pure: judges the limits it is given, so a test can feed it any host.
     *
     * @param  array<string, string>  $ini
     * @return list<array{status: string, area: string, check: string, detail: string}>
     */
    public static function evaluateLimits(array $ini, int $uploadBytes = 10 * 1024 * 1024): array
    {
        $rows = [];
        $row = function (string $status, string $check, string $detail) use (&$rows): void {
            $rows[] = ['status' => $status, 'area' => 'PHP limits', 'check' => $check, 'detail' => $detail];
        };

        $post = self::bytes($ini['post_max_size'] ?? '8M');
        $upload = self::bytes($ini['upload_max_filesize'] ?? '2M');
        $memory = self::bytes($ini['memory_limit'] ?? '128M');
        $time = (int) ($ini['max_execution_time'] ?? 30);
        $needPost = $uploadBytes + 1024 * 1024;

        if ($upload !== null && $upload > 0 && $upload < $uploadBytes) {
            $row(self::WARN, 'upload_max_filesize', ($ini['upload_max_filesize'] ?? '').' is below the '.self::mb($uploadBytes).' a driver document may be: larger licence or PrDP scans fail with a generic error. Set it to '.self::mb($needPost).'.');
        } else {
            $row(self::PASS, 'upload_max_filesize', ($upload === 0 || $upload === null ? 'unlimited' : (string) ($ini['upload_max_filesize'] ?? '')).' (documents up to '.self::mb($uploadBytes).')');
        }

        if ($post !== null && $post > 0 && $post < 1024 * 1024) {
            $row(self::FAIL, 'post_max_size', ($ini['post_max_size'] ?? '').' is below 1M: even a phone photo of a document is refused.');
        } elseif ($post !== null && $post > 0 && $post < $needPost) {
            $row(self::WARN, 'post_max_size', ($ini['post_max_size'] ?? '').' is smaller than a full-size document plus its form ('.self::mb($needPost).' needed).');
        } else {
            $row(self::PASS, 'post_max_size', ($post === 0 || $post === null ? 'unlimited' : (string) ($ini['post_max_size'] ?? '')));
        }

        if ($memory === null || $memory < 0) {
            $row(self::INFO, 'memory_limit', 'unlimited here; the website may still have a limit.');
        } elseif ($memory < 64 * 1024 * 1024) {
            $row(self::FAIL, 'memory_limit', ($ini['memory_limit'] ?? '').' is too low to decode a document photo at all. Set it to 256M.');
        } elseif ($memory < 256 * 1024 * 1024) {
            $row(self::WARN, 'memory_limit', ($ini['memory_limit'] ?? '').': large camera photos of documents (the app accepts up to 40 megapixels) may run out of memory while they are re-encoded. Set 256M.');
        } else {
            $row(self::PASS, 'memory_limit', (string) ($ini['memory_limit'] ?? ''));
        }

        $row($time === 0 || $time >= 30 ? self::PASS : self::WARN, 'max_execution_time', $time === 0 ? 'unlimited' : "{$time}s".($time < 30 ? ' is short: a routing or payment provider may take up to 10 s.' : ''));

        return $rows;
    }

    /** PHP shorthand ("8M", "2G", "512K", "-1") to bytes; null for unlimited (-1) or empty. */
    public static function bytes(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').' MB';
    }

    // ------------------------------------------------------------------ application

    private function application(): void
    {
        $production = app()->environment('production');
        $env = (string) config('app.env');

        $this->add($production ? self::PASS : self::INFO, 'Application', 'Environment', $production ? 'production' : "{$env}; the deploy forces production on the live host.");

        $this->add(
            filled(config('app.key')) ? self::PASS : self::FAIL,
            'Application',
            'APP_KEY',
            filled(config('app.key'))
                ? 'set. It encrypts driver licence and ID numbers, saved card tokens and ride PINs: back it up with every database backup and never rotate it casually.'
                : 'missing: sessions, encrypted identifiers, card tokens and ride PINs cannot work. Generate it once and never change it.',
        );

        if (config('app.debug')) {
            $this->add($production ? self::FAIL : self::WARN, 'Application', 'APP_DEBUG', 'on: every error would show a stack trace and configuration to visitors.');
        } else {
            $this->add(self::PASS, 'Application', 'APP_DEBUG', 'off');
        }

        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        if ($local) {
            $this->add($production ? self::FAIL : self::INFO, 'Application', 'APP_URL', $url === '' ? 'empty' : "{$url} is a local address: trip-share links, receipts, emails and the Paystack callback would point nowhere.");
        } elseif ($scheme !== 'https') {
            $this->add($production ? self::WARN : self::INFO, 'Application', 'APP_URL', "{$url} is not https: browsers refuse location and push on http, and Paystack needs an https callback.");
        } else {
            $this->add(self::PASS, 'Application', 'APP_URL', $url);
        }

        if ($scheme === 'https') {
            $secure = config('session.secure');
            $this->add($secure === true ? self::PASS : self::WARN, 'Application', 'Session cookie', $secure === true ? 'Secure flag on' : 'SESSION_SECURE_COOKIE is not true, so the session cookie is also sent over http. Set it to true.');
            $hsts = (int) config('security.hsts_seconds', 0);
            $this->add($hsts > 0 ? self::PASS : self::INFO, 'Application', 'HSTS', $hsts > 0 ? "{$hsts} seconds on https responses" : 'off (SECURITY_HSTS_SECONDS=0)');
        }

        $reportOnly = (bool) config('security.csp_report_only', true);
        $this->add(self::INFO, 'Application', 'Content-Security-Policy', $reportOnly ? 'report-only (SECURITY_CSP_REPORT_ONLY=true): violations are reported to /csp-report, nothing is blocked' : 'enforced');

        if ($production) {
            $cached = [
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
                'views' => (glob(storage_path('framework/views/*.php')) ?: []) !== [],
            ];
            $notCached = array_keys(array_filter($cached, fn (bool $v) => ! $v));
            $this->add(
                $notCached === [] ? self::PASS : self::WARN,
                'Application',
                'Caches',
                $notCached === [] ? 'config, routes, events and views are cached' : 'not cached: '.implode(', ', $notCached).' (run config:cache, route:cache, view:cache, event:cache)',
            );
        }

        $logLevel = strtolower((string) config('logging.channels.single.level', 'debug'));
        $this->add(
            $production && $logLevel === 'debug' ? self::WARN : self::PASS,
            'Application',
            'Log level',
            $logLevel.($production && $logLevel === 'debug' ? ': debug floods the log on a platform that polls every few seconds. Set LOG_LEVEL=warning.' : ''),
        );

        $mailer = (string) config('mail.default');
        $mailBad = in_array($mailer, ['log', 'array'], true);
        $this->add(
            $mailBad ? ($production ? self::WARN : self::INFO) : self::PASS,
            'Application',
            'Mail',
            $mailer.($mailBad ? ': nobody receives email. Safety alerts to trusted contacts, receipts, document and password emails are recorded as NOT delivered. Set a real SMTP transport (no-reply@infodot.co.za).' : ''),
        );

        $broadcast = (string) config('broadcasting.default');
        $this->add(
            $production && $broadcast === 'reverb' ? self::WARN : self::PASS,
            'Application',
            'Broadcasting',
            $broadcast.($production && $broadcast === 'reverb' ? ': no Reverb server can run on shared hosting, so every broadcast job fails. Set BROADCAST_CONNECTION=log; the apps poll instead.' : ' (live screens poll every 2-3 s; no websocket is needed)'),
        );

        if (config('ehail_dispatch.broadcast_events') && $broadcast !== 'reverb') {
            $this->add(self::WARN, 'Application', 'Ride event broadcasts', 'EHAIL_BROADCAST_RIDE_EVENTS=true but the broadcaster is not reverb: the extra broadcasts go nowhere. Set it to false on this host.');
        }

        $open = in_array('registration', (array) config('fortify.features'), true);
        $this->add($open ? self::INFO : self::PASS, 'Application', 'Sign-up', $open ? 'public sign-up is OPEN (ALLOW_REGISTRATION=true)' : 'closed; InfoDot creates accounts at first sign-in');
    }

    // ------------------------------------------------------------------ database

    private function database(): bool
    {
        $production = app()->environment('production');
        $driver = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            $this->add(self::PASS, 'Database', 'Connection', $driver.' at '.($driver === 'sqlite' ? 'a file' : (string) config("database.connections.{$driver}.host")));
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Connection', 'Cannot connect ('.class_basename($e).'). Check DB_* in .env.');

            return false;
        }

        if ($driver === 'sqlite') {
            $c = (array) config('database.connections.sqlite');
            $tuned = ($c['transaction_mode'] ?? null) === 'IMMEDIATE' && (int) ($c['busy_timeout'] ?? 0) >= 5000 && strtolower((string) ($c['journal_mode'] ?? '')) === 'wal';
            $settings = sprintf('transaction_mode=%s, busy_timeout=%s, journal_mode=%s', $c['transaction_mode'] ?? 'default', $c['busy_timeout'] ?? 'default', $c['journal_mode'] ?? 'default');
            $this->add(
                $production ? self::WARN : self::INFO,
                'Database',
                'Driver',
                'sqlite: one file, one writer at a time. Every driver posts a location every 4 s and every screen polls; PostgreSQL is the database this platform is built and load-tested for. '.($tuned ? "Tuned ({$settings})." : "Not tuned for concurrent writers ({$settings}); dispatch recommends IMMEDIATE, 10000, wal (docs/R46_DISPATCH.md).")
                .(config('cache.default') === 'database' ? ' The cache (rate limiters, locks) also writes to this file on most requests: CACHE_STORE=file takes that load off it (D-818).' : ''),
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $this->add($production ? self::WARN : self::INFO, 'Database', 'Driver', "{$driver}: the suites run on SQLite and PostgreSQL only; partial unique indexes (one active vehicle, one active ride) are not enforced by MySQL.");
        } else {
            $this->add(self::PASS, 'Database', 'Driver', $driver);
        }

        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->add(self::FAIL, 'Database', 'Migrations', 'The migrations table does not exist. Run php artisan migrate --force.');

                return false;
            }

            $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
            $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

            $this->add(
                $pending === [] ? self::PASS : self::FAIL,
                'Database',
                'Migrations',
                $pending === [] ? count($files).' applied, none pending' : count($pending).' pending (first: '.$pending[0].'). Run php artisan migrate --force.',
            );

            return $pending === [];
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Migrations', 'Could not read the migration state ('.class_basename($e).').');

            return false;
        }
    }

    // ------------------------------------------------------------------ storage

    private function storage(): void
    {
        $dirs = [
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'storage/app' => storage_path('app'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $bad = [];

        foreach ($dirs as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        $this->add($bad === [] ? self::PASS : self::FAIL, 'Storage', 'Writable folders', $bad === [] ? implode(', ', array_keys($dirs)) : 'Not writable or missing: '.implode(', ', $bad));

        foreach (['Driver documents' => (string) config('ehail_safety.uploads.disk'), 'Support attachments' => (string) config('ehail_safety.uploads.support_disk')] as $label => $diskName) {
            $this->privateDisk($label, $diskName);
        }

        $free = @disk_free_space(storage_path());

        if ($free !== false) {
            $this->add(
                $free < 100 * 1048576 ? self::FAIL : ($free < 1024 * 1048576 ? self::WARN : self::PASS),
                'Storage',
                'Free disk space',
                self::mb((int) $free).' free where storage/ lives (documents, logs, rollback copies and backups all land on this account)',
            );
        }
    }

    private function privateDisk(string $label, string $diskName): void
    {
        $disk = config("filesystems.disks.{$diskName}");

        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
            $this->add(self::FAIL, 'Storage', $label, "disk '{$diskName}' is not a configured local disk.");

            return;
        }

        $root = (string) ($disk['root'] ?? '');
        $rootReal = rtrim((string) (realpath($root) ?: $root), '/');
        $inPublic = false;

        foreach (array_unique(array_filter([rtrim(public_path(), '/'), rtrim((string) realpath(public_path()), '/')])) as $publicRoot) {
            if ($root !== '' && (str_starts_with(rtrim($root, '/').'/', $publicRoot.'/') || str_starts_with($rootReal.'/', $publicRoot.'/'))) {
                $inPublic = true;
            }
        }

        if (($disk['visibility'] ?? 'private') !== 'private' || ! empty($disk['url']) || ! empty($disk['serve']) || $inPublic) {
            $this->add(self::FAIL, 'Storage', $label, "disk '{$diskName}' is reachable from the web (public visibility, a url, served, or a folder under public/). Identity documents must stay private.");

            return;
        }

        $existing = $root;

        while ($existing !== '' && $existing !== '/' && ! is_dir($existing)) {
            $existing = dirname($existing);
        }

        $writable = $existing !== '' && is_writable($existing);
        $this->add(
            $writable ? self::PASS : self::FAIL,
            'Storage',
            $label,
            $writable ? "private disk '{$diskName}', outside public/, ".(is_dir($root) ? 'writable' : 'created on the first upload').' ('.str_replace(base_path().'/', '', $root).')' : "{$root} is not writable.",
        );
    }

    // ------------------------------------------------------------------ frontend

    private function frontend(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (! is_file($manifestPath)) {
            $this->add(self::FAIL, 'Frontend', 'Built assets', 'public/build/manifest.json is missing: every page that loads @vite would answer 500.');
        } else {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (! is_array($manifest)) {
                $this->add(self::FAIL, 'Frontend', 'Built assets', 'public/build/manifest.json is not valid JSON.');
            } else {
                $needed = self::viteEntriesInViews();
                $missing = array_values(array_filter($needed, fn (string $e) => ! isset($manifest[$e])));
                $absentFiles = [];

                foreach ($manifest as $entry) {
                    foreach (array_merge([(string) ($entry['file'] ?? '')], (array) ($entry['css'] ?? [])) as $file) {
                        if ($file !== '' && ! is_file(public_path('build/'.$file))) {
                            $absentFiles[] = $file;
                        }
                    }
                }

                if ($missing !== []) {
                    $this->add(self::FAIL, 'Frontend', 'Built assets', 'Missing from the manifest: '.implode(', ', $missing));
                } elseif ($absentFiles !== []) {
                    $this->add(self::FAIL, 'Frontend', 'Built assets', count($absentFiles).' file(s) the manifest names are not in public/build, first: '.$absentFiles[0]);
                } else {
                    $this->add(self::PASS, 'Frontend', 'Built assets', count($needed).' entries used by the views are all built');
                }
            }
        }

        $fontRefs = [];

        foreach (File::allFiles(resource_path('css')) as $css) {
            if (preg_match_all('#/fonts/[A-Za-z0-9._/-]+\.woff2#', (string) file_get_contents($css->getPathname()), $m)) {
                foreach ($m[0] as $ref) {
                    $fontRefs[$ref] = true;
                }
            }
        }

        $fontMissing = array_values(array_filter(array_keys($fontRefs), fn (string $ref) => ! is_file(public_path(ltrim($ref, '/')))));
        $this->add(
            $fontRefs !== [] && $fontMissing === [] ? self::PASS : self::FAIL,
            'Frontend',
            'Self-hosted fonts',
            $fontRefs === [] ? 'The stylesheets reference no font under /fonts.' : ($fontMissing === [] ? count($fontRefs).' font files referenced by the stylesheets are present' : 'Missing: '.implode(', ', $fontMissing)),
        );

        $this->addAll(self::pwaRows(public_path()));
    }

    /**
     * The installable app: manifest, its icons, the service worker at the site root (so its scope is the whole
     * site) and every file the worker precaches. Public so a test can point it at a broken folder.
     *
     * @return list<array{status: string, area: string, check: string, detail: string}>
     */
    public static function pwaRows(string $public): array
    {
        $rows = [];
        $problems = [];
        $manifestFile = $public.'/manifest.webmanifest';
        $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;

        if (! is_array($manifest)) {
            $problems[] = 'manifest.webmanifest is missing or not JSON';
        } else {
            foreach ((array) ($manifest['icons'] ?? []) as $icon) {
                $src = ltrim((string) ($icon['src'] ?? ''), '/');

                if ($src === '' || ! is_file($public.'/'.$src)) {
                    $problems[] = 'manifest icon /'.$src.' is missing';
                }
            }
        }

        $sw = is_file($public.'/sw.js') ? (string) file_get_contents($public.'/sw.js') : null;

        if ($sw === null) {
            $problems[] = 'sw.js is missing at the site root';
        } elseif (preg_match('/const\s+PRECACHE\s*=\s*\[(.*?)\]/s', $sw, $m) && preg_match_all("#'(/[^']+)'#", $m[1], $paths)) {
            foreach ($paths[1] as $path) {
                if (! is_file($public.$path)) {
                    $problems[] = "sw.js precaches {$path}, which is not in public/ (the worker would fail to install)";
                }
            }
        }

        $rows[] = [
            'status' => $problems === [] ? self::PASS : self::FAIL,
            'area' => 'Frontend',
            'check' => 'Installable app (PWA)',
            'detail' => $problems === [] ? 'manifest, icons, /sw.js (scope /) and every precached file are present' : implode('; ', $problems),
        ];

        return $rows;
    }

    /**
     * Every entry named in a literal @vite([...]) in the Blade views. Dynamic arguments are skipped.
     *
     * @return list<string>
     */
    public static function viteEntriesInViews(?string $viewsPath = null): array
    {
        $entries = [];

        foreach (File::allFiles($viewsPath ?? resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all('/@vite\(\s*\[(.*?)\]\s*\)/s', (string) file_get_contents($view->getPathname()), $directives)) {
                foreach ($directives[1] as $list) {
                    if (preg_match_all('/[\'"](resources\/[^\'"]+)[\'"]/', $list, $m)) {
                        foreach ($m[1] as $entry) {
                            $entries[$entry] = true;
                        }
                    }
                }
            }
        }

        $entries = array_keys($entries);
        sort($entries);

        return $entries;
    }

    // ------------------------------------------------------------------ queue and schedule

    private function queueAndSchedule(bool $databaseUp): void
    {
        $production = app()->environment('production');
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add($production ? self::WARN : self::INFO, 'Queue', 'Queue driver', 'sync: settlement, receipts, webhooks and notifications run inside the request. Use database on the live host.');
        } elseif ($connection === 'database') {
            if (! $databaseUp || ! Schema::hasTable('jobs')) {
                $this->add($databaseUp ? self::FAIL : self::INFO, 'Queue', 'Queue driver', $databaseUp ? 'database, but the jobs table is missing.' : 'database (not read: the database is not ready).');
            } else {
                $oldest = DB::table('jobs')->min('created_at');
                $waiting = DB::table('jobs')->count();
                $age = $oldest ? time() - (int) $oldest : 0;
                $this->add(
                    $age > 900 ? self::WARN : self::PASS,
                    'Queue',
                    'Backlog',
                    $waiting === 0 ? 'database, nothing waiting' : "{$waiting} job(s) waiting, the oldest for ".intdiv($age, 60).' min'.($age > 900 ? ': the cron worker is not draining them (it does not run during maintenance mode).' : ''),
                );

                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
                $this->add($failed > 0 ? self::WARN : self::PASS, 'Queue', 'Failed jobs', $failed > 0 ? "{$failed} failed job(s): php artisan queue:failed to read them, queue:retry all to retry." : 'none');
            }
        } else {
            $this->add(self::INFO, 'Queue', 'Queue driver', $connection);
        }

        $this->add(self::INFO, 'Queue', 'Worker model', 'cron-driven: queue:work --stop-when-empty --tries=3 --max-time=50, every second minute on this account; no daemon (deploy.yml installs the line). Queued: ride settlement and charges, Paystack webhooks, receipts, notifications.');

        $scheduled = [];

        foreach (app(Schedule::class)->events() as $event) {
            $scheduled[] = (string) ($event->command ?? '');
        }

        $missing = array_values(array_filter(self::EXPECTED_SCHEDULE, fn (string $c) => ! collect($scheduled)->contains(fn (string $s) => str_contains($s, ' '.$c) || str_ends_with($s, $c))));
        $this->add(
            $missing === [] ? self::PASS : self::FAIL,
            'Schedule',
            'Registered tasks',
            $missing === [] ? count(self::EXPECTED_SCHEDULE).' tasks: dispatch tick and ride expiry, safety and money sweeps and comms delivery every minute; retention purges (quotes hourly, trails 02:40, comms 03:30, the POPIA privacy purge); verification expiry 05:00; payout drafts daily '.config('money.payouts.schedule_time', '06:00').' acting on ISO weekday '.config('money.payouts.schedule_day', 1).'.' : 'Not scheduled: '.implode(', ', $missing),
        );

        $log = storage_path('logs/cron-scheduler.log');
        $seen = is_file($log) ? time() - (int) filemtime($log) : null;

        if ($seen !== null && $seen < 300) {
            $this->add(self::PASS, 'Schedule', 'Cron seen', 'schedule:run wrote its log '.intdiv($seen, 60).' min ago');
        } else {
            $this->add(
                $production ? self::WARN : self::INFO,
                'Schedule',
                'Cron seen',
                $seen === null
                    ? 'storage/logs/cron-scheduler.log does not exist yet. Without the every-minute scheduler, offers only move on while someone polls, rides never expire and payments are never retried. deploy.yml installs the line after this check (expected on a first deploy).'
                    : 'the scheduler log is '.intdiv($seen, 60).' min old: the cron line is not running (offers stall, rides never expire).',
            );
        }
    }

    // ------------------------------------------------------------------ providers

    private function providers(): void
    {
        $production = app()->environment('production');

        // Routing: the single most important owner step. No road route = no price = no booking.
        try {
            $routing = app(RoutePlanner::class);
            $routingOn = $routing->isConfigured();
            $routingName = $routing->providerName();
        } catch (Throwable $e) {
            $routingOn = false;
            $routingName = (string) config('ehail-geo.routing.driver');
        }

        if ($routingOn) {
            $this->add(self::PASS, 'Providers', 'Routing', "{$routingName}: road distance and duration for quotes, ETAs and billable distance");
        } else {
            $this->bookingBlockers[] = 'no routing provider';
            $this->add(
                self::WARN,
                'Providers',
                'Routing',
                'NOT CONFIGURED (EHAIL_ROUTING_DRIVER='.config('ehail-geo.routing.driver').'). Nobody can book: every quote answers "Route pricing is unavailable" and the fare calculator prices nothing. Set EHAIL_ROUTING_DRIVER=google with EHAIL_GOOGLE_MAPS_SERVER_KEY, or ors with EHAIL_ORS_KEY (docs/R46_OPERATIONS.md, step 1).',
            );
        }

        try {
            $geocoder = app(Geocoder::class);
            $geoName = $geocoder->providerName();
            $geoOn = $geocoder->isConfigured();
            $autocomplete = $geocoder->supportsAutocomplete();
        } catch (Throwable) {
            $geoName = (string) config('ehail-geo.geocoding.driver');
            $geoOn = false;
            $autocomplete = false;
        }

        if (! $geoOn) {
            $this->add(self::WARN, 'Providers', 'Address search', "{$geoName}: not configured. People can still drop a pin on the map, but cannot search an address.");
        } elseif ($geoName === 'nominatim' && str_contains((string) config('ehail-geo.providers.nominatim.url'), 'nominatim.openstreetmap.org')) {
            $this->add($production ? self::WARN : self::INFO, 'Providers', 'Address search', 'public OSM Nominatim: one request per second for the whole platform, search on Enter only (no type-ahead, their policy), no SLA. Fine for a launch, not for a city: move to google, mapbox, ors or your own photon/Nominatim.');
        } else {
            $this->add(self::PASS, 'Providers', 'Address search', $geoName.($autocomplete ? ', search-as-you-type' : ', search on Enter'));
        }

        $tiles = (string) config('ehail-geo.tiles.url');
        $tileHost = (string) parse_url(str_replace(['{z}', '{x}', '{y}', '{s}', '{r}'], '0', $tiles), PHP_URL_HOST);

        if ($tileHost === '') {
            $this->add(self::FAIL, 'Providers', 'Map tiles', "EHAIL_TILES_URL ({$tiles}) is not a URL: every map is blank.");
        } elseif (str_ends_with($tileHost, 'tile.openstreetmap.org')) {
            $this->add($production ? self::WARN : self::INFO, 'Providers', 'Map tiles', 'the OpenStreetMap Foundation tile server: no SLA, and it may block heavy commercial use. Contract a tile provider (Google Map Tiles, Mapbox, MapTiler, Stadia) before traffic grows.');
        } else {
            $this->add(self::PASS, 'Providers', 'Map tiles', $tileHost.' (restrict a keyed URL to this site by HTTP referrer at the provider)');
        }

        // Payments: card (Paystack), cash, business accounts. With neither card nor cash, a passenger cannot book.
        $paystack = app(PaystackConfig::class);
        $cash = (bool) config('money.cash.enabled');
        $webhook = rtrim((string) config('app.url'), '/').'/webhooks/paystack';

        if (! $paystack->isConfigured()) {
            $this->add($cash ? self::OFF : self::WARN, 'Providers', 'Card payments (Paystack)', 'off: no PAYSTACK_SECRET_KEY.'.($cash ? ' Cash rides only.' : ''));
        } elseif ($paystack->isRefused() || ! $paystack->canTakePayments()) {
            $this->add(self::FAIL, 'Providers', 'Card payments (Paystack)', (string) $paystack->notice());
        } elseif ($paystack->isTestMode()) {
            $this->add($production ? self::WARN : self::INFO, 'Providers', 'Card payments (Paystack)', "TEST mode (sk_test_): no real money moves. For launch set the live keys and PAYSTACK_ALLOW_LIVE=true. Webhook URL in the Paystack dashboard: {$webhook}");
        } else {
            $this->add(self::PASS, 'Providers', 'Card payments (Paystack)', "LIVE mode. Webhook URL in the Paystack dashboard must be {$webhook}");
        }

        $this->add($cash ? self::INFO : self::OFF, 'Providers', 'Cash', $cash ? 'accepted (EHAIL_CASH_ENABLED=true); drivers owe the commission, netted from payouts' : 'not accepted (EHAIL_CASH_ENABLED=false)');

        if (! $paystack->canTakePayments() && ! $cash) {
            $this->bookingBlockers[] = 'no way to pay (no usable Paystack key and cash is off)';
            $this->add(self::WARN, 'Providers', 'Way to pay', 'none: with no usable card payments and cash off, every ride request is refused ("no way to collect the fare"), except business-account rides.');
        }

        $transfers = (bool) config('money.payouts.paystack_transfers');

        if (! $transfers) {
            $this->add(self::OFF, 'Providers', 'Payout transfers', 'manual: payout batches are drafted and exported as a bank file; an operator pays them and marks them paid');
        } elseif (! $paystack->canTakePayments()) {
            $this->add(self::FAIL, 'Providers', 'Payout transfers', 'EHAIL_PAYSTACK_TRANSFERS=true but Paystack is not usable: every transfer would fail.');
        } else {
            $this->add($paystack->isTestMode() ? self::INFO : self::PASS, 'Providers', 'Payout transfers', 'Paystack Transfers on'.($paystack->isTestMode() ? ' (test mode: no real money)' : '').'; needs a Registered Business on Paystack with transfers enabled and OTP off');
        }

        $sms = app(SmsGateway::class);
        $smsDriver = (string) config('ehail_safety.sms.driver', 'null');

        if ($smsDriver === 'null' || $smsDriver === '') {
            $this->add(self::OFF, 'Providers', 'SMS', 'off (EHAIL_SMS_DRIVER=null): trusted contacts and the safety team are reached by email and in-app; screens never claim an SMS was sent');
        } elseif (! $sms->isConfigured()) {
            $this->add(self::FAIL, 'Providers', 'SMS', "EHAIL_SMS_DRIVER={$smsDriver} but its credentials are missing (BULKSMS_TOKEN_ID / BULKSMS_TOKEN_SECRET): every safety SMS would fail.");
        } else {
            $this->add(self::PASS, 'Providers', 'SMS', $sms->name());
        }

        $vapidPublic = (string) config('ehail_comms.push.public_key');
        $vapidPrivate = (string) config('ehail_comms.push.private_key');

        if (app(VapidKeys::class)->configured()) {
            $this->add(self::PASS, 'Providers', 'Web Push', 'VAPID keys set; changing them later makes every device switch push on again');
        } elseif ($vapidPublic !== '' || $vapidPrivate !== '') {
            $this->add(self::FAIL, 'Providers', 'Web Push', 'EHAIL_VAPID_PUBLIC_KEY / EHAIL_VAPID_PRIVATE_KEY are set but not a valid pair: run php artisan ehail:comms-vapid-keys and paste all three lines.');
        } else {
            $this->add(self::OFF, 'Providers', 'Web Push', 'off: no VAPID keys (php artisan ehail:comms-vapid-keys makes them). Notifications stay in-app and email.');
        }

        $sso = (string) config('ecosystem.infodot.secret', '');
        $this->add(
            filled($sso) ? self::PASS : ($production ? self::WARN : self::INFO),
            'Providers',
            'InfoDot sign-in',
            filled($sso) ? 'service secret set' : 'INFODOT_SERVICE_SECRET is empty: people cannot sign in through InfoDot, the only way in while sign-up is closed.',
        );

        $this->pair('Dot.Notify email events', ['NOTIFY_INGEST_URL' => config('notify.url'), 'NOTIFY_INGEST_SECRET' => config('notify.secret')], 'off: Dot.Ehail sends its own email; Notify events are not sent');
        $this->pair('Dot.Analytics snapshot', ['ANALYTICS_INGEST_URL' => config('analytics.url'), 'ANALYTICS_INGEST_SECRET' => config('analytics.secret')], 'off: no daily aggregate is sent');

        $people = 0;

        foreach ((array) config('ehail_safety.escalation.levels', []) as $level) {
            $people += count((array) ($level['user_ids'] ?? [])) + count((array) ($level['emails'] ?? []));
        }

        $this->add(
            $people > 0 ? self::PASS : ($production ? self::WARN : self::INFO),
            'Providers',
            'Safety escalation',
            $people > 0 ? "{$people} on-call / manager recipient(s) for unacknowledged incidents" : 'nobody: an SOS nobody acknowledges is recorded as "nobody configured" at each level and only the operators are reminded. Set EHAIL_SAFETY_ONCALL_EMAILS / _USER_IDS and EHAIL_SAFETY_MANAGER_EMAILS / _USER_IDS.',
        );
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function pair(string $label, array $vars, string $offText): void
    {
        $set = array_filter($vars, fn ($v) => filled($v));

        if ($set === []) {
            $this->add(self::OFF, 'Providers', $label, $offText);
        } elseif (count($set) < count($vars)) {
            $this->add(self::WARN, 'Providers', $label, 'partly set: '.implode(' and ', array_keys(array_diff_key($vars, $set))).' is empty, so nothing is sent.');
        } else {
            $url = (string) reset($vars);
            $this->add(str_starts_with($url, 'https://') ? self::PASS : self::WARN, 'Providers', $label, 'on, '.parse_url($url, PHP_URL_HOST).(str_starts_with($url, 'https://') ? '' : ' (not https)'));
        }
    }

    // ------------------------------------------------------------------ reference data

    private function referenceData(): void
    {
        $production = app()->environment('production');

        if (! Schema::hasTable('service_areas') || ! Schema::hasTable('ride_categories') || ! Schema::hasTable('pricing_rule_sets')) {
            $this->add(self::FAIL, 'Reference data', 'Tables', 'service_areas, ride_categories or pricing_rule_sets is missing; run the migrations.');
            $this->bookingBlockers[] = 'reference tables missing';

            return;
        }

        $areas = ServiceArea::query()->where('is_active', true)->count();
        $approximate = ServiceArea::query()->where('is_active', true)->where('is_approximate', true)->count();
        $airports = Zone::query()->where('is_active', true)->where('kind', 'airport')->count();

        if ($areas === 0) {
            $this->bookingBlockers[] = 'no active service area';
            $this->add(self::WARN, 'Reference data', 'Service areas', 'none active: no pickup is inside a served area. Run php artisan ehail:geo-reference-areas (the deploy does) or draw one in /ops.');
        } elseif ($approximate > 0) {
            $this->add($production ? self::WARN : self::INFO, 'Reference data', 'Service areas', "{$areas} active, {$approximate} flagged approximate (hand-drawn outlines from public coordinates), {$airports} airport zone(s). Review the outlines in /ops before launch.");
        } else {
            $this->add(self::PASS, 'Reference data', 'Service areas', "{$areas} active, {$airports} airport zone(s)");
        }

        $categories = RideCategory::query()->where('is_active', true)->count();

        if ($categories === 0) {
            $this->bookingBlockers[] = 'no active ride category';
            $this->add(self::FAIL, 'Reference data', 'Ride categories', 'none active: the catalogue migration (2026_11_10_500100) did not install it, or every category was switched off.');
        } else {
            $this->add(self::PASS, 'Reference data', 'Ride categories', "{$categories} active");
        }

        $inForce = PricingRuleSet::query()
            ->where('status', 'published')
            ->whereNull('retired_at')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()));
        $published = (clone $inForce)->count();
        $real = (clone $inForce)->where('is_example', false)->count();

        if ($published === 0) {
            $this->bookingBlockers[] = 'no published tariff';
            $this->add(self::WARN, 'Reference data', 'Tariff', 'NO published tariff: no prices, so no quotes and no bookings (every option says no_tariff). Publish the real tariff in /ops/pricing (docs/R46_OPERATIONS.md, step 3). The example tariff is installed only on request.');
        } elseif ($real === 0) {
            $this->add($production ? self::WARN : self::INFO, 'Reference data', 'Tariff', "{$published} published rule set(s), ALL the example tariff (placeholder prices, labelled \"Example tariff: review before launch\" to passengers). Publish the reviewed prices and retire the example.");
        } else {
            $this->add(self::PASS, 'Reference data', 'Tariff', "{$real} published rule set(s) in force".($published > $real ? ', plus '.($published - $real).' example' : ''));
        }

        if (Schema::hasTable('commission_plans')) {
            $plans = CommissionPlan::query()->where('status', 'published')->whereNull('retired_at')->count();
            $this->add($plans > 0 ? self::PASS : self::INFO, 'Reference data', 'Commission', $plans > 0 ? "{$plans} published commission plan(s)" : 'no published commission plan: drivers\' earnings use EHAIL_COMMISSION_PERCENT ('.config('money.commission.percent').' %).');
        }

        $operators = User::query()->where('is_platform_operator', true)->count();
        $roles = Schema::hasTable('ops_role_assignments') ? DB::table('ops_role_assignments')->count() : 0;
        $this->add(
            $operators > 0 ? self::PASS : ($production ? self::WARN : self::INFO),
            'Reference data',
            'Operators',
            $operators > 0
                ? "{$operators} platform operator(s), {$roles} role assignment(s)".($roles === 0 ? ' (operators without roles keep full access until an admin assigns roles in /ops/roles)' : '')
                : 'none: nobody can review driver documents, publish a tariff, answer an SOS or pay drivers. Run php artisan ehail:operator you@example.com after your first sign-in.',
        );
    }

    private function drivers(): void
    {
        $enforced = (bool) config('ehail_safety.verification.require_documents', true);
        $approved = DriverProfile::withoutGlobalScopes()->where('status', 'approved')->count();

        if ($approved === 0) {
            $this->add(self::INFO, 'Drivers', 'Verification', 'no approved drivers yet'.($enforced ? '; each new driver must upload every required document and have it approved before going online.' : '.'));

            return;
        }

        $eligible = 0;
        $checked = 0;
        $reasons = [];

        try {
            $gate = app(DriverEligibility::class);

            DriverProfile::withoutGlobalScopes()->where('status', 'approved')->with(['user', 'vehicles'])->orderBy('id')->limit(2000)->get()
                ->each(function (DriverProfile $profile) use ($gate, &$eligible, &$checked, &$reasons): void {
                    $vehicle = $profile->vehicles->firstWhere('is_active', true) ?? $profile->vehicles->first();

                    if ($profile->user === null || $vehicle === null) {
                        $reasons['no_vehicle'] = ($reasons['no_vehicle'] ?? 0) + 1;
                        $checked++;

                        return;
                    }

                    $result = $gate->canGoOnline($profile->user, $vehicle);
                    $checked++;

                    if ($result->allowed()) {
                        $eligible++;

                        return;
                    }

                    foreach (array_slice($result->codes(), 0, 1) as $code) {
                        $reasons[$code] = ($reasons[$code] ?? 0) + 1;
                    }
                });
        } catch (Throwable $e) {
            $this->add(self::WARN, 'Drivers', 'Verification', "{$approved} approved driver(s); could not evaluate the go-online gate (".class_basename($e).').');

            return;
        }

        arsort($reasons);
        $why = $reasons === [] ? '' : ' Main reasons: '.implode(', ', array_map(fn ($code, $n) => "{$code} {$n}", array_keys($reasons), $reasons)).'.';
        $blocked = $checked - $eligible;

        $this->add(
            self::INFO,
            'Drivers',
            'Verification',
            ($enforced ? 'documents required (EHAIL_VERIFICATION_REQUIRE_DOCUMENTS=true). ' : 'documents NOT required (transition window; logged on every go-online). ')
            ."{$approved} approved driver(s): {$eligible} may go online now, {$blocked} are blocked until their documents are uploaded and approved by an operator in /ops.{$why}",
        );
    }

    /** Operator identity on receipts and statements (D-1905): never invented, so ops must see what is missing. */
    private function operatorIdentity(): void
    {
        $missing = \App\Domain\Payments\OperatorIdentity::missing();

        $this->add(
            $missing === [] ? self::PASS : self::WARN,
            'Money documents',
            'Operator details',
            $missing === []
                ? 'legal name, registration, address, VAT position, support contact and EFT details are set'
                : 'Operator details not configured: '.implode('; ', $missing).'. Receipts and statements leave these lines off until they are set.',
        );
    }

    private function readiness(): void
    {
        $this->add(
            $this->bookingBlockers === [] ? self::PASS : self::WARN,
            'Readiness',
            'Can a passenger book?',
            $this->bookingBlockers === [] ? 'yes: routing, a served area, categories, a tariff and a way to pay are all in place' : 'NO: '.implode('; ', array_unique($this->bookingBlockers)).'.',
        );
    }

    // ------------------------------------------------------------------ output

    /** @param  list<array{status: string, area: string, check: string, detail: string}>  $rows */
    private function addAll(array $rows): void
    {
        foreach ($rows as $row) {
            $this->results[] = $row;
        }
    }

    private function add(string $status, string $area, string $check, string $detail): void
    {
        $this->results[] = ['status' => $status, 'area' => $area, 'check' => $check, 'detail' => $detail];
    }

    private function report(): int
    {
        $counts = array_fill_keys([self::PASS, self::INFO, self::OFF, self::WARN, self::FAIL], 0);

        foreach ($this->results as $r) {
            $counts[$r['status']]++;
        }

        $exit = ($counts[self::FAIL] > 0 || ($this->option('strict') && $counts[self::WARN] > 0)) ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $exit === self::SUCCESS, 'counts' => $counts, 'results' => $this->results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $area = null;

        foreach ($this->results as $r) {
            if ($r['area'] !== $area) {
                $area = $r['area'];
                $this->newLine();
                $this->line("<fg=white;options=bold>{$area}</>");
            }

            $colour = match ($r['status']) {
                self::PASS => 'green',
                self::FAIL => 'red',
                self::WARN => 'yellow',
                default => 'gray',
            };
            $this->line(sprintf('  <fg=%s>%-4s</>  %-26s %s', $colour, $r['status'], $r['check'], $r['detail']));
        }

        $this->newLine();
        $summary = sprintf('%d pass, %d info, %d off, %d warning(s), %d failure(s).', $counts[self::PASS], $counts[self::INFO], $counts[self::OFF], $counts[self::WARN], $counts[self::FAIL]);

        if ($exit === self::SUCCESS) {
            $this->info('Preflight OK: '.$summary);
        } else {
            $this->error('Preflight FAILED: '.$summary);
        }

        return $exit;
    }
}
