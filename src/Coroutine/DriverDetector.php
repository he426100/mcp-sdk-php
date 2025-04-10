<?php

declare(strict_types=1);

namespace Mcp\Coroutine;

use RuntimeException;

/**
 * Class DriverDetector
 * 
 * 协程驱动环境检测器
 */
class DriverDetector
{
    public const DRIVER_SWOOLE = 'swoole';
    public const DRIVER_SWOW = 'swow';

    /**
     * 检测当前环境支持的驱动
     *
     * @return string 返回支持的驱动类名
     * @throws RuntimeException 当没有支持的驱动时抛出异常
     */
    public static function detect(): string
    {
        if (extension_loaded('swoole')) {
            return self::DRIVER_SWOOLE;
        }

        if (extension_loaded('swow')) {
            return self::DRIVER_SWOW;
        }

        throw new RuntimeException('No supported coroutine driver found. Please install swoole or swow extension.');
    }
}
