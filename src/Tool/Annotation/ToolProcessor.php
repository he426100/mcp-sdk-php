<?php

namespace Mcp\Tool\Annotation;

use Mcp\Annotation\Tool;
use Mcp\Types\Tool as McpTool;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use ReflectionClass;
use ReflectionMethod;

/**
 * 工具注解处理器
 */
class ToolProcessor extends AnnotationProcessor
{
    /**
     * 处理工具注解
     *
     * @param ReflectionClass $reflectionClass
     * @return array [工具定义数组, 工具名称到方法名的映射]
     */
    public function process(ReflectionClass $reflectionClass): array
    {
        $tools = [];
        $toolMethodMap = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $toolAttributes = $method->getAttributes(Tool::class);
            foreach ($toolAttributes as $attribute) {
                $tool = $attribute->newInstance();
                $tools[] = $this->createToolDefinition($tool, $method);
                $toolMethodMap[$tool->getName()] = $method->getName();
            }
        }

        return [$tools, $toolMethodMap];
    }

    /**
     * 创建工具定义
     *
     * @param Tool $tool 工具注解
     * @param ReflectionMethod $method 工具方法
     * @return McpTool 工具定义
     */
    private function createToolDefinition(Tool $tool, ReflectionMethod $method): McpTool
    {
        $parameters = $tool->getParameters();
        if (empty($parameters)) {
            $parameters = $this->extractParametersFromMethod($method);
        }

        $properties = ToolInputProperties::fromArray($parameters);
        $required = array_keys(array_filter($parameters, fn($p) => ($p['required'] ?? false)));

        return new McpTool(
            name: $tool->getName(),
            description: $tool->getDescription(),
            inputSchema: new ToolInputSchema(
                properties: $properties,
                required: $required
            ),
        );
    }
}
