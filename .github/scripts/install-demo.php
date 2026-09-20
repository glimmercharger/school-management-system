<?php
/**
 * Headless Gibbon installer for the disposable live demo.
 *
 * The shipped installer at installer/install.php is a three-step web wizard, so it
 * cannot be driven from CI. This script performs the same work by calling the same
 * Gibbon\Install\Installer API that the wizard's controller calls, in one pass:
 *
 *   1. write config.php                          (wizard step 2)
 *   2. create the database and import gibbon.sql (wizard step 2)
 *   3. create the admin user, write the System   (wizard step 3)
 *      settings, optionally import gibbon_demo.sql
 *
 * Everything is read from the environment so nothing sensitive is ever spliced into
 * a shell command line. It is intended for throwaway instances only: it sets every
 * account to one shared password and disables the phone-home settings.
 *
 * Usage: php .github/scripts/install-demo.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

/**
 * Why this script announces success on stdout instead of relying on its exit code.
 *
 * gibbon.php installs a shutdown function that ends in a bare `exit;`, i.e. exit(0).
 * Shutdown functions run on every kind of termination, and an exit() inside one
 * replaces the status the script asked for -- so once gibbon.php has been included,
 * an uncaught exception, an unreachable database, or even an explicit exit(1) all
 * leave this process with status 0. Verified, not assumed.
 *
 * So the caller must not trust `php install-demo.php` returning 0. This line is
 * printed last, only on a complete install, and .github/workflows/live-demo.yml
 * greps for it. Change it here and there together.
 */
const DEMO_INSTALL_SENTINEL = 'GIBBON_DEMO_INSTALL_OK';

/**
 * Read a required environment variable, or die with a useful message.
 */
function demo_env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
    return $value;
}

function demo_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function demo_step(string $message): void
{
    fwrite(STDOUT, '==> ' . $message . "\n");
}

// Everything this script needs lives in one array. gibbon.php declares a pile of
// globals in whatever scope includes it -- $container, $session, $version, $guid,
// $caching and, the one that bites, $absoluteURL -- so a plain local named after
// any of those would be silently overwritten by the include below.
$demo = [
    'root'          => dirname(__DIR__, 2),
    'dbHost'        => demo_env_required('GIBBON_DB_HOST'),
    'dbName'        => demo_env_required('GIBBON_DB_NAME'),
    'dbUser'        => demo_env_required('GIBBON_DB_USER'),
    'dbPass'        => demo_env('GIBBON_DB_PASS'),
    'url'           => demo_env_required('GIBBON_ABSOLUTE_URL'),
    'adminUsername' => demo_env_required('GIBBON_ADMIN_USERNAME'),
    'password'      => demo_env_required('GIBBON_DEMO_PASSWORD'),
    'adminEmail'    => demo_env('GIBBON_ADMIN_EMAIL', 'admin@example.invalid'),
    'demoData'      => demo_env('GIBBON_DEMO_DATA', 'true') === 'true',
    'timezone'      => demo_env('GIBBON_TIMEZONE', 'UTC'),
    'locale'        => demo_env('GIBBON_LOCALE', 'en_GB'),
    'orgName'       => demo_env('GIBBON_ORG_NAME', 'Gibbon Demo School'),
    'orgNameShort'  => demo_env('GIBBON_ORG_NAME_SHORT', 'GDS'),
    'country'       => demo_env('GIBBON_COUNTRY', 'United Kingdom'),
    'summaryFile'   => demo_env('GIBBON_SUMMARY_FILE'),
];

