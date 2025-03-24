<?php

declare(strict_types=1);

namespace Mcp\Server;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Closure;
use Throwable;

class Waiter
{
    protected float $pushTimeout = 10.0;

    protected float $popTimeout = 10.0;

    public function __construct(float $timeout = 10.0)
    {
        $this->popTimeout = $timeout;
    }

    /**
     * @template TReturn
     *
     * @param Closure():TReturn $closure
     * @param null|float $timeout seconds
     * @return TReturn
     */
    public function wait(Closure $closure, ?float $timeout = null)
    {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        Coroutine::create(function () use ($channel, $closure) {
            try {
                $result = $closure();
            } catch (Throwable $exception) {
                $result = $exception;
            } finally {
                $channel->push($result ?? null, $this->pushTimeout);
            }
        });

        $result = $channel->pop($timeout);
        if ($result === false && $channel->errCode == \SWOOLE_CHANNEL_TIMEOUT) {
            throw new \Exception(sprintf('Channel wait failed, reason: Timed out for %s s', $timeout));
        }
        if ($result instanceof \Exception) {
            throw $result;
        }

        return $result;
    }
}
