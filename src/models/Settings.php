<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;

class Settings extends Model
{
    // Stripe
    public string $stripeSecretKey = '';
    public string $stripePublishableKey = '';
    public string $stripeWebhookSecret = '';
    public bool $stripeEnabled = true;

    // PayPal
    public string $paypalClientId = '';
    public string $paypalClientSecret = '';
    public string $paypalWebhookId = '';
    public bool $paypalSandbox = true;
    public bool $paypalEnabled = false;

    // General
    public string $defaultCurrency = 'USD';
    public string $checkoutSuccessUrl = '/membership/thank-you';
    public string $checkoutCancelUrl = '/membership/plans';
    public string $loginUrl = '/login';
    public string $pricingUrl = '/membership/plans';

    // Email
    public bool $sendWelcomeEmail = true;
    public bool $sendPaymentReceiptEmail = true;
    public bool $sendPaymentFailedEmail = true;
    public bool $sendExpirationReminderEmail = true;
    public bool $sendTrialEndingEmail = true;
    public bool $sendCancellationEmail = true;
    public bool $sendDripUnlockedEmail = true;
    public int $expirationReminderDays = 3;

    // Outgoing Webhooks
    public string $outgoingWebhookUrl = '';
    public string $outgoingWebhookSecret = '';

    // API
    public string $apiKey = '';

    public function defineRules(): array
    {
        return [
            [['stripeSecretKey', 'stripePublishableKey', 'stripeWebhookSecret'], 'string'],
            [['paypalClientId', 'paypalClientSecret', 'paypalWebhookId'], 'string'],
            [['defaultCurrency'], 'string', 'max' => 3],
            [['checkoutSuccessUrl', 'checkoutCancelUrl', 'loginUrl', 'pricingUrl'], 'string'],
            [['outgoingWebhookUrl', 'outgoingWebhookSecret', 'apiKey'], 'string'],
            [['stripeEnabled', 'paypalEnabled', 'paypalSandbox'], 'boolean'],
            [[
                'sendWelcomeEmail',
                'sendPaymentReceiptEmail',
                'sendPaymentFailedEmail',
                'sendExpirationReminderEmail',
                'sendTrialEndingEmail',
                'sendCancellationEmail',
                'sendDripUnlockedEmail',
            ], 'boolean'],
            [['expirationReminderDays'], 'integer', 'min' => 1],
        ];
    }
}
