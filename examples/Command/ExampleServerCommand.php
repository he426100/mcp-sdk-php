<?php

namespace Examples\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Examples\Service\LoggerService;

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

class ExampleServerCommand extends Command
{
    // 配置命令
    protected function configure(): void
    {
        $this
            ->setName('mcp:example-server')
            ->setDescription('运行示例服务器')
            ->setHelp('此命令启动一个示例服务器，提供提示服务');
    }

    // 执行命令
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 创建日志记录器
        $logger = LoggerService::createLogger(
            'mcp-server',
            BASE_PATH . '/runtime/server_log.txt',
            false
        );

        $output->writeln("<info>创建服务器实例</info>");

        // 创建服务器实例
        $server = new Server('example-server', $logger);

        // 注册提示处理器
        $server->registerHandler('prompts/list', function ($params) {
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

        $server->registerHandler('prompts/get', function (GetPromptRequestParams $params) {
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
        $runner = new ServerRunner($server, $initOptions, $logger);

        try {
            $output->writeln("<info>启动服务器</info>");
            $runner->run();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>发生错误: " . $e->getMessage() . "</error>");
            $logger->error("服务器运行失败", ['exception' => $e]);
            return Command::FAILURE;
        }
    }
}
