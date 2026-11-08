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
use justinholtweb\headcount\records\WebhookLogRecord;
use Stripe\Checkout\Session;
use Stripe\Event as StripeEvent;

/**
 * A paid checkout must become a subscription however Headcount hears about it — the
 * webhook, the member's return, or `headcount/subscriptions/import` — exactly once, and a
 * checkout that couldn't be recorded must be logged as a failure rather than "processed".
 *
 * Season (fixed-term) checkouts are used throughout because they have no Stripe
 * subscription to fetch, so nothing here calls the Stripe API.
 *
 * @covers \justinholtweb\headcount\services\Subscriptions::createFromStripeCheckoutSession
 * @covers \justinholtweb\headcount\services\Webhooks
 */
class CheckoutRecoveryTest extends TestCase
{
    private bool $stripeEnabled;
    private string $webhookSecret;

    protected function _before(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $this->stripeEnabled = $settings->stripeEnabled;
        $this->webhookSecret = (string)$settings->stripeWebhookSecret;

        $cache = Craft::$app->getCache();
        $cache->delete('headcount:webhook-rejected:stripe');
        $cache->delete('headcount:checkout-fallback');
    }

    protected function _after(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $settings->stripeEnabled = $this->stripeEnabled;
        $settings->stripeWebhookSecret = $this->webhookSecret;
    }

    private function makeSeasonPlan(): Plan
    {
        $suffix = random_int(10000, 99999);

        $group = new UserGroup();
        $group->name = 'Checkout members ' . $suffix;
        $group->handle = 'checkoutmembers' . $suffix;
        self::assertTrue(Craft::$app->getUserGroups()->saveGroup($group));

        $plan = new Plan();
        $plan->name = 'Season ' . $suffix;
        $plan->handle = 'season' . $suffix;
        $plan->billingInterval = 'year';
        $plan->price = 120;
        $plan->userGroupId = $group->id;
        $plan->termType = Plan::TERM_FIXED;
        $plan->setSeasonStartDate(new DateTime('2026-07-01'));
        $plan->setSeasonEndDate(new DateTime('2027-06-30'));

        self::assertTrue(
            Headcount::getInstance()->plans->savePlan($plan),
            implode(', ', $plan->getErrorSummary(true)),
        );

        return $plan;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->username = 'checkoutmember' . random_int(10000, 99999);
        $user->email = $user->username . '@example.test';
        self::assertTrue(Craft::$app->getElements()->saveElement($user));

        return $user;
    }

    private function sessionData(string $id, int $userId, int $planId, array $extra = []): array
    {
        return array_replace([
            'id' => $id,
            'object' => 'checkout.session',
            'status' => 'complete',
            'mode' => 'payment',
            'customer' => 'cus_test',
            'amount_total' => 8000,
            'client_reference_id' => (string)$userId,
            'metadata' => [
                'craft_user_id' => (string)$userId,
                'plan_id' => (string)$planId,
                'term_type' => Plan::TERM_FIXED,
                'term_start' => '2026-11-04T00:00:00+00:00',
                'term_end' => '2027-06-30T23:59:59+00:00',
            ],
        ], $extra);
    }

