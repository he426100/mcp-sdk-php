<?php

declare(strict_types=1);

namespace Mcp\Coroutine\Channel;

/**
 * ChannelInterface
 * 来自 workerman-php/coroutine
 */
interface ChannelInterface
{
    /**
     * Push data to channel.
     *
     * @param mixed $data
     * @param float $timeout
     * @return bool
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * Pop data from channel.
     *
     * @param float $timeout
     * @return mixed
     */
    public function pop(float $timeout = -1): mixed;


    /**
     *
     * @return bool
     */
    public function isAvailable(): bool;


    /**
     * Close the channel.
     *
     * @return void
     */
    public function close(): void;
}
