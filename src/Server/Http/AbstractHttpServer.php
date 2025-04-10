<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP服务器抽象基类
 * 
 * 为具体HTTP服务器实现提供共享功能
 */
abstract class AbstractHttpServer implements HttpServerInterface
{
    /** @var callable|null */
    protected $sseHandler = null;

    /** @var callable|null */
    protected $messagesHandler = null;

    /** @var bool */
    protected bool $running = false;

    /** @var LoggerInterface */
    protected LoggerInterface $logger;

    /**
     * 构造函数
     * 
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function withSseHandler(callable $handler): self
    {
        $this->sseHandler = $handler;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function withMessagesHandler(callable $handler): self
    {
        $this->messagesHandler = $handler;
        return $this;
    }
}