// The URL ends up in every rendered link, asset src and htmx endpoint, so a
// malformed one produces a site that looks installed but cannot navigate.
if (!filter_var($demo['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $demo['url'])) {
    fwrite(STDERR, "GIBBON_ABSOLUTE_URL is not a valid http(s) URL: {$demo['url']}\n");
    exit(1);
}
// No trailing slash: the templates all render "{{ absoluteURL }}/path".
$demo['url'] = rtrim($demo['url'], '/');

// Importing gibbon.sql is several hundred statements; the web installer raises
// these same limits (installer/install.php).
ini_set('memory_limit', '1024M');
set_time_limit(0);

// gibbon.php redirects to the web installer unless it can see that an install is in
// progress, which it decides from PHP_SELF (Gibbon\Core::isInstalling). Presenting
// ourselves as the installer keeps it from redirecting and from trying to open a
// database connection that does not exist yet. DOCUMENT_ROOT and HTTP_HOST are read
// by gibbon.php's own absoluteURL fallback; the setting we write below is what the
// running site actually uses.
$_SERVER['PHP_SELF']       = '/installer/install.php';
$_SERVER['DOCUMENT_ROOT']  = $demo['root'];
$_SERVER['HTTP_HOST']      = parse_url($demo['url'], PHP_URL_HOST) ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';

chdir($demo['root']);

require_once $demo['root'] . '/version.php';   // $version, $systemRequirements
require_once $demo['root'] . '/gibbon.php';    // $container, $gibbon, $session

/**
 * Run the install. Taking the container and session as arguments (rather than
 * reaching for the globals gibbon.php left behind) keeps this scope clean.
 *
 * @param \Psr\Container\ContainerInterface $container
 * @param \Gibbon\Contracts\Services\Session $session
 */
function install_gibbon_demo($container, $session, array $demo): void
{
    // Twig reads absolutePath as a global, and the installer templates expect it.
    $session->set('absolutePath', $demo['root']);
    $session->set('stringReplacement', []);

    // Context::fromEnvironment() under CLI reports "not Apache", which makes the
    // mod_rewrite check a no-op; the real Apache config is asserted by the workflow.
    $context = \Gibbon\Install\Context::fromEnvironment()->setInstallPath($demo['root']);

    // Refuse to clobber an existing install rather than half-overwriting one.
    $context->validateConfigPath();

    $config = (new \Gibbon\Install\Config())
        ->setDatabaseInfo($demo['dbHost'], $demo['dbName'], $demo['dbUser'], $demo['dbPass'])
        ->setGuid(\Gibbon\Install\Installer::randomGuid());

    $installer = new \Gibbon\Install\Installer($container->get('twig'));

    demo_step('Writing config.php');
    $installer->createConfigFile($context, $config);

    demo_step("Connecting to {$demo['dbUser']}@{$demo['dbHost']} and selecting `{$demo['dbName']}`");
    $installer->useConfigConnection($config);   // creates the database if it is missing

    demo_step('Importing gibbon.sql (schema and base data)');
    $installer->install($context, $demo['locale']);

    demo_step("Creating administrator '{$demo['adminUsername']}'");
    // Mirrors InstallController::parseUserSubmission: passwordStrong is
    // hash('sha256', passwordStrongSalt . password), which is what login verifies
    // via Aura's PasswordVerifier('sha256') (src/Services/AuthServiceProvider.php).
    $salt = getSalt();
    $installer->createUser([
        'title'               => '',
        'surname'             => 'Demo',
        'firstName'           => 'Admin',
        'preferredName'       => 'Admin',
        'officialName'        => 'Admin Demo',
        'username'            => $demo['adminUsername'],
        'passwordStrong'      => hash('sha256', $salt . $demo['password']),
        'passwordStrongSalt'  => $salt,
        'status'              => 'Full',
        'canLogin'            => 'Y',
        'passwordForceReset'  => 'N',
        'gibbonRoleIDPrimary' => '001',   // Administrator
        'gibbonRoleIDAll'     => '001',
        'email'               => $demo['adminEmail'],
    ]);
    $installer->setPersonAsStaff(1, 'Teaching');

    demo_step('Writing System settings');
    // Mirrors InstallController::parsePostInstallSettings. statsCollection,
    // registerGibbonSupport and cuttingEdgeCode are all forced off: a throwaway CI
    // instance must not phone home to gibbonedu.org or try to update itself.
    $settings = [
        'System' => [
            'absoluteURL'                  => $demo['url'],
            'absolutePath'                 => $demo['root'],
            'systemName'                   => 'Gibbon',
            'organisationName'             => $demo['orgName'],
            'organisationNameShort'        => $demo['orgNameShort'],
            'organisationEmail'            => $demo['adminEmail'],
            'organisationAdministrator'    => '1',
            'organisationDBA'              => '1',
            'organisationHR'               => '1',
            'organisationAdmissions'       => '1',
            'gibboneduComOrganisationName' => '',
            'gibboneduComOrganisationKey'  => '',
            'currency'                     => 'USD $',
            'country'                      => $demo['country'],
            'timezone'                     => $demo['timezone'],
            'installType'                  => 'Testing',
            'statsCollection'              => 'N',
            'cuttingEdgeCode'              => 'N',
            'registerGibbonSupport'        => 'N',
        ],
        'Finance' => [
            'email' => $demo['adminEmail'],
        ],
    ];

    $failed = [];
    foreach ($settings as $scope => $scopeSettings) {
        foreach ($scopeSettings as $name => $value) {
            if (!$installer->setSetting($name, (string) $value, $scope)) {
                $failed[] = "{$scope}.{$name}";
            }
        }
    }
    if (!empty($failed)) {
        fwrite(STDERR, 'Failed to write settings: ' . implode(', ', $failed) . "\n");
        exit(1);
    }

    // Read absoluteURL back rather than trusting the write: if it is wrong, every
    // link and asset on the site points somewhere unreachable.
    $stored = $installer->getSetting('absoluteURL');
    if ($stored !== $demo['url']) {
        fwrite(STDERR, "absoluteURL was stored as '{$stored}', expected '{$demo['url']}'.\n");
        exit(1);
    }
    demo_step("Site will render all links against {$stored}");

    if ($demo['demoData']) {
        demo_step('Importing gibbon_demo.sql (demo school: students, classes, timetables)');
        if (!$installer->installDemoData($context)) {
            fwrite(STDERR, "Demo data import failed.\n");
            exit(1);
        }
    } else {
        demo_step('Skipping demo data');
    }

    $pdo = $installer->getPDO();

    // Give every account that can log in the same password, so the demo can be
    // explored as a teacher, student or parent and not only as the administrator.
    // failCount is cleared because Gibbon locks an account at three failures
    // (src/Auth/Adapter/AuthenticationAdapter.php).
    demo_step('Setting one shared password on every account that can log in');
    $reset = $pdo->prepare(
        "UPDATE gibbonPerson
            SET passwordStrong = SHA2(CONCAT(passwordStrongSalt, :password), 256),
                passwordForceReset = 'N',
                failCount = 0
          WHERE canLogin = 'Y'
            AND passwordStrongSalt IS NOT NULL
            AND passwordStrongSalt != ''"
    );
    $reset->execute([':password' => $demo['password']]);
    // rowCount() on an UPDATE reports rows *changed*, so it reads 0 when the only
    // account is the admin we just created with this very password. Count the
    // eligible accounts separately, or the log looks like a failure.
    $loginable = (int) $pdo->query(
        "SELECT COUNT(*) FROM gibbonPerson
          WHERE canLogin = 'Y' AND passwordStrongSalt IS NOT NULL AND passwordStrongSalt != ''"
    )->fetchColumn();
    demo_step($loginable . ' account(s) can now log in with the demo password');

    // Gibbon picks the academic year by status, not by date
    // (SchoolYearGateway::getCurrentSchoolYear), and refuses to start without one.
    $year = $pdo->query("SELECT name, firstDay, lastDay FROM gibbonSchoolYear WHERE status='Current'")
                ->fetch(\PDO::FETCH_ASSOC);
    if (empty($year)) {
        fwrite(STDERR, "No academic year is marked Current; Gibbon cannot start without one.\n");
        exit(1);
    }
    demo_step("Current academic year: {$year['name']} ({$year['firstDay']} to {$year['lastDay']})");

    // The bundled demo data is fixed to one academic year, and nothing renumbers it.
    // Once today falls outside that range the site still works -- getCurrentSchoolYear()
    // selects on status, not on dates -- but anything scoped to "today" (timetable,
    // attendance, the daily widgets) comes up empty. Shifting the year row alone would
    // not help: the terms, timetable days and attendance rows all carry their own dates.
    // So report it instead of silently shipping a demo that looks half-broken.
    $today = date('Y-m-d');
    $yearIsStale = $today < $year['firstDay'] || $today > $year['lastDay'];
    if ($yearIsStale) {
        demo_step("Note: today ({$today}) is outside that range, so date-scoped views will be empty");
    }

    if ($demo['summaryFile'] === '') {
        demo_step('Install complete: ' . DEMO_INSTALL_SENTINEL);
        return;
    }

    // One real login per role, for the run summary.
    $lines = [
        '| Role | Username | Password |',
        '| --- | --- | --- |',
        sprintf('| Administrator | `%s` | `%s` |', $demo['adminUsername'], $demo['password']),
    ];
    if ($demo['demoData']) {
        foreach (['002' => 'Teacher', '003' => 'Student', '004' => 'Parent'] as $roleID => $roleName) {
            $sample = $pdo->prepare(
                "SELECT username, preferredName, surname
                   FROM gibbonPerson
                  WHERE gibbonRoleIDPrimary = :roleID AND canLogin = 'Y' AND status = 'Full'
                  ORDER BY surname LIMIT 1"
            );
            $sample->execute([':roleID' => $roleID]);
            if ($row = $sample->fetch(\PDO::FETCH_ASSOC)) {
                $lines[] = sprintf(
                    '| %s (%s %s) | `%s` | `%s` |',
                    $roleName,
                    $row['preferredName'],
                    $row['surname'],
                    $row['username'],
                    $demo['password']
                );
            }
        }
    }
    $lines[] = '';
    $lines[] = sprintf(
        'Academic year: **%s** (%s to %s). Demo data: **%s**.',
        $year['name'],
        $year['firstDay'],
        $year['lastDay'],
        $demo['demoData'] ? 'loaded' : 'not loaded'
    );
    if ($yearIsStale) {
        $lines[] = '';
        $lines[] = sprintf(
            'Today (%s) falls outside that year, which is as far as the bundled demo data goes. '
            . 'Everything works, but views scoped to *today* -- timetable, attendance, the daily '
            . 'dashboard widgets -- will be empty. Use the year switcher or browse by date to see data.',
            $today
        );
    }

    file_put_contents($demo['summaryFile'], implode("\n", $lines) . "\n");
    demo_step('Install complete: ' . DEMO_INSTALL_SENTINEL);
}

try {
    install_gibbon_demo($container, $session, $demo);
} catch (\Throwable $e) {
    // Without this, an exception becomes a PHP fatal, and gibbon.php's shutdown
    // handler renders its full HTML error page into the CI log.
    // Gibbon's installer exceptions carry HTML markup, which is noise in a CI log.
    fwrite(STDERR, 'Install failed: ' . trim(strip_tags($e->getMessage())) . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);   // best effort; see DEMO_INSTALL_SENTINEL above for why the caller checks stdout
}
