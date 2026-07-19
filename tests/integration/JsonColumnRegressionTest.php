<?php

namespace justinholtweb\headcount\tests\integration;

use craft\test\TestCase;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use justinholtweb\headcount\records\DripScheduleRecord;

/**
 * Regression coverage for the JSON `json()`-column double-encoding bug: values
 * are encoded twice on write, so read paths that go through a raw query (or
 * element population) rather than an ActiveRecord attribute must decode
 * defensively. Before the fix these all threw "Cannot assign string to property
 * ... of type ?array" as soon as the column held a value.
 *
 * @covers \justinholtweb\headcount\helpers\Json
 */
class JsonColumnRegressionTest extends TestCase
{
    public function testPlanFeaturesDecodeToArray(): void
    {
        $plan = new Plan();
        $plan->name = 'Featured';
        $plan->handle = 'featured' . random_int(1000, 9999);
        $plan->billingInterval = 'month';
        $plan->price = 10;
        $plan->features = ['Priority support', 'Early access'];
        self::assertTrue(Headcount::getInstance()->plans->savePlan($plan), implode(', ', $plan->getErrorSummary(true)));

        // getPlanById loads via a raw query — this crashed before the fix.
        $loaded = Headcount::getInstance()->plans->getPlanById($plan->id);

        self::assertNotNull($loaded);
        self::assertSame(['Priority support', 'Early access'], $loaded->features);
    }

    public function testSubscriptionMetadataDecodesToArray(): void
    {
        $created = Headcount::getInstance()->subscriptions->createSubscription([
            'amount' => 5,
            'status' => Subscription::STATUS_ACTIVE,
            'gateway' => 'manual',
            'metadata' => ['source' => 'test', 'ref' => 42],
        ]);
        self::assertInstanceOf(Subscription::class, $created);

        // Populating the element from the query row crashed before the fix.
        $loaded = Subscription::find()->id($created->id)->one();

        self::assertNotNull($loaded);
        self::assertSame(['source' => 'test', 'ref' => 42], $loaded->metadata);
    }

    public function testDripSchedulePlanIdsDecodeToArray(): void
    {
        $record = new DripScheduleRecord();
        $record->name = 'Week one';
        $record->type = 'section';
        $record->targetId = 123;
        $record->planIds = json_encode([4, 5]);
        $record->delayDays = 7;
        $record->enabled = true;
        self::assertTrue($record->save(), implode(', ', $record->getErrorSummary(true)));

        $drip = Headcount::getInstance()->drip;
        // The service caches schedules on the singleton; force a reload.
        $this->setInaccessibleProperty($drip, '_schedules', null);

        $schedules = $drip->getAllSchedules();

        self::assertCount(1, $schedules);
        self::assertSame([4, 5], $schedules[0]->planIds);
    }
}
