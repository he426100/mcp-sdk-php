<?php

declare(strict_types=1);

namespace Mcp\Coroutine;

use Mcp\Coroutine\Coroutine\CoroutineInterface;
use Mcp\Coroutine\Coroutine\Swoole as SwooleCoroutine;
use Mcp\Coroutine\Coroutine\Swow as SwowCoroutine;

/**
 * Class Coroutine
 * 来自 workerman-php/coroutine
 */
class Coroutine implements CoroutineInterface
{
    /**
     * @var class-string<CoroutineInterface>
     */
    protected static string $driverClass;

    /**
     * @var CoroutineInterface
     */
    public CoroutineInterface $driver;

    /**
     * Coroutine constructor.
     *
     * @param callable $callable
     */
    public function __construct(callable $callable)
    {
        $this->driver = new static::$driverClass($callable);
    }

    /**
     * @inheritDoc
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        return static::$driverClass::create($callable, ...$args);
    }

    /**
     * @inheritDoc
     */
    public function id(): int
    {
        return $this->driver->id();
    }

    /**
     * @inheritDoc
     */
    public function isExecuting(): bool
    {
        return $this->driver->isExecuting();
    }

    /**
     * @inheritDoc
     */
    public function kill(): void
    {
        $this->driver->kill();
    }

    /**
     * @return void
     */
    public static function init(): void
    {
        static::$driverClass = match (DriverDetector::detect()) {
            DriverDetector::DRIVER_SWOOLE => SwooleCoroutine::class,
            DriverDetector::DRIVER_SWOW => SwowCoroutine::class,
        };
    }
}
