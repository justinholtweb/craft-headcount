<?php

namespace justinholtweb\headcount\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use justinholtweb\headcount\records\AccessRuleRecord;

/**
 * Integration coverage for {@see \justinholtweb\headcount\services\Gating} — the
 * access decision that gates content behind a subscription. Exercises rule
 * matching by section and the subscription check, against real Entry elements
 * and AccessRule rows.
 *
 * @covers \justinholtweb\headcount\services\Gating
 */
class GatingServiceTest extends TestCase
{
    private int $counter = 0;

    protected function _before(): void
    {
        // The Gating service caches loaded rules on the plugin singleton, which
        // outlives a single transaction-isolated test; reset it each time.
        $this->setInaccessibleProperty(Headcount::getInstance()->gating, '_rules', null);
    }

    private function makeEntry(): Entry
    {
        $suffix = $this->counter++ . random_int(1000, 9999);

        $entryType = new EntryType();
        $entryType->name = "Article $suffix";
        $entryType->handle = "article$suffix";
        self::assertTrue(
            Craft::$app->getEntries()->saveEntryType($entryType),
            implode(', ', $entryType->getErrorSummary(true)),
        );

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $section = new Section();
        $section->name = "News $suffix";
        $section->handle = "news$suffix";
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $primarySiteId,
                'hasUrls' => false,
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        self::assertTrue(
            Craft::$app->getEntries()->saveSection($section),
            implode(', ', $section->getErrorSummary(true)),
        );

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->title = "Gated $suffix";
        self::assertTrue(
            Craft::$app->getElements()->saveElement($entry),
            implode(', ', $entry->getErrorSummary(true)),
        );

        return $entry;
    }

    private function makePlan(): Plan
    {
        $plan = new Plan();
        $plan->name = 'Plan ' . $this->counter;
        $plan->handle = 'gplan' . $this->counter . 'x' . random_int(1000, 9999);
        $plan->billingInterval = 'month';
        $plan->price = 10;
        self::assertTrue(Headcount::getInstance()->plans->savePlan($plan), implode(', ', $plan->getErrorSummary(true)));

        return $plan;
    }

    private function makeSectionRule(int $sectionId, int $planId): void
    {
        $rule = new AccessRuleRecord();
        $rule->name = 'Members only';
        $rule->type = 'section';
        $rule->targetId = $sectionId;
        $rule->planIds = json_encode([$planId]);
        $rule->behavior = 'redirect';
        $rule->enabled = true;
        self::assertTrue($rule->save(), implode(', ', $rule->getErrorSummary(true)));
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->username = 'reader' . $this->counter . '_' . random_int(1000, 9999);
        $user->email = $user->username . '@example.test';
        self::assertTrue(Craft::$app->getElements()->saveElement($user), implode(', ', $user->getErrorSummary(true)));

        return $user;
    }

    public function testNoRuleMeansUnrestricted(): void
    {
        $entry = $this->makeEntry();

        // No access rule for this section => evaluateAccess returns null (open).
        self::assertNull(Headcount::getInstance()->gating->evaluateAccess($entry, null));
    }

    public function testMatchingRuleFoundBySection(): void
    {
        $entry = $this->makeEntry();
        $plan = $this->makePlan();
        $this->makeSectionRule($entry->sectionId, $plan->id);

        $rule = Headcount::getInstance()->gating->getMatchingRule($entry);

        self::assertNotNull($rule);
        self::assertSame('section', $rule->type);
        self::assertSame($entry->sectionId, $rule->targetId);
    }

    public function testAccessDeniedWithoutSubscription(): void
    {
        $entry = $this->makeEntry();
        $plan = $this->makePlan();
        $this->makeSectionRule($entry->sectionId, $plan->id);
        $user = $this->makeUser();

        $result = Headcount::getInstance()->gating->evaluateAccess($entry, $user);

        self::assertNotNull($result);
        self::assertFalse($result['allowed']);
        self::assertSame('subscription_required', $result['reason']);
    }

    public function testAccessGrantedWithActiveSubscription(): void
    {
        $entry = $this->makeEntry();
        $plan = $this->makePlan();
        $this->makeSectionRule($entry->sectionId, $plan->id);
        $user = $this->makeUser();

        Headcount::getInstance()->subscriptions->createSubscription([
            'userId' => $user->id,
            'planId' => $plan->id,
            'amount' => 10,
            'status' => Subscription::STATUS_ACTIVE,
            'gateway' => 'manual',
            'startDate' => time(),
        ]);

        $result = Headcount::getInstance()->gating->evaluateAccess($entry, $user);

        self::assertNotNull($result);
        self::assertTrue($result['allowed']);
    }
}
