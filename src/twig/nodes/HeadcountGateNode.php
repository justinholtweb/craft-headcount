<?php

namespace justinholtweb\headcount\twig\nodes;

use Twig\Compiler;
use Twig\Node\Node;

class HeadcountGateNode extends Node
{
    private array $attributes;

    public function __construct(Node $body, array $attributes, int $lineno, string $tag)
    {
        parent::__construct(['body' => $body], [], $lineno, $tag);
        $this->attributes = $attributes;
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->addDebugInfo($this);

        // Start conditional check
        $compiler->write('$__headcount_plugin = \justinholtweb\headcount\Headcount::getInstance();' . "\n");
        $compiler->write('$__headcount_user = \Craft::$app->getUser()->getIdentity();' . "\n");
        $compiler->write('$__headcount_allowed = false;' . "\n");

        $compiler->write('if ($__headcount_user) {' . "\n");
        $compiler->indent();

        if (isset($this->attributes['planHandle'])) {
            $compiler->write('$__headcount_allowed = $__headcount_plugin->subscriptions->hasActiveSubscription($__headcount_user->id, ');
            $this->attributes['planHandle']->compile($compiler);
            $compiler->raw(');' . "\n");
        } else {
            $compiler->write('$__headcount_allowed = !empty($__headcount_plugin->subscriptions->getActiveSubscriptionsForUser($__headcount_user->id));' . "\n");
        }

        $compiler->outdent();
        $compiler->write('}' . "\n");

        $compiler->write('if ($__headcount_allowed) {' . "\n");
        $compiler->indent();
        $compiler->subcompile($this->getNode('body'));
        $compiler->outdent();
        $compiler->write('}' . "\n");
    }
}
