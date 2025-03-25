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
 * Filename: Server/ServerSession.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Shared\BaseSession;
use Mcp\Shared\RequestResponder;
use Mcp\Shared\Version;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\LoggingLevel;
use Mcp\Types\Implementation;
use Mcp\Types\ClientRequest;
use Mcp\Types\ClientNotification;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\InitializeResult;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\Result;
use Mcp\Server\InitializationState;
use Mcp\Server\InitializationOptions;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\NotificationParams;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swow\Channel;
use Swow\Coroutine;
use RuntimeException;
use InvalidArgumentException;

/**
 * ServerSession manages the MCP server-side session.
 * It sets up initialization and ensures that requests and notifications are
 * handled only after the client has initialized.
 *
 * Similar to Python's ServerSession, but synchronous and integrated with our PHP classes.
 */
class ServerSession extends BaseSession
{
    private InitializationState $initializationState = InitializationState::NotInitialized;
    private ?InitializeRequestParams $clientParams = null;
    private LoggerInterface $logger;
    /** @var callable[] */
    private array $methodHandlers = [];
    /** @var callable[] */
    private array $notificationMethodHandlers = [];
    private bool $isStarted = false;

    public function __construct(
        private readonly Channel $read,
        private readonly Channel $write,
        private readonly InitializationOptions $initOptions,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        // The server receives ClientRequest and ClientNotification from the client
        parent::__construct(
            receiveRequestType: ClientRequest::class,
            receiveNotificationType: ClientNotification::class
        );

        // Register handlers for incoming requests and notifications
        $this->onRequest([$this, 'handleRequest']);
        $this->onNotification([$this, 'handleNotification']);
    }

    /**
     * Starts the server session.
     */
    public function start(): void
    {
        if ($this->isInitialized) {
            throw new RuntimeException('Session already initialized');
        }

        $this->initialize();
        $this->isStarted = true;
    }

