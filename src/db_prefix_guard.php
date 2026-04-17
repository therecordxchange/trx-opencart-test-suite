<?php

/**
 * Loaded via Composer "files" autoload when tests/phpunit/vendor/autoload.php runs.
 *
 * OpenCartTest uses lazy init (TRX-3869): init()/loadConfiguration() run from setUp(), but PHPUnit
 * invokes subclass #[Before] hooks before setUp(). Namespaced ActiveRecord models use \DB_PREFIX in
 * static $table_name; without this guard, those hooks can autoload models before DB_PREFIX exists.
 *
 * TEST_DB_PREFIX matches .github test-configs and CI env (see trx-enterprise-php workflows).
 */
if (!defined('DB_PREFIX')) {
    $prefix = getenv('TEST_DB_PREFIX');
    define('DB_PREFIX', ($prefix !== false && $prefix !== '') ? $prefix : 'oc_');
}
