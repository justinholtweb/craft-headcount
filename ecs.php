<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Codeception-generated actors and the test Craft app aren't ours to lint.
    $ecsConfig->skip([
        __DIR__ . '/tests/_support/_generated',
        __DIR__ . '/tests/_craft',
        __DIR__ . '/tests/_output',
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
