<?php

namespace Mcp\Tool\Annotation;

use ReflectionClass;
use ReflectionMethod;

/**
 * 注解处理基础抽象类
 */
abstract class AnnotationProcessor
{
    /**
     * 缓存已处理过的反射方法
     * @var array<string, ReflectionMethod>
     */
    protected static array $reflectionCache = [];

    /**
     * 处理类的注解
     * 
     * @param ReflectionClass $reflectionClass 反射类
     * @return array 处理结果
     */
    abstract public function process(ReflectionClass $reflectionClass): array;

    /**
     * 获取缓存的反射方法
     *
     * @param ReflectionClass $class 反射类
     * @param string $methodName 方法名
     * @return ReflectionMethod 反射方法
     */
    protected function getCachedMethod(ReflectionClass $class, string $methodName): ReflectionMethod
    {
        $className = $class->getName();
        $cacheKey = "$className::$methodName";

        if (!isset(self::$reflectionCache[$cacheKey])) {
            self::$reflectionCache[$cacheKey] = $class->getMethod($methodName);
        }

        return self::$reflectionCache[$cacheKey];
    }

    /**
     * 从方法中提取参数信息
     *
     * @param ReflectionMethod $method 反射方法
     * @return array 参数信息数组
     */
    protected function extractParametersFromMethod(ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $parameters[$param->getName()] = [
                'type' => $type ? $this->getTypeString($type) : 'string',
                'description' => '', // 默认空描述
                'required' => !$param->isOptional()
            ];
        }
        return $parameters;
    }

    /**
     * 将PHP类型转换为JSON Schema类型
     *
     * @param \ReflectionType $type PHP类型
     * @return string JSON Schema类型
     */
    protected function getTypeString(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'int', 'float' => 'number',
                'bool' => 'boolean',
                'array' => 'object',
                default => 'string'
            };
        }
        return 'string';
    }
}
