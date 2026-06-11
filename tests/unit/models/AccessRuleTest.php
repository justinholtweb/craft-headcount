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

    public function testValidRulePassesValidation(): void
    {
        $rule = $this->rule([
            'name' => 'Members only',
            'type' => 'section',
            'behavior' => 'redirect',
        ]);

        self::assertTrue($rule->validate(), implode(' / ', $rule->getErrorSummary(true)));
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
     * @dataProvider validTypeProvider
     */
    public function testTypeAcceptsKnownValues(string $type): void
    {
        $rule = $this->rule(['name' => 'Rule', 'type' => $type, 'behavior' => 'hide']);
        self::assertTrue($rule->validate(['type']), "Type '{$type}' should be valid");
    }

    public static function validTypeProvider(): array
    {
        return [['section'], ['entryType'], ['category'], ['entry'], ['custom']];
    }

    public function testUnknownTypeIsRejected(): void
    {
        $rule = $this->rule(['name' => 'Rule', 'type' => 'widget', 'behavior' => 'hide']);
        self::assertFalse($rule->validate(['type']));
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
