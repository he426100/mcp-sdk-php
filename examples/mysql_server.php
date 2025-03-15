<?php

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

// 启用全面错误日志
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/runtime/php_errors.log');
ini_set('memory_limit', '1G');
error_reporting(E_ALL);

date_default_timezone_set('PRC');

require BASE_PATH . '/vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Tool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\TextContent;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\Content;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * 获取数据库连接
 * 
 * @return PDO 数据库连接实例
 * @throws Exception 当无法连接数据库时抛出
 */
function getDatabaseConnection()
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $database = getenv('DB_DATABASE') ?: 'mysql';

    // 验证环境变量
    if (!$username || !$database) {
        throw new \Exception("数据库连接信息不完整，请设置必要的环境变量");
    }

    try {
        $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, $username, $password, $options);
    } catch (\PDOException $e) {
        throw new \Exception("数据库连接失败: " . $e->getMessage());
    }
}

/**
 * 处理list_tables工具
 */
function handleListTables($logger)
{
    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 限制结果集大小以防止内存问题
        if (count($tables) > 1000) {
            $tables = array_slice($tables, 0, 1000);
            $tablesText = "数据库表列表 (仅显示前1000个):\n\n";
        } else {
            $tablesText = "数据库表列表:\n\n";
        }
        
        $tablesText .= "| 序号 | 表名 |\n";
        $tablesText .= "|------|------|\n";
        
        foreach ($tables as $index => $table) {
            $tablesText .= "| " . ($index + 1) . " | " . $table . " |\n";
        }
        
        // 清理可能的大型对象以减少内存使用
        $stmt = null;
        
        return new CallToolResult(
            content: [new TextContent(text: $tablesText)],
            isError: false
        );
    } catch (\Exception $e) {
        $logger->error("list_tables执行失败", ['exception' => $e->getMessage()]);
        throw $e; // 让上层处理错误
    }
}

/**
 * 处理describe-table工具
 */
function handleDescribeTable($tableName, $logger)
{
    try {
        $pdo = getDatabaseConnection();
        
        // 验证表名以防止SQL注入
        if (!is_string($tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \InvalidArgumentException("无效的表名");
        }
        
        $stmt = $pdo->prepare('DESCRIBE ' . $tableName);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($columns)) {
            throw new \Exception("表 '{$tableName}' 不存在或没有列");
        }
        
        // 将表结构格式化为表格字符串
        $tableDesc = "表 '{$tableName}' 的结构:\n\n";
        $tableDesc .= "| 字段 | 类型 | 允许为空 | 键 | 默认值 | 额外 |\n";
        $tableDesc .= "|------|------|----------|-----|--------|------|\n";
        
        foreach ($columns as $column) {
            $tableDesc .= "| " . $column['Field'] . " | "
                      . $column['Type'] . " | "
                      . $column['Null'] . " | "
                      . $column['Key'] . " | "
                      . ($column['Default'] === null ? 'NULL' : $column['Default']) . " | "
                      . $column['Extra'] . " |\n";
        }
        
        // 清理可能的大型对象以减少内存使用
        $stmt = null;
        $columns = null;
        
        return new CallToolResult(
            content: [new TextContent(text: $tableDesc)],
            isError: false
        );
    } catch (\Exception $e) {
        $logger->error("describe-table执行失败", [
            'table' => $tableName,
            'exception' => $e->getMessage()
        ]);
        throw $e; // 让上层处理错误
    }
}

/**
 * 处理read_query工具
 */
function handleReadQuery($sql, $logger)
{
    try {
        $pdo = getDatabaseConnection();
        
        // 只允许SELECT查询以确保安全
        if (!is_string($sql)) {
            throw new \InvalidArgumentException("SQL查询必须是字符串");
        }
        
        $sql = trim($sql);
        if (!preg_match('/^SELECT\s/i', $sql)) {
            throw new \InvalidArgumentException("只允许SELECT查询");
        }
        
        // 限制查询以防止大型结果集
        if (strpos(strtoupper($sql), 'LIMIT') === false) {
            $sql .= ' LIMIT 1000';
            $limitAdded = true;
        } else {
            $limitAdded = false;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return new CallToolResult(
                content: [new TextContent(text: "查询执行成功，但没有返回结果。")],
                isError: false
            );
        }
        
        // 从结果中提取列名
        $columns = array_keys($results[0]);
        
        // 构建表格标题
        $resultText = "查询结果";
        if ($limitAdded) {
            $resultText .= " (已自动添加LIMIT 1000)";
        }
        $resultText .= ":\n\n";
        
        $resultText .= "| " . implode(" | ", $columns) . " |\n";
        $resultText .= "| " . implode(" | ", array_map(function ($col) {
            return str_repeat("-", mb_strlen($col));
        }, $columns)) . " |\n";
        
        // 添加数据行
        $rowCount = 0;
        $maxRows = 100; // 限制显示的行数，以避免生成过大的响应
        
        foreach ($results as $row) {
            if ($rowCount++ >= $maxRows) {
                break;
            }
            
            $resultText .= "| " . implode(" | ", array_map(function ($val) {
                if ($val === null) {
                    return 'NULL';
                } elseif (is_string($val) && mb_strlen($val) > 100) {
                    // 截断过长的文本
                    return mb_substr($val, 0, 97) . '...';
                } else {
                    return (string)$val;
                }
            }, $row)) . " |\n";
        }
        
        $totalRows = count($results);
        $resultText .= "\n共返回 " . $totalRows . " 条记录";
        
        if ($rowCount < $totalRows) {
            $resultText .= "，仅显示前 " . $rowCount . " 条";
        }
        
        // 清理可能的大型对象以减少内存使用
        $stmt = null;
        $results = null;
        
        return new CallToolResult(
            content: [new TextContent(text: $resultText)],
            isError: false
        );
    } catch (\Exception $e) {
        $logger->error("read_query执行失败", [
            'sql' => $sql,
            'exception' => $e->getMessage()
        ]);
        throw $e; // 让上层处理错误
    }
}

