<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php 
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/Transport/SwowServerTransport.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Shared\McpError;
use Mcp\Shared\ErrorData as TypesErrorData;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestParams;
use Mcp\Types\NotificationParams;
use Mcp\Types\Result;
use RuntimeException;
use InvalidArgumentException;
use Swow\Buffer;
use Swow\Socket;
use Swow\Stream\EofStream;

/**
 * Class SwowServerTransport
 *
 * Swow-based transport implementation for MCP servers.
 * Uses Swow's EofStream to handle JSON-RPC messages over STDIO.
 */
class SwowServerTransport implements Transport
{
    /** @var EofStream 输入流 */
    private EofStream $input;
    
    /** @var EofStream 输出流 */
    private EofStream $output;
    
    /** @var bool 是否已启动 */
    private bool $isStarted = false;

    /**
     * SwowServerTransport 构造函数
     * 
     * @param EofStream|null $input 输入流（默认为标准输入）
     * @param EofStream|null $output 输出流（默认为标准输出）
     */
    public function __construct(
        ?EofStream $input = null,
        ?EofStream $output = null
    ) {
        $this->input = $input ?? (new EofStream("\n", type: Socket::TYPE_STDIN))->setReadTimeout(-1);
        $this->output = $output ?? new EofStream("\n", Socket::TYPE_STDOUT);
    }

    /**
     * 启动传输层
     *
     * @throws RuntimeException 如果传输层已经启动
     */
    public function start(): void
    {
        if ($this->isStarted) {
            throw new RuntimeException('传输层已经启动');
        }

        $this->isStarted = true;
    }

    /**
     * 停止传输层
     */
    public function stop(): void
    {
        if (!$this->isStarted) {
            return;
        }

        $this->isStarted = false;
    }

    /**
     * 从输入流读取下一条 JSON-RPC 消息
     *
     * @return JsonRpcMessage|null 如果有可用消息则返回，否则返回 null
     * @throws RuntimeException 如果传输层未启动
     * @throws McpError 如果在解析或验证 JSON-RPC 消息时发生错误
     */
    public function readMessage(): ?JsonRpcMessage
    {
        if (!$this->isStarted) {
            throw new RuntimeException('传输层未启动');
        }

        try {
            // 使用 EofStream 的消息接收功能
            $buffer = new Buffer(0);
            $messageLength = $this->input->recvMessage($buffer);
            
            if ($messageLength <= 0) {
                return null; // 没有可用数据
            }
            
            $line = $buffer->toString();
            
            // 解码 JSON 并进行严格错误处理
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // JSON 解析错误
            throw new McpError(
                new TypesErrorData(
                    code: -32700,
                    message: '解析错误: ' . $e->getMessage()
                )
            );
        } catch (\Exception $e) {
            // Swow 接收消息的错误
            if ($e->getMessage() === 'Operation timed out') {
                return null; // 超时表示没有可用消息
            }
            throw new RuntimeException('读取消息失败: ' . $e->getMessage());
        }

        // 验证 'jsonrpc' 字段
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new McpError(
                new TypesErrorData(
                    code: -32600,
                    message: '无效请求: jsonrpc 版本必须为 "2.0"'
                )
            );
        }

        // 根据特定字段的存在来确定消息类型
        $hasMethod = array_key_exists('method', $data);
        $hasId = array_key_exists('id', $data);
        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);

        // 如果存在则初始化 RequestId
        $id = null;
        if ($hasId) {
            $id = new RequestId($data['id']);
        }

        try {
            if ($hasError) {
                // JSON-RPC 错误类型
                $errorData = $data['error'];
                if (!isset($errorData['code']) || !isset($errorData['message'])) {
                    throw new McpError(
                        new TypesErrorData(
                            code: -32600,
                            message: '无效请求: 错误对象必须包含 code 和 message'
                        )
                    );
                }

                $errorObj = new JsonRpcErrorObject(
                    code: $errorData['code'],
                    message: $errorData['message'],
                    data: $errorData['data'] ?? null
                );

                $errorMsg = new JSONRPCError(
                    jsonrpc: '2.0',
                    id: $id ?? new RequestId(''), // 错误消息必须包含 id
                    error: $errorObj
                );

                $errorMsg->validate();
                return new JsonRpcMessage($errorMsg);
            } elseif ($hasMethod && $hasId && !$hasResult) {
                // JSON-RPC 请求
                $method = $data['method'];
                $params = isset($data['params']) && is_array($data['params']) ? RequestParams::fromArray($data['params']) : null;

                $req = new JSONRPCRequest(
                    jsonrpc: '2.0',
                    id: $id,
                    params: $params,
                    method: $method
                );

                $req->validate();
                return new JsonRpcMessage($req);
            } elseif ($hasMethod && !$hasId && !$hasResult) {
                // JSON-RPC 通知
                $method = $data['method'];
                $params = isset($data['params']) && is_array($data['params']) ? NotificationParams::fromArray($data['params']) : null;

                $not = new JSONRPCNotification(
                    jsonrpc: '2.0',
                    params: $params,
                    method: $method
                );

                $not->validate();
                return new JsonRpcMessage($not);
            } elseif ($hasId && $hasResult && !$hasMethod) {
                // JSON-RPC 响应
                $resultData = $data['result'];

                // 创建通用 Result 对象
                $result = new Result();
                foreach ($resultData as $k => $v) {
                    if ($k !== '_meta') {
                        $result->$k = $v;
                    }
                }

                $resp = new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: $id,
                    result: $result
                );

                $resp->validate();
                return new JsonRpcMessage($resp);
            } else {
                // 无效的消息结构
                throw new McpError(
                    new TypesErrorData(
                        code: -32600,
                        message: '无效请求: 无法确定消息类型'
                    )
                );
            }
        } catch (McpError $e) {
            // 直接重新抛出 McpError
            throw $e;
        } catch (\Exception $e) {
            // 其他异常视为解析错误
            throw new McpError(
                new TypesErrorData(
                    code: -32700,
                    message: '解析错误: ' . $e->getMessage()
                )
            );
        }
    }

    /**
     * 将 JSON-RPC 消息写入输出流
     *
     * @param JsonRpcMessage $message 要发送的 JSON-RPC 消息
     * @throws RuntimeException 如果传输层未启动或写入失败
     */
    public function writeMessage(JsonRpcMessage $message): void
    {
        if (!$this->isStarted) {
            throw new RuntimeException('传输层未启动');
        }

        // 将 JsonRpcMessage 编码为 JSON
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('消息编码为 JSON 失败: ' . json_last_error_msg());
        }

        try {
            // 使用 EofStream 发送消息（会自动添加配置的 EOF）
            $this->output->sendMessage($json, 0, strlen($json), null);
        } catch (\Exception $e) {
            throw new RuntimeException('写入输出流失败: ' . $e->getMessage());
        }
    }

    /**
     * 刷新写入缓冲区
     *
     * 注意：EofStream 的 sendMessage 方法通常会直接发送数据，所以这个方法可能不需要额外操作
     */
    public function flush(): void
    {
        // EofStream 通常不需要显式刷新
    }

    /**
     * 创建 SwowServerTransport 的新实例
     *
     * @param EofStream|null $input 输入流
     * @param EofStream|null $output 输出流
     * @return self
     */
    public static function create(?EofStream $input = null, ?EofStream $output = null): self
    {
        return new self($input, $output);
    }
} 