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
 * Filename: Server/Transport/SseServerTransport.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Shared\McpError;
use Mcp\Shared\ErrorData;
use Mcp\Shared\BaseSession;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestParams;
use Mcp\Types\NotificationParams;
use Mcp\Types\Result;
use Mcp\Types\Meta;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swow\Channel;
use Swow\Psr7\Server\ServerConnection;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class SseServerTransport
 *
 * Server-side SSE transport for MCP servers.
 *
 * This transport manages Server-Sent Events (SSE) connections, allowing the server
 * to push JSON-RPC messages to connected clients and handle incoming messages via POST requests.
 */
class SseServerTransport implements Transport
{
    /** @var array<string, array{created: int, lastSeen: int, output: ServerConnection}> */
    private array $sessions = [];
    /** @var BaseSession|null */
    private ?BaseSession $session = null;
    /** @var bool */
    private bool $isStarted = false;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** 模拟python的read_stream和write_stream */
    private Channel $read;
    private Channel $write;

    /**
     * SseServerTransport constructor.
     *
     * @param string                $endpoint The SSE endpoint URL.
     * @param LoggerInterface|null  $logger   PSR-3 compliant logger.
     *
     * @throws InvalidArgumentException If the endpoint is invalid.
     */
    public function __construct(
        private readonly string $endpoint,
        ?LoggerInterface $logger = null
    ) {
        if (empty($endpoint)) {
            throw new InvalidArgumentException('Endpoint cannot be empty.');
        }

        $this->logger = $logger ?? new NullLogger();

        $this->read = new Channel();
        $this->write = new Channel();
    }

    public function getStreams(): array
    {
        return [$this->read, $this->write];
    }

    /**
     * Starts the SSE transport.
     *
     * @throws RuntimeException If the transport is already started.
     * @return void
     */
    public function start(): void
    {
        if ($this->isStarted) {
            throw new RuntimeException('Transport already started');
        }

        $this->isStarted = true;
        $this->logger->debug('SSE transport started');
    }

    /**
     * Stops the SSE transport and cleans up all sessions.
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->isStarted) {
            return;
        }

        // Close all SSE connections
        foreach ($this->sessions as $sessionId => $session) {
            $session['output']->close();
            $this->logger->debug("Closed SSE connection: $sessionId");
        }

        $this->sessions = [];
        $this->isStarted = false;
        $this->logger->debug('SSE transport stopped');
    }

    /**
     * Handles the initial SSE connection from a client.
     *
     * @param ServerConnection $output An output stream resource to write SSE events to.
     *
     * @return string The generated session ID for this connection.
     *
     * @throws InvalidArgumentException If the output is not a valid resource.
     * @throws RuntimeException         If the transport is not started.
     */
    public function handleSseRequest(ServerConnection $output): string
    {
        if (!$this->isStarted) {
            throw new RuntimeException('Transport not started');
        }

        $sessionId = bin2hex(random_bytes(16));
        $currentTime = time();

        $this->sessions[$sessionId] = [
            'created' => $currentTime,
            'lastSeen' => $currentTime,
            'output' => $output,
        ];

        // Set SSE headers (assuming this method is called before headers are sent)
        $output->respond([
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'Content-Length' => null,
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable response buffering
        ]);

        // Send initial event with endpoint information
        $this->sendSseEvent($sessionId, 'endpoint', "{$this->endpoint}?session_id={$sessionId}");

        $this->logger->debug("New SSE connection established: $sessionId");

        return $sessionId;
    }

    /**
     * Handles incoming messages from the client via POST requests.
     *
     * @param string $sessionId The session ID provided by the client as a query parameter.
     * @param string $content   The JSON content from the POST body.
     *
     * @throws McpError              If parsing or validation fails.
     * @throws RuntimeException       If the transport is not started or the session is invalid.
     *
     * @return void
     */
    public function handlePostRequest(string $sessionId, string $content): void
    {
        if (!$this->isStarted) {
            throw new RuntimeException('Transport not started');
        }

        if (!isset($this->sessions[$sessionId])) {
            throw new McpError(new ErrorData(
                code: -32001,
                message: 'Session not found'
            ));
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpError(new ErrorData(
                code: -32700,
                message: 'Parse error: ' . $e->getMessage()
            ));
        }

        // Validate 'jsonrpc' field
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new McpError(new ErrorData(
                code: -32600,
                message: 'Invalid Request: jsonrpc version must be "2.0"'
            ));
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
                    throw new McpError(new ErrorData(
                        code: -32600,
                        message: 'Invalid Request: error object must contain code and message'
                    ));
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
                $message = new JsonRpcMessage($errorMsg);
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
                $message = new JsonRpcMessage($req);
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
                $message = new JsonRpcMessage($not);
            } elseif ($hasId && $hasResult && !$hasMethod) {
                // It's a JSONRPCResponse
                $resultData = $data['result'];
                $result = $this->buildResult($resultData);

                $resp = new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: $id,
                    result: $result
                );

