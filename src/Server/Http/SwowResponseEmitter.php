<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Swow\Psr7\Server\ServerConnection;

/**
 * Swow响应发送器实现
 */
class SwowResponseEmitter implements ResponseEmitterInterface
{
    /** @var bool */
    private bool $isConnected = true;

    /**
     * 构造函数
     * 
     * @param ServerConnection $connection
     */
    public function __construct(
        private readonly ServerConnection $connection
    ) {}

    /**
     * {@inheritdoc}
     */
    public function sendSseHeaders(): void
    {
        $this->connection->respond([
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'Content-Length' => '0',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // 禁用响应缓冲
        ]);
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
            $this->connection->send($sseData);
            return true;
        } catch (\Exception $e) {
            $this->isConnected = false;
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->isConnected) {
            $this->connection->close();
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
