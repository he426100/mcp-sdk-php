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
 * Filename: Server/ServerRunner.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Server\Transport\SseServerTransport;
use Mcp\Server\ServerSession;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Types\ServerCapabilities;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Swow\Coroutine;
use function Swow\Sync\waitAll;
use Swow\WatchDog;
use Swow\Psr7\Message\ServerRequest as HttpRequest;
use Swow\Psr7\Server\EventDriver;
use Swow\Psr7\Server\Server as Psr7Server;
use Swow\Psr7\Server\ServerConnection;
use RuntimeException;

/**
 * Main entry point for running an MCP server synchronously using STDIO transport.
 *
 * This class emulates the behavior seen in the Python code, which uses async streams.
 * Here, we run a loop reading messages from STDIO, passing them to the Server for handling.
 */
class ServerRunner
{
    public function __construct(
        private ?LoggerInterface $logger = null,
        private string $transport = 'stdin',
        private string $host = '0.0.0.0',
        private int $port = 8000,
    ) {}

    /**
     * Run the server using STDIO transport.
     *
     * This sets up a ServerSession with a StdioServerTransport and enters a loop to read messages.
     */
    public function run(Server $server, InitializationOptions $initOptions): void
    {
        // Suppress warnings unless explicitly enabled (similar to Python code ignoring warnings)
        if (!getenv('MCP_ENABLE_WARNINGS')) {
            error_reporting(E_ERROR | E_PARSE);
        }

        WatchDog::run();

        if ($this->transport == 'sse') {
            try {
                $transport = new SseServerTransport('/messages', $this->logger);
                $transport->start();

                list($read, $write) = $transport->getStreams();
                $session = new ServerSession(
                    $read,
                    $write,
                    $initOptions,
                    $this->logger
                );

                // Add handlers
                $session->registerHandlers($server->getHandlers());
                $session->registerNotificationHandlers($server->getNotificationHandlers());
                $session->start();

                $this->logger->info('Server started');

                $server = new EventDriver(new Psr7Server());
                $server->withRequestHandler(function (ServerConnection $connection, HttpRequest $request) use ($transport): void {
                    $uri = $request->getUri()->getPath();
                    if ($uri == '/sse') {
                        $transport->handleSseRequest($connection);
                    } elseif ($uri == '/messages') {
                        $sessionId = (string)$request->getQueryParams()['session_id'];
                        $transport->handlePostRequest($sessionId, (string)$request->getBody());
                    }
                })->withExceptionHandler(function (ServerConnection $connection, \Exception $e) {
                    $this->logger->error('sse server: ' . $e->getMessage());
                })->startOn($this->host, $this->port);

                // 必须在这里wait，否则就跑到下面的 finally 了
                waitAll();
            } catch (\Exception $e) {
                $this->logger->error('Server error: ' . $e->getMessage());
                throw $e;
            } finally {
                if (isset($session)) {
                    $session->stop();
                }
                if (isset($transport)) {
                    $transport->stop();
                }
            }
        } else {
            try {
                $transport = StdioServerTransport::create();
                $transport->start();

                list($read, $write) = $transport->getStreams();
                $session = new ServerSession(
                    $read,
                    $write,
                    $initOptions,
                    $this->logger
                );

                // Add handlers
                $session->registerHandlers($server->getHandlers());
                $session->registerNotificationHandlers($server->getNotificationHandlers());
                $session->start();

                $this->logger->info('Server started');

                // 必须在这里wait，否则就跑到下面的 finally 了
                waitAll();
            } catch (\Exception $e) {
                $this->logger->error('Server error: ' . $e->getMessage());
                throw $e;
            } finally {
                if (isset($session)) {
                    $session->stop();
                }
                if (isset($transport)) {
                    $transport->stop();
                }
            }
        }
    }
}
