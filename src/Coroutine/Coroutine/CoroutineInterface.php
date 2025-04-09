<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Coroutine;

/**
 * Interface CoroutineInterface
 * 来自 workerman-php/coroutine
 */
interface CoroutineInterface
{

    /**
     * Create a coroutine.
     *
     * @param callable $callable
     * @param ...$data
     * @return CoroutineInterface
     */
    public static function create(callable $callable, ...$data): CoroutineInterface;

    /**
     * Get the id of the coroutine.
     *
     * @return int
     */
    public function id(): int;

    /**
     * 
     * @return bool 
     */
    public function isExecuting(): bool;

    /**
     * 
     * @return void 
     */
    public function kill(): void;
}
