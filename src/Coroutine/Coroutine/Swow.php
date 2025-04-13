<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Coroutine;

use Swow\Coroutine;

/**
 * Class Swow
 */
class Swow extends Coroutine implements CoroutineInterface
{
    /**
     * @inheritDoc
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        return static::run($callable, ...$args);
    }

    /**
     * @inheritDoc
     */
    public function id(): int
    {
        return $this->getId();
    }

    /**
     * 
     * @inheritDoc
     */
    public function cancel(): void
    {
        if ($this->isExecuting()) {
            $this->kill();
        }
    }
}
