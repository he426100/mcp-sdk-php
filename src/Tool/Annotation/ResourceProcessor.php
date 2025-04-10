<?php

namespace Mcp\Tool\Annotation;

use Mcp\Annotation\Resource;
use Mcp\Types\Resource as McpResource;
use ReflectionClass;
use ReflectionMethod;

/**
 * 资源注解处理器
 */
class ResourceProcessor extends AnnotationProcessor
{
    /**
     * 处理资源注解
     *
     * @param ReflectionClass $reflectionClass
     * @return array [资源定义数组, 资源URI到方法名的映射]
     */
    public function process(ReflectionClass $reflectionClass): array
    {
        $resources = [];
        $resourceUriMap = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $resourceAttributes = $method->getAttributes(Resource::class);
            foreach ($resourceAttributes as $attribute) {
                $resource = $attribute->newInstance();
                $resources[] = $this->createResourceDefinition($resource, $method);
                $resourceUriMap[$resource->getUri()] = $method->getName();
            }
        }

        return [$resources, $resourceUriMap];
    }

    /**
     * 创建资源定义
     *
     * @param Resource $resource 资源注解
     * @param ReflectionMethod $method 资源方法
     * @return McpResource 资源定义
     */
    private function createResourceDefinition(Resource $resource, ReflectionMethod $method): McpResource
    {
        return new McpResource(
            uri: $resource->getUri(),
            name: $resource->getName(),
            description: $resource->getDescription(),
            mimeType: $resource->getMimeType(),
        );
    }
}
