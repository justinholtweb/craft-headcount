<?php

namespace justinholtweb\headcount\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\models\UserGroup;
use craft\test\TestCase;
use DateTime;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

/**
 * Season memberships end by themselves, and take their access with them.
 *
 * The unit suite pins down the arithmetic of a season window; this covers the part that
 * needs a database and a real element: that
 * {@see \justinholtweb\headcount\services\Subscriptions::processExpiredSubscriptions()}
 * retires a finished season term as `expired` and the user group goes with it, while a
 * recurring subscription sitting past the end of a billing period is left alone for the
 * gateway to renew.
 *
 * @covers \justinholtweb\headcount\services\Subscriptions
 * @covers \justinholtweb\headcount\models\Plan
 */
class SeasonMembershipTest extends TestCase
{
    private int $counter = 0;

    private function makeGroup(): UserGroup
    {
        $group = new UserGroup();
        $group->name = 'Season members ' . $this->counter;
        $group->handle = 'seasonmembers' . $this->counter;
        $this->counter++;

        self::assertTrue(
            Craft::$app->getUserGroups()->saveGroup($group),
            implode(', ', $group->getErrorSummary(true)),
        );

        return $group;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->username = 'seasonmember' . $this->counter . '_' . random_int(1000, 9999);
        $user->email = $user->username . '@example.test';

        self::assertTrue(
            Craft::$app->getElements()->saveElement($user),
            implode(', ', $user->getErrorSummary(true)),
        );

        return $user;
    }

    private function makePlan(int $userGroupId, bool $fixedTerm): Plan
    {
        $plan = new Plan();
        $plan->name = 'Season ' . $this->counter;
        $plan->handle = 'season' . $this->counter . 'x' . random_int(1000, 9999);
        $plan->billingInterval = 'year';
        $plan->price = 120;
        $plan->userGroupId = $userGroupId;

        if ($fixedTerm) {
            $plan->termType = Plan::TERM_FIXED;
            $plan->setSeasonStartDate(new DateTime('2026-07-01'));
            $plan->setSeasonEndDate(new DateTime('2027-06-30'));
        }

        self::assertTrue(
            Headcount::getInstance()->plans->savePlan($plan),
            implode(', ', $plan->getErrorSummary(true)),
        );

        return $plan;
    }

    /**
     * A subscription whose term ran out yesterday, with nobody having cancelled anything.
     */
    private function makeFinishedSubscription(int $userId, int $planId): Subscription
    {
        $subscription = Headcount::getInstance()->subscriptions->createSubscription([
            'userId' => $userId,
            'planId' => $planId,
            'amount' => 120,
            'status' => Subscription::STATUS_ACTIVE,
            'gateway' => 'manual',
            'startDate' => (new DateTime('-1 year'))->getTimestamp(),
            'endDate' => (new DateTime('-1 day'))->getTimestamp(),
        ]);

        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function isInGroup(int $userId, int $groupId): bool
    {
        return (new Query())
            ->from('{{%usergroups_users}}')
            ->where(['userId' => $userId, 'groupId' => $groupId])
            ->exists();
    }

    public function testFinishedSeasonTermExpiresAndRevokesTheGroup(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id, fixedTerm: true);
        $subscription = $this->makeFinishedSubscription($user->id, $plan->id);

        self::assertTrue($this->isInGroup($user->id, $group->id));

        Headcount::getInstance()->subscriptions->processExpiredSubscriptions();

        $reloaded = Headcount::getInstance()->subscriptions->getSubscriptionById($subscription->id);

        self::assertSame(Subscription::STATUS_EXPIRED, $reloaded->status);
        self::assertFalse($this->isInGroup($user->id, $group->id));
    }

    /**
     * A recurring subscription's end date is the end of a *billing period*, not the end of
     * the membership — the gateway will either renew it or tell us it failed. Expiring it
     * here would cut off a paying member the moment their renewal was a minute late.
     */
    public function testRecurringSubscriptionPastItsPeriodEndIsLeftAlone(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id, fixedTerm: false);
        $subscription = $this->makeFinishedSubscription($user->id, $plan->id);

        Headcount::getInstance()->subscriptions->processExpiredSubscriptions();

        $reloaded = Headcount::getInstance()->subscriptions->getSubscriptionById($subscription->id);

        self::assertSame(Subscription::STATUS_ACTIVE, $reloaded->status);
        self::assertTrue($this->isInGroup($user->id, $group->id));
    }

    /**
     * A member who asked to leave is `canceled`, not `expired`; the distinction is what the
     * reporting and the cancellation webhook are built on.
     */
    public function testCancelledSubscriptionStillBecomesCanceledRatherThanExpired(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id, fixedTerm: true);
        $subscription = $this->makeFinishedSubscription($user->id, $plan->id);

        $subscription->cancelAtPeriodEnd = true;
        Craft::$app->getElements()->saveElement($subscription);

        Headcount::getInstance()->subscriptions->processExpiredSubscriptions();

        $reloaded = Headcount::getInstance()->subscriptions->getSubscriptionById($subscription->id);

        self::assertSame(Subscription::STATUS_CANCELED, $reloaded->status);
    }

    /**
     * The season window survives a round trip through the database — the dates are stored as
     * datetimes and rebuilt into a window, including the end-of-day normalisation that keeps
     * a card valid all through its last day.
     */
    public function testSeasonWindowSurvivesReload(): void
    {
        $group = $this->makeGroup();
        $plan = $this->makePlan($group->id, fixedTerm: true);

        $reloaded = Headcount::getInstance()->plans->getPlanById($plan->id);
        $window = $reloaded->getSeasonWindow(new DateTime('2026-08-13'));

        self::assertNotNull($window);
        self::assertSame('2026-07-01', $window['start']->format('Y-m-d'));
        self::assertSame('2027-06-30 23:59:59', $window['end']->format('Y-m-d H:i:s'));
    }
}
