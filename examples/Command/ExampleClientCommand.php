<?php

namespace Examples\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Examples\Service\LoggerService;

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

class ExampleClientCommand extends Command
{
    // 配置命令
    protected function configure(): void
    {
        $this
            ->setName('mcp:example-client')
            ->setDescription('运行示例客户端')
            ->setHelp('此命令启动一个示例客户端，连接到示例服务器并列出可用的提示')
            ->addOption('cmd', 'c', InputOption::VALUE_REQUIRED)
            ->addOption('args', 'a', InputOption::VALUE_REQUIRED);
    }

    // 执行命令
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 创建日志记录器
        $logger = LoggerService::createLogger(
            'mcp-client',
            BASE_PATH . '/runtime/client_log.txt',
            true
        );

        // 创建服务器参数
        $serverParams = new StdioServerParameters(
            command: $input->getOption('cmd'),
            args: [$input->getOption('args')],
            env: null
        );

        $output->writeln("<info>创建客户端</info>");

        // 创建客户端实例
        $client = new Client($logger);

        try {
            $output->writeln("<info>开始连接</info>");
            // 连接到服务器
            $session = $client->connect(
                commandOrUrl: $serverParams->getCommand(),
                args: $serverParams->getArgs(),
                env: $serverParams->getEnv()
            );

            $output->writeln("<info>获取可用提示</info>");
            // 列出可用提示
            $promptsResult = $session->listPrompts();

            // 输出提示列表
            if (!empty($promptsResult->prompts)) {
                $output->writeln("<comment>可用提示：</comment>");
                foreach ($promptsResult->prompts as $prompt) {
                    $output->writeln("  - 名称: " . $prompt->name);
                    $output->writeln("    描述: " . $prompt->description);
                    $output->writeln("    参数:");
                    if (!empty($prompt->arguments)) {
                        foreach ($prompt->arguments as $argument) {
                            $output->writeln("      - " . $argument->name .
                                " (" . ($argument->required ? "必需" : "可选") . "): " .
                                $argument->description);
                        }
                    } else {
                        $output->writeln("      (无)");
                    }
                }
            } else {
                $output->writeln("<comment>没有可用的提示。</comment>");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>错误: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        } finally {
            // 关闭服务器连接
            if (isset($client)) {
                $client->close();
            }
        }
    }
}
