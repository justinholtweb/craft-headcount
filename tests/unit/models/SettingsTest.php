<?php

namespace justinholtweb\headcount\tests\unit\models;

use justinholtweb\headcount\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \justinholtweb\headcount\models\Settings
 */
class SettingsTest extends TestCase
{
    public function testDefaultsAreValid(): void
    {
        $settings = new Settings();
        self::assertTrue($settings->validate(), implode(' / ', $settings->getErrorSummary(true)));
    }

    public function testSensibleDefaultValues(): void
    {
        $settings = new Settings();

        self::assertSame('USD', $settings->defaultCurrency);
        self::assertTrue($settings->stripeEnabled);
        self::assertFalse($settings->paypalEnabled);
        self::assertTrue($settings->paypalSandbox);
        self::assertSame(3, $settings->expirationReminderDays);
    }

    public function testCurrencyIsCappedAtThreeCharacters(): void
    {
        $settings = new Settings();
        $settings->defaultCurrency = 'DOLLARS';

        self::assertFalse($settings->validate(['defaultCurrency']));
    }

    public function testExpirationReminderDaysMustBeAtLeastOne(): void
    {
        $settings = new Settings();
        $settings->expirationReminderDays = 0;

        self::assertFalse($settings->validate(['expirationReminderDays']));
    }

    public function testEmailTogglesAreBooleans(): void
    {
        $settings = new Settings();
        $settings->sendWelcomeEmail = false;
        $settings->sendCancellationEmail = false;

        self::assertTrue($settings->validate(['sendWelcomeEmail', 'sendCancellationEmail']));
    }
}
