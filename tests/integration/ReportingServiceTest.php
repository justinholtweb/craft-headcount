<?php

namespace justinholtweb\headcount\tests\integration;

use craft\test\TestCase;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

/**
 * Integration coverage for {@see \justinholtweb\headcount\services\Reporting} —
 * specifically the MRR normalization, which converts every billing interval
 * (day/week/month/year) and interval count to a single monthly figure. A wrong
 * factor here silently misreports revenue.
 *
 * @covers \justinholtweb\headcount\services\Reporting
 */
class ReportingServiceTest extends TestCase
{
    private function makePlan(string $handle, string $interval, int $count, float $price): Plan
    {
        $plan = new Plan();
        $plan->name = ucfirst($handle);
        $plan->handle = $handle;
        $plan->billingInterval = $interval;
        $plan->billingIntervalCount = $count;
        $plan->price = $price;

        self::assertTrue(
            Headcount::getInstance()->plans->savePlan($plan),
            implode(', ', $plan->getErrorSummary(true)),
        );

        return $plan;
    }

    private function makeSubscription(int $planId, float $amount, string $status = Subscription::STATUS_ACTIVE): void
    {
        $subscription = Headcount::getInstance()->subscriptions->createSubscription([
            'planId' => $planId,
            'amount' => $amount,
            'status' => $status,
            'gateway' => 'manual',
            'startDate' => time(),
        ]);

        self::assertInstanceOf(Subscription::class, $subscription);
    }

    public function testMrrNormalizesMonthlyAndYearly(): void
    {
        $monthly = $this->makePlan('monthly', 'month', 1, 20);
        $yearly = $this->makePlan('yearly', 'year', 1, 120);

        $this->makeSubscription($monthly->id, 20);   // 20/month
        $this->makeSubscription($yearly->id, 120);   // 120/year => 10/month

        self::assertSame(30.0, Headcount::getInstance()->reporting->getMRR());
    }

    public function testMrrDividesByBillingIntervalCount(): void
    {
        // Billed $30 every 3 months => $10/month.
        $quarterly = $this->makePlan('quarterly', 'month', 3, 30);
        $this->makeSubscription($quarterly->id, 30);

        self::assertSame(10.0, Headcount::getInstance()->reporting->getMRR());
    }

    public function testMrrExcludesInactiveSubscriptions(): void
    {
        $monthly = $this->makePlan('monthly', 'month', 1, 50);

        $this->makeSubscription($monthly->id, 50, Subscription::STATUS_ACTIVE);
        $this->makeSubscription($monthly->id, 999, Subscription::STATUS_CANCELED);
        $this->makeSubscription($monthly->id, 999, Subscription::STATUS_EXPIRED);

        self::assertSame(50.0, Headcount::getInstance()->reporting->getMRR());
    }

    public function testMrrCountsTrialingAsActive(): void
    {
        $monthly = $this->makePlan('monthly', 'month', 1, 15);
        $this->makeSubscription($monthly->id, 15, Subscription::STATUS_TRIALING);

        self::assertSame(15.0, Headcount::getInstance()->reporting->getMRR());
    }

    public function testActiveMemberCount(): void
    {
        $plan = $this->makePlan('basic', 'month', 1, 10);

        $this->makeSubscription($plan->id, 10, Subscription::STATUS_ACTIVE);
        $this->makeSubscription($plan->id, 10, Subscription::STATUS_TRIALING);
        $this->makeSubscription($plan->id, 10, Subscription::STATUS_CANCELED);

        self::assertSame(2, Headcount::getInstance()->reporting->getActiveMemberCount());
    }
}
