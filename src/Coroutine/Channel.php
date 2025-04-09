<?php

declare(strict_types=1);

namespace Mcp\Coroutine;

use InvalidArgumentException;
use Mcp\Coroutine\Channel\ChannelInterface;
use Mcp\Coroutine\Channel\Swoole as ChannelSwoole;
use Mcp\Coroutine\Channel\Swow as ChannelSwow;

/**
 * Class Channel
 * 来自 workerman-php/coroutine
 */
class Channel implements ChannelInterface
{

    /**
     * @var ChannelInterface
     */
    protected ChannelInterface $driver;

    /**
     * Channel constructor.
     *
     * @param int $capacity
     */
    public function __construct(int $capacity = 1)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException("The capacity must be greater than 0");
        }
        $this->driver = match (DriverDetector::detect()) {
            DriverDetector::DRIVER_SWOOLE => new ChannelSwoole($capacity),
            DriverDetector::DRIVER_SWOW => new ChannelSwow($capacity),
        };
    }

    /**
     * @inheritDoc
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        return $this->driver->push($data, $timeout);
    }

    /**
     * @inheritDoc
     */
    public function pop(float $timeout = -1): mixed
    {
        return $this->driver->pop($timeout);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->driver->close();
    }
}
