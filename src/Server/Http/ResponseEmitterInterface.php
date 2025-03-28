<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

/**
 * 响应发送器接口
 * 
 * 提供发送SSE事件的抽象接口，使SseServerTransport不依赖于具体实现
 */
interface ResponseEmitterInterface
{
    /**
     * 发送SSE响应头
     * 
     * @return void
     */
    public function sendSseHeaders(): void;

    /**
     * 发送SSE事件
     * 
     * @param string $event 事件名称
     * @param string $data 事件数据
     * @return bool 是否成功发送
     */
    public function sendEvent(string $event, string $data): bool;

    /**
     * 关闭连接
     * 
     * @return void
     */
    public function close(): void;

    /**
     * 检查连接是否开启
     * 
     * @return bool
     */
    public function isConnected(): bool;
}
