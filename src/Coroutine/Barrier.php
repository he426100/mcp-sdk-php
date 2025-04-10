<?php

declare(strict_types=1);

namespace Mcp\Coroutine;

use Mcp\Coroutine\Barrier\BarrierInterface;
use Mcp\Coroutine\Barrier\Swoole as BarrierSwoole;
use Mcp\Coroutine\Barrier\Swow as BarrierSwow;

/**
 * Class Barrier
 * 来自 workerman-php/coroutine
 */
class Barrier implements BarrierInterface
{

    /**
     * @var string
     */
    protected static string $driver;

    /**
     * Get driver.
     *
     * @return string
     */
    protected static function getDriver(): string
    {
        return static::$driver ??= match (DriverDetector::detect()) {
            DriverDetector::DRIVER_SWOOLE => BarrierSwoole::class,
            DriverDetector::DRIVER_SWOW => BarrierSwow::class,
        };
    }

    /**
     * @inheritDoc
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        static::getDriver()::wait($barrier, $timeout);
    }

    /**
     * @inheritDoc
     */
    public static function create(): object
    {
        return static::getDriver()::create();
    }
}
