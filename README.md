# Model Context Protocol SDK for PHP

This package provides a PHP implementation of the [Model Context Protocol](https://modelcontextprotocol.io), allowing applications to provide context for LLMs in a standardized way. It separates the concerns of providing context from the actual LLM interaction.

## Overview

This PHP SDK implements the full MCP specification, making it easy to:
* Build MCP clients that can connect to any MCP server
* Create MCP servers that expose resources, prompts and tools
* Use standard transports like stdio and SSE
* Handle all MCP protocol messages and lifecycle events

Based on the official [Python SDK](https://github.com/modelcontextprotocol/python-sdk) for the Model Context Protocol.

This SDK is primarily targeted at developers working on frontier AI integration solutions. Some functionality may be incomplete and implementations should undergo thorough testing and security review by experienced developers prior to production use.

## Installation

You can install the package via composer:

```bash
composer require he426100/mcp-sdk-php
```

### Requirements
* PHP 8.1 or higher
* ext-curl
* ext-pcntl (optional, recommended for CLI environments)

## Basic Usage

### Creating an MCP Server

Here's a complete example of creating an MCP server that provides prompts:

```php
<?php

// A basic example server with a list of prompts for testing

require 'vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\TextContent;
use Mcp\Types\Role;
use Mcp\Types\GetPromptResult;
use Mcp\Types\GetPromptRequestParams;

// Create a server instance
$server = new Server('example-server');

// Register prompt handlers
$server->registerHandler('prompts/list', function($params) {
    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );
    return new ListPromptsResult([$prompt]);
});

$server->registerHandler('prompts/get', function(GetPromptRequestParams $params) {

    $name = $params->name;
    $arguments = $params->arguments;

    if ($name !== 'example-prompt') {
        throw new \InvalidArgumentException("Unknown prompt: {$name}");
    }

    // Get argument value safely
    $argValue = $arguments ? $arguments->arg1 : 'none';

    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );

    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(
                    text: "Example prompt text with argument: $argValue"
                )
            )
        ],
        description: 'Example prompt'
    );
});

// Create initialization options and run server
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner();
$runner->run($server, $initOptions);
```

Save this as `example_server.php`

## Sample Project

### MySQL MCP Server

[MySQL MCP Server](https://github.com/he426100/mysql-mcp-server) is an example of an MCP server implemented based on this SDK. It allows AI assistants to interact securely with MySQL databases through controlled interfaces. The server implements the following functions:

- List MySQL tables as resources
- Read table contents
- Execute SQL queries and perform appropriate error handling
- Configure secure database access through environment variables
- Complete logging


## Documentation

For detailed information about the Model Context Protocol, visit the [official documentation](https://modelcontextprotocol.io).

## Credits

This PHP SDK was developed by:
- [Josh Abbott](https://joshabbott.com)
- [he426100](https://github.com/he426100)
- Claude 3.5 Sonnet (Anthropic AI model)

Additional debugging and refactoring done by Josh Abbott using OpenAI ChatGPT o1 pro mode.

Based on the original [Python SDK](https://github.com/modelcontextprotocol/python-sdk) for the Model Context Protocol.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
