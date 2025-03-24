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
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Request as HttpRequest;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use function Swoole\Coroutine\run;
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


        if ($this->transport == 'sse') {
            try {
                run(function () use ($server, $initOptions) {
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

                    $httpServer = new HttpServer($this->host, $this->port);

                    $httpServer->handle('/sse', function (Request $request, Response $response) use ($transport) {
                        $transport->handleSseRequest($response);
                    });

                    $httpServer->handle('/messages', function (Request $request, Response $response) use ($transport) {
                        $sessionId = $request->get['session_id'] ?? '';
                        $transport->handlePostRequest($sessionId, $request->rawContent());
                    });

                    $httpServer->start();
                });
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
                $this->logger->debug('Server stopped');
            }
        } else {
            try {
                run(function () use ($server, $initOptions) {
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
                });
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
                $this->logger->debug('Server stopped');
            }
        }
    }
}
