<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Barrier;

use Swow\Sync\WaitReference;

/**
 * @package Mcp\Coroutine\Barrier
 */
class Swow implements BarrierInterface
{
    /**
     * @inheritDoc
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        WaitReference::wait($barrier, $timeout);
    }

    /**
     * @inheritDoc
     */
    public static function create(): object
    {
        return new WaitReference();
    }
}
