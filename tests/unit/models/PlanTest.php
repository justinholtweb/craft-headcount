<?php

namespace justinholtweb\headcount\tests\unit\models;

use justinholtweb\headcount\models\Plan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \justinholtweb\headcount\models\Plan
 */
class PlanTest extends TestCase
{
    private function plan(array $attributes = []): Plan
    {
        $plan = new Plan();
        foreach ($attributes as $key => $value) {
            $plan->$key = $value;
        }
        return $plan;
    }

    // getIntervalLabel() --------------------------------------------------

    public function testIntervalLabelForSingleUnitIntervals(): void
    {
        self::assertSame('daily', $this->plan(['billingInterval' => 'day'])->getIntervalLabel());
        self::assertSame('weekly', $this->plan(['billingInterval' => 'week'])->getIntervalLabel());
        self::assertSame('monthly', $this->plan(['billingInterval' => 'month'])->getIntervalLabel());
        self::assertSame('yearly', $this->plan(['billingInterval' => 'year'])->getIntervalLabel());
    }

    public function testIntervalLabelDefaultsToMonthly(): void
    {
        // Default billingInterval is 'month', billingIntervalCount is 1.
        self::assertSame('monthly', $this->plan()->getIntervalLabel());
    }

    public function testIntervalLabelForMultiUnitIntervals(): void
    {
        self::assertSame(
            'every 3 months',
            $this->plan(['billingInterval' => 'month', 'billingIntervalCount' => 3])->getIntervalLabel()
        );
        self::assertSame(
            'every 2 weeks',
            $this->plan(['billingInterval' => 'week', 'billingIntervalCount' => 2])->getIntervalLabel()
        );
    }

    public function testIntervalLabelFallsBackToRawIntervalForUnknownSingleUnit(): void
    {
        // Unknown interval with count 1 returns the raw interval string.
        self::assertSame('quarter', $this->plan(['billingInterval' => 'quarter'])->getIntervalLabel());
    }

    // getFormattedPrice() -------------------------------------------------

    public function testFormattedPriceUppercasesCurrencyAndFormatsToTwoDecimals(): void
    {
        self::assertSame(
            'USD 19.00',
            $this->plan(['price' => 19, 'currency' => 'usd'])->getFormattedPrice()
        );
    }

    public function testFormattedPriceAddsThousandsSeparator(): void
    {
        self::assertSame(
            'EUR 1,234.50',
            $this->plan(['price' => 1234.5, 'currency' => 'EUR'])->getFormattedPrice()
        );
    }

    public function testFormattedPriceForZero(): void
    {
        self::assertSame('USD 0.00', $this->plan(['price' => 0])->getFormattedPrice());
    }

    // defineRules() -------------------------------------------------------

    public function testValidPlanPassesValidation(): void
    {
        $plan = $this->plan([
            'name' => 'Pro',
            'handle' => 'pro',
            'billingInterval' => 'month',
            'currency' => 'USD',
            'price' => 29.0,
        ]);

        self::assertTrue($plan->validate(), implode(' / ', $plan->getErrorSummary(true)));
    }

    public function testNameAndHandleAreRequired(): void
    {
        $plan = $this->plan(['billingInterval' => 'month', 'currency' => 'USD']);

        self::assertFalse($plan->validate());
        self::assertArrayHasKey('name', $plan->getErrors());
        self::assertArrayHasKey('handle', $plan->getErrors());
    }

    /**
     * @dataProvider invalidHandleProvider
     */
    public function testHandleMustMatchPattern(string $handle): void
    {
        $plan = $this->plan([
            'name' => 'Plan',
            'handle' => $handle,
            'billingInterval' => 'month',
            'currency' => 'USD',
        ]);

        self::assertFalse($plan->validate(['handle']), "Handle '{$handle}' should be invalid");
    }

    public static function invalidHandleProvider(): array
    {
        return [
            'leading digit' => ['1pro'],
            'space' => ['pro plan'],
            'leading dash' => ['-pro'],
        ];
    }

    /**
     * @dataProvider validHandleProvider
     */
    public function testHandleAcceptsValidValues(string $handle): void
    {
        $plan = $this->plan([
            'name' => 'Plan',
            'handle' => $handle,
            'billingInterval' => 'month',
            'currency' => 'USD',
        ]);

        self::assertTrue($plan->validate(['handle']), "Handle '{$handle}' should be valid");
    }

    public static function validHandleProvider(): array
    {
        return [
            'lowercase' => ['pro'],
            'with dash' => ['pro-plan'],
            'with digits' => ['plan2'],
            // Craft-style handles, refused before 5.3.4.
            'camelCase' => ['proMonthly'],
            'underscore' => ['pro_plan'],
        ];
    }

    public function testNegativePriceIsRejected(): void
    {
        $plan = $this->plan([
            'name' => 'Plan',
            'handle' => 'plan',
            'billingInterval' => 'month',
            'currency' => 'USD',
            'price' => -5.0,
        ]);

        self::assertFalse($plan->validate(['price']));
    }

    public function testUnknownBillingIntervalIsRejected(): void
    {
        $plan = $this->plan([
            'name' => 'Plan',
            'handle' => 'plan',
            'billingInterval' => 'fortnight',
            'currency' => 'USD',
        ]);

        self::assertFalse($plan->validate(['billingInterval']));
    }
}