                $resp->validate();
                $message = new JsonRpcMessage($resp);
            } else {
                // Invalid message structure
                throw new McpError(new ErrorData(
                    code: -32600,
                    message: 'Invalid Request: Could not determine message type'
                ));
            }

            // Update the session's last seen timestamp
            $this->sessions[$sessionId]['lastSeen'] = time();
            $this->logger->debug("Received message from session $sessionId");

            // Pass message to the session
            $this->read->push($message);
        } catch (McpError $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new McpError(new ErrorData(
                code: -32700,
                message: 'Parse error: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Writes a JSON-RPC message to all connected SSE clients.
     *
     * @param JsonRpcMessage $message The JSON-RPC message to send.
     *
     * @throws RuntimeException If the transport is not started.
     *
     * @return void
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

        // Send the JSON-RPC message as an SSE event to all sessions
        foreach ($this->sessions as $sessionId => $session) {
            $this->sendSseEvent($sessionId, 'message', $json);
        }

        $this->logger->debug("Broadcasted message to all SSE sessions");
    }

    /**
     * Reads a message from the transport.
     *
     * Note: SSE does not provide a direct way to read messages from the client.
     * Incoming messages are handled via `handlePostRequest`.
     *
     * @return JsonRpcMessage|null Always returns null.
     */
    public function readMessage(): ?JsonRpcMessage
    {
        // SSE is unidirectional (server to client), reading is handled via POST requests
        return null;
    }

    /**
     * Builds a Result object from an associative array.
     *
     * @param array $resultData The result data array from the JSON-RPC response.
     *
     * @return Result The constructed Result object.
     */
    private function buildResult(array $resultData): Result
    {
        $meta = null;
        if (isset($resultData['_meta']) && is_array($resultData['_meta'])) {
            $meta = Meta::FromArray($resultData['_meta']);
        }

        $result = new Result(_meta: $meta);

        // Assign other result fields dynamically
        foreach ($resultData as $key => $value) {
            if ($key !== '_meta') {
                $result->$key = $value;
            }
        }

        return $result;
    }

    /**
     * Sends an SSE event to a specific session.
     *
     * @param string $sessionId The session ID to send the event to.
     * @param string $event     The SSE event type.
     * @param string $data      The data payload of the event.
     *
     * @return void
     */
    private function sendSseEvent(string $sessionId, string $event, string $data): void
    {
        if (!isset($this->sessions[$sessionId])) {
            $this->logger->warning("Attempted to send SSE event to unknown session: $sessionId");
            return;
        }

        $output = $this->sessions[$sessionId]['output'];

        $sseData = "event: {$event}\ndata: {$data}\n\n";

        try {
            $output->send($sseData);
        } catch (\Exception $e) {
            $this->logger->error("Failed to write SSE event to session: $sessionId");
            unset($this->sessions[$sessionId]);
            return;
        }

        $this->logger->debug("Sent SSE event '{$event}' to session: $sessionId");
    }

    /**
     * Cleans up expired sessions based on the maximum allowed age.
     *
     * @param int $maxAge Maximum age in seconds before a session is considered expired.
     *
     * @return void
     */
    public function cleanupSessions(int $maxAge = 3600): void
    {
        $now = time();
        foreach ($this->sessions as $sessionId => $session) {
            if ($now - $session['lastSeen'] > $maxAge) {
                $session['output']->close();
                unset($this->sessions[$sessionId]);
                $this->logger->debug("Cleaned up expired session: $sessionId");
            }
        }
    }

    /**
     * 检查传输层是否已启动
     */
    public function isStarted(): bool
    {
        return $this->isStarted;
    }
}
