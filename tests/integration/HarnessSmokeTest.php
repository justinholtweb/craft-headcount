<?php

namespace justinholtweb\headcount\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\headcount\Headcount;

/**
 * Verifies the Codeception + Craft harness boots and installs the plugin.
 *
 * If this passes, a real Craft application is running against the test database
 * with Headcount installed (its Install migration having built the schema).
 */
class HarnessSmokeTest extends TestCase
{
    public function testPluginIsInstalledAndServicesResolve(): void
    {
        self::assertTrue(Craft::$app->getPlugins()->isPluginInstalled('headcount'));

        $plugin = Headcount::getInstance();
        self::assertInstanceOf(Headcount::class, $plugin);
        self::assertNotNull($plugin->coupons);
        self::assertNotNull($plugin->subscriptions);
        self::assertNotNull($plugin->reporting);
        self::assertNotNull($plugin->members);
    }

    public function testPluginTablesExist(): void
    {
        $schema = Craft::$app->getDb()->getSchema();
        self::assertNotNull($schema->getTableSchema('{{%headcount_plans}}'));
        self::assertNotNull($schema->getTableSchema('{{%headcount_subscriptions}}'));
        self::assertNotNull($schema->getTableSchema('{{%headcount_coupons}}'));
        self::assertNotNull($schema->getTableSchema('{{%headcount_webhook_logs}}'));
    }
}
