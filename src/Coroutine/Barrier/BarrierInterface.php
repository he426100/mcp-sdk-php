<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Barrier;

/**
 * 来自 workerman-php/coroutine
 * Interface BarrierInterface
 * @package Mcp\Coroutine\Barrier
 */
interface BarrierInterface
{
    /**
     * Wait for the barrier to be released.
     *
     * @param object $barrier
     * @param int $timeout
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void;

    /**
     * Create a new barrier instance.
     *
     * @return BarrierInterface
     */
    public static function create(): object;
}
