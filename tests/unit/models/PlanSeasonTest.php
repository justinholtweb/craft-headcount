<?php

namespace justinholtweb\headcount\tests\unit\models;

use DateTime;
use justinholtweb\headcount\models\Plan;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-term ("season") plans: the window a membership is sold into, and what it costs.
 *
 * The arithmetic here is the part a club will notice if it is wrong — a member charged for
 * the wrong number of months, or a card that expires a day early — so it is pinned down
 * against a July–June season, the shape the feature was built for.
 *
 * @covers \justinholtweb\headcount\models\Plan
 */
class PlanSeasonTest extends TestCase
{
    private function season(array $attributes = []): Plan
    {
        $plan = new Plan();
        $plan->name = 'Season';
        $plan->handle = 'season';
        $plan->currency = 'GBP';
        $plan->price = 120.0;
        $plan->termType = Plan::TERM_FIXED;
        $plan->seasonStartDate = new DateTime('2026-07-01 00:00:00');
        $plan->seasonEndDate = new DateTime('2027-06-30 00:00:00');

        foreach ($attributes as $key => $value) {
            $plan->$key = $value;
        }

        return $plan;
    }

    // Season window -------------------------------------------------------

    public function testRecurringPlanHasNoSeasonWindow(): void
    {
        $plan = $this->season(['termType' => Plan::TERM_RECURRING]);

        self::assertNull($plan->getSeasonWindow(new DateTime('2026-08-13')));
    }

    public function testWindowIsTheStoredOneDuringTheSeason(): void
    {
        $window = $this->season()->getSeasonWindow(new DateTime('2026-08-13'));

        self::assertSame('2026-07-01', $window['start']->format('Y-m-d'));
        self::assertSame('2027-06-30', $window['end']->format('Y-m-d'));
    }

    /**
     * The end date is the last day of membership, not the moment it ends: a season ending
     * "30 June" must still be valid at 6pm on 30 June.
     */
    public function testSeasonEndsAtTheEndOfItsLastDay(): void
    {
        $window = $this->season()->getSeasonWindow(new DateTime('2026-08-13'));

        self::assertSame('2027-06-30 23:59:59', $window['end']->format('Y-m-d H:i:s'));
    }

    public function testRepeatingSeasonRollsForwardOnceItHasFinished(): void
    {
        $window = $this->season()->getSeasonWindow(new DateTime('2029-09-01'));

        self::assertSame('2029-07-01', $window['start']->format('Y-m-d'));
        self::assertSame('2030-06-30', $window['end']->format('Y-m-d'));
    }

    public function testRepeatingSeasonRollsForwardOnlyPastItsEnd(): void
    {
        // 30 June is still inside the 2026–27 season, so the window must not move on.
        $window = $this->season()->getSeasonWindow(new DateTime('2027-06-30 12:00:00'));

        self::assertSame('2026-07-01', $window['start']->format('Y-m-d'));
    }

    /**
     * A club setting next season up in advance is selling *that* season — rolling backwards
     * would sell the one that has not started yet as though it were already running.
     */
    public function testSeasonNeverRollsBackwards(): void
    {
        $window = $this->season()->getSeasonWindow(new DateTime('2026-05-01'));

        self::assertSame('2026-07-01', $window['start']->format('Y-m-d'));
    }

    public function testNonRepeatingSeasonStaysPut(): void
    {
        $window = $this->season(['seasonRepeats' => false])->getSeasonWindow(new DateTime('2029-09-01'));

        self::assertSame('2026-07-01', $window['start']->format('Y-m-d'));
    }

    // Term start / end ----------------------------------------------------

    public function testJoiningBeforeTheSeasonStartsBuysFromItsStart(): void
    {
        $start = $this->season()->getTermStart(new DateTime('2026-05-01'));

        self::assertSame('2026-07-01', $start->format('Y-m-d'));
    }

    public function testJoiningMidSeasonStartsImmediately(): void
    {
        $start = $this->season()->getTermStart(new DateTime('2026-10-20 09:30:00'));

        self::assertSame('2026-10-20', $start->format('Y-m-d'));
    }

    public function testEveryoneExpiresOnTheSameDayWheneverTheyJoined(): void
    {
        $plan = $this->season();

        self::assertSame(
            $plan->getTermEnd(new DateTime('2026-07-02'))->format('Y-m-d'),
            $plan->getTermEnd(new DateTime('2027-01-15'))->format('Y-m-d'),
        );
    }

    // Pro-rata ------------------------------------------------------------

    public function testFullPriceWhenProrationIsOff(): void
    {
        $plan = $this->season(['prorate' => false]);

        self::assertSame(120.0, $plan->getProratedPrice(new DateTime('2026-10-20')));
    }

    public function testFullPriceForAnyoneJoiningBeforeTheSeasonOpens(): void
    {
        $plan = $this->season(['prorate' => true]);

        self::assertSame(120.0, $plan->getProratedPrice(new DateTime('2026-05-01')));
    }

    /**
     * October of a July–June season leaves nine months — October included, because a member
     * joining on the 20th still gets the rest of October.
     */
    public function testMonthlyProrationChargesForWholeMonthsRemaining(): void
    {
        $plan = $this->season(['prorate' => true]);

        self::assertSame(90.0, $plan->getProratedPrice(new DateTime('2026-10-20')));
    }

