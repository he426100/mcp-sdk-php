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
 * Filename: Shared/ErrorCode.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

/**
 * Error codes for MCP JSON-RPC error responses
 * 
 * Based on the standard JSON-RPC 2.0 error codes and additional MCP-specific error codes
 */
class ErrorCode
{
    /**
     * Standard JSON-RPC 2.0 error codes
     */

    /**
     * Invalid JSON was received by the server.
     * An error occurred on the server while parsing the JSON text.
     */
    public const PARSE_ERROR = -32700;

    /**
     * The JSON sent is not a valid Request object.
     */
    public const INVALID_REQUEST = -32600;

    /**
     * The method does not exist / is not available.
     */
    public const METHOD_NOT_FOUND = -32601;

    /**
     * Invalid method parameter(s).
     */
    public const INVALID_PARAMS = -32602;

    /**
     * Internal JSON-RPC error.
     */
    public const INTERNAL_ERROR = -32603;

    /**
     * MCP-specific error codes
     * Custom codes should be in the range -32000 to -32099
     */

    /**
     * Session not found or expired.
     */
    public const SESSION_NOT_FOUND = -32001;
} 