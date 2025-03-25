<?php

// A version of the basic example server with extra logging

// Enable comprehensive error logging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__, 1) . '/runtime/php_errors.log'); // Logs errors to a file
ini_set('memory_limit', '2G');
error_reporting(E_ALL);

date_default_timezone_set('PRC');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
require BASE_PATH . '/vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Annotation\Prompt;
use Mcp\Tool\McpHandlerRegistrar;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('mcp-server');

// Delete previous log
@unlink(BASE_PATH . '/runtime/server_log.txt');

// Create a handler that writes to server_log.txt
$handler = new StreamHandler(BASE_PATH . '/runtime/server_log.txt', Level::Debug);

// Optional: Create a custom formatter to make logs more readable
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// Add the handler to the logger
$logger->pushHandler($handler);

// Create a server instance
$server = new Server('example-server', $logger);

class Example
{
    #[Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            'arg1' => [
                'description' => 'Example argument',
                'required' => true
            ]
        ]
    )]
    public function example(string $arg1): string
    {
        return "Example prompt text with argument: $arg1";
    }
}

$example = new Example;
(new McpHandlerRegistrar)->registerHandler($server, $example);

// Create initialization options and run server
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner($logger);

try {
    $runner->run($server, $initOptions);
} catch (\Throwable $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
    $logger->error("Server run failed", ['exception' => $e]);
}
