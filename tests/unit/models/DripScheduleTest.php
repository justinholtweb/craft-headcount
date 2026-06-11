<?php

namespace justinholtweb\headcount\tests\unit\models;

use justinholtweb\headcount\models\DripSchedule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \justinholtweb\headcount\models\DripSchedule
 */
class DripScheduleTest extends TestCase
{
    private function schedule(array $attributes = []): DripSchedule
    {
        $schedule = new DripSchedule();
        foreach ($attributes as $key => $value) {
            $schedule->$key = $value;
        }
        return $schedule;
    }

    public function testValidSchedulePassesValidation(): void
    {
        $schedule = $this->schedule([
            'name' => 'Week 1 lessons',
            'type' => 'section',
            'delayDays' => 7,
        ]);

        self::assertTrue($schedule->validate(), implode(' / ', $schedule->getErrorSummary(true)));
    }

    public function testNameAndTypeAreRequired(): void
    {
        $schedule = $this->schedule(['name' => '', 'type' => '']);

        self::assertFalse($schedule->validate());
        self::assertArrayHasKey('name', $schedule->getErrors());
        self::assertArrayHasKey('type', $schedule->getErrors());
    }

    /**
     * @dataProvider validTypeProvider
     */
    public function testTypeAcceptsKnownValues(string $type): void
    {
        $schedule = $this->schedule(['name' => 'Drip', 'type' => $type]);
        self::assertTrue($schedule->validate(['type']), "Type '{$type}' should be valid");
    }

    public static function validTypeProvider(): array
    {
        return [['section'], ['entryType'], ['category'], ['entry']];
    }

    public function testUnknownTypeIsRejected(): void
    {
        $schedule = $this->schedule(['name' => 'Drip', 'type' => 'custom']);
        self::assertFalse($schedule->validate(['type']));
    }

    public function testNegativeDelayDaysIsRejected(): void
    {
        $schedule = $this->schedule(['name' => 'Drip', 'type' => 'section', 'delayDays' => -1]);
        self::assertFalse($schedule->validate(['delayDays']));
    }

    public function testZeroDelayDaysIsAllowed(): void
    {
        $schedule = $this->schedule(['name' => 'Drip', 'type' => 'section', 'delayDays' => 0]);
        self::assertTrue($schedule->validate(['delayDays']));
    }
}
