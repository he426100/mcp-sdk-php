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
 * Filename: Types/NotificationParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `params` object in a Notification.
 * Similar to RequestParams, it can have `_meta?: object` and arbitrary fields.
 */
class NotificationParams implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public ?Meta $_meta = null,
    ) {}

    public static function fromArray(array $data): self {
        $meta = null;
        if (isset($data['_meta']) && is_array($data['_meta'])) {
            $meta = Meta::FromArray($data['_meta']);
        }

        $params = new self(_meta: $meta);

        // Assign other parameters dynamically
        foreach ($data as $key => $value) {
            if ($key !== '_meta') {
                $params->$key = $value;
            }
        }

        return $params;
    }

    public function validate(): void {
        if ($this->_meta !== null) {
            $this->_meta->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = [];
        
        // If $_meta is non-null, let it be serialized, and only add if not empty
        if ($this->_meta !== null) {
            $serializedMeta = $this->_meta->jsonSerialize();
            if (!($serializedMeta instanceof \stdClass && count(get_object_vars($serializedMeta)) === 0) && 
                !(is_array($serializedMeta) && empty($serializedMeta))) {
                $data['_meta'] = $serializedMeta;
            }
        }
        
        // Only merge extraFields if they are non-empty
        if (!empty($this->extraFields)) {
            $data = array_merge($data, $this->extraFields);
        }
        
        return $data;
    }
}