<?php

namespace Mcp\Tool\Handler;

use Mcp\Server\Server;
use Mcp\Tool\Annotation\PromptProcessor;
use Mcp\Tool\Execution\MethodExecutor;
use Mcp\Tool\Result\ResultProcessor;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use ReflectionClass;

/**
 * 提示模板处理器类
 */
class PromptHandler
{
    /**
     * @var PromptProcessor
     */
    private PromptProcessor $processor;

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
     * @param PromptProcessor $processor 提示模板注解处理器
     * @param ResultProcessor $resultProcessor 结果处理器
     * @param MethodExecutor $methodExecutor 方法执行器
     */
    public function __construct(
        PromptProcessor $processor,
        ResultProcessor $resultProcessor,
        MethodExecutor $methodExecutor
    ) {
        $this->processor = $processor;
        $this->resultProcessor = $resultProcessor;
        $this->methodExecutor = $methodExecutor;
    }

    /**
     * 注册提示模板相关处理器
     *
     * @param Server $server MCP服务器实例
     * @param ReflectionClass $reflectionClass 反射类
     * @param object $handler 处理器实例
     * @return void
     */
    public function register(Server $server, ReflectionClass $reflectionClass, object $handler): void
    {
        [$prompts, $promptMethodMap] = $this->processor->process($reflectionClass);

        if (empty($prompts)) {
            return;
        }

        // 注册提示模板列表处理器
        $server->registerHandler('prompts/list', function () use ($prompts) {
            return new ListPromptsResult($prompts);
        });

        // 注册提示模板获取处理器
        $server->registerHandler('prompts/get', function ($params) use ($prompts, $reflectionClass, $promptMethodMap, $handler) {
            $name = $params->name;
            $arguments = [];
            if (isset($params->arguments)) {
                $arguments = json_decode(json_encode($params->arguments), true);
            }

            if (!isset($promptMethodMap[$name])) {
                throw new \InvalidArgumentException("Unknown prompt: {$name}");
            }

            $methodName = $promptMethodMap[$name];

            try {
                $method = $reflectionClass->getMethod($methodName);
                $result = $this->methodExecutor->executeMethodSafely($method, $arguments, $handler);

                // 检查是否已经是 GetPromptResult 类型
                if ($result instanceof GetPromptResult) {
                    return $result;
                }

                // 找到对应的prompt定义以获取描述
                $promptDescription = '';
                foreach ($prompts as $prompt) {
                    if ($prompt->name === $name) {
                        $promptDescription = $prompt->description;
                        break;
                    }
                }

                $messages = $this->resultProcessor->processPromptResult($result);
                return new GetPromptResult(
                    messages: $messages,
                    description: $promptDescription
                );
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException("Error processing prompt: " . $e->getMessage(), 0, $e);
            }
        });
    }
}
