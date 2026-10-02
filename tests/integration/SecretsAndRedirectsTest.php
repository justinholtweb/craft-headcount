<?php

namespace justinholtweb\headcount\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Settings;

/**
 * The 5.3.3 security fixes: credentials that are environment variables, webhooks that fail
 * closed when they can't be verified, and the billing portal's return URL.
 */
class SecretsAndRedirectsTest extends TestCase
{
    private array $originalSettings = [];

    protected function _before(): void
    {
        parent::_before();
        $this->originalSettings = Headcount::getInstance()->getSettings()->toArray();
    }

    protected function _after(): void
    {
        Headcount::getInstance()->getSettings()->setAttributes($this->originalSettings, false);
        putenv('HEADCOUNT_TEST_SECRET');
        parent::_after();
    }

    public function testALiteralSecretIsUsedAsTyped(): void
    {
        self::assertSame('sk_live_abc', Settings::secret('sk_live_abc'));
    }

    public function testAnEnvironmentVariableIsResolved(): void
    {
        putenv('HEADCOUNT_TEST_SECRET=resolved-value');

        self::assertSame('resolved-value', Settings::secret('$HEADCOUNT_TEST_SECRET'));
    }

    public function testAnUnresolvedEnvironmentVariableIsNoSecretAtAll(): void
    {
        // Until 5.3.3 the API key was compared as typed, so `$HEADCOUNT_API_KEY` *was* the key.
        self::assertSame('', Settings::secret('$HEADCOUNT_NOT_DEFINED_ANYWHERE'));
        self::assertSame('', Settings::secret(''));

        $settings = Headcount::getInstance()->getSettings();
        $settings->apiKey = '$HEADCOUNT_NOT_DEFINED_ANYWHERE';
        self::assertFalse($settings->secretIsSet('apiKey'));
    }

    public function testAStripeWebhookIsRefusedWithoutASigningSecret(): void
    {
        $settings = Headcount::getInstance()->getSettings();
        $payload = json_encode(['id' => 'evt_forged', 'object' => 'event', 'type' => 'checkout.session.completed']);
        $timestamp = time();

        foreach (['', '$HEADCOUNT_NOT_DEFINED_ANYWHERE'] as $secret) {
            $settings->stripeWebhookSecret = $secret;

            // Exactly what an attacker can compute when the key is empty.
            $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', "$timestamp.$payload", '');

            try {
                Headcount::getInstance()->stripe->verifyWebhookSignature($payload, $header);
                self::fail("A webhook was accepted with the signing secret set to '$secret'.");
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAStripeWebhookSignedWithTheRealSecretIsAccepted(): void
    {
        Headcount::getInstance()->getSettings()->stripeWebhookSecret = 'whsec_test_123';
        $payload = json_encode(['id' => 'evt_real', 'object' => 'event', 'type' => 'invoice.paid']);
        $timestamp = time();
        $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', "$timestamp.$payload", 'whsec_test_123');

        $event = Headcount::getInstance()->stripe->verifyWebhookSignature($payload, $header);

        self::assertSame('evt_real', $event->id);
    }

    public function testAPayPalWebhookIsRefusedWithoutAWebhookId(): void
    {
        Headcount::getInstance()->getSettings()->paypalWebhookId = '';

        $verified = Headcount::getInstance()->paypal->verifyWebhook(
            json_encode(['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'I-FORGED']]),
            Craft::$app->getRequest()->getHeaders(),
        );

        self::assertFalse($verified);
    }

    public function testThePortalOnlyReturnsMembersToThisSite(): void
    {
        $stripe = Headcount::getInstance()->stripe;
        $siteHost = parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);

        // A path becomes an absolute URL on this site (the test app has no URL rewriting, so
        // it may come back as `index.php?p=account`).
        $path = (string)$stripe->safeReturnUrl('/account');
        self::assertStringContainsString('account', $path);
        self::assertSame($siteHost, parse_url($path, PHP_URL_HOST));
        self::assertSame("https://$siteHost/account", $stripe->safeReturnUrl("https://$siteHost/account"));

        foreach (['https://evil.example/', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', ''] as $bad) {
            self::assertNull($stripe->safeReturnUrl($bad), "Accepted $bad");
        }
    }
}