    private function checkoutEvent(string $eventId, array $session): StripeEvent
    {
        return StripeEvent::constructFrom([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session],
        ]);
    }

    private function subscriptionsFor(int $userId): array
    {
        return Subscription::find()->status(null)->userId($userId)->all();
    }

    private function isInGroup(int $userId, int $groupId): bool
    {
        return (new Query())
            ->from('{{%usergroups_users}}')
            ->where(['userId' => $userId, 'groupId' => $groupId])
            ->exists();
    }

    public function testCheckoutReturnCreatesTheSubscriptionAndGrantsTheGroup(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $session = Session::constructFrom($this->sessionData('cs_test_return1', $user->id, $plan->id));

        $result = Headcount::getInstance()->subscriptions->createFromStripeCheckoutSession($session, false);

        self::assertSame('processed', $result);
        $subscriptions = $this->subscriptionsFor($user->id);
        self::assertCount(1, $subscriptions);
        self::assertSame(Subscription::STATUS_ACTIVE, $subscriptions[0]->status);
        self::assertSame('cs_test_return1', $subscriptions[0]->metadata['checkoutSessionId'] ?? null);
        self::assertEqualsWithDelta(80.0, (float)$subscriptions[0]->amount, 0.001);
        self::assertTrue($this->isInGroup($user->id, $plan->userGroupId));
    }

    public function testWebhookAfterTheReturnDoesNotCreateASecondSubscription(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $data = $this->sessionData('cs_test_both1', $user->id, $plan->id);

        $subscriptions = Headcount::getInstance()->subscriptions;
        self::assertSame('processed', $subscriptions->createFromStripeCheckoutSession(Session::constructFrom($data), false));

        $result = Headcount::getInstance()->webhooks->processStripeEvent($this->checkoutEvent('evt_both1', $data));

        self::assertSame('already_exists', $result);
        self::assertCount(1, $this->subscriptionsFor($user->id));
        self::assertSame('processed', WebhookLogRecord::findOne(['eventId' => 'evt_both1'])->status);

        // And an import run afterwards finds it too.
        self::assertSame('already_exists', $subscriptions->createFromStripeCheckoutSession(Session::constructFrom($data), false));
        self::assertCount(1, $this->subscriptionsFor($user->id));
    }

    public function testSessionIdPrefixDoesNotMatchAnotherSession(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $subscriptions = Headcount::getInstance()->subscriptions;

        $subscriptions->createFromStripeCheckoutSession(Session::constructFrom($this->sessionData('cs_test_abc123', $user->id, $plan->id)), false);

        self::assertNull($subscriptions->getSubscriptionForCheckoutSession(Session::constructFrom(['id' => 'cs_test_abc'])));
        self::assertNotNull($subscriptions->getSubscriptionForCheckoutSession(Session::constructFrom(['id' => 'cs_test_abc123'])));
    }

    public function testUnfinishedSessionIsNotRecorded(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $session = Session::constructFrom($this->sessionData('cs_test_open1', $user->id, $plan->id, ['status' => 'open']));

        self::assertSame('incomplete', Headcount::getInstance()->subscriptions->createFromStripeCheckoutSession($session, false));
        self::assertCount(0, $this->subscriptionsFor($user->id));
    }

    /**
     * A bank debit completes Checkout before the money arrives. Nothing is granted until
     * `checkout.session.async_payment_succeeded` says it has.
     */
    public function testDelayedPaymentIsOnlyGrantedOnceItSucceeds(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $webhooks = Headcount::getInstance()->webhooks;

        $pending = $this->sessionData('cs_test_ach1', $user->id, $plan->id, ['payment_status' => 'unpaid']);
        self::assertSame('unpaid', $webhooks->processStripeEvent($this->checkoutEvent('evt_ach_completed', $pending)));
        self::assertSame('ignored', WebhookLogRecord::findOne(['eventId' => 'evt_ach_completed'])->status);
        self::assertCount(0, $this->subscriptionsFor($user->id));
        self::assertFalse($this->isInGroup($user->id, $plan->userGroupId));

        $paid = $this->sessionData('cs_test_ach1', $user->id, $plan->id, ['payment_status' => 'paid']);
        $event = StripeEvent::constructFrom([
            'id' => 'evt_ach_succeeded',
            'object' => 'event',
            'type' => 'checkout.session.async_payment_succeeded',
            'data' => ['object' => $paid],
        ]);

        self::assertSame('processed', $webhooks->processStripeEvent($event));
        self::assertCount(1, $this->subscriptionsFor($user->id));
        self::assertTrue($this->isInGroup($user->id, $plan->userGroupId));
    }

    public function testDeletedSubscriptionIsNotRecreatedFromItsCheckout(): void
    {
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        $data = $this->sessionData('cs_test_deleted1', $user->id, $plan->id, ['payment_status' => 'paid']);
        $subscriptions = Headcount::getInstance()->subscriptions;

        self::assertSame('processed', $subscriptions->createFromStripeCheckoutSession(Session::constructFrom($data), false));
        $subscription = $this->subscriptionsFor($user->id)[0];
        self::assertTrue(Craft::$app->getElements()->deleteElement($subscription));

        self::assertSame('already_exists', $subscriptions->createFromStripeCheckoutSession(Session::constructFrom($data), false));
        self::assertCount(0, $this->subscriptionsFor($user->id));
    }

    public function testCheckoutWithoutHeadcountMetadataIsIgnoredNotFailed(): void
    {
        $result = Headcount::getInstance()->webhooks->processStripeEvent($this->checkoutEvent('evt_foreign1', [
            'id' => 'cs_test_foreign1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'metadata' => [],
        ]));

        self::assertSame('missing_metadata', $result);
        self::assertFalse(Headcount::getInstance()->webhooks->isFailure($result));
        self::assertSame('ignored', WebhookLogRecord::findOne(['eventId' => 'evt_foreign1'])->status);
    }

    /**
     * Before 5.4.1 this was logged as `processed`, so Stripe got a 200 and never retried.
     */
    public function testCheckoutForAMissingPlanIsLoggedAsFailedAndCanBeRetried(): void
    {
        $user = $this->makeUser();
        $data = $this->sessionData('cs_test_noplan1', $user->id, 999999);
        $webhooks = Headcount::getInstance()->webhooks;

        $result = $webhooks->processStripeEvent($this->checkoutEvent('evt_noplan1', $data));

        self::assertSame('plan_not_found', $result);
        self::assertTrue($webhooks->isFailure($result));

        $log = WebhookLogRecord::findOne(['eventId' => 'evt_noplan1']);
        self::assertSame('failed', $log->status);
        self::assertStringContainsString('plan_not_found', (string)$log->error);

        // Stripe's retry is processed again rather than skipped as a duplicate.
        self::assertSame('plan_not_found', $webhooks->processStripeEvent($this->checkoutEvent('evt_noplan1', $data)));
    }

    public function testRejectedWebhookRaisesAProblem(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $settings->stripeEnabled = true;
        $settings->stripeWebhookSecret = 'whsec_test_secret';
        $webhooks = Headcount::getInstance()->webhooks;

        self::assertSame([], array_filter($webhooks->getStripeProblems(), fn($p) => str_contains($p, 'rejected')));

        $webhooks->recordRejection('stripe', 'No signatures found matching the expected signature for payload');

        $problems = $webhooks->getStripeProblems();
        self::assertNotEmpty(array_filter($problems, fn($p) => str_contains($p, 'rejected')));
        self::assertSame('No signatures found matching the expected signature for payload', $webhooks->getHealth()['lastRejected']['reason']);
    }

    public function testSignupWithNoWebhookSinceRaisesAProblemAfterTheGracePeriod(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $settings->stripeEnabled = true;
        $settings->stripeWebhookSecret = 'whsec_test_secret';
        $webhooks = Headcount::getInstance()->webhooks;
        WebhookLogRecord::deleteAll(['gateway' => 'stripe']);

        // Recording the signup leaves a marker in the log; it mustn't pass for a webhook.
        $plan = $this->makeSeasonPlan();
        $user = $this->makeUser();
        Headcount::getInstance()->subscriptions->createFromStripeCheckoutSession(
            Session::constructFrom($this->sessionData('cs_test_marker1', $user->id, $plan->id)),
            false,
        );
        self::assertNull($webhooks->getHealth()['lastReceived']);

        // Just now: the webhook may still be on its way.
        $webhooks->recordCheckoutFallback();
        self::assertSame([], array_filter($webhooks->getStripeProblems(), fn($p) => str_contains($p, 'no Stripe webhook')));

        // Twenty minutes on and still nothing.
        Craft::$app->getCache()->set('headcount:checkout-fallback', time() - 20 * 60);
        self::assertNotEmpty(array_filter($webhooks->getStripeProblems(), fn($p) => str_contains($p, 'no Stripe webhook')));
    }

    public function testMissingSecretIsTheOnlyProblemReported(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $settings->stripeEnabled = true;
        $settings->stripeWebhookSecret = '';

        $problems = Headcount::getInstance()->webhooks->getStripeProblems();

        self::assertCount(1, $problems);
        self::assertStringContainsString('no signing secret', $problems[0]);
    }
}
