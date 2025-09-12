<?php

use PHPUnit\Framework\TestCase;
use trx\Services\FeatureFlag;

// abstract class OpenCartTest extends \PHPUnit\Framework\TestCase {
abstract class OpenCartTest extends TestCase
{

    protected $registry;
    protected $front;
    protected static $tablesCreated = false;

    public function __construct(string $name = '')
    {
        parent::__construct($name);

        $this->init();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Load controller files for coverage tracking
        $this->loadControllersForCoverage();

        // Check if the test class uses DatabaseTransactions
        if (in_array(DatabaseTransactions::class, class_uses($this))) {
            $this->startTransactions();
        }
    }

    /**
     * Load controller files for coverage tracking
     * This ensures OpenCart controllers are properly tracked by PHPUnit coverage
     */
    protected function loadControllersForCoverage(): void
    {
        // Get the test class name and try to determine the controller path
        $testClass = get_class($this);

        // Extract controller path from test class name
        // E.g., ControllerStudioWorkbenchVoicecopyTest -> studio/workbench_voicecopy
        if (preg_match('/Controller(.+)Test$/', $testClass, $matches)) {
            $controllerPath = $this->convertClassNameToPath($matches[1]);
            $this->loadControllerFile($controllerPath);
        }

        // Also try to auto-detect from test file path
        $this->loadControllerFromTestPath();
    }

    /**
     * Convert CamelCase controller name to OpenCart path format
     * E.g., StudioWorkbenchVoicecopy -> studio/workbench_voicecopy
     */
    protected function convertClassNameToPath(string $className): string
    {
        // Convert CamelCase to snake_case with directory separators
        $path = preg_replace('/([A-Z])/', '_$1', $className);
        $path = trim($path, '_');
        $path = strtolower($path);

        // Convert underscores to directory separators for major sections
        // This is a heuristic - you might need to adjust based on your naming conventions
        $parts = explode('_', $path);

        if (count($parts) >= 2) {
            // Assume first part is the directory, rest form the filename
            $directory = $parts[0];
            $filename = implode('_', array_slice($parts, 1));
            return $directory . '/' . $filename;
        }

        return $path;
    }

    /**
     * Load controller file from test path detection
     */
    protected function loadControllerFromTestPath(): void
    {
        $reflection = new ReflectionClass($this);
        $testFile = $reflection->getFileName();

        // Extract controller path from test file path
        // E.g., /tests/phpunit/opencart/catalog/controller/studio/ControllerStudioWorkbenchVoicecopyTest.php
        // Should map to: /htdocs/catalog/controller/studio/workbench_voicecopy.php

        if (preg_match('/\/tests\/phpunit\/opencart\/(.+)\/([^\/]+)Test\.php$/', $testFile, $matches)) {
            $basePath = $matches[1]; // e.g., catalog/controller/studio
            $testClassName = $matches[2]; // e.g., ControllerStudioWorkbenchVoicecopy

            // Extract just the controller name part
            if (preg_match('/Controller(.+)$/', $testClassName, $controllerMatches)) {
                $controllerName = $this->camelCaseToSnakeCase($controllerMatches[1]);
                $controllerPath = $basePath . '/' . $controllerName . '.php';
                $this->loadControllerFile($controllerPath, false); // Don't convert path
            }
        }
    }

    /**
     * Convert CamelCase to snake_case
     */
    protected function camelCaseToSnakeCase(string $input): string
    {
        // Handle specific cases like "StudioWorkbenchVoicecopy" -> "workbench_voicecopy"
        // Remove the first part if it matches the directory
        $parts = preg_split('/(?=[A-Z])/', $input, -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) > 1) {
            // Skip first part if it's likely a directory name (Studio, Admin, etc.)
            $firstPart = strtolower($parts[0]);
            $remainingParts = array_slice($parts, 1);

            // Check if we should include the first part or skip it
            $controllerName = implode('_', array_map('strtolower', $remainingParts));
            return $controllerName;
        }

