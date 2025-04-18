中文 | [English](README.en.md)

## 安装

您可以通过 composer 安装此包：

```bash
composer require he426100/mcp-sdk-php
```

### 要求
* PHP 8.1 或更高版本
* ext-curl
* ext-pcntl (可选，在 CLI 环境中推荐使用)
* [ext-swow](https://github.com/swow/swow) 或 [ext-swoole](https://github.com/swoole/swoole-src) (用于 sse 和 websocket 传输)

## 基本用法

### 创建 MCP 服务器

以下是创建提供 prompts 的 MCP 服务器的完整示例：

```php
<?php

// 一个带有测试用 prompts 列表的基本示例服务器

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

// 创建服务器实例
$server = new Server('example-server');

// 注册 prompt 处理器
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

    // 安全获取参数值
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

// 创建初始化选项并运行服务器
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner();
$runner->run($server, $initOptions);
```

将此代码保存为 `example_server.php`

### 使用注解


```php
<?php

require 'vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Annotation\Prompt;
use Mcp\Tool\McpHandlerRegistrar;

$server = new Server('example-server');

// 使用注解定义处理 prompt 的类
class ExamplePrompts 
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
    public function generatePrompt(string $arg1): string
    {
        return "Example prompt text with argument: $arg1";
    }
}

// 注册注解处理器
(new McpHandlerRegistrar)->registerHandler($server, new ExamplePrompts());

// 创建初始化选项并运行服务器
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner();
$runner->run($server, $initOptions);
```

## 示例项目

- [php-mcp-server](https://github.com/he426100/php-mcp-server)


## 文档

有关 Model Context Protocol 的详细信息，请访问[官方文档](https://modelcontextprotocol.io)。

## 致谢

这个 PHP SDK 由以下人员开发：
- [Josh Abbott](https://joshabbott.com)
- [he426100](https://github.com/he426100)
- Claude 3.5 Sonnet (Anthropic AI 模型)

附加调试和重构由 Josh Abbott 使用 OpenAI ChatGPT o1 专业模式完成。

基于 Model Context Protocol 的原始 [Python SDK](https://github.com/modelcontextprotocol/python-sdk)。

## 许可证

MIT 许可证 (MIT)。更多信息请查看[许可证文件](LICENSE)。
