<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\AccessRule;
use justinholtweb\headcount\records\AccessRuleRecord;
use yii\base\Component;

class Gating extends Component
{
    private ?array $_rules = null;

    public function evaluateAccess(Entry $entry, ?User $user): ?array
    {
        $rule = $this->getMatchingRule($entry);

        if (!$rule || !$rule->enabled) {
            return null; // No rule = unrestricted
        }

        // Check drip schedule first
        $dripResult = Headcount::getInstance()->drip->isUnlocked($entry, $user);
        if ($dripResult === false) {
            return [
                'allowed' => false,
                'behavior' => $rule->behavior,
                'redirectUrl' => $rule->redirectUrl,
                'teaserLength' => $rule->teaserLength,
                'rule' => $rule,
                'reason' => 'drip',
            ];
        }

        // Check if user has an active subscription to any of the required plans
        if ($user) {
            $planIds = $rule->planIds ?? [];
            if (!empty($planIds)) {
                $activeSubscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);
                foreach ($activeSubscriptions as $subscription) {
                    if (in_array($subscription->planId, $planIds)) {
                        return ['allowed' => true];
                    }
                }
            }

            // Also check user group membership (plans may have mapped user groups)
            foreach ($planIds as $planId) {
                $plan = Headcount::getInstance()->plans->getPlanById($planId);
                if ($plan && $plan->userGroupId && $user->isInGroup($plan->userGroupId)) {
                    return ['allowed' => true];
                }
            }
        }

        // User doesn't have access
        $settings = Headcount::getInstance()->getSettings();

        return [
            'allowed' => false,
            'behavior' => $rule->behavior,
            'redirectUrl' => $rule->redirectUrl ?: $settings->loginUrl,
            'teaserLength' => $rule->teaserLength,
            'rule' => $rule,
            'reason' => 'subscription_required',
        ];
    }

    public function getMatchingRule(Entry $entry): ?AccessRule
    {
        $rules = $this->getAllRules(true);

        foreach ($rules as $rule) {
            switch ($rule->type) {
                case 'entry':
                    if ($rule->targetId === $entry->id) {
                        return $rule;
                    }
                    break;

                case 'entryType':
                    if ($entry->typeId === $rule->targetId) {
                        return $rule;
                    }
                    break;

                case 'section':
                    if ($entry->sectionId === $rule->targetId) {
                        return $rule;
                    }
                    break;

                case 'category':
                    // Check if entry has a category in the specified category
                    $categoryFields = $entry->getFieldLayout()?->getCustomFields() ?? [];
                    foreach ($categoryFields as $field) {
                        if ($field instanceof \craft\fields\Categories || $field instanceof \craft\fields\Entries) {
                            $related = $entry->getFieldValue($field->handle);
                            if ($related) {
                                foreach ($related->all() as $relatedElement) {
                                    if ($relatedElement->id === $rule->targetId) {
                                        return $rule;
                                    }
                                }
                            }
                        }
                    }
                    break;
            }
        }

        return null;
    }

    public function getRulesForEntry(Entry $entry): array
    {
        $rules = $this->getAllRules(true);
        $matchingRules = [];

        foreach ($rules as $rule) {
            $matches = match ($rule->type) {
                'entry' => $rule->targetId === $entry->id,
                'entryType' => $entry->typeId === $rule->targetId,
                'section' => $entry->sectionId === $rule->targetId,
                default => false,
            };

            if ($matches) {
                $matchingRules[] = $rule;
            }
        }

        return $matchingRules;
    }

    public function getAllRules(bool $enabledOnly = false): array
    {
        if ($this->_rules === null) {
            $this->_loadRules();
        }

        if ($enabledOnly) {
            return array_filter($this->_rules, fn(AccessRule $rule) => $rule->enabled);
        }

        return $this->_rules;
    }

    public function getRuleById(int $id): ?AccessRule
    {
        $row = (new Query())
            ->select('*')
            ->from('{{%headcount_access_rules}}')
            ->where(['id' => $id])
            ->one();

        return $row ? $this->_createRuleFromRow($row) : null;
    }

    public function saveRule(AccessRule $rule): bool
    {
        if (!$rule->validate()) {
            return false;
        }

        $isNew = !$rule->id;

        if ($isNew) {
            $record = new AccessRuleRecord();
        } else {
            $record = AccessRuleRecord::findOne($rule->id);
            if (!$record) {
                return false;
            }
        }

        $record->name = $rule->name;
        $record->type = $rule->type;
        $record->targetId = $rule->targetId;
        $record->targetUid = $rule->targetUid;
        $record->planIds = $rule->planIds ? json_encode($rule->planIds) : null;
        $record->behavior = $rule->behavior;
        $record->redirectUrl = $rule->redirectUrl;
        $record->teaserLength = $rule->teaserLength;
        $record->sortOrder = $rule->sortOrder;
        $record->enabled = $rule->enabled;

        if (!$record->save()) {
            $rule->addErrors($record->getErrors());
            return false;
        }

        if ($isNew) {
            $rule->id = $record->id;
        }

        $this->_rules = null;
        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $record = AccessRuleRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->delete();
        $this->_rules = null;

        return true;
    }

    private function _loadRules(): void
    {
        $this->_rules = [];

        $rows = (new Query())
            ->select('*')
            ->from('{{%headcount_access_rules}}')
            ->orderBy('sortOrder')
            ->all();

        foreach ($rows as $row) {
            $this->_rules[] = $this->_createRuleFromRow($row);
        }
    }

    private function _createRuleFromRow(array $row): AccessRule
    {
        $rule = new AccessRule();
        $rule->id = (int)$row['id'];
        $rule->name = $row['name'];
        $rule->type = $row['type'];
        $rule->targetId = $row['targetId'] ? (int)$row['targetId'] : null;
        $rule->targetUid = $row['targetUid'];
        $rule->planIds = $row['planIds'] ? json_decode($row['planIds'], true) : null;
        $rule->behavior = $row['behavior'];
        $rule->redirectUrl = $row['redirectUrl'];
        $rule->teaserLength = $row['teaserLength'] ? (int)$row['teaserLength'] : null;
        $rule->sortOrder = (int)$row['sortOrder'];
        $rule->enabled = (bool)$row['enabled'];
        $rule->dateCreated = $row['dateCreated'];
        $rule->dateUpdated = $row['dateUpdated'];
        $rule->uid = $row['uid'];

        return $rule;
    }
}
