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
$runner = new ServerRunner($server, $initOptions);
$runner->run();
```

Save this as `example_server.php`

### Creating an MCP Client

Here's how to create a client that connects to the example server:

```php
<?php

// A basic example client that connects to example_server.php and outputs the prompts

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

// Create server parameters for stdio connection
$serverParams = new StdioServerParameters(
    command: 'php',  // Executable
    args: ['example_server.php'],  // File path to the server
    env: null  // Optional environment variables
);

// Create client instance
$client = new Client();

try {
    echo("Starting to connect\n");
    // Connect to the server using stdio transport
    $session = $client->connect(
        commandOrUrl: $serverParams->getCommand(),
        args: $serverParams->getArgs(),
        env: $serverParams->getEnv()
    );

    echo("Starting to get available prompts\n");
    // List available prompts
    $promptsResult = $session->listPrompts();

    // Output the list of prompts
    if (!empty($promptsResult->prompts)) {
        echo "Available prompts:\n";
        foreach ($promptsResult->prompts as $prompt) {
            echo "  - Name: " . $prompt->name . "\n";
            echo "    Description: " . $prompt->description . "\n";
            echo "    Arguments:\n";
            if (!empty($prompt->arguments)) {
                foreach ($prompt->arguments as $argument) {
                    echo "      - " . $argument->name . " (" . ($argument->required ? "required" : "optional") . "): " . $argument->description . "\n";
                }
            } else {
                echo "      (None)\n";
            }
        }
    } else {
        echo "No prompts available.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Close the server connection
    if (isset($client)) {
        $client->close();
    }
}
```

Save this as `example_client.php` and run it:
```bash
php example_client.php
```

## Advanced Logging For Debugging

### Using Logging

You can enable detailed logging on the client side, the server side, or both.

### Creating an MCP Server With Logging

Here's the previous example MCP server with detailed logging enabled:

```php
<?php

// A version of the basic example server with extra logging

// Enable comprehensive error logging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log'); // Logs errors to a file
error_reporting(E_ALL);

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

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('mcp-server');

// Delete previous log
@unlink(__DIR__ . '/server_log.txt');

// Create a handler that writes to server_log.txt
$handler = new StreamHandler(__DIR__ . '/server_log.txt', Logger::DEBUG);

// Optional: Create a custom formatter to make logs more readable
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// Add the handler to the logger
$logger->pushHandler($handler);

// Create a server instance
$server = new Server('example-server', $logger);

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
$runner = new ServerRunner($server, $initOptions, $logger);

try {
    $runner->run();
} catch (\Throwable $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
    $logger->error("Server run failed", ['exception' => $e]);
}
```

Save this as `debug_example_server.php`

### Creating an MCP Client With Logging

Here's how to create a client with detailed logging enabled:

```php
<?php

// A version of the basic example client with extra logging and output

// Enable comprehensive error reporting and logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log'); // Logs errors to a file
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('mcp-client');

// Delete previous log
@unlink(__DIR__ . '/client_log.txt');

// Create a handler that writes to client_log.txt
$handler = new StreamHandler(__DIR__ . '/client_log.txt', Logger::DEBUG);

// Optional: Create a custom formatter to make logs more readable
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// Add the handler to the logger
$logger->pushHandler($handler);

// Create server parameters for stdio connection
$serverParams = new StdioServerParameters(
    command: 'php',  // Executable
    args: ['debug_example_server.php'],  // File path to the server
    env: null  // Optional environment variables
);

echo("Creating client\n");

// Create client instance
$client = new Client($logger);

try {
    echo("Starting to connect\n");
    // Connect to the server using stdio transport
    $session = $client->connect(
        commandOrUrl: $serverParams->getCommand(),
        args: $serverParams->getArgs(),
        env: $serverParams->getEnv()
    );

    echo("Starting to get available prompts\n");
    // List available prompts
    $promptsResult = $session->listPrompts();

    // Output the list of prompts
    if (!empty($promptsResult->prompts)) {
        echo "Available prompts:\n";
        foreach ($promptsResult->prompts as $prompt) {
            echo "  - Name: " . $prompt->name . "\n";
            echo "    Description: " . $prompt->description . "\n";
            echo "    Arguments:\n";
            if (!empty($prompt->arguments)) {
                foreach ($prompt->arguments as $argument) {
                    echo "      - " . $argument->name . " (" . ($argument->required ? "required" : "optional") . "): " . $argument->description . "\n";
                }
            } else {
                echo "      (None)\n";
            }
        }
    } else {
        echo "No prompts available.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Close the server connection
    if (isset($client)) {
        $client->close();
    }
}
```

Save this as `debug_example_client.php` and run it:
```bash
php debug_example_client.php
```

## MCP Web Client

The "webclient" directory includes a web-based application for testing MCP servers. It was designed to demonstrate a MCP client capable of running in a typical web hosting environment.

### Setting Up The Web Client

To setup the web client, upload the contents of "webclient" to a web directory, such as public_html on a cPanel server. Ensure that the MCP SDK for PHP is installed in that same directory by running the Composer command found in the Installation section of this README.

### Using The Web Client

Once the web client has been uploaded to a web directory, navigate to index.php to open the interface. To connect to the included MCP test server, enter `php` in the Command field and `test_server.php` in the Arguments field and click `Connect to Server`. The interface allows you to test Prompts, Tools, and Resources. There is also a Debug Panel allowing you to view the JSON-RPC messages being sent between the Client and Server.

### Web Client Notes And Limitations

This MCP Web Client is intended for developers to test MCP servers, and it is not recommended to be made publicly accessible as a web interface without additional testing for security, error handling, and resource management.

While MCP is usually implemented as a stateful session protocol, a typical PHP-based web hosting environment restricts long-running processes. To maximize compatibility, the MCP Web Client will initialize a new connection between the client and server for every request, and then close that connection after the request is complete.

## Documentation

For detailed information about the Model Context Protocol, visit the [official documentation](https://modelcontextprotocol.io).

## Credits

This PHP SDK was developed by:
- [Josh Abbott](https://joshabbott.com)
- Claude 3.5 Sonnet (Anthropic AI model)

Additional debugging and refactoring done by Josh Abbott using OpenAI ChatGPT o1 pro mode.

Based on the original [Python SDK](https://github.com/modelcontextprotocol/python-sdk) for the Model Context Protocol.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