// 创建日志记录器
$logger = new Logger('mysql-mcp-server');

// 删除之前的日志
// @unlink(BASE_PATH . '/runtime/server_log.txt');

// 创建写入server_log.txt的处理器
$handler = new StreamHandler(BASE_PATH . '/runtime/server_log.txt', Level::Debug);

// 创建自定义格式化器使日志更易读
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// 将处理器添加到日志记录器
$logger->pushHandler($handler);

// 创建服务器实例
$server = new Server('mysql-server', $logger);

// 注册工具列表处理器
$server->registerHandler('tools/list', function ($params) {
    // 定义工具列表
    $tools = [
        new Tool(
            name: 'list_tables',
            description: '列出数据库中的所有表',
            inputSchema: new ToolInputSchema(),
        ),
        new Tool(
            name: 'describe-table',
            description: '描述指定表的结构',
            inputSchema: new ToolInputSchema(
                properties: ToolInputProperties::fromArray([
                    'table_name' => [
                        'type' => 'string',
                        'description' => '表名'
                    ]
                ]),
                required: ['table_name']
            )
        ),
        new Tool(
            name: 'read_query',
            description: '执行SQL查询并返回结果',
            inputSchema: new ToolInputSchema(
                properties: ToolInputProperties::fromArray([
                    'sql' => [
                        'type' => 'string',
                        'description' => 'SQL查询语句'
                    ]
                ]),
                required: ['sql']
            )
        )
    ];

    return new ListToolsResult($tools);
});

// 注册工具调用处理器
$server->registerHandler('tools/call', function (CallToolRequestParams $params) use ($logger) {
    $name = $params->name;
    $arguments = $params->arguments;
    
    // 记录请求信息以便调试
    $logger->info("正在处理工具调用", [
        'tool' => $name,
        'arguments' => json_encode($arguments)
    ]);

    // 根据工具名称分派到不同处理函数
    try {
        switch ($name) {
            case 'list_tables':
                return handleListTables($logger);
                
            case 'describe-table':
                // 检查参数是否存在
                if (!is_array($arguments) || !isset($arguments['table_name'])) {
                    throw new \InvalidArgumentException("缺少必要参数: table_name");
                }
                $tableName = $arguments['table_name'];
                return handleDescribeTable($tableName, $logger);
                
            case 'read_query':
                // 检查参数是否存在
                if (!is_array($arguments) || !isset($arguments['sql'])) {
                    throw new \InvalidArgumentException("缺少必要参数: sql");
                }
                $sql = $arguments['sql'];
                return handleReadQuery($sql, $logger);
                
            default:
                throw new \InvalidArgumentException("未知工具: {$name}");
        }
    } catch (\InvalidArgumentException $e) {
        // 参数错误 - 返回JSON-RPC错误
        $logger->error("参数验证失败", [
            'tool' => $name, 
            'error' => $e->getMessage()
        ]);
        throw $e;
    } catch (\Exception $e) {
        // 工具执行错误 - 返回带有isError=true的结果
        $logger->error("工具执行失败", [
            'tool' => $name,
            'exception' => $e->getMessage()
        ]);
        
        return new CallToolResult(
            content: [new TextContent(text: "执行失败: " . $e->getMessage())],
            isError: true
        );
    }
});

// 创建初始化选项并运行服务器
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner($server, $initOptions, $logger);

try {
    $runner->run();
} catch (\Throwable $e) {
    echo "发生错误: " . $e->getMessage() . "\n";
    $logger->error("服务器运行失败", ['exception' => $e]);
}
