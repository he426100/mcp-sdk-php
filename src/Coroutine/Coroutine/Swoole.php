<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Coroutine;

use Swoole\Coroutine;

class Swoole implements CoroutineInterface
{
    /**
     * @var int
     */
    private int $id = 0;

    /**
     * @inheritDoc
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        $id = Coroutine::create($callable, ...$args);
        $coroutine = new self($callable);
        $coroutine->id = $id;
        return $coroutine;
    }

    /**
     * @inheritDoc
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * 
     * @inheritDoc
     */
    public function cancel(): void
    {
        Coroutine::cancel($this->id);
    }
}
