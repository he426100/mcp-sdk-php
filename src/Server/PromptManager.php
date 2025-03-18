<?php

namespace Mcp\Server;

use Mcp\Types\Prompt;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PromptManager
{
    private array $prompts = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addPrompt(Prompt $prompt): void
    {
        $this->prompts[$prompt->name] = $prompt;
    }

    public function listPrompts(): array
    {
        return array_values($this->prompts);
    }

    public function getPrompt(string $name, array $arguments = []): mixed
    {
        if (!isset($this->prompts[$name])) {
            throw new \InvalidArgumentException("Prompt not found: $name");
        }

        $prompt = $this->prompts[$name];
        return $prompt->render($arguments);
    }
}
