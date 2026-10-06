<?php

namespace justinholtweb\headcount\tests\integration;

use craft\test\TestCase;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

/**
 * Saving a plan from the control panel. Before 5.3.4 a Craft-style handle failed with only
 * "Couldn't save plan", and a duplicate handle escaped as a database exception.
 *
 * @covers \justinholtweb\headcount\services\Plans::savePlan
 */
class PlanSaveTest extends TestCase
{
    private function plan(string $handle): Plan
    {
        $plan = new Plan();
        $plan->name = 'Pro Monthly';
        $plan->handle = $handle;
        $plan->billingInterval = 'month';
        $plan->price = 10;
        $plan->stripePriceId = 'price_existing123';

        return $plan;
    }

    public function testCamelCaseHandleSaves(): void
    {
        $plan = $this->plan('proMonthly' . random_int(1000, 9999));

        self::assertTrue(Headcount::getInstance()->plans->savePlan($plan), implode(', ', $plan->getErrorSummary(true)));
        self::assertNotNull($plan->id);
    }

    public function testDuplicateHandleIsAFieldError(): void
    {
        $handle = 'dupe' . random_int(1000, 9999);
        $plans = Headcount::getInstance()->plans;

        self::assertTrue($plans->savePlan($this->plan($handle)));

        $second = $this->plan($handle);
        self::assertFalse($plans->savePlan($second));
        self::assertArrayHasKey('handle', $second->getErrors());
    }

    public function testResavingAPlanKeepsItsOwnHandle(): void
    {
        $plans = Headcount::getInstance()->plans;
        $plan = $this->plan('keep' . random_int(1000, 9999));
        self::assertTrue($plans->savePlan($plan));

        $plan->price = 12;
        self::assertTrue($plans->savePlan($plan), implode(', ', $plan->getErrorSummary(true)));
    }
}
