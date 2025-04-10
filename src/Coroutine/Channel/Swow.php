<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Channel;

use Swow\Channel;
use Throwable;

/**
 * Class Swow
 */
class Swow implements ChannelInterface
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
        try {
            $this->channel->push($data, $timeout == -1 ? -1 : (int)($timeout * 1000));
        } catch (Throwable) {
            return false;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function pop(float $timeout = -1): mixed
    {
        try {
            return $this->channel->pop($timeout == -1 ? -1 : (int)($timeout * 1000));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->channel->isAvailable()) {
            $this->channel->close();
        }
    }
}
