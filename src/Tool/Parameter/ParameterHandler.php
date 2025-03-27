<?php

namespace Mcp\Tool\Parameter;

use ReflectionMethod;
use ReflectionParameter;

/**
 * 参数处理器类
 */
class ParameterHandler
{
    /**
     * 准备方法参数并进行类型转换
     *
     * @param ReflectionMethod $method 反射方法
     * @param array $arguments 原始参数值
     * @return array 处理后的参数数组
     * @throws \InvalidArgumentException 参数无效时
     */
    public function prepareArguments(ReflectionMethod $method, array $arguments): array
    {
        // 验证输入参数
        foreach ($arguments as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException("Invalid parameter name: must be string");
            }
        }

        $preparedArgs = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (isset($arguments[$name])) {
                $preparedArgs[] = $this->convertValue($arguments[$name], $param);
            } elseif ($param->isOptional()) {
                $preparedArgs[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException("Missing required parameter: {$name}");
            }
        }
        return $preparedArgs;
    }

    /**
     * 根据参数类型对值进行转换
     *
     * @param mixed $value 原始值
     * @param ReflectionParameter $param 参数反射对象
     * @return mixed 转换后的值
     * @throws \InvalidArgumentException 类型转换失败时
     */
    private function convertValue($value, ReflectionParameter $param)
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        try {
            return match ($type->getName()) {
                'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                    ?? throw new \InvalidArgumentException("Invalid integer value"),
                'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE)
                    ?? throw new \InvalidArgumentException("Invalid float value"),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                'string' => (string)$value,
                'array' => is_array($value) ? $value : (array)$value,
                default => $value
            };
        } catch (\TypeError $e) {
            throw new \InvalidArgumentException(
                "Cannot convert value to {$type->getName()}: {$e->getMessage()}"
            );
        }
    }
}
