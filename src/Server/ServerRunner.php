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
use Mcp\Server\Transport\Transport;
use Mcp\Server\ServerSession;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Shared\BaseSession;
use Mcp\Types\ServerCapabilities;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Mcp\Coroutine\Coroutine;
use Mcp\Coroutine\Coroutine\CoroutineInterface;
use RuntimeException;
use Mcp\Coroutine\Channel;
use Mcp\Coroutine\Barrier;
use Psr\Log\NullLogger;
use Mcp\Server\Http\HttpServerFactory;
use Mcp\Server\Http\ResponseEmitterInterface;

/**
 * Main entry point for running an MCP server synchronously using STDIO transport.
 *
 * This class emulates the behavior seen in the Python code, which uses async streams.
 * Here, we run a loop reading messages from STDIO, passing them to the Server for handling.
 */
class ServerRunner
{
    private ?object $waitRef = null;
    private Channel $controlSignal;
    /** @var array<CoroutineInterface> */
    private array $coroutines = [];

    private const MAX_SELECT_TIMOUT_US = 800000;
    private ?Transport $transportInstance = null;
    private ?ServerSession $sessionInstance = null;

    public function __construct(
        private ?LoggerInterface $logger = null,
        private string $transport = 'stdio',
        private string $host = '0.0.0.0',
        private int $port = 8000,
    ) {
        $this->controlSignal = new Channel(); // 控制信号通道
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 运行服务器
     */
    public function run(Server $server, InitializationOptions $initOptions): void
    {
        // Suppress warnings unless explicitly enabled (similar to Python code ignoring warnings)
        if (!getenv('MCP_ENABLE_WARNINGS')) {
            error_reporting(E_ERROR | E_PARSE);
        }

        // 创建WaitReference
        $this->waitRef = Barrier::create();

        try {
            Coroutine::init();
            
            // 选择运行模式
            if ($this->transport == 'sse') {
                $this->runSseServer($server, $initOptions);
            } else {
                $this->runStdioServer($server, $initOptions);
            }

            // 监听控制信号
            Coroutine::create(function (): void {
                $signal = $this->controlSignal->pop();
                if ($signal === 'shutdown') {
                    $this->logger->info('Received shutdown signal, stopping server...');
                    // 关闭组件
                    $this->shutdownServerInstances();
                    $this->killAllCoroutines();
                }
            });

            // 等待所有协程完成
            Barrier::wait($this->waitRef);
            $this->logger->info('Server stopped');
        } catch (\Throwable $e) {
            $this->logger->error('Server error: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->shutdownServerInstances();
        }
    }

    /**
     * 运行STDIO服务器
     */
    private function runStdioServer(Server $server, InitializationOptions $initOptions): void
    {
        try {
            $transport = StdioServerTransport::create();
            $this->transportInstance = $transport;

            // 启动transport，但不要在transport内部创建协程
            $transport->start();

            list($read, $write) = $transport->getStreams();
            $session = new ServerSession(
                $read,
                $write,
                $initOptions,
                $this->logger
            );
            $this->sessionInstance = $session;

            // 添加处理器
            $session->registerHandlers($server->getHandlers());
            $session->registerNotificationHandlers($server->getNotificationHandlers());

            // 启动session
            $session->start();

            // 由ServerRunner管理读取消息的协程
            $this->spawnCoroutine(function () use ($transport, $read): void {
                while (true) {
                    try {
                        // 读取消息
                        $message = $transport->readMessage();
                        if ($message !== null) {
                            $read->push($message);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('Error reading message: ' . $e->getMessage());
                    }

                    // 检查是否应该退出
                    if (!$transport->isStarted()) {
                        break;
                    }

                    // 短暂休眠避免CPU占用过高
                    usleep(self::MAX_SELECT_TIMOUT_US);
                }
            });

            // 由ServerRunner管理写入消息的协程
            $this->spawnCoroutine(function () use ($transport, $write): void {
                while (true) {
                    try {
                        // 获取并写入消息
                        $message = $write->pop();
                        if ($message !== null) {
                            $transport->writeMessage($message);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('Error writing message: ' . $e->getMessage());
                    }

                    // 检查是否应该退出
                    if (!$transport->isStarted()) {
                        break;
                    }
                }
            });

            // 由ServerRunner管理处理消息的协程
            $this->spawnCoroutine(function () use ($session): void {
                while (true) {
                    try {
                        $session->processNextMessage();
                    } catch (\Exception $e) {
                        $this->logger->error('Error processing message: ' . $e->getMessage());
                    }

                    // 检查是否应该退出
                    if (!$session->isStarted()) {
                        break;
                    }

                    // 短暂休眠避免CPU占用过高
                    usleep(self::MAX_SELECT_TIMOUT_US);
                }
            });

            $this->logger->info('Server started');
        } catch (\Throwable $e) {
            $this->logger->error('Server initialization error: ' . $e->getMessage());
            $this->controlSignal->push('shutdown');
            throw $e;
        }
    }

    /**
     * 运行SSE服务器
     */
    private function runSseServer(Server $server, InitializationOptions $initOptions): void
    {
        try {
            // 创建transport
            $transport = new SseServerTransport('/messages', $this->logger);
            $this->transportInstance = $transport;

            // 启动transport
            $transport->start();

            list($read, $write) = $transport->getStreams();

            // 创建session
            $session = new ServerSession(
                $read,
                $write,
                $initOptions,
                $this->logger
            );
            $this->sessionInstance = $session;

            // 添加处理器
            $session->registerHandlers($server->getHandlers());
            $session->registerNotificationHandlers($server->getNotificationHandlers());

            // 启动session
            $session->start();

            // 协程：定期清理过期的SSE会话
            $this->spawnCoroutine(function () use ($transport): void {
                while ($transport->isStarted()) {
                    try {
                        $transport->cleanupSessions();
                        sleep(60); // 每分钟清理一次
                    } catch (\Exception $e) {
                        $this->logger->error('Error cleaning up sessions: ' . $e->getMessage());
                    }
                }
            });

            // 协程：处理写入消息
            $this->spawnCoroutine(function () use ($transport, $write): void {
                while ($transport->isStarted()) {
                    try {
                        $message = $write->pop();
                        if ($message !== null) {
                            $transport->writeMessage($message);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('Error writing SSE message: ' . $e->getMessage());
                    }
                }
            });

            // 协程：处理消息
            $this->spawnCoroutine(function () use ($session): void {
                while ($session->isStarted()) {
                    try {
                        $session->processNextMessage();
                    } catch (\Exception $e) {
                        $this->logger->error('Error processing message: ' . $e->getMessage());
                    }

                    // 短暂休眠避免CPU占用过高
                    usleep(self::MAX_SELECT_TIMOUT_US);
                }
            });

            // 启动HTTP服务器
            $this->spawnCoroutine(function () use ($transport): void {
                $httpServer = HttpServerFactory::create('auto', $this->logger);

                $httpServer->withSseHandler(function (ResponseEmitterInterface $emitter) use ($transport) {
                    $transport->handleSseRequest($emitter);
                });

                $httpServer->withMessagesHandler(function (string $sessionId, string $content) use ($transport) {
                    $transport->handlePostRequest($sessionId, $content);
                    return json_encode(['success' => true]);
                });
                $httpServer->start($this->host, $this->port);
                $this->logger->info("SSE server started on http://{$this->host}:{$this->port}/sse");
            });
        } catch (\Throwable $e) {
            $this->logger->error('SSE server initialization error: ' . $e->getMessage());
            $this->controlSignal->push('shutdown');
            throw $e;
        }
    }

    /**
     * 创建并管理协程
     */
    private function spawnCoroutine(callable $callback): int
    {
        $coroutine = Coroutine::create($callback, $this->waitRef);
        $this->coroutines[] = $coroutine;
        return $coroutine->id();
    }

    /**
     * 停止所有组件
     */
    private function shutdownServerInstances(): void
    {
        if ($this->transportInstance) {
            $this->transportInstance->stop();
        }

        if ($this->sessionInstance) {
            $this->sessionInstance->stop();
        }
    }

    /**
     * 终止所有运行中的协程
     */
    private function killAllCoroutines(): void
    {
        foreach ($this->coroutines as $coroutine) {
            if ($coroutine->isExecuting()) {
                $coroutine->kill();
            }
        }
    }

    /**
     * 
     * @return Transport 
     */
    public function getTransport(): Transport
    {
        return $this->transportInstance;
    }


    /**
     * 
     * @return ServerSession 
     */
    public function getSession(): ServerSession
    {
        return $this->sessionInstance;
    }

    /**
     * 关闭服务器
     */
    public function shutdown(): void
    {
        $this->logger->info('Manual shutdown requested');
        $this->controlSignal->push('shutdown');
    }
}
