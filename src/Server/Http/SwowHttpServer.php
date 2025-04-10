<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Psr\Log\LoggerInterface;
use Swow\Psr7\Message\ServerRequest as HttpRequest;
use Swow\Psr7\Server\EventDriver;
use Swow\Psr7\Server\Server as Psr7Server;
use Swow\Psr7\Server\ServerConnection;
use Swow\Psr7\Message\ServerRequestPlusInterface;
use RuntimeException;

/**
 * Swow引擎的HTTP服务器实现
 */
class SwowHttpServer extends AbstractHttpServer
{
    /**
     * {@inheritdoc}
     */
    public function start(string $host, int $port): void
    {
        if ($this->running) {
            throw new RuntimeException('HTTP server is already running');
        }

        if ($this->sseHandler === null || $this->messagesHandler === null) {
            throw new RuntimeException('SSE and Messages handlers must be set before starting the server');
        }

        $httpServer = new EventDriver(new Psr7Server());

        $httpServer = $httpServer->withRequestHandler(function (ServerConnection $connection, ServerRequestPlusInterface $request): mixed {
            $uri = $request->getUri()->getPath();

            try {
                if ($uri == '/sse') {
                    $sseHandler = $this->sseHandler;
                    $emitter = new SwowResponseEmitter($connection);
                    $sseHandler($emitter, $request);
                    return null;
                } elseif ($uri == '/messages') {
                    $messagesHandler = $this->messagesHandler;
                    $sessionId = (string)($request->getQueryParams()['session_id'] ?? '');
                    $content = (string)$request->getBody();

                    $messagesHandler($sessionId, $content);

                    return [
                        ['Content-Type' => 'application/json'],
                        json_encode(['success' => true])
                    ];
                } else {
                    // 处理404
                    return [
                        ['Content-Type' => 'application/json', 'Status' => '404'],
                        json_encode(['error' => 'Not found'])
                    ];
                }
            } catch (\Exception $e) {
                // 处理错误
                $this->logger->error('HTTP request error: ' . $e->getMessage());
                return [
                    ['Content-Type' => 'application/json', 'Status' => '500'],
                    json_encode(['error' => $e->getMessage()])
                ];
            }
        })->withExceptionHandler(function (ServerConnection $connection, \Exception $e): void {
            $this->logger->error('HTTP server error: ' . $e->getMessage());
            $connection->respond(
                ['Content-Type' => 'application/json', 'Status' => '500'],
                json_encode(['error' => 'Server error'])
            );
        });

        try {
            $this->running = true;
            $this->logger->info("HTTP server started on http://{$host}:{$port}");
            $httpServer->startOn($host, $port);
        } catch (\Exception $e) {
            $this->logger->error('Failed to start HTTP server: ' . $e->getMessage());
            $this->running = false;
        }
    }
}
