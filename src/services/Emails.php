<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\jobs\SendMemberEmail;
use justinholtweb\headcount\models\DripSchedule;
use yii\base\Component;

class Emails extends Component
{
    public function sendWelcomeEmail(Subscription $subscription): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendWelcomeEmail) {
            return;
        }

        $plan = $subscription->getPlan();
        $user = $subscription->getUser();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Welcome to ' . ($plan ? $plan->name : 'your subscription'),
            $this->renderEmailBody('welcome', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
            ])
        );
    }

    public function sendPaymentReceiptEmail(Subscription $subscription, float $amount): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendPaymentReceiptEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Payment Receipt',
            $this->renderEmailBody('receipt', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
                'amount' => $amount,
                'currency' => $subscription->currency,
            ])
        );
    }

    public function sendPaymentFailedEmail(Subscription $subscription): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendPaymentFailedEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Payment Failed - Action Required',
            $this->renderEmailBody('payment-failed', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
            ])
        );
    }

    public function sendExpirationReminderEmail(Subscription $subscription): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendExpirationReminderEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Your subscription is expiring soon',
            $this->renderEmailBody('expiration-reminder', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
            ])
        );
    }

    public function sendTrialEndingEmail(Subscription $subscription): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendTrialEndingEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Your trial is ending soon',
            $this->renderEmailBody('trial-ending', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
            ])
        );
    }

    public function sendCancellationEmail(Subscription $subscription): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendCancellationEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'Subscription Canceled',
            $this->renderEmailBody('cancellation', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
            ])
        );
    }

    public function sendDripUnlockedEmail(Subscription $subscription, DripSchedule $schedule): void
    {
        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->sendDripUnlockedEmail) {
            return;
        }

        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        if (!$user) {
            return;
        }

        $this->queueEmail(
            $user->email,
            'New content unlocked!',
            $this->renderEmailBody('drip-unlocked', [
                'user' => $user,
                'plan' => $plan,
                'subscription' => $subscription,
                'schedule' => $schedule,
            ])
        );
    }

    private function queueEmail(string $to, string $subject, string $body): void
    {
        Craft::$app->getQueue()->push(new SendMemberEmail([
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ]));
    }

    private function renderEmailBody(string $template, array $variables = []): string
    {
        $variables['siteName'] = Craft::$app->getSystemName();
        $variables['siteUrl'] = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

        // Use a simple HTML email body -- customizable templates can be added later
        $heading = match ($template) {
            'welcome' => 'Welcome!',
            'receipt' => 'Payment Receipt',
            'payment-failed' => 'Payment Failed',
            'expiration-reminder' => 'Subscription Expiring Soon',
            'trial-ending' => 'Trial Ending Soon',
            'cancellation' => 'Subscription Canceled',
            'drip-unlocked' => 'New Content Available',
            default => '',
        };

        $user = $variables['user'] ?? null;
        $plan = $variables['plan'] ?? null;
        $firstName = $user?->firstName ?: ($user?->username ?? 'Member');

        $body = match ($template) {
            'welcome' => "Hi {$firstName},\n\nWelcome to {$variables['siteName']}! Your {$plan?->name} subscription is now active.\n\nThank you for joining!",

            'receipt' => "Hi {$firstName},\n\nWe've received your payment of " . strtoupper($variables['currency'] ?? 'USD') . ' ' . number_format($variables['amount'] ?? 0, 2) . " for your {$plan?->name} subscription.\n\nThank you!",

            'payment-failed' => "Hi {$firstName},\n\nWe were unable to process your payment for {$plan?->name}. Please update your payment method to avoid any interruption to your subscription.\n\nYou can manage your subscription at {$variables['siteUrl']}",

            'expiration-reminder' => "Hi {$firstName},\n\nYour {$plan?->name} subscription is expiring soon. If you'd like to continue your access, no action is needed if you have automatic renewal enabled.",

            'trial-ending' => "Hi {$firstName},\n\nYour trial for {$plan?->name} is ending soon. After your trial ends, you'll be billed at the regular rate.\n\nIf you'd like to continue, no action is needed!",

            'cancellation' => "Hi {$firstName},\n\nYour {$plan?->name} subscription has been canceled. You'll continue to have access until the end of your current billing period.\n\nWe're sorry to see you go!",

            'drip-unlocked' => "Hi {$firstName},\n\nNew content has been unlocked for you as part of your {$plan?->name} subscription!\n\nVisit {$variables['siteUrl']} to check it out.",

            default => '',
        };

        return $body;
    }
}
