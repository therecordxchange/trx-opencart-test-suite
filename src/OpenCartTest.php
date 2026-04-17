<?php

use PHPUnit\Framework\TestCase;
use trx\Services\FeatureFlag;

// abstract class OpenCartTest extends \PHPUnit\Framework\TestCase {
abstract class OpenCartTest extends TestCase
{
    use \ControllerLoadingTrait;

    // TRX-3869: $registry is now accessed via __get() to enable lazy initialization.
    // This prevents "Too many connections" errors during PHPUnit test discovery.
    // DO NOT declare $registry as a property - it must go through __get().
    private $_registry = null;
    
    protected $front;
    protected static $tablesCreated = false;
    
    /**
     * Flag to track if init() has been called for this test instance.
     * Prevents multiple initializations and allows lazy-loading.
     */
    private bool $_initialized = false;

    public function __construct(string $name = '')
    {
        parent::__construct($name);

        // TRX-3869: Moved init() call to lazy initialization.
        // PHPUnit instantiates all test objects during discovery, which was creating
        // 181+ DB connections before any tests ran, exhausting MySQL's max_connections.
        // Now DB connections are only created when tests actually run (via __get).
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Lazy-initialize OpenCart environment on first setUp() call
        $this->_ensureInitialized();

        // Load controller files for coverage tracking
        $this->loadControllersForCoverage();

        // // Check if the test class uses DatabaseTransactions
        // if (in_array(DatabaseTransactions::class, class_uses($this))) {
        //     $this->startTransactions();
        // }
    }
    
    /**
     * Ensure the OpenCart environment is initialized.
     * Can be called multiple times safely - only initializes once.
     */
    protected function _ensureInitialized(): void
    {
        if (!$this->_initialized) {
            // Set flag BEFORE init() to prevent recursion when init() uses $this->request etc.
            $this->_initialized = true;
            $this->init();
        }
    }

    protected function tearDown(): void
    {
        // // Check if the test class uses DatabaseTransactions
        // if (in_array(DatabaseTransactions::class, class_uses($this))) {
        //     $this->rollbackTransactions();
        // }

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
        if ($this->_registry && $this->_registry->get('db')) {
            $this->db = $this->_registry->get('db');
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
        // Handle direct access to $this->registry
        if ($key === 'registry') {
            $this->_ensureInitialized();
            return $this->_registry;
        }
        
        // Lazy-initialize if accessing registry before setUp() is called
        // This maintains backward compatibility with tests that access
        // registry properties in their setUp() before calling parent::setUp()
        $this->_ensureInitialized();
        return $this->_registry->get($key);
    }

    public function __set($key, $value)
    {
        // Handle direct assignment to $this->registry
        if ($key === 'registry') {
            $this->_registry = $value;
            return;
        }
        
        // Lazy-initialize if setting registry before setUp() is called
        $this->_ensureInitialized();
        $this->_registry->set($key, $value);
    }

    public function loadConfiguration()
    {
        // Do not return on HTTP_SERVER alone: something may have defined it without loading
        // config.catalog.php, leaving DB_PREFIX undefined and breaking namespaced ActiveRecord models
        // that use \DB_PREFIX for $table_name (e.g. trx\model\, core\, multiseller\model\).
        if (defined('HTTP_SERVER') && defined('DB_PREFIX')) {
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
        $this->_registry = new Registry();

        // Loader
        $loader = new Loader($this->_registry);
        $this->_registry->set('load', $loader);

        // Config
        $config = new Config();
        $this->_registry->set('config', $config);

        //Template Resolver
        $this->_registry->set('template_resolver', new Template_Resolver($this->_registry));

        // Database
        $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
        $this->db = $db;
        $this->_registry->set('db', $db);

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
        $this->_registry->set('url', $url);

        // Request
        $request = new Request();
        $this->_registry->set('request', $request);

        // Response - Using Test Response - Redirects are disabled.
        $response = new TestResponse();

        $response->addHeader('Content-Type: text/html; charset=utf-8');
        $response->setCompression($config->get('config_compression'));
        $this->_registry->set('response', $response);

        // Cache
        $cache = new Cache('file', -1);
        $this->_registry->set('cache', $cache);

        // Session
        $session = new Session();
        $this->_registry->set('session', $session);

        // TRX Custom - filemanager provider
        $filemanager = new TrxFileManager($this->_registry);
        $this->_registry->set('filemanager', $filemanager);

        // TRX Custom - FeatureFlag service (if available)
        if (class_exists('\\trx\\Services\\FeatureFlag')) {
            $featureFlag = new FeatureFlag();
            $this->_registry->set('featureFlag', $featureFlag);
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

        $this->_registry->set('language', $language);

        // Document
        $this->_registry->set('document', new Document());

        // Affiliate
        $this->_registry->set('affiliate', new Affiliate($this->_registry));

        if (isset($request->get['tracking'])) {
            setcookie('tracking', $request->get['tracking'], time() + 3600 * 24 * 1000, '/');
        }

        // Currency
        $this->_registry->set('currency', new Currency($this->_registry));

        // Tax
        $this->_registry->set('tax', new Tax($this->_registry));

        // Weight
        $this->_registry->set('weight', new Weight($this->_registry));

        // Length
        $this->_registry->set('length', new Length($this->_registry));

        // Event
        $this->_registry->set('event', new Event($this->_registry));

        // Mail
        $this->_registry->set('mail', new TestMail($this->_registry));

        // Encryption
        $this->_registry->set('encryption', new Encryption($config->get('config_encryption')));

        // Log
        $this->_registry->set('log', new Log($config->get('config_error_filename')));

        // Front Controller
        $this->front = new Front($this->_registry);

        //Codeigniter Helpers
        foreach (glob(DIR_SYSTEM . "helper/*_helper.php") as $filename) {
            require_once($filename);
        }

        $this->request->server['REMOTE_ADDR'] = '127.0.0.1';

        if (self::isAdmin()) {
            $this->request->get['token'] = 'token';
            $this->session->data['token'] = 'token';

            $user = new User($this->_registry);
            $this->_registry->set('user', $user);
            $user->login(ADMIN_USERNAME, ADMIN_PASSWORD);

            $this->front->addPreAction(new Action('common/login/check'));
            $this->front->addPreAction(new Action('error/permission/check'));
        } else {
            $this->_registry->set('cart', new Cart($this->_registry));
            $this->_registry->set('customer', new Customer($this->_registry));

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
        $request = $this->_registry->get('request');
        $request->get['route'] = $route;
        $this->_registry->set('request', $request);

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
