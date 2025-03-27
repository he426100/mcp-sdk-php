<?php

namespace Mcp\Tool\Annotation;

use Mcp\Annotation\Prompt;
use Mcp\Types\Prompt as McpPrompt;
use Mcp\Types\PromptArgument;
use ReflectionClass;
use ReflectionMethod;

/**
 * 提示模板注解处理器
 */
class PromptProcessor extends AnnotationProcessor
{
    /**
     * 处理提示模板注解
     *
     * @param ReflectionClass $reflectionClass
     * @return array [提示模板定义数组, 提示模板名称到方法名的映射]
     */
    public function process(ReflectionClass $reflectionClass): array
    {
        $prompts = [];
        $promptMethodMap = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $promptAttributes = $method->getAttributes(Prompt::class);
            foreach ($promptAttributes as $attribute) {
                $prompt = $attribute->newInstance();
                $prompts[] = $this->createPromptDefinition($prompt, $method);
                $promptMethodMap[$prompt->getName()] = $method->getName();
            }
        }

        return [$prompts, $promptMethodMap];
    }

    /**
     * 创建提示模板定义
     *
     * @param Prompt $prompt 提示模板注解
     * @param ReflectionMethod $method 提示模板方法
     * @return McpPrompt 提示模板定义
     */
    private function createPromptDefinition(Prompt $prompt, ReflectionMethod $method): McpPrompt
    {
        $arguments = $prompt->getArguments();
        if (empty($arguments)) {
            $arguments = $this->extractParametersFromMethod($method);
        }

        $promptArguments = [];
        foreach ($arguments as $name => $config) {
            $promptArguments[] = new PromptArgument(
                name: $name,
                description: $config['description'] ?? '',
                required: $config['required'] ?? true
            );
        }

        return new McpPrompt(
            name: $prompt->getName(),
            description: $prompt->getDescription(),
            arguments: $promptArguments,
        );
    }
}
