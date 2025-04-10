<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Psr\Log\LoggerInterface;

/**
 * HTTP服务器接口
 * 
 * 定义HTTP服务器的通用行为，使不同引擎的HTTP服务器实现可以互换
 */
interface HttpServerInterface
{
    /**
     * 注册SSE请求处理器
     * 
     * @param callable $handler 处理SSE连接的回调
     * @return self
     */
    public function withSseHandler(callable $handler): self;

    /**
     * 注册消息处理器
     * 
     * @param callable $handler 处理消息请求的回调
     * @return self
     */
    public function withMessagesHandler(callable $handler): self;

    /**
     * 启动HTTP服务器
     * 
     * @param string $host 主机地址
     * @param int $port 端口号
     * @return void
     */
    public function start(string $host, int $port): void;
}
