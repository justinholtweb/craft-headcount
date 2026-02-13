<?php

namespace justinholtweb\headcount\twig;

use Craft;
use craft\elements\Entry;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

class HeadcountVariable
{
    /**
     * Check if the current user has an active subscription.
     */
    public function isSubscribed(?string $planHandle = null): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return false;
        }

        return Headcount::getInstance()->subscriptions->hasActiveSubscription($user->id, $planHandle);
    }

    /**
     * Check if user can access a specific entry (respects gating + drip).
     */
    public function canAccess(Entry $entry): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $result = Headcount::getInstance()->gating->evaluateAccess($entry, $user);

        if ($result === null) {
            return true; // No gating rule
        }

        return $result['allowed'];
    }

    /**
     * Get current user's active subscriptions.
     */
    public function subscriptions(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [];
        }

        return Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);
    }

    /**
     * Get all available (enabled) plans.
     */
    public function plans(): array
    {
        return Headcount::getInstance()->plans->getAllPlans(true);
    }

    /**
     * Get a specific plan by handle.
     */
    public function plan(string $handle): ?Plan
    {
        return Headcount::getInstance()->plans->getPlanByHandle($handle);
    }

    /**
     * Get checkout URL for a plan.
     */
    public function checkoutUrl(string $planHandle, string $gateway = 'stripe'): string
    {
        return \craft\helpers\UrlHelper::actionUrl('headcount/checkout/create-session', [
            'planHandle' => $planHandle,
            'gateway' => $gateway,
        ]);
    }

    /**
     * Get portal URL for subscription management.
     */
    public function portalUrl(?string $returnUrl = null): string
    {
        $params = [];
        if ($returnUrl) {
            $params['returnUrl'] = $returnUrl;
        }

        return \craft\helpers\UrlHelper::actionUrl('headcount/portal/redirect', $params);
    }

    /**
     * Check if drip content is unlocked.
     */
    public function isUnlocked(Entry $entry): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $result = Headcount::getInstance()->drip->isUnlocked($entry, $user);

        return $result !== false;
    }

    /**
     * Get days until drip content unlocks.
     */
    public function unlocksIn(Entry $entry): ?int
    {
        $user = Craft::$app->getUser()->getIdentity();
        return Headcount::getInstance()->drip->daysUntilUnlocked($entry, $user);
    }

    /**
     * Render a coupon input field.
     */
    public function couponField(array $options = []): string
    {
        $name = $options['name'] ?? 'coupon';
        $placeholder = $options['placeholder'] ?? 'Enter coupon code';
        $class = $options['class'] ?? '';

        return '<input type="text" name="' . htmlspecialchars($name) . '" placeholder="' . htmlspecialchars($placeholder) . '" class="' . htmlspecialchars($class) . '">';
    }

    /**
     * Get subscription element query.
     */
    public function subscriptionQuery(): \justinholtweb\headcount\elements\db\SubscriptionQuery
    {
        return Subscription::find();
    }
}
