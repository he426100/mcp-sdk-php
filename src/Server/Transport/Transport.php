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
 * Filename: Server/Transport/Transport.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

/**
 * Base interface for MCP transport implementations
 */
interface Transport
{
    /**
     * Start the transport
     *
     * @throws \RuntimeException if transport cannot be started
     */
    public function start(): void;

    /**
     * Stop the transport and cleanup resources
     */
    public function stop(): void;

    /**
     * is transport started
     * @return bool 
     */
    public function isStarted(): bool;

    /**
     * 
     * @return array 
     */
    public function getStreams(): array;   
}
