<?php

namespace Mcp\Tool\Handler;

use Mcp\Server\Server;
use Mcp\Tool\Annotation\AnnotationProcessor;
use Mcp\Tool\Annotation\ToolProcessor;
use Mcp\Tool\Annotation\PromptProcessor;
use Mcp\Tool\Annotation\ResourceProcessor;
use Mcp\Tool\Execution\MethodExecutor;
use Mcp\Tool\Parameter\ParameterHandler;
use Mcp\Tool\Result\ResultProcessor;

/**
 * 处理器工厂类
 */
class HandlerFactory
{
    /**
     * 创建工具处理器
     *
     * @return ToolHandler
     */
    public function createToolHandler(): ToolHandler
    {
        return new ToolHandler(
            new ToolProcessor(),
            new ResultProcessor(),
            $this->createMethodExecutor()
        );
    }

    /**
     * 创建提示模板处理器
     *
     * @return PromptHandler
     */
    public function createPromptHandler(): PromptHandler
    {
        return new PromptHandler(
            new PromptProcessor(),
            new ResultProcessor(),
            $this->createMethodExecutor()
        );
    }

    /**
     * 创建资源处理器
     *
     * @return ResourceHandler
     */
    public function createResourceHandler(): ResourceHandler
    {
        return new ResourceHandler(
            new ResourceProcessor(),
            new ResultProcessor(),
            $this->createMethodExecutor()
        );
    }

    /**
     * 创建方法执行器
     *
     * @return MethodExecutor
     */
    private function createMethodExecutor(): MethodExecutor
    {
        return new MethodExecutor(
            new ParameterHandler()
        );
    }
}
