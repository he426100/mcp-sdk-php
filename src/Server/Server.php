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
 * Filename: Server/Server.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Types\JsonRpcMessage;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\ServerPromptsCapability;
use Mcp\Types\ServerResourcesCapability;
use Mcp\Types\ServerToolsCapability;
use Mcp\Types\ServerLoggingCapability;
use Mcp\Types\ExperimentalCapabilities;
use Mcp\Types\LoggingLevel;
use Mcp\Types\RequestId;
use Mcp\Shared\McpError;
use Mcp\Shared\ErrorData as TypesErrorData;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\Result;
use Mcp\Types\EmptyResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Shared\ErrorData;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;

/**
 * MCP Server implementation
 *
 * This class manages request and notification handlers, integrates with ServerSession,
 * and handles incoming messages by dispatching them to the appropriate handlers.
 */
class Server
{
    /** @var array<string, callable(?array): Result> */
    private array $requestHandlers = [];
    /** @var array<string, callable(?array): void> */
    private array $notificationHandlers = [];
    private LoggerInterface $logger;
    private ToolManager $toolManager;
    private ResourceManager $resourceManager; 
    private PromptManager $promptManager;

    public function __construct(
        private readonly string $name,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->logger->debug("Initializing server '$name'");

        // 初始化管理器
        $this->toolManager = new ToolManager();
        $this->resourceManager = new ResourceManager();
        $this->promptManager = new PromptManager();

        // 注册默认 handlers
        $this->registerDefaultHandlers();
    }

    /**
     * 注册默认的请求处理器
     */
    private function registerDefaultHandlers(): void
    {
        // 注册内置的 ping handler
        $this->registerHandler('ping', function (?array $params): EmptyResult {
            return new EmptyResult();
        });

        // 工具相关 handlers
        $this->registerHandler('tools/list', function (?array $params): ListToolsResult {
            $tools = $this->toolManager->listTools();
            return new ListToolsResult($tools);
        });

        $this->registerHandler('tools/call', function (?array $params): CallToolResult {
            if (!isset($params['name'])) {
                throw new InvalidArgumentException('Tool name is required');
            }
            $name = $params['name'];
            $arguments = $params['arguments'] ?? [];
            
            $result = $this->toolManager->callTool($name, $arguments);
            return new CallToolResult($result);
        });

        // 资源相关 handlers
        $this->registerHandler('resources/list', function (?array $params): ListResourcesResult {
            $resources = $this->resourceManager->listResources();
            return new ListResourcesResult($resources);
        });

        $this->registerHandler('resources/read', function (?array $params): ReadResourceResult {
            if (!isset($params['uri'])) {
                throw new InvalidArgumentException('Resource URI is required');
            }
            $uri = $params['uri'];
            
            $content = $this->resourceManager->readResource($uri);
            return new ReadResourceResult($content);
        });

        // 提示相关 handlers
        $this->registerHandler('prompts/list', function (?array $params): ListPromptsResult {
            $prompts = $this->promptManager->listPrompts();
            return new ListPromptsResult($prompts);
        });

        $this->registerHandler('prompts/get', function (?array $params): GetPromptResult {
            if (!isset($params['name'])) {
                throw new InvalidArgumentException('Prompt name is required');
            }
            $name = $params['name'];
            $arguments = $params['arguments'] ?? [];
            
            $prompt = $this->promptManager->getPrompt($name, $arguments);
            return new GetPromptResult($prompt);
        });

        // 资源模板相关 handlers
        $this->registerHandler('resources/templates/list', function (?array $params): ListResourceTemplatesResult {
            $templates = $this->resourceManager->listTemplates();
            return new ListResourceTemplatesResult($templates);
        });
    }

    /**
     * Creates initialization options for the server.
     */
    public function createInitializationOptions(
        ?NotificationOptions $notificationOptions = null,
        ?array $experimentalCapabilities = null
    ): InitializationOptions {
        $notificationOptions ??= new NotificationOptions();
        $experimentalCapabilities ??= [];

        return new InitializationOptions(
            serverName: $this->name,
            serverVersion: $this->getPackageVersion('mcp'),
            capabilities: $this->getCapabilities($notificationOptions, $experimentalCapabilities)
        );
    }

    /**
     * Gets server capabilities based on registered handlers.
     */
    public function getCapabilities(
        NotificationOptions $notificationOptions,
        array $experimentalCapabilities
    ): ServerCapabilities {
        // Initialize capabilities as null
        $promptsCapability = null;
        $resourcesCapability = null;
        $toolsCapability = null;
        $loggingCapability = null;

        if (isset($this->requestHandlers['prompts/list'])) {
            $promptsCapability = new ServerPromptsCapability(
                listChanged: $notificationOptions->promptsChanged
            );
        }

        if (isset($this->requestHandlers['resources/list'])) {
            $resourcesCapability = new ServerResourcesCapability(
                subscribe: false, // Adjust based on your requirements
                listChanged: $notificationOptions->resourcesChanged
            );
        }

        if (isset($this->requestHandlers['tools/list'])) {
            $toolsCapability = new ServerToolsCapability(
                listChanged: $notificationOptions->toolsChanged
            );
        }

        if (isset($this->requestHandlers['logging/setLevel'])) {
            $loggingCapability = new ServerLoggingCapability(
                // Provide necessary initialization parameters
            );
        }

        return new ServerCapabilities(
            prompts: $promptsCapability,
            resources: $resourcesCapability,
            tools: $toolsCapability,
            logging: $loggingCapability,
            experimental: ExperimentalCapabilities::fromArray($experimentalCapabilities) // Assuming a constructor
        );
    }

    /**
     * Registers a request handler for a given method.
     *
     * The handler should return a `Result` object or throw `McpError`.
     */
    public function registerHandler(string $method, callable $handler): void
    {
        $this->requestHandlers[$method] = $handler;
        $this->logger->debug("Registered handler for request method: $method");
    }

    public function getHandlers(): array
    {
        return $this->requestHandlers;
    }

    /**
     * Registers a notification handler for a given method.
     *
     * The handler does not return a result, just processes the notification.
     */
    public function registerNotificationHandler(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
        $this->logger->debug("Registered notification handler for method: $method");
    }

    public function getNotificationHandlers(): array
    {
        return $this->notificationHandlers;
    }

    /**
     * Retrieves the package version.
     *
     * @param string $package The package name.
     * @return string The package version.
     */
    private function getPackageVersion(string $package): string
    {
        // Return a static version. Actual implementation can read from composer.json or elsewhere.
        return '1.0.0';
    }
}
