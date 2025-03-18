<?php

namespace Mcp\Server;

use Mcp\Types\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ToolManager
{
    private array $tools = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addTool(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function listTools(): array
    {
        return array_values($this->tools);
    }

    public function callTool(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool not found: $name");
        }

        $tool = $this->tools[$name];
        return $tool->call($arguments);
    }
}
