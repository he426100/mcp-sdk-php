<?php

namespace Mcp\Tool;

use Mcp\Server\Server;
use Mcp\Tool\Handler\HandlerFactory;
use ReflectionClass;

/**
 * MCP处理器注册器
 * 负责将注解处理器注册到MCP服务器
 */
class McpHandlerRegistrar
{
    /**
     * @var HandlerFactory
     */
    private HandlerFactory $handlerFactory;

    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->handlerFactory = new HandlerFactory();
    }

    /**
     * 将处理器注册到MCP服务器
     *
     * @param Server $server MCP服务器实例
     * @param object $handler 处理器实例
     * @return void
     */
    public function registerHandler(Server $server, object $handler): void
    {
        $reflectionClass = new ReflectionClass($handler);

        // 创建并注册各类处理器
        $toolHandler = $this->handlerFactory->createToolHandler();
        $toolHandler->register($server, $reflectionClass, $handler);

        $promptHandler = $this->handlerFactory->createPromptHandler();
        $promptHandler->register($server, $reflectionClass, $handler);

        $resourceHandler = $this->handlerFactory->createResourceHandler();
        $resourceHandler->register($server, $reflectionClass, $handler);
    }
}
