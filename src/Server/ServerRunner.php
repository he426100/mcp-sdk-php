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
use Mcp\Server\ServerSession;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Types\ServerCapabilities;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Swow\Coroutine;
use Swow\Sync\WaitReference;
use RuntimeException;

/**
 * Main entry point for running an MCP server synchronously using STDIO transport.
 *
 * This class emulates the behavior seen in the Python code, which uses async streams.
 * Here, we run a loop reading messages from STDIO, passing them to the Server for handling.
 */
class ServerRunner
{
    public function __construct(private ?LoggerInterface $logger = null) {}

    /**
     * Run the server using STDIO transport.
     *
     * This sets up a ServerSession with a StdioServerTransport and enters a loop to read messages.
     */
    public function run(Server $server, InitializationOptions $initOptions,): void
    {
        // Suppress warnings unless explicitly enabled (similar to Python code ignoring warnings)
        if (!getenv('MCP_ENABLE_WARNINGS')) {
            error_reporting(E_ERROR | E_PARSE);
        }

        $wr = new WaitReference();
        try {
            $transport = StdioServerTransport::create();
            $transport->start();

            Coroutine::run(function () use ($wr, $transport): void {
                $transport->run();
            });

            Coroutine::run(function () use ($wr, $transport, $server, $initOptions): void {
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
            });
            
            $this->logger->info('Server started');
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
        WaitReference::wait($wr);
    }
}
