<?php

/**
 * PHPUnit bootstrap.
 *
 * The `unit` test suite exercises pure model logic (formatting + Yii validation
 * rules) and needs nothing beyond Composer's autoloader: `craft\base\Model` is
 * autoloadable from craftcms/cms, and Yii's built-in validators degrade
 * gracefully to plain placeholder substitution when no application instance
 * exists (Yii::t() returns the raw message when Yii::$app is null), so
 * `$model->validate()` works without booting Craft.
 *
 * The `integration` suite (services, the Subscription element, webhooks) does
 * need a running Craft application and a database — see tests/README.md.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "\nCould not find vendor/autoload.php. Run `composer install` first.\n\n");
    exit(1);
}

require $autoload;
