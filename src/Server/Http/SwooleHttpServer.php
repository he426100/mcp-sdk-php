<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Http\Server as HttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Status;
use Throwable;
use RuntimeException;

class SwooleHttpServer extends AbstractHttpServer
{
    private ?HttpServer $server = null;
    
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

        $this->server = new HttpServer($host, $port);

        $this->server->handle('/', function (Request $request, Response $response): void {
            try {
                $this->handleRequest($request, $response);
            } catch (Throwable $e) {
                $this->handleError($response, $e);
            }
        });
        
        try {
            $this->running = true;
            $this->logger?->info("Swoole HTTP server starting on {$host}:{$port}");
            $this->server->start();
        } catch (Throwable $e) {
            $this->logger?->error('Failed to start HTTP server: ' . $e->getMessage());
            $this->running = false;
        }
    }

    /**
     * 处理HTTP请求
     */
    private function handleRequest(Request $request, Response $response): void
    {
        $uri = $request->server['request_uri'];
        $response->header('Content-Type', 'application/json');

        if ($uri === '/sse') {
            $sseHandler = $this->sseHandler;
            $sseHandler(new SwooleResponseEmitter($response), $request);
        } elseif ($uri === '/messages') {
            $messagesHandler = $this->messagesHandler;
            $sessionId = (string)($request->get['session_id'] ?? '');
            $messagesHandler($sessionId, (string)$request->rawContent());
            $response->end(json_encode(['success' => true]));
        } else {
            $response->status(404, Status::getReasonPhrase(404));
            $response->end(json_encode(['error' => 'Not found']));
        }
    }

    /**
     * 处理错误
     */
    private function handleError(Response $response, Throwable $e): void
    {
        $response->status(500, Status::getReasonPhrase(500));
        $response->end(json_encode(['error' => $e->getMessage()]));
        $this->logger?->error('HTTP request error: ' . $e->getMessage());
    }
}
