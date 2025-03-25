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
use Swow\Channel;
use Swow\Sync\WaitReference;
use Psr\Log\NullLogger;

/**
 * Main entry point for running an MCP server synchronously using STDIO transport.
 *
 * This class emulates the behavior seen in the Python code, which uses async streams.
 * Here, we run a loop reading messages from STDIO, passing them to the Server for handling.
 */
class ServerRunner
{
    private ?WaitReference $waitRef = null;
    private Channel $controlSignal;
    private array $coroutines = [];
    private const MAX_SELECT_TIMOUT_US = 800000;
    private ?Transport $transportInstance = null;
    private ?ServerSession $sessionInstance = null;

    public function __construct(
        private ?LoggerInterface $logger = null,
        private string $transport = 'stdin',
        private string $host = '0.0.0.0',
        private int $port = 8000,
    ) {
        $this->controlSignal = new Channel(1); // 控制信号通道
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 运行服务器
     */
    public function run(Server $server, InitializationOptions $initOptions): void
    {
        // 创建WaitReference
        $this->waitRef = new WaitReference();
        
        try {
            // 启动信号处理
            $this->setupSignalHandling();
            
            // 选择运行模式
            if ($this->transport == 'sse') {
                $this->runSseServer($server, $initOptions);
            } else {
                $this->runStdioServer($server, $initOptions);
            }
            
            // 监听控制信号
            $this->spawnCoroutine(function (): void {
                $signal = $this->controlSignal->pop();
                if ($signal === 'shutdown') {
                    $this->logger->info('Received shutdown signal, stopping server...');
                    // 关闭组件
                    $this->stopComponents();
                }
            });
            
            // 等待所有协程完成
            WaitReference::wait($this->waitRef);
            $this->logger->info('Server stopped');
            
        } catch (\Exception $e) {
            $this->logger->error('Server error: ' . $e->getMessage());
            $this->stopComponents();
            throw $e;
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
                    sleep(self::MAX_SELECT_TIMOUT_US);
                }
            });
            
            // 由ServerRunner管理写入消息的协程
            $this->spawnCoroutine(function () use ($transport, $write): void {
                while (true) {
                    try {
                        // 获取并写入消息
                        $message = $write->pop(3); // 3秒超时
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
                    sleep(self::MAX_SELECT_TIMOUT_US);
                }
            });
            
            $this->logger->info('Server started');
            
        } catch (\Exception $e) {
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
            
            list($readChannel, $writeChannel) = $transport->getStreams();
            
            // 创建session
            $session = new ServerSession(
                $readChannel,
                $writeChannel,
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
            $this->spawnCoroutine(function () use ($transport, $writeChannel): void {
                while ($transport->isStarted()) {
                    try {
                        $message = $writeChannel->pop(3); // 3秒超时
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
                    sleep(self::MAX_SELECT_TIMOUT_US);
                }
            });
            
            // 设置HTTP服务器
            $httpServer = new EventDriver(new Psr7Server());
            $httpServer->withRequestHandler(function (ServerConnection $connection, HttpRequest $request) use ($transport): void {
                $uri = $request->getUri()->getPath();
                
                try {
                    if ($uri == '/sse') {
                        // 处理SSE连接请求
                        $transport->handleSseRequest($connection);
                    } elseif ($uri == '/messages') {
                        // 处理POST消息请求
                        $sessionId = (string)$request->getQueryParams()['session_id'];
                        $transport->handlePostRequest($sessionId, (string)$request->getBody());
                        $connection->respond([
                            'Content-Type' => 'application/json',
                        ], json_encode(['success' => true]));
                    } else {
                        // 处理404
                        $connection->respond([
                            'Content-Type' => 'application/json',
                            'Status' => 404,
                        ], json_encode(['error' => 'Not found']));
                    }
                } catch (\Exception $e) {
                    // 处理错误
                    $connection->respond([
                        'Content-Type' => 'application/json',
                        'Status' => 500,
                    ], json_encode(['error' => $e->getMessage()]));
                    $this->logger->error('HTTP request error: ' . $e->getMessage());
                }
            })->withExceptionHandler(function (ServerConnection $connection, \Exception $e) {
                $this->logger->error('HTTP server error: ' . $e->getMessage());
                $connection->respond([
                    'Content-Type' => 'application/json',
                    'Status' => 500,
                ], json_encode(['error' => 'Server error']));
            });
            
            // 启动HTTP服务器
            $this->spawnCoroutine(function () use ($httpServer): void {
                try {
                    $httpServer->startOn($this->host, $this->port);
                } catch (\Exception $e) {
                    $this->logger->error('HTTP server error: ' . $e->getMessage());
                    $this->controlSignal->push('shutdown');
                }
            });
            
            $this->logger->info("SSE server started on http://{$this->host}:{$this->port}/sse");
            
        } catch (\Exception $e) {
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
        $coroutineId = Coroutine::run($callback, $this->waitRef)->getId();
        $this->coroutines[] = $coroutineId;
        return $coroutineId;
    }
    
    /**
     * 设置信号处理
     */
    private function setupSignalHandling(): void
    {
        if (extension_loaded('pcntl')) {
            // 监听中断信号
            pcntl_signal(SIGINT, function () {
                $this->logger->info('Received SIGINT signal');
                $this->controlSignal->push('shutdown');
            });
            pcntl_signal(SIGTERM, function () {
                $this->logger->info('Received SIGTERM signal');
                $this->controlSignal->push('shutdown');
            });
            
            // 启动信号分发协程
            $this->spawnCoroutine(function (): void {
                while (true) {
                    pcntl_signal_dispatch();
                    sleep(self::MAX_SELECT_TIMOUT_US);
                    
                    // 检查组件状态，如果已关闭则退出
                    if ($this->transportInstance && !$this->transportInstance->isStarted()) {
                        break;
                    }
                }
            });
        } else {
            $this->logger->warning('pcntl extension not loaded, signal handling disabled');
        }
    }
    
    /**
     * 停止所有组件
     */
    private function stopComponents(): void
    {
        if ($this->transportInstance && $this->transportInstance->isStarted()) {
            $this->transportInstance->stop();
        }
        
        if ($this->sessionInstance && $this->sessionInstance->isStarted()) {
            $this->sessionInstance->stop();
        }
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
