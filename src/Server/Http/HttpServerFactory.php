<?php

declare(strict_types=1);

namespace Mcp\Server\Http;

use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * HTTP服务器工厂类
 * 
 * 用于创建不同引擎的HTTP服务器实例
 */
class HttpServerFactory
{
    /**
     * 创建HTTP服务器实例
     * 
     * @param string $engine HTTP服务器引擎类型 ('auto', 'swow', 'swoole')
     * @param LoggerInterface|null $logger 日志记录器
     * @return HttpServerInterface
     * @throws InvalidArgumentException 当指定的引擎类型不受支持时
     */
    public static function create(string $engine = 'auto', ?LoggerInterface $logger = null): HttpServerInterface
    {
        $engine = strtolower($engine);

        if ($engine === 'auto') {
            $engine = self::detectEngine();
        }

        return match ($engine) {
            'swow' => new SwowHttpServer($logger),
            'swoole' => new SwooleHttpServer($logger),
            default => throw new InvalidArgumentException("Unsupported HTTP server engine: {$engine}"),
        };
    }

    /**
     * 探测可用的服务器引擎
     * 
     * @return string 返回探测到的引擎类型
     * @throws InvalidArgumentException 当没有可用的引擎时
     */
    private static function detectEngine(): string
    {
        if (extension_loaded('swow')) {
            return 'swow';
        }

        if (extension_loaded('swoole')) {
            return 'swoole';
        }

        throw new InvalidArgumentException('No supported HTTP server engine detected. Please install swow or swoole extension.');
    }
}
