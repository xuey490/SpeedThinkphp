<?php
declare(strict_types=1);

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use think\App;

// ----------------------------
// 自动加载 Composer
// ----------------------------
require __DIR__ . '/vendor/autoload.php';

// ----------------------------
// 定义 ThinkPHP 应用路径
// ----------------------------
#define('APP_PATH', __DIR__ . '/app');
define('PUBLIC_PATH', __DIR__ . '/public');
define('ROOT_PATH', __DIR__);

// ----------------------------
// 创建 Workerman HTTP 服务
// ----------------------------
$worker = new Worker('http://0.0.0.0:8787');
$worker->name  = 'ThinkPHP8';
$worker->count = 4;

// 配置
$worker->gzipLevel = 6;
$worker->memoryLimit = 256 * 1024 * 1024; // 256MB
$worker->watchDirs = [__DIR__ . '/app', __DIR__ . '/config', __DIR__ . '/public'];//监控目录

// 日志
$logFile = __DIR__ . '/runtime/workerman_access.log';

// ThinkPHP 入口文件路径
define('APP_PATH', __DIR__ . '/public/index.php');

// ----------------------------
// Worker 启动时加载核心
// ----------------------------
$worker->onWorkerStart = function($worker)  {
    Worker::log(" ThinkPHP8 Workerman Server started at http://127.0.0.1:8787\n");

    // 热更新监控（仅开发模式）
    if (in_array('--debug', $_SERVER['argv'])) {
        echo "[HotReload] Watching for PHP file changes...\n";
        $dirs = $worker->watchDirs;
        watch_files_and_reload($dirs, $worker);
    }
	
    // 内存占用检测
    Timer::add(60, function () use ($worker) {
        $usage = memory_get_usage(true);
		Worker::log("[MEM] use ".round($usage / 1024 / 1024, 2)." MB");
        if ($usage > $worker->memoryLimit) {
            echo "⚠️ Memory limit exceeded (" . round($usage / 1024 / 1024, 2) . "MB), restarting...\n";
            Worker::stopAll();
        }
    });	
	
};

// ----------------------------
// 请求处理逻辑
// ----------------------------
$worker->onMessage = function($connection, Request $request) use($worker, $logFile) {
    try {
		$startTime = microtime(true);
        $uri = urldecode($request->path());
        $file = PUBLIC_PATH . $uri;

        // ① 如果请求静态文件
        if (is_file($file) && is_readable($file)) {
            return $connection->send(build_static_response($file));
        }

        // ② 否则交由 ThinkPHP 处理
        $_SERVER  = build_server($request);
        $_GET     = $request->get() ?? [];
        $_POST    = $request->post() ?? [];
        $_COOKIE  = $request->cookie() ?? [];
        $_FILES   = $request->file() ?? [];
        $_REQUEST = array_merge($_GET, $_POST);


        $http = (new App())->http;
        $thinkResponse = $http->run();
        
        // 强制获取响应内容
        $content = extractResponseContent($thinkResponse);
        $status = $thinkResponse->getCode();
        $headers = $thinkResponse->getHeader();
        
        // 移除可能冲突的 Content-Length 头
        unset($headers['Content-Length']);
        
        // 创建 Workerman Response
		#print_r($content);
        $response = new Response($status, $headers, $content);
        
        $connection->send($response);

		
		$http->end($response);	
		file_put_contents($logFile, sprintf("[%s] %s %s %.2fms\n", date('Y-m-d H:i:s'), $_SERVER['REQUEST_METHOD'], $uri, $elapsed), FILE_APPEND);
    } catch (\Throwable $e) {
		#print_r($e);
        $connection->send(new Response(500, ['Content-Type' => 'text/plain'], 
            "Internal Server Error:\n" . $e->getMessage()
        ));
    }
};

// ----------------------------
// 提取响应内容的辅助函数
// ----------------------------
function extractResponseContent($response) {
    // 方法1: 如果响应对象有 getContent 方法
    if (method_exists($response, 'getContent')) {
        $content = $response->getContent();
        if (is_string($content)) {
            return $content;
        }
    }
    
    // 方法2: 使用输出缓冲捕获
    ob_start();
    $content = $response->send();
    ob_get_clean();
    
    return $content;
}

// ----------------------------
// 构造 $_SERVER 环境
// ----------------------------
function build_server(Request $req): array {
    $server = [];
    $server['REQUEST_METHOD'] = $req->method();
    $server['REQUEST_URI']    = $req->uri();
    $server['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $server['REMOTE_ADDR'] = $req->connection->getRemoteIp();
    $server['REMOTE_PORT'] = $req->connection->getRemotePort();
    $server['SERVER_ADDR']  = '127.0.0.1';
    $server['SERVER_PORT']  = 8787;
    $server['HTTP_HOST']    = $req->host();

    foreach ($req->header() as $key => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }
    return $server;
}

// ----------------------------
// 静态文件响应构造
// ----------------------------
function build_static_response(string $file): Response {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'json' => 'application/json',
        'txt'  => 'text/plain',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip'
    ];
    $contentType = $mime[$ext] ?? 'application/octet-stream';
    return new Response(200, ['Content-Type' => $contentType], file_get_contents($file));
}



// ----------------------------
// 热更新模块
// ----------------------------
function watch_files_and_reload(array $dirs, Worker $worker): void {
    Timer::add(1, function() use ($dirs, $worker) {
        static $fileTimes = [];
        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                //if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;
                $path = (string)$file;
                $mtime = filemtime($path);
                if (!isset($fileTimes[$path])) {
                    $fileTimes[$path] = $mtime;
                } elseif ($mtime != $fileTimes[$path]) {
                    echo "[HotReload] Detected change: {$path}, restarting...\n";
                    $fileTimes[$path] = $mtime;
                    Worker::stopAll();
                    return;
                }
            }
        }
    });
}

// ----------------------------
// 启动 Worker
// ----------------------------
Worker::runAll();
