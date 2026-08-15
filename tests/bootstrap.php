<?php

/**
 * PHPUnit bootstrap.
 *
 * The `unit` test suite exercises pure model logic (formatting + Yii validation
 * rules). `craft\base\Model` is autoloadable from craftcms/cms, and Yii's
 * built-in validators degrade gracefully to plain placeholder substitution when
 * no application instance exists (Yii::t() returns the raw message when
 * Yii::$app is null), so `$model->validate()` works without booting Craft.
 *
 * The one thing Composer's autoloader does not provide is the global `Yii`
 * class itself: Yii2 ships it as a plain non-PSR-4 file that must be required
 * explicitly (it also registers Yii's autoloader and DI container). The
 * validators call `Yii::t()`, so we load it here — no Craft application or
 * database is booted.
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

// Yii2 ships its `Yii` helper as a non-PSR-4 global class, so Composer's
// autoloader never loads it. Requiring it defines `Yii` (with `Yii::$app`
// left null) and lets the validators' `Yii::t()` calls resolve.
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// `Craft` is the same story one level up: a global class in a non-PSR-4 file,
// so it is not autoloadable either. Models reach for `Craft::t()` when building
// validation messages, which inherits Yii's null-app behaviour and returns the
// raw message — but only once the class exists. Still no application, no
// database, no plugin instance.
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
