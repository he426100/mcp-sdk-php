<?php

namespace Mcp\Tool\Handler;

use Mcp\Server\Server;
use Mcp\Tool\Annotation\ToolProcessor;
use Mcp\Tool\Execution\MethodExecutor;
use Mcp\Tool\Result\ResultProcessor;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\TextContent;
use Mcp\Types\EmbeddedResource;
use Mcp\Types\ImageContent;
use Mcp\Types\Content;
use ReflectionClass;

/**
 * 工具处理器类
 */
class ToolHandler
{
    /**
     * @var ToolProcessor
     */
    private ToolProcessor $processor;

    /**
     * @var ResultProcessor
     */
    private ResultProcessor $resultProcessor;

    /**
     * @var MethodExecutor
     */
    private MethodExecutor $methodExecutor;

    /**
     * 构造方法
     *
     * @param ToolProcessor $processor 工具注解处理器
     * @param ResultProcessor $resultProcessor 结果处理器
     * @param MethodExecutor $methodExecutor 方法执行器
     */
    public function __construct(
        ToolProcessor $processor,
        ResultProcessor $resultProcessor,
        MethodExecutor $methodExecutor
    ) {
        $this->processor = $processor;
        $this->resultProcessor = $resultProcessor;
        $this->methodExecutor = $methodExecutor;
    }

    /**
     * 注册工具相关处理器
     *
     * @param Server $server MCP服务器实例
     * @param ReflectionClass $reflectionClass 反射类
     * @param object $handler 处理器实例
     * @return void
     */
    public function register(Server $server, ReflectionClass $reflectionClass, object $handler): void
    {
        [$tools, $toolMethodMap] = $this->processor->process($reflectionClass);

        if (empty($tools)) {
            return;
        }

        // 注册工具列表处理器
        $server->registerHandler('tools/list', function () use ($tools) {
            return new ListToolsResult($tools);
        });

        // 注册工具调用处理器
        $server->registerHandler('tools/call', function ($params) use ($reflectionClass, $toolMethodMap, $handler) {
            $name = $params->name;
            $arguments = [];
            if (isset($params->arguments)) {
                $arguments = json_decode(json_encode($params->arguments), true);
            }

            if (!isset($toolMethodMap[$name])) {
                return new CallToolResult(
                    content: [new TextContent(text: "Error: Unknown tool: {$name}")],
                    isError: true
                );
            }

            $methodName = $toolMethodMap[$name];

            try {
                $method = $reflectionClass->getMethod($methodName);
                $result = $this->methodExecutor->executeMethodSafely($method, $arguments, $handler);

                // 检查是否已经是 CallToolResult 类型
                if ($result instanceof CallToolResult) {
                    return $result;
                }

                $content = $this->resultProcessor->processToolResult($result);
                return new CallToolResult(content: $content);
            } catch (\Throwable $e) {
                return new CallToolResult(
                    content: [new TextContent(text: "Error: " . $e->getMessage())],
                    isError: true
                );
            }
        });
    }
}
