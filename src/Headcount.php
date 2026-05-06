<?php

namespace justinholtweb\headcount;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\AuthorizationCheckEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\headcount\assets\HeadcountAsset;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\models\Settings;
use justinholtweb\headcount\services\Coupons;
use justinholtweb\headcount\services\Drip;
use justinholtweb\headcount\services\Emails;
use justinholtweb\headcount\services\Gating;
use justinholtweb\headcount\services\Members;
use justinholtweb\headcount\services\PayPal;
use justinholtweb\headcount\services\Plans;
use justinholtweb\headcount\services\Reporting;
use justinholtweb\headcount\services\Stripe;
use justinholtweb\headcount\services\Subscriptions;
use justinholtweb\headcount\services\Webhooks;
use justinholtweb\headcount\twig\HeadcountTwigExtension;
use justinholtweb\headcount\twig\HeadcountVariable;
use justinholtweb\headcount\widgets\MembershipOverviewWidget;
use justinholtweb\headcount\widgets\RevenueWidget;
use yii\base\Event;

/**
 * Headcount - Membership & Subscription Management for Craft CMS 5
 *
 * @property-read Plans $plans
 * @property-read Subscriptions $subscriptions
 * @property-read Gating $gating
 * @property-read Stripe $stripe
 * @property-read PayPal $paypal
 * @property-read Webhooks $webhooks
 * @property-read Drip $drip
 * @property-read Coupons $coupons
 * @property-read Members $members
 * @property-read Reporting $reporting
 * @property-read Emails $emails
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Headcount extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'plans' => Plans::class,
                'subscriptions' => Subscriptions::class,
                'gating' => Gating::class,
                'stripe' => Stripe::class,
                'paypal' => PayPal::class,
                'webhooks' => Webhooks::class,
                'drip' => Drip::class,
                'coupons' => Coupons::class,
                'members' => Members::class,
                'reporting' => Reporting::class,
                'emails' => Emails::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerElementTypes();
        $this->_registerCpUrlRules();
        $this->_registerSiteUrlRules();
        $this->_registerPermissions();
        $this->_registerWidgets();
        $this->_registerTemplateVariable();
        $this->_registerTwigExtension();
        $this->_registerContentGating();
        $this->_registerTemplateHooks();
        $this->_registerCpAssets();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Headcount';

        $user = Craft::$app->getUser()->getIdentity();

        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'headcount'],
            'plans' => ['label' => 'Plans', 'url' => 'headcount/plans'],
            'subscriptions' => ['label' => 'Subscriptions', 'url' => 'headcount/subscriptions'],
            'access-rules' => ['label' => 'Access Rules', 'url' => 'headcount/access-rules'],
            'drip' => ['label' => 'Drip', 'url' => 'headcount/drip'],
            'coupons' => ['label' => 'Coupons', 'url' => 'headcount/coupons'],
        ];

        if ($user && $user->can('headcount-viewReports')) {
            $item['subnav']['reports'] = ['label' => 'Reports', 'url' => 'headcount/reports'];
        }

        $item['subnav']['settings'] = ['label' => 'Settings', 'url' => 'headcount/settings'];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'headcount/settings/index',
            ['settings' => $this->getSettings()]
        );
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Subscription::class;
            }
        );
    }

    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['headcount'] = 'headcount/reporting/dashboard';
                $event->rules['headcount/plans'] = 'headcount/plans/index';
                $event->rules['headcount/plans/new'] = 'headcount/plans/edit';
                $event->rules['headcount/plans/<planId:\d+>'] = 'headcount/plans/edit';
                $event->rules['headcount/subscriptions'] = 'headcount/subscriptions/index';
                $event->rules['headcount/subscriptions/<elementId:\d+>'] = 'headcount/subscriptions/edit';
                $event->rules['headcount/access-rules'] = 'headcount/access-rules/index';
                $event->rules['headcount/access-rules/new'] = 'headcount/access-rules/edit';
                $event->rules['headcount/access-rules/<ruleId:\d+>'] = 'headcount/access-rules/edit';
                $event->rules['headcount/drip'] = 'headcount/drip/index';
                $event->rules['headcount/drip/new'] = 'headcount/drip/edit';
                $event->rules['headcount/drip/<scheduleId:\d+>'] = 'headcount/drip/edit';
                $event->rules['headcount/coupons'] = 'headcount/coupons/index';
                $event->rules['headcount/coupons/new'] = 'headcount/coupons/edit';
                $event->rules['headcount/coupons/<couponId:\d+>'] = 'headcount/coupons/edit';
                $event->rules['headcount/reports'] = 'headcount/reporting/index';
                $event->rules['headcount/settings'] = 'headcount/settings/index';
                $event->rules['headcount/settings/stripe'] = 'headcount/settings/stripe';
                $event->rules['headcount/settings/paypal'] = 'headcount/settings/paypal';
                $event->rules['headcount/settings/emails'] = 'headcount/settings/emails';
            }
        );
    }

    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['headcount/checkout'] = 'headcount/checkout/create-session';
                $event->rules['headcount/checkout/success'] = 'headcount/checkout/success';
                $event->rules['headcount/checkout/cancel'] = 'headcount/checkout/cancel';
                $event->rules['headcount/portal'] = 'headcount/portal/redirect';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Headcount',
                    'permissions' => [
                        'headcount-managePlans' => [
                            'label' => 'Manage membership plans',
                        ],
                        'headcount-manageSubscriptions' => [
                            'label' => 'Manage subscriptions',
                        ],
                        'headcount-manageAccessRules' => [
                            'label' => 'Manage access rules',
                        ],
                        'headcount-viewReports' => [
                            'label' => 'View reports',
                        ],
                        'headcount-manageCoupons' => [
                            'label' => 'Manage coupons',
                        ],
                        'headcount-manageDrip' => [
                            'label' => 'Manage drip schedules',
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = MembershipOverviewWidget::class;
                $event->types[] = RevenueWidget::class;
            }
        );
    }

    private function _registerTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                $event->sender->set('headcount', HeadcountVariable::class);
            }
        );
    }

    private function _registerTwigExtension(): void
    {
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Craft::$app->getView()->registerTwigExtension(new HeadcountTwigExtension());
        }
    }

    private function _registerContentGating(): void
    {
        if (!Craft::$app->getRequest()->getIsSiteRequest()) {
            return;
        }

        Event::on(
            Entry::class,
            Element::EVENT_AUTHORIZE_VIEW,
            function (AuthorizationCheckEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $user = Craft::$app->getUser()->getIdentity();

                $result = $this->gating->evaluateAccess($entry, $user);

                if ($result !== null && !$result['allowed']) {
                    $event->authorized = false;
                    $event->handled = true;

                    // Store the gating result for the template to use
                    Craft::$app->getUrlManager()->setRouteParams([
                        '_headcountGating' => $result,
                    ]);
                }
            }
        );
    }

    private function _registerCpAssets(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function () {
                Craft::$app->getView()->registerAssetBundle(HeadcountAsset::class);
            }
        );
    }

    private function _registerTemplateHooks(): void
    {
        Craft::$app->getView()->hook('cp.entries.edit.details', function (array &$context) {
            $entry = $context['entry'] ?? null;
            if (!$entry) {
                return '';
            }

            return Craft::$app->getView()->renderTemplate(
                'headcount/_sidebar/entry-gating',
                ['entry' => $entry]
            );
        });
    }
}
