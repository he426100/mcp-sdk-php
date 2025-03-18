<?php

namespace Mcp\Server;

use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ResourceManager
{
    private array $resources = [];
    private array $templates = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addResource(Resource $resource): void
    {
        $this->resources[$resource->uri] = $resource;
    }

    public function addTemplate(ResourceTemplate $template): void
    {
        $this->templates[$template->uriTemplate] = $template;
    }

    public function listResources(): array
    {
        return array_values($this->resources);
    }

    public function listTemplates(): array
    {
        return array_values($this->templates);
    }

    public function readResource(string $uri): mixed
    {
        if (isset($this->resources[$uri])) {
            return $this->resources[$uri]->read();
        }

        foreach ($this->templates as $template) {
            if ($params = $template->matches($uri)) {
                return $template->createResource($uri, $params)->read();
            }
        }

        throw new \InvalidArgumentException("Resource not found: $uri");
    }
}
