<?php

namespace Mcp\Tool\Execution;

use Mcp\Tool\Parameter\ParameterHandler;
use ReflectionMethod;

/**
 * 方法执行器类
 */
class MethodExecutor
{
    /**
     * @var ParameterHandler
     */
    private ParameterHandler $parameterHandler;

    /**
     * 构造方法
     *
     * @param ParameterHandler $parameterHandler 参数处理器
     */
    public function __construct(ParameterHandler $parameterHandler)
    {
        $this->parameterHandler = $parameterHandler;
    }

    /**
     * 安全执行方法，统一异常处理
     *
     * @param ReflectionMethod $method 要执行的方法
     * @param array $arguments 参数数组
     * @param object $handler 处理器实例
     * @return mixed 方法返回值
     * @throws \RuntimeException 执行异常时
     */
    public function executeMethodSafely(ReflectionMethod $method, array $arguments, object $handler)
    {
        try {
            return $method->invoke($handler, ...$this->parameterHandler->prepareArguments($method, $arguments));
        } catch (\Throwable $e) {
            // 记录错误
            error_log("Error executing method {$method->getName()}: " . $e->getMessage());
            throw new \RuntimeException("Error: " . $e->getMessage(), 0, $e);
        }
    }
}
