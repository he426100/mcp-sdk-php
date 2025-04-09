<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Channel;

use Swoole\Coroutine\Channel;

/**
 * Class Swoole
 */
class Swoole implements ChannelInterface
{

    /**
     * @var Channel
     */
    protected Channel $channel;

    /**
     * Constructor.
     *
     * @param int $capacity
     */
    public function __construct(protected int $capacity = 1)
    {
        $this->channel = new Channel($capacity);
    }

    /**
     * @inheritDoc
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        return $this->channel->push($data, $timeout);
    }

    /**
     * @inheritDoc
     */
    public function pop(float $timeout = -1): mixed
    {
        return $this->channel->pop($timeout);
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->channel->close();
    }
}
