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
 * Filename: Server/Transport/StdioServerTransport.php
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
 * Class StdioServerTransport
 *
 * STDIO-based transport implementation for MCP servers.
 * Uses Swow's EofStream to handle JSON-RPC messages over STDIO.
 */
class StdioServerTransport implements Transport
{
    /** @var EofStream 输入流 */
    private EofStream $input;
    /** @var EofStream 输出流 */
    private EofStream $output;
    /** @var array<string> */
    private array $writeBuffer = [];
    /** @var bool 是否已启动 */
    private bool $isStarted = false;

    /**
     * StdinServerTransport 构造函数
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
     * Starts the transport by setting streams to non-blocking mode if applicable.
     *
     * @throws RuntimeException If the transport is already started or if setting non-blocking mode fails.
     */
    public function start(): void
    {
        if ($this->isStarted) {
            throw new RuntimeException('Transport already started');
        }

        $this->isStarted = true;
    }

    /**
     * Stops the transport and flushes any remaining messages in the buffer.
     */
    public function stop(): void
    {
        if (!$this->isStarted) {
            return;
        }

        $this->flush();
        $this->isStarted = false;
    }

    /**
     * Checks if there is data available to read from STDIN.
     *
     * @return bool True if data is available, false otherwise.
     */
    public function hasDataAvailable(): bool
    {
        $buffer = new Buffer(1);
        $bytesRead = $this->input->peek($buffer, 0, 1, 0);
        return $bytesRead > 0;
    }

    /**
     * Reads the next JSON-RPC message from STDIN.
     *
     * @return JsonRpcMessage|null The next message if available, or null if no data is present.
     *
     * @throws RuntimeException If the transport is not started.
     * @throws McpError          If a JSON-RPC error occurs during parsing or validation.
     */
    public function readMessage(): ?JsonRpcMessage
    {
        if (!$this->isStarted) {
            throw new RuntimeException('Transport not started');
        }

        // Attempt to read a line from STDIN
        $line = $this->input->recvMessageString();

        try {
            // Decode JSON with strict error handling
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // JSON parse error
            throw new McpError(
                new TypesErrorData(
                    code: -32700,
                    message: 'Parse error: ' . $e->getMessage()
                )
            );
        }

        // Validate 'jsonrpc' field
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new McpError(
                new TypesErrorData(
                    code: -32600,
                    message: 'Invalid Request: jsonrpc version must be "2.0"'
                )
            );
        }

        // Determine message type based on presence of specific fields
        $hasMethod = array_key_exists('method', $data);
        $hasId = array_key_exists('id', $data);
        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);

        // Initialize RequestId if present
        $id = null;
        if ($hasId) {
            $id = new RequestId($data['id']);
        }

        try {
            if ($hasError) {
                // It's a JSONRPCError
                $errorData = $data['error'];
                if (!isset($errorData['code']) || !isset($errorData['message'])) {
                    throw new McpError(
                        new TypesErrorData(
                            code: -32600,
                            message: 'Invalid Request: error object must contain code and message'
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
                    id: $id ?? new RequestId(''), // 'id' must be present for error
                    error: $errorObj
                );

                $errorMsg->validate();
                return new JsonRpcMessage($errorMsg);
            } elseif ($hasMethod && $hasId && !$hasResult) {
                // It's a JSONRPCRequest
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
                // It's a JSONRPCNotification
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
                // It's a JSONRPCResponse
                $resultData = $data['result'];

                // Create a generic Result object
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
                // Invalid message structure
                throw new McpError(
                    new TypesErrorData(
                        code: -32600,
                        message: 'Invalid Request: Could not determine message type'
                    )
                );
            }
        } catch (McpError $e) {
            // Rethrow McpError as is
            throw $e;
        } catch (\Exception $e) {
            // Other exceptions become parse errors
            throw new McpError(
                new TypesErrorData(
                    code: -32700,
                    message: 'Parse error: ' . $e->getMessage()
                )
            );
        }
    }

    /**
     * Writes a JSON-RPC message to STDOUT.
     *
     * @param JsonRpcMessage $message The JSON-RPC message to send.
     *
     * @throws RuntimeException If the transport is not started or if writing fails.
     */
    public function writeMessage(JsonRpcMessage $message): void
    {
        if (!$this->isStarted) {
            throw new RuntimeException('Transport not started');
        }

        // Encode the JsonRpcMessage to JSON
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode message as JSON: ' . json_last_error_msg());
        }

        // Append newline as per JSON-RPC over STDIO specification
        $json .= "\n";

        // Buffer the message
        $this->writeBuffer[] = $json;

        // Attempt to flush immediately for non-blocking behavior
        $this->flush();
    }

    /**
     * Flushes the write buffer by sending all buffered messages to STDOUT.
     *
     * @throws RuntimeException If writing to STDOUT fails.
     */
    public function flush(): void
    {
        if (!$this->isStarted) {
            return;
        }

        while (!empty($this->writeBuffer)) {
            $data = array_shift($this->writeBuffer);

            try {
                $this->output->sendMessage($data);
            } catch (\Exception $e) {
                throw new RuntimeException('Failed to write to stdout: ' . $e->getMessage());
            }
        }
    }

    /**
     * 创建 StdinServerTransport 的新实例
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