    public function testMonthlyProrationOnTheLastMonthStillChargesOneMonth(): void
    {
        $plan = $this->season(['prorate' => true]);

        self::assertSame(10.0, $plan->getProratedPrice(new DateTime('2027-06-15')));
    }

    public function testDailyProrationIsProportionalToDaysLeft(): void
    {
        $plan = $this->season([
            'prorate' => true,
            'prorationBasis' => Plan::PRORATION_DAY,
        ]);

        // 2026-07-01 .. 2027-06-30 inclusive is 365 days; from 1 January, 181 remain.
        self::assertSame(round(120.0 * 181 / 365, 2), $plan->getProratedPrice(new DateTime('2027-01-01')));
    }

    /**
     * A gateway will reject a zero-value charge, so the last day of the season must still
     * cost something.
     */
    public function testDailyProrationOnTheFinalDayIsStillChargeable(): void
    {
        $plan = $this->season([
            'prorate' => true,
            'prorationBasis' => Plan::PRORATION_DAY,
        ]);

        self::assertGreaterThan(0, $plan->getProratedPrice(new DateTime('2027-06-30 09:00:00')));
    }

    public function testRecurringPlansAreNeverProrated(): void
    {
        $plan = $this->season(['termType' => Plan::TERM_RECURRING, 'prorate' => true]);

        self::assertSame(120.0, $plan->getProratedPrice(new DateTime('2026-10-20')));
    }

    // Sellability ---------------------------------------------------------

    public function testRepeatingSeasonIsAlwaysStillSellable(): void
    {
        self::assertFalse($this->season()->hasSeasonEnded(new DateTime('2035-01-01')));
    }

    public function testOneOffSeasonStopsSellingOnceItIsOver(): void
    {
        $plan = $this->season(['seasonRepeats' => false]);

        self::assertFalse($plan->hasSeasonEnded(new DateTime('2027-06-30 09:00:00')));
        self::assertTrue($plan->hasSeasonEnded(new DateTime('2027-07-01 09:00:00')));
    }

    public function testRecurringPlansNeverEnd(): void
    {
        $plan = $this->season(['termType' => Plan::TERM_RECURRING, 'seasonRepeats' => false]);

        self::assertFalse($plan->hasSeasonEnded(new DateTime('2035-01-01')));
    }

    // Labelling and validation --------------------------------------------

    public function testSeasonPlansAreLabelledPerSeason(): void
    {
        self::assertSame('per season', $this->season()->getIntervalLabel());
    }

    public function testSeasonDatesAreRequiredForAFixedTermPlan(): void
    {
        $plan = $this->season();
        $plan->seasonStartDate = null;
        $plan->seasonEndDate = null;

        self::assertFalse($plan->validate());
        self::assertArrayHasKey('seasonStartDate', $plan->getErrors());
        self::assertArrayHasKey('seasonEndDate', $plan->getErrors());
    }

    public function testSeasonDatesAreNotRequiredForARecurringPlan(): void
    {
        $plan = $this->season(['termType' => Plan::TERM_RECURRING]);
        $plan->seasonStartDate = null;
        $plan->seasonEndDate = null;

        self::assertTrue($plan->validate(), implode(' / ', $plan->getErrorSummary(true)));
    }

    public function testSeasonMustEndAfterItStarts(): void
    {
        $plan = $this->season(['seasonEndDate' => new DateTime('2026-06-30')]);

        self::assertFalse($plan->validate());
        self::assertArrayHasKey('seasonEndDate', $plan->getErrors());
    }

    /**
     * A repeating window longer than a year would overlap its own next occurrence, and
     * "which season is this member in" would stop having one answer.
     */
    public function testRepeatingSeasonMustFitInsideAYear(): void
    {
        $plan = $this->season(['seasonEndDate' => new DateTime('2028-06-30')]);

        self::assertFalse($plan->validate());
        self::assertArrayHasKey('seasonEndDate', $plan->getErrors());
    }

    public function testNonRepeatingSeasonMayRunLongerThanAYear(): void
    {
        $plan = $this->season([
            'seasonRepeats' => false,
            'seasonEndDate' => new DateTime('2028-06-30'),
        ]);

        self::assertTrue($plan->validate(), implode(' / ', $plan->getErrorSummary(true)));
    }

    /**
     * Dates arrive from callers as DateTime, and from the database and control panel as
     * strings and arrays. Only the DateTime path is exercised here: the others go through
     * Craft's date parser, which asks the application for the site's timezone, so they need
     * a running Craft and belong to the integration suite.
     */
    public function testDateSettersAcceptDateTimes(): void
    {
        $plan = new Plan();
        $plan->setSeasonStartDate(new DateTime('2026-07-01'));
        $plan->setSeasonEndDate(new DateTime('2027-06-30'));

        self::assertSame('2026-07-01', $plan->seasonStartDate?->format('Y-m-d'));
        self::assertSame('2027-06-30', $plan->seasonEndDate?->format('Y-m-d'));
    }

    public function testDateSettersClearOnEmptyInput(): void
    {
        $plan = $this->season();
        $plan->setSeasonStartDate(null);

        self::assertNull($plan->seasonStartDate);
    }
}
