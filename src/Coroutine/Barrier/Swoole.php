<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Barrier;

use Swoole\Coroutine\Barrier as SwooleBarrier;

/**
 * @package Mcp\Coroutine\Barrier
 */
class Swoole implements BarrierInterface
{

    /**
     * @inheritDoc
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        SwooleBarrier::wait($barrier, $timeout);
    }

    /**
     * @inheritDoc
     */
    public static function create(): object
    {
        return SwooleBarrier::make();
    }
}
