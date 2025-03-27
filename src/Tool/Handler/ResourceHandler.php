<?php

namespace Mcp\Tool\Handler;

use Mcp\Server\Server;
use Mcp\Tool\Annotation\ResourceProcessor;
use Mcp\Tool\Execution\MethodExecutor;
use Mcp\Tool\Result\ResultProcessor;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\ResourceContents;
use ReflectionClass;

/**
 * 资源处理器类
 */
class ResourceHandler
{
    /**
     * @var ResourceProcessor
     */
    private ResourceProcessor $processor;

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
     * @param ResourceProcessor $processor 资源注解处理器
     * @param ResultProcessor $resultProcessor 结果处理器
     * @param MethodExecutor $methodExecutor 方法执行器
     */
    public function __construct(
        ResourceProcessor $processor,
        ResultProcessor $resultProcessor,
        MethodExecutor $methodExecutor
    ) {
        $this->processor = $processor;
        $this->resultProcessor = $resultProcessor;
        $this->methodExecutor = $methodExecutor;
    }

    /**
     * 注册资源相关处理器
     *
     * @param Server $server MCP服务器实例
     * @param ReflectionClass $reflectionClass 反射类
     * @param object $handler 处理器实例
     * @return void
     */
    public function register(Server $server, ReflectionClass $reflectionClass, object $handler): void
    {
        [$resources, $resourceUriMap] = $this->processor->process($reflectionClass);

        if (empty($resources)) {
            return;
        }

        // 注册资源列表处理器
        $server->registerHandler('resources/list', function () use ($resources) {
            return new ListResourcesResult($resources);
        });

        // 注册资源读取处理器
        $server->registerHandler('resources/read', function ($params) use ($resources, $reflectionClass, $resourceUriMap, $handler) {
            $uri = $params->uri;

            if (!isset($resourceUriMap[$uri])) {
                throw new \InvalidArgumentException("Unknown resource: {$uri}");
            }

            $methodName = $resourceUriMap[$uri];

            // 找到对应的resource定义以获取mimeType
            $mimeType = 'text/plain';
            foreach ($resources as $resource) {
                if ($resource->uri === $uri) {
                    $mimeType = $resource->mimeType;
                    break;
                }
            }

            try {
                $method = $reflectionClass->getMethod($methodName);
                $result = $this->methodExecutor->executeMethodSafely($method, [], $handler);

                // 检查是否已经是 ReadResourceResult 类型
                if ($result instanceof ReadResourceResult) {
                    return $result;
                }

                // 处理资源结果
                $resourceContents = $this->resultProcessor->processResourceResult($result, $mimeType, $uri);
                return new ReadResourceResult(
                    contents: $resourceContents
                );
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException("Error reading resource: " . $e->getMessage(), 0, $e);
            }
        });
    }
}
