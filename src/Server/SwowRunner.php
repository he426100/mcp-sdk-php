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

use Mcp\Server\Transport\SwowServerTransport;
use Mcp\Server\Transport\Transport;
use Mcp\Server\ServerSession;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Types\ServerCapabilities;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * 运行 MCP 服务器的主入口点
 *
 * 该类支持使用标准 PHP 流或 Swow 协程框架运行服务器
 */
class SwowRunner
{
    private LoggerInterface $logger;

    /**
     * 构造函数
     *
     * @param Server $server 要运行的服务器实例
     * @param InitializationOptions $initOptions 初始化选项
     * @param LoggerInterface|null $logger 日志记录器
     */
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * 运行服务器
     *
     * 根据配置使用标准 PHP 流或 Swow 协程框架
     */
    public function run(): void
    {
        // 除非明确启用，否则抑制警告
        if (!getenv('MCP_ENABLE_WARNINGS')) {
            error_reporting(E_ERROR | E_PARSE);
        }

        try {
            // 创建适当的传输层
            $transport = $this->createTransport();

            $session = new ServerSession(
                $transport,
                $this->initOptions,
                $this->logger
            );

            // 添加处理程序
            $session->registerHandlers($this->server->getHandlers());
            $session->registerNotificationHandlers($this->server->getNotificationHandlers());

            $session->start();

            $this->logger->info('服务器已启动, 使用 Swow');
        } catch (\Exception $e) {
            $this->logger->error('服务器错误: ' . $e->getMessage());
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

    /**
     * 创建合适的传输层
     * 
     * @return Transport
     */
    private function createTransport(): Transport
    {
        return SwowServerTransport::create();
    }

    /**
     * 创建默认的 PSR 日志记录器
     */
    private function createDefaultLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function emergency($message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                $timestamp = date('Y-m-d H:i:s');
                fprintf(
                    STDERR,
                    "[%s] %s: %s\n",
                    $timestamp,
                    strtoupper($level),
                    $this->interpolate($message, $context)
                );
            }

            private function interpolate($message, array $context = []): string
            {
                $replace = [];
                foreach ($context as $key => $val) {
                    $replace['{' . $key . '}'] = $val;
                }
                return strtr($message, $replace);
            }
        };
    }
}
