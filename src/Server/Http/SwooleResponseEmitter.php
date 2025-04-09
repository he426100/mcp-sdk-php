<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Swoole\Http\Response;

class SwooleResponseEmitter implements ResponseEmitterInterface
{
    /** @var bool */
    private bool $isConnected = true;

    public function __construct(
        private Response $response
    ) {}

    /**
     * {@inheritdoc}
     */
    public function sendSseHeaders(): void
    {
        $this->response->header('Content-Type', 'text/event-stream');
        $this->response->header('Cache-Control', 'no-cache');
        $this->response->header('Connection', 'keep-alive');
        $this->response->header('X-Accel-Buffering', 'no');
    }

    /**
     * {@inheritdoc}
     */
    public function sendEvent(string $event, string $data): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            $sseData = "event: {$event}\ndata: {$data}\n\n";
            $this->response->write($sseData);
            return true;
        } catch (\Exception $e) {
            $this->isConnected = false;
            return false;
        }
    }

    /**
     * 发送数据
     */
    public function emit(string $data): void
    {
        $this->response->write("data: {$data}\n\n");
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->isConnected) {
            $this->response->end();
            $this->isConnected = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
}
