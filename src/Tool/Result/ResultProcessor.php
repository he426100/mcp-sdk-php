<?php

namespace Mcp\Tool\Result;

use Mcp\Types\Content;
use Mcp\Types\TextContent;
use Mcp\Types\ImageContent;
use Mcp\Types\EmbeddedResource;
use Mcp\Types\PromptMessage;
use Mcp\Types\Role;
use Mcp\Types\ResourceContents;
use Mcp\Types\TextResourceContents;
use Mcp\Types\BlobResourceContents;

/**
 * 结果处理器类
 */
class ResultProcessor
{
    /**
     * 处理工具返回值，转换为适当的内容类型
     *
     * @param mixed $result 工具方法的返回值
     * @return array<TextContent|ImageContent|EmbeddedResource> 内容数组
     */
    public function processToolResult(mixed $result): array
    {
        // 如果已经是内容类型数组，直接返回
        if (is_array($result) && !empty($result) && $this->isContentArray($result)) {
            return $result;
        }

        // 如果是单个内容对象，包装成数组
        if ($this->isContentObject($result)) {
            return [$result];
        }

        // 如果是资源内容，转换为 EmbeddedResource
        if ($result instanceof ResourceContents) {
            return [new EmbeddedResource($result)];
        }

        // 如果是字符串或其他标量类型，转换为TextContent
        if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
            return [new TextContent(text: (string)$result)];
        }

        // 如果是null，返回空字符串
        if ($result === null) {
            return [new TextContent(text: '')];
        }

        // 如果是数组或对象，转换为JSON字符串
        if (is_array($result) || is_object($result)) {
            return [new TextContent(text: json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ))];
        }

        // 其他情况，转换为字符串
        return [new TextContent(text: var_export($result, true))];
    }

    /**
     * 处理提示模板返回值，转换为适当的消息格式
     *
     * @param mixed $result 提示模板方法的返回值
     * @return array<PromptMessage> 消息数组
     */
    public function processPromptResult(mixed $result): array
    {
        // 如果已经是PromptMessage数组，直接返回
        if (is_array($result) && !empty($result) && $this->isPromptMessageArray($result)) {
            return $result;
        }

        // 如果是单个PromptMessage对象，包装成数组
        if ($result instanceof PromptMessage) {
            return [$result];
        }

        // 如果是Content对象，创建用户消息
        if ($this->isContentObject($result)) {
            return [new PromptMessage(
                role: Role::USER,
                content: $result
            )];
        }

        // 如果是字符串或其他标量类型，转换为TextContent的用户消息
        if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
            return [new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: (string)$result)
            )];
        }

        // 如果是null，返回空消息
        if ($result === null) {
            return [new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: '')
            )];
        }

        // 如果是数组或对象，转换为JSON字符串
        if (is_array($result) || is_object($result)) {
            return [new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: json_encode(
                    $result,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ))
            )];
        }

        // 其他情况，转换为字符串
        return [new PromptMessage(
            role: Role::USER,
            content: new TextContent(text: var_export($result, true))
        )];
    }

    /**
     * 处理资源返回值，转换为适当的资源内容格式
     *
     * @param mixed $result 资源方法的返回值
     * @param string $mimeType MIME类型
     * @param string $uri 资源URI
     * @return array<TextResourceContents|BlobResourceContents> 资源内容数组
     */
    public function processResourceResult(mixed $result, string $mimeType, string $uri): array
    {
        // 如果已经是ResourceContents数组，直接返回
        if (is_array($result) && !empty($result) && $this->isResourceContentsArray($result)) {
            return $result;
        }

        // 如果是单个ResourceContents对象，包装成数组
        if ($this->isResourceContentsObject($result)) {
            return [$result];
        }

        // 处理字符串或可转换为字符串的对象
        if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
            $content = (string)$result;
            return [$this->processResourceContent($content, $mimeType, $uri)];
        }

        // 处理null值
        if ($result === null) {
            return [new TextResourceContents(
                uri: $uri,
                text: '',
                mimeType: $mimeType
            )];
        }

        // 处理数组或对象
        if (is_array($result) || is_object($result)) {
            $content = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return [$this->processResourceContent($content, 'application/json', $uri)];
        }

        // 默认情况
        $content = var_export($result, true);
        return [$this->processResourceContent($content, 'text/plain', $uri)];
    }

    /**
     * 检查是否为内容对象
     *
     * @param mixed $object 要检查的对象
     * @return bool
     */
    private function isContentObject(mixed $object): bool
    {
        return $object instanceof TextContent ||
            $object instanceof ImageContent ||
            $object instanceof EmbeddedResource;
    }

    /**
     * 检查是否为内容对象数组
     *
     * @param array $array 要检查的数组
     * @return bool
     */
    private function isContentArray(array $array): bool
    {
        foreach ($array as $item) {
            if (!$this->isContentObject($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 检查是否为PromptMessage对象数组
     *
     * @param array $array 要检查的数组
     * @return bool
     */
    private function isPromptMessageArray(array $array): bool
    {
        foreach ($array as $item) {
            if (!($item instanceof PromptMessage)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 检查是否为资源内容对象
     *
     * @param mixed $object 要检查的对象
     * @return bool
     */
    private function isResourceContentsObject(mixed $object): bool
    {
        return $object instanceof TextResourceContents ||
            $object instanceof BlobResourceContents;
    }

    /**
     * 检查是否为ResourceContents对象数组
     *
     * @param array $array 要检查的数组
     * @return bool
     */
    private function isResourceContentsArray(array $array): bool
    {
        foreach ($array as $item) {
            if (!$this->isResourceContentsObject($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 处理资源内容，支持大文件处理
     *
     * @param string $content 资源内容
     * @param string $mimeType MIME类型
     * @param string $uri 资源URI
     * @return TextResourceContents|BlobResourceContents 资源内容对象
     */
    protected function processResourceContent(string $content, string $mimeType, string $uri): TextResourceContents|BlobResourceContents
    {
        // 对于大文件，使用分块处理
        $maxSize = 2 * 1024 * 1024; // 2MB限制
        if (strlen($content) > $maxSize) {
            $truncatedContent = substr($content, 0, $maxSize);
            $truncatedContent .= "\n... (content truncated, full size: " . strlen($content) . " bytes)";

            // 根据MIME类型选择合适的资源内容类型
            if (str_starts_with($mimeType, 'text/')) {
                return new TextResourceContents(
                    uri: $uri,
                    text: $truncatedContent,
                    mimeType: $mimeType
                );
            } else {
                return new BlobResourceContents(
                    uri: $uri,
                    blob: $truncatedContent,
                    mimeType: $mimeType
                );
            }
        }

        // 根据MIME类型选择合适的资源内容类型
        if (str_starts_with($mimeType, 'text/')) {
            return new TextResourceContents(
                uri: $uri,
                text: $content,
                mimeType: $mimeType
            );
        } else {
            return new BlobResourceContents(
                uri: $uri,
                blob: $content,
                mimeType: $mimeType
            );
        }
    }
}
