<?php

namespace justinholtweb\headcount\tests\unit\models;

use justinholtweb\headcount\models\AccessRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \justinholtweb\headcount\models\AccessRule
 */
class AccessRuleTest extends TestCase
{
    private function rule(array $attributes = []): AccessRule
    {
        $rule = new AccessRule();
        foreach ($attributes as $key => $value) {
            $rule->$key = $value;
        }
        return $rule;
    }

    /**
     * Everything but `type`: since 5.2.0 the scope is checked against the gating registry,
     * which needs a running app — see GatingServiceTest for that half.
     */
    public function testValidRulePassesValidation(): void
    {
        $rule = $this->rule([
            'name' => 'Members only',
            'elementType' => 'craft\\elements\\Entry',
            'type' => 'section',
            'behavior' => 'redirect',
        ]);

        $attributes = array_values(array_diff($rule->activeAttributes(), ['type']));

        self::assertTrue($rule->validate($attributes), implode(' / ', $rule->getErrorSummary(true)));
    }

    public function testNameTypeAndBehaviorAreRequired(): void
    {
        // Override defaults with empty strings to trip the `required` validator.
        $rule = $this->rule(['name' => '', 'type' => '', 'behavior' => '']);

        self::assertFalse($rule->validate());
        self::assertArrayHasKey('name', $rule->getErrors());
        self::assertArrayHasKey('type', $rule->getErrors());
        self::assertArrayHasKey('behavior', $rule->getErrors());
    }

    /**
     * @dataProvider validBehaviorProvider
     */
    public function testBehaviorAcceptsKnownValues(string $behavior): void
    {
        $rule = $this->rule(['name' => 'Rule', 'type' => 'section', 'behavior' => $behavior]);
        self::assertTrue($rule->validate(['behavior']), "Behavior '{$behavior}' should be valid");
    }

    public static function validBehaviorProvider(): array
    {
        return [['redirect'], ['paywall'], ['hide']];
    }

    public function testUnknownBehaviorIsRejected(): void
    {
        $rule = $this->rule(['name' => 'Rule', 'type' => 'section', 'behavior' => 'explode']);
        self::assertFalse($rule->validate(['behavior']));
    }
}