    /**
     * Check if the client supports a specific capability.
     */
    public function checkClientCapability(ClientCapabilities $capability): bool
    {
        if ($this->clientParams === null) {
            return false;
        }

        $clientCaps = $this->clientParams->capabilities;

        if ($capability->roots !== null) {
            if ($clientCaps->roots === null) {
                return false;
            }
            if ($capability->roots->listChanged && !$clientCaps->roots->listChanged) {
                return false;
            }
        }

        if ($capability->sampling !== null) {
            if ($clientCaps->sampling === null) {
                return false;
            }
        }

        if ($capability->experimental !== null) {
            if ($clientCaps->experimental === null) {
                return false;
            }

            $expProps = get_object_vars($capability->experimental);
            foreach ($expProps as $key => $value) {
                if (
                    !property_exists($clientCaps->experimental, $key) ||
                    $clientCaps->experimental->$key !== $value
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    public function registerHandlers(array $handlers): void
    {
        foreach ($handlers as $method => $callable) {
            $this->methodHandlers[$method] = $callable;
        }
    }

    public function registerNotificationHandlers(array $handlers): void
    {
        foreach ($handlers as $method => $callable) {
            $this->notificationMethodHandlers[$method] = $callable;
        }
    }

    /**
     * Handle incoming requests. If it's the initialize request, handle it specially.
     * Otherwise, ensure initialization is complete before handling other requests.
     *
     * @param RequestResponder $responder
     */
    public function handleRequest(RequestResponder $responder): void
    {
        /** @var ClientRequest */
        $request = $responder->getRequest(); // a ClientRequest
        $actualRequest = $request->getRequest(); // the underlying typed Request
        $method = $actualRequest->method;
        $params = $actualRequest->params;

        if ($method === 'initialize') {
            $respond = fn($result) => $responder->sendResponse($result);
            $this->handleInitialize($request, $respond);
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new \RuntimeException('Received request before initialization was complete');
        }

        // Now we integrate the method-specific handlers:
        if (isset($this->methodHandlers[$method])) {
            $this->logger->info("Calling handler for method: $method");
            $handler = $this->methodHandlers[$method];
            try {
                $result = $handler($params); // call the user-defined handler
                $responder->sendResponse($result);
            } catch (\Throwable $e) {
                $this->logger->info("Handler Error: $e");
            }
        } else {
            $this->logger->info("No registered handler for method: $method");
            // Possibly send an error response or ignore
        }
    }

    /**
     * Handle incoming notifications. If it's the "initialized" notification, mark state as Initialized.
     *
     * @param ClientNotification $notification The incoming client notification.
     */
    public function handleNotification(ClientNotification $notification): void
    {
        // 1) Extract the actual typed Notification (e.g., InitializedNotification)
        $actualNotification = $notification->getNotification();

        // 2) Retrieve the method from the typed notification
        $method = $actualNotification->method;

        if ($method === 'notifications/initialized') {
            $this->initializationState = InitializationState::Initialized;
            $this->logger->info('Client has completed initialization.');
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new RuntimeException('Received notification before initialization was complete');
        }

        // Fallback for notifications you haven't specialized:
        $this->logger->info('Received notification: ' . $method);
    }

    /**
     * Handle the initialize request from the client.
     *
     * @param ClientRequest $request The initialize request.
     * @param callable $respond The responder callable.
     */
    private function handleInitialize(ClientRequest $request, callable $respond): void
    {
        $this->initializationState = InitializationState::Initializing;
        /** @var InitializeRequestParams $params */
        $params = $request->getRequest()->params;
        $this->clientParams = $params;

        $result = new InitializeResult(
            protocolVersion: Version::LATEST_PROTOCOL_VERSION,
            capabilities: $this->initOptions->capabilities,
            serverInfo: new Implementation(
                name: $this->initOptions->serverName,
                version: $this->initOptions->serverVersion
            )
        );

        $respond($result);

        $this->initializationState = InitializationState::Initialized;
        $this->logger->info('Initialization complete.');
    }

    /**
     * Sends a log message as a notification to the client.
     *
     * @param LoggingLevel $level The logging level.
     * @param mixed $data The data to log.
     * @param string|null $logger The logger name.
     */
    public function sendLogMessage(
        LoggingLevel $level,
        mixed $data,
        ?string $logger = null
    ): void {
        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/message',
            params: NotificationParams::fromArray([
                'level' => $level->value,
                'data' => $data,
                'logger' => $logger
            ])
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Sends a resource updated notification to the client.
     *
     * @param string $uri The URI of the updated resource.
     */
    public function sendResourceUpdated(string $uri): void
    {
        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/resources/updated',
            params: NotificationParams::fromArray(['uri' => $uri])
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Sends a progress notification for a request currently in progress.
     *
     * @param string|int $progressToken The progress token.
     * @param float $progress The current progress.
     * @param float|null $total The total progress value.
     */
    public function writeProgressNotification(
        string|int $progressToken,
        float $progress,
        ?float $total = null
    ): void {
        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: NotificationParams::fromArray([
                'progressToken' => $progressToken,
                'progress' => $progress,
                'total' => $total
            ])
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Sends a resource list changed notification to the client.
     */
    public function sendResourceListChanged(): void
    {
        $this->writeNotification('notifications/resources/list_changed');
    }

    /**
     * Sends a tool list changed notification to the client.
     */
    public function sendToolListChanged(): void
    {
        $this->writeNotification('notifications/tools/list_changed');
    }

    /**
     * Sends a prompt list changed notification to the client.
     */
    public function sendPromptListChanged(): void
    {
        $this->writeNotification('notifications/prompts/list_changed');
    }

    /**
     * Writes a generic notification to the client.
     *
     * @param string $method The method name of the notification.
     * @param array|null $params The parameters of the notification.
     */
    private function writeNotification(string $method, ?array $params = null): void
    {
        $notificationParams = $params !== null ? NotificationParams::fromArray($params) : null;

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $method,
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Implementing abstract methods from BaseSession
     */

    protected function startMessageProcessing(): void
    {
        // Start reading messages from the transport
        // This could be a loop or a separate thread in a real implementation
        // For demonstration, we'll use a simple loop
    }

    protected function stopMessageProcessing(): void {}

    protected function writeMessage(JsonRpcMessage $message): void
    {
        $this->logger->debug('writeMessage: ' . json_encode($message));
        $this->write->push($message);
    }

    protected function waitForResponse(int $requestIdValue, string $resultType, ?\Mcp\Types\McpModel &$futureResult): \Mcp\Types\McpModel
    {
        // The server typically does not wait for responses from the client.
        throw new RuntimeException('Server does not support waiting for responses from the client.');
    }

    protected function readNextMessage(): JsonRpcMessage
    {
        $message = $this->read->pop();
        $this->logger->debug('readNextMessage: ' . json_encode($message));
        return $message;
    }

    /**
     * Check if the session is started.
     */
    public function isStarted(): bool
    {
        return $this->isStarted;
    }

    /**
     * Process the next message in the session.
     */
    public function processNextMessage(): void
    {
        if (!$this->isStarted) {
            throw new RuntimeException('Session not started');
        }
        
        try {
            $message = $this->readNextMessage();
            $this->handleIncomingMessage($message);
        } catch (\Exception $e) {
            // 处理异常
            throw new RuntimeException('Error processing message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Stops the server session.
     */
    public function stop(): void
    {
        if (!$this->isStarted) {
            return;
        }

        $this->close();
        $this->isStarted = false;
    }
}
