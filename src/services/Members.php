<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\base\Component;

class Members extends Component
{
    /**
     * Sync user group membership based on subscription status.
     */
    public function syncUserGroups(Subscription $subscription): void
    {
        if (!$subscription->userId) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserById($subscription->userId);
        if (!$user) {
            return;
        }

        $plan = $subscription->getPlan();
        if (!$plan || !$plan->userGroupId) {
            return;
        }

        $currentGroupIds = array_map(
            fn($group) => $group->id,
            $user->getGroups()
        );

        if ($subscription->isActive()) {
            // Add user to the plan's user group
            if (!in_array($plan->userGroupId, $currentGroupIds)) {
                $currentGroupIds[] = $plan->userGroupId;
                Craft::$app->getUsers()->assignUserToGroups($user->id, $currentGroupIds);

                Craft::info("Added user {$user->id} to group {$plan->userGroupId} for plan {$plan->handle}", 'headcount');
            }
        } else {
            // Remove user from the plan's user group
            // But only if they don't have another active subscription to a plan with the same group
            $otherActiveSubscriptions = Subscription::find()
                ->userId($user->id)
                ->active()
                ->planId($plan->id)
                ->id(['not', $subscription->id])
                ->exists();

            if (!$otherActiveSubscriptions && in_array($plan->userGroupId, $currentGroupIds)) {
                $currentGroupIds = array_filter($currentGroupIds, fn($id) => $id !== $plan->userGroupId);
                Craft::$app->getUsers()->assignUserToGroups($user->id, $currentGroupIds);

                Craft::info("Removed user {$user->id} from group {$plan->userGroupId} for plan {$plan->handle}", 'headcount');
            }
        }
    }

    /**
     * Sync all user groups for a user based on their active subscriptions.
     */
    public function syncAllGroupsForUser(int $userId): void
    {
        $user = Craft::$app->getUsers()->getUserById($userId);
        if (!$user) {
            return;
        }

        $activeSubscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($userId);

        // Get current non-headcount group IDs (keep existing groups that aren't managed by us)
        $headcountGroupIds = $this->getHeadcountManagedGroupIds();
        $currentGroupIds = array_map(fn($g) => $g->id, $user->getGroups());
        $nonHeadcountGroupIds = array_diff($currentGroupIds, $headcountGroupIds);

        // Add groups from active subscriptions
        $newGroupIds = $nonHeadcountGroupIds;
        foreach ($activeSubscriptions as $subscription) {
            $plan = $subscription->getPlan();
            if ($plan && $plan->userGroupId) {
                $newGroupIds[] = $plan->userGroupId;
            }
        }

        $newGroupIds = array_unique($newGroupIds);
        Craft::$app->getUsers()->assignUserToGroups($userId, $newGroupIds);
    }

    /**
     * Get all user group IDs that are managed by Headcount plans.
     */
    public function getHeadcountManagedGroupIds(): array
    {
        $plans = Headcount::getInstance()->plans->getAllPlans();
        $groupIds = [];

        foreach ($plans as $plan) {
            if ($plan->userGroupId) {
                $groupIds[] = $plan->userGroupId;
            }
        }

        return array_unique($groupIds);
    }

    /**
     * Get member count for a specific plan.
     */
    public function getMemberCountForPlan(int $planId): int
    {
        return Subscription::find()
            ->planId($planId)
            ->active()
            ->count();
    }

    /**
     * Get total active member count.
     */
    public function getTotalActiveMemberCount(): int
    {
        return Subscription::find()
            ->active()
            ->count();
    }
}