        return strtolower($input);
    }

    /**
     * Load a specific controller file for coverage tracking
     */
    protected function loadControllerFile(string $controllerPath, bool $convertPath = true): void
    {
        if ($convertPath) {
            $controllerPath = $this->convertClassNameToPath($controllerPath);
        }

        // Ensure exactly one .php extension
        $controllerPath = rtrim($controllerPath, '.php') . '.php';

        // Try different base paths
        $basePaths = [
            APP_ROOT . 'catalog/controller/',
            APP_ROOT . 'admin/controller/',
            '/var/www/trx-enterprise-php/htdocs/catalog/controller/',
            '/var/www/trx-enterprise-php/htdocs/admin/controller/',
            DIR_APPLICATION . 'controller/',
            DIR_ROOT,
        ];

        foreach ($basePaths as $basePath) {
            $fullPath = $basePath . $controllerPath;

            if (file_exists($fullPath)) {
                require_once $fullPath;
                return;
            }
        }

    // Log for debugging but don't throw exception to avoid breaking coverage
    error_log("Coverage: Could not find controller file for path: {$controllerPath}");
    }

    /**
     * Manually load a controller file for coverage (for use in individual tests)
     */
    protected function loadControllerForCoverage(string $controllerPath): void
    {
        $this->loadControllerFile($controllerPath);
    }

    protected function tearDown(): void
    {
        // Check if the test class uses DatabaseTransactions
        if (in_array(DatabaseTransactions::class, class_uses($this))) {
            $this->rollbackTransactions();
        }

        parent::tearDown();
    }

    protected $db;

    // protected $arConnection;

    protected $transactionStarted = false;

    protected function startTransactions(): void
    {
        if ($this->transactionStarted) {
            return;
        }

        // OpenCart DB Transaction
        if ($this->registry && $this->registry->get('db')) {
            $this->db = $this->registry->get('db');
            $this->db->begin();
        }

        // // ActiveRecord Transaction
        // if (class_exists('ActiveRecord\ConnectionManager')) {
        //     $this->arConnection = ActiveRecord\ConnectionManager::get_connection();
        //     $this->arConnection->transaction();
        // }

        $this->transactionStarted = true;
    }

    protected function rollbackTransactions(): void
    {
        if (!$this->transactionStarted) {
            return;
        }

        // Rollback OpenCart DB
        if (isset($this->db)) {
            $this->db->rollback();
        }

        // // Rollback ActiveRecord
        // if (isset($this->arConnection)) {
        //     $this->arConnection->rollback();
        // }

        $this->transactionStarted = false;
    }
    protected static function isAdmin()
    {
        return preg_match('/^Admin/', get_called_class()) == true;
    }

    protected static function getConfigurationPath()
    {
        if (self::isAdmin()) {
            return CONFIG_ADMIN;
        } else {
            return CONFIG_CATALOG;
        }
    }

    public function __get($key)
    {
        return $this->registry->get($key);
    }

    public function __set($key, $value)
    {
        $this->registry->set($key, $value);
    }

    public function loadConfiguration()
    {
        if (defined('HTTP_SERVER')) {
            return;
        }

        // either load admin or catalog config.php		
        $path = self::getConfigurationPath();

        // Configuration
        if (file_exists($path)) {
            require_once($path);
        } else {
            throw new Exception('OpenCart has to be installed first!');
        }
    }

    public function init(): void
    {
        $this->loadConfiguration();

        // VirtualQMOD
        if (defined('USE_VQMOD')) {
            require_once(APP_ROOT . '/vqmod/vqmod.php');
            VQMod::bootup();

            // VQMODDED Startup
            require_once(VQMod::modCheck(DIR_SYSTEM . 'startup.php'));
        } else {

            // Startup
            require_once(DIR_SYSTEM . 'startup.php');

            // Application Classes
            require_once(modification(DIR_SYSTEM . 'library/customer.php'));
            require_once(modification(DIR_SYSTEM . 'library/affiliate.php'));
            require_once(modification(DIR_SYSTEM . 'library/currency.php'));
            require_once(modification(DIR_SYSTEM . 'library/tax.php'));
            require_once(modification(DIR_SYSTEM . 'library/weight.php'));
            require_once(modification(DIR_SYSTEM . 'library/length.php'));
            require_once(modification(DIR_SYSTEM . 'library/cart.php'));
        }

        // Registry
        $this->registry = new Registry();

        // Loader
        $loader = new Loader($this->registry);
        $this->registry->set('load', $loader);

        // Config
        $config = new Config();
        $this->registry->set('config', $config);

        //Template Resolver
        $this->registry->set('template_resolver', new Template_Resolver($this->registry));

        // Database
        $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
        $this->db = $db;
        $this->registry->set('db', $db);

        // Recreating the database
        if (!self::$tablesCreated) {
            $lines = null;

            if (defined('SQL_FILE')) {
                $file = SQL_FILE;
                $lines = file($file);
            }

            if ($lines) {
                $sql = '';

                foreach ($lines as $line) {
                    if ($line && (substr($line, 0, 2) != '--') && (substr($line, 0, 1) != '#')) {
                        $sql .= $line;

                        if (preg_match('/;\s*$/', $line)) {
                            $sql = str_replace("DROP TABLE IF EXISTS `oc_", "DROP TABLE IF EXISTS `" . DB_PREFIX, $sql);
                            $sql = str_replace("CREATE TABLE `oc_", "CREATE TABLE `" . DB_PREFIX, $sql);
                            $sql = str_replace("INSERT INTO `oc_", "INSERT INTO `" . DB_PREFIX, $sql);

                            $db->query($sql);

                            $sql = '';
                        }
                    }
                }

                $db->query("SET CHARACTER SET utf8");

                $db->query("UPDATE `" . DB_PREFIX . "product` SET `viewed` = '0'");
            }

            self::$tablesCreated = true;
        }

        // assume a HTTP connection
        $sql = "SELECT * FROM " . DB_PREFIX . "store WHERE REPLACE(`url`, 'www.', '') = '" . $db->escape('http://' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . rtrim(dirname($_SERVER['PHP_SELF']), '/.\\') . '/') . "'";
        $store_query = $db->query($sql);

        if ($store_query->num_rows) {
            $config->set('config_store_id', $store_query->row['store_id']);
        } else {
            $config->set('config_store_id', 0);
        }

        // Settings
        $query = $db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '0' OR store_id = '" . (int) $config->get('config_store_id') . "' ORDER BY store_id ASC");

        foreach ($query->rows as $setting) {
            if (!$setting['serialized']) {
                $config->set($setting['key'], $setting['value']);
            } else {
                $config->set($setting['key'], unserialize($setting['value']));
            }
        }

        if (!$store_query->num_rows) {
            $config->set('config_url', HTTP_SERVER);
            $config->set('config_ssl', HTTPS_SERVER);
        }

        // Url
        $url = new Url($config->get('config_url'), $config->get('config_secure') ? $config->get('config_ssl') : $config->get('config_url'));
        $this->registry->set('url', $url);

        // Request
        $request = new Request();
        $this->registry->set('request', $request);

        // Response - Using Test Response - Redirects are disabled.
        $response = new TestResponse();

        $response->addHeader('Content-Type: text/html; charset=utf-8');
        $response->setCompression($config->get('config_compression'));
        $this->registry->set('response', $response);

        // Cache
        $cache = new Cache('file', -1);
        $this->registry->set('cache', $cache);

        // Session
        $session = new Session();
        $this->registry->set('session', $session);

        // TRX Custom - filemanager provider
        $filemanager = new TrxFileManager($this->registry);
        $this->registry->set('filemanager', $filemanager);

        // TRX Custom - FeatureFlag service (if available)
        if (class_exists('\\trx\\Services\\FeatureFlag')) {
            $featureFlag = new FeatureFlag();
            $this->registry->set('featureFlag', $featureFlag);
        }

        // Language Detection
        $languages = array();

        $query = $db->query("SELECT * FROM `" . DB_PREFIX . "language` WHERE status = '1'");

        foreach ($query->rows as $result) {
            $languages[$result['code']] = $result;
        }

        $detect = '';

        if (isset($request->server['HTTP_ACCEPT_LANGUAGE']) && $request->server['HTTP_ACCEPT_LANGUAGE']) {
            $browser_languages = explode(',', $request->server['HTTP_ACCEPT_LANGUAGE']);

            foreach ($browser_languages as $browser_language) {
                foreach ($languages as $key => $value) {
                    if ($value['status']) {
                        $locale = explode(',', $value['locale']);

                        if (in_array($browser_language, $locale)) {
                            $detect = $key;
                        }
                    }
                }
            }
        }

        if (isset($session->data['language']) && array_key_exists($session->data['language'], $languages) && $languages[$session->data['language']]['status']) {
            $code = $session->data['language'];
        } elseif (isset($request->cookie['language']) && array_key_exists($request->cookie['language'], $languages) && $languages[$request->cookie['language']]['status']) {
            $code = $request->cookie['language'];
        } elseif ($detect) {
            $code = $detect;
        } else {
            $code = $config->get('config_language');
        }

        if (!isset($session->data['language']) || $session->data['language'] != $code) {
            $session->data['language'] = $code;
        }

        if (!isset($request->cookie['language']) || $request->cookie['language'] != $code) {
            setcookie('language', $code, time() + 60 * 60 * 24 * 30, '/', $request->server['HTTP_HOST']);
        }

        $config->set('config_language_id', $languages[$code]['language_id']);
        $config->set('config_language', $languages[$code]['code']);

        // Language
        $language = new Language($languages[$code]['directory']);
        $language->setLanguageInfo($languages[$code]);
        //$language->load($languages[$code]['filename']);
        $language->load($languages[$code]['directory']);

        $this->registry->set('language', $language);

        // Document
        $this->registry->set('document', new Document());

        // Affiliate
        $this->registry->set('affiliate', new Affiliate($this->registry));

        if (isset($request->get['tracking'])) {
            setcookie('tracking', $request->get['tracking'], time() + 3600 * 24 * 1000, '/');
        }

        // Currency
        $this->registry->set('currency', new Currency($this->registry));

        // Tax
        $this->registry->set('tax', new Tax($this->registry));

        // Weight
        $this->registry->set('weight', new Weight($this->registry));

        // Length
        $this->registry->set('length', new Length($this->registry));

        // Event
        $this->registry->set('event', new Event($this->registry));

        // Mail
        $this->registry->set('mail', new TestMail($this->registry));

        // Encryption
        $this->registry->set('encryption', new Encryption($config->get('config_encryption')));

        // Log
        $this->registry->set('log', new Log($config->get('config_error_filename')));

        // Front Controller
        $this->front = new Front($this->registry);

        //Codeigniter Helpers
        foreach (glob(DIR_SYSTEM . "helper/*_helper.php") as $filename) {
            require_once($filename);
        }

        $this->request->server['REMOTE_ADDR'] = '127.0.0.1';

        if (self::isAdmin()) {
            $this->request->get['token'] = 'token';
            $this->session->data['token'] = 'token';

            $user = new User($this->registry);
            $this->registry->set('user', $user);
            $user->login(ADMIN_USERNAME, ADMIN_PASSWORD);

            $this->front->addPreAction(new Action('common/login/check'));
            $this->front->addPreAction(new Action('error/permission/check'));
        } else {
            $this->registry->set('cart', new Cart($this->registry));
            $this->registry->set('customer', new Customer($this->registry));

            $this->front->addPreAction(new Action('common/seo_url'));
        }
    }

    public function customerLogin($user, $password, $override = false)
    {
        $logged = $this->customer->login($user, $password, $override);

        //required for ACL 
        //@see oc_events
        //@see catalog/controller/trx/auth.php
        $this->event->trigger('post.customer.login');

        if (!$logged) {
            throw new Exception('Could not login customer');
        }
    }

    public function customerLogout()
    {
        if ($this->customer->isLogged()) {
            $this->customer->logout();
        }
    }

    // legal hack to access a private property, this is only neccessary because
    // my pull request was rejected: https://github.com/opencart/opencart/pull/607
    public function getOutput()
    {

        $class = new ReflectionClass("Response");
        $property = $class->getProperty("output");
        $property->setAccessible(true);
        return $property->getValue($this->response);
    }

    public function dispatchAction($route)
    {

        // Router
        if (!empty($route)) {
            $action = new Action($route);
        } else {
            $action = new Action('common/home');
        }

        // Set request:
        $request = $this->registry->get('request');
        $request->get['route'] = $route;
        $this->registry->set('request', $request);

        // Dispatch
        $this->front->dispatch($action, new Action('error/not_found'));

        return $this->response;
    }

    public function loadModelByRoute($route)
    {
        $this->load->model($route);
        $parts = explode("/", $route);

        $model = 'model';

        foreach ($parts as $part) {
            $model .= "_" . $part;
        }

        return $this->$model;
    }
}
