<?php

namespace justinholtweb\headcount\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\models\UserGroup;
use craft\test\TestCase;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

/**
 * Integration coverage for the subscription lifecycle and the Craft user-group
 * sync it drives ({@see \justinholtweb\headcount\services\Subscriptions::updateSubscriptionStatus}
 * → {@see \justinholtweb\headcount\services\Members::syncUserGroups}). Activating a
 * subscription must grant the plan's mapped group; ending it must revoke the
 * group — unless another active subscription still maps to it.
 *
 * @covers \justinholtweb\headcount\services\Members
 * @covers \justinholtweb\headcount\services\Subscriptions
 */
class SubscriptionLifecycleTest extends TestCase
{
    private int $groupCounter = 0;

    private function makeGroup(): UserGroup
    {
        $group = new UserGroup();
        $group->name = 'Members ' . $this->groupCounter;
        $group->handle = 'members' . $this->groupCounter;
        $this->groupCounter++;

        self::assertTrue(
            Craft::$app->getUserGroups()->saveGroup($group),
            implode(', ', $group->getErrorSummary(true)),
        );

        return $group;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->username = 'member' . $this->groupCounter . '_' . random_int(1000, 9999);
        $user->email = $user->username . '@example.test';

        self::assertTrue(
            Craft::$app->getElements()->saveElement($user),
            implode(', ', $user->getErrorSummary(true)),
        );

        return $user;
    }

    private function makePlan(int $userGroupId): Plan
    {
        $plan = new Plan();
        $plan->name = 'Plan ' . $this->groupCounter;
        $plan->handle = 'plan' . $this->groupCounter . 'x' . random_int(1000, 9999);
        $plan->billingInterval = 'month';
        $plan->price = 10;
        $plan->userGroupId = $userGroupId;

        self::assertTrue(
            Headcount::getInstance()->plans->savePlan($plan),
            implode(', ', $plan->getErrorSummary(true)),
        );

        return $plan;
    }

    private function makeActiveSubscription(int $userId, int $planId): Subscription
    {
        $subscription = Headcount::getInstance()->subscriptions->createSubscription([
            'userId' => $userId,
            'planId' => $planId,
            'amount' => 10,
            'status' => Subscription::STATUS_ACTIVE,
            'gateway' => 'manual',
            'startDate' => time(),
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

    public function testActivatingSubscriptionGrantsGroup(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);

        $this->makeActiveSubscription($user->id, $plan->id);

        self::assertTrue($this->isInGroup($user->id, $group->id));
    }

    public function testCancelingSubscriptionRevokesGroup(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);
        $subscription = $this->makeActiveSubscription($user->id, $plan->id);

        self::assertTrue($this->isInGroup($user->id, $group->id));

        Headcount::getInstance()->subscriptions
            ->updateSubscriptionStatus($subscription, Subscription::STATUS_CANCELED);

        self::assertFalse($this->isInGroup($user->id, $group->id));
    }

    public function testGroupRetainedWhileAnotherActiveSubscriptionMapsToIt(): void
    {
        $group = $this->makeGroup();
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);

        $first = $this->makeActiveSubscription($user->id, $plan->id);
        $this->makeActiveSubscription($user->id, $plan->id);

        // Cancelling one of two active subscriptions to the same plan must NOT
        // revoke the group — the other subscription still entitles it.
        Headcount::getInstance()->subscriptions
            ->updateSubscriptionStatus($first, Subscription::STATUS_CANCELED);

        self::assertTrue($this->isInGroup($user->id, $group->id));
    }
}
