<?php
declare(strict_types=1);

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use think\App;
use think\facade\Session;

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

// 下面的常量定义，根据自己的index.php的需要进行定义，也可以删除
// 定义当前版本号
define('CMS_VERSION','5.9.5');

// 定义手机端当前版本号
define('MB_VERSION','1.3');

// 定义Layui版本号
define('LAYUI_VERSION','2.11.6');

// 定义项目目录
define('CMS_ROOT', __DIR__ . '/');

// 定义报错模版
define('EEEOR_REPORTING',CMS_ROOT.'/public/tpl/error.html');
// 常量定义结束



// ----------------------------
// 创建 Workerman HTTP 服务
// ----------------------------
$worker = new Worker('http://0.0.0.0:8787');
$worker->name  = 'ThinkPHP8';
$worker->count = 1;

// 配置
//$worker->gzipLevel = 6;
$worker->memoryLimit = 256 * 1024 * 1024; // 256MB
$worker->watchDirs = [__DIR__ . '/app', __DIR__ . '/config', __DIR__ . '/public'];//监控目录

// 日志
$logFile = __DIR__ . '/runtime/run.log';

// ThinkPHP 入口文件路径
define('APP_PATH', __DIR__ . '/public/index.php');

// ----------------------------
// Worker 启动时加载核心
// ----------------------------
$worker->onWorkerStart = function(Worker $worker)  {
    Worker::log(" ThinkPHP8 Workerman Server started at http://127.0.0.1:8787\n");

    // 热更新监控（仅开发模式）
    if (in_array('--debug', $_SERVER['argv'])) {
        Worker::log( "[HotReload] Watching for PHP file changes...\n");
        $dirs = $worker->watchDirs;
        watch_files_and_reload($dirs, $worker);
    }
	
    // 内存占用检测
    Timer::add(10, function () use ($worker) {
        $usage = memory_get_usage(true);
		Worker::log("[MEM] use ".round($usage / 1024 / 1024, 2)." MB");
        if ($usage > $worker->memoryLimit) {
            Worker::log( "⚠️ Memory limit exceeded (" . round($usage / 1024 / 1024, 2) . "MB), restarting...\n");
            Worker::stopAll();
        }
    });	
	
};

// ----------------------------
// 请求处理逻辑
// ----------------------------
$worker->onMessage = function ($connection, Request $request) use ($worker, $logFile) {
    try {
			  $_GET = $_POST = $_COOKIE = $_SERVER = $_FILES = [];
				$startTime = microtime(true);
				$datetime = date('Y-m-d H:i:s');
       $uri = urldecode($request->path());
				if($uri ==='/') {
					$connection->send(new Response(302, ['Location' => '/home'], ''));
					return; // 终止后续逻辑执行			
				}


        // 构造 TP 请求对象
        //$thinkRequest = $app->make('request');
        $_SERVER  = build_server($request);
        $_GET     = $request->get() ?? [];
        $_POST    = $request->post() ?? [];
        $_COOKIE  = $request->cookie() ?? [];
        $_FILES   = $request->file() ?? [];
        $_REQUEST = array_merge($_GET, $_POST);

        // 获取客户端 PHPSESSID
        $sid = $request->cookie('PHPSESSID');
		
				// 初始化 ThinkPHP 应用（此操作必须在请求内执行）
				$app = new App();
				$app->initialize();

        // ① 初始化 Session 模块
        $config = $app->config->get('session', []);
        $sessionConfig = array_merge([
            'type'       => 'cache',
            'store'      => 'redis',
            'prefix'     => 'session_',
            'expire'     => 1440,
            'auto_start' => false,
        ], $config);
        Session::init($sessionConfig);

        // ② 如果客户端带有 PHPSESSID，复用它，否则生成新的
        if ($sid) {
            Session::setId($sid);
        } else {
            Session::setId(bin2hex(random_bytes(16))); // 生成新 ID
        }

        // ④ 启动 session
        Session::init();
		
				// 静态文件支持
        $file = PUBLIC_PATH . $uri;

        //  如果请求静态文件
        if (is_file($file) && is_readable($file)) {
            return $connection->send(build_static_response($file));
        }

        // ⑤运行应用
        $http = $app->http;
        $response = $http->run();
        $content = $response->getContent();
        $status = $response->getCode();
        $headers = $response->getHeader();

        // ⑥  写入 session（只写一次）
        Session::save();

        // ⑦ 设置 Set-Cookie（仅当新建 session 时）
        if (!$sid) {
            $headers['Set-Cookie'] = 'PHPSESSID=' . Session::getId() . '; path=/; HttpOnly';
        }

        // ⑦ 发送响应
        $connection->send(new Response($status, $headers, $content));

        
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
			   // 记录日志
	      Worker::log("请求地址：{$uri} |请求时间:{$datetime} | 响应时间：{$elapsed}ms");
        $http->end($response);
    } catch (\Throwable $e) {
        $connection->send(new Response(500, ['Content-Type' => 'text/plain'], "Internal Server Error:\n" . $e->getMessage()));
    } finally {
				unset($content, $app, $request , $response);
			  Session::clear();
			  $_GET = $_POST = $_COOKIE = $_SERVER = $_FILES = [];    
        gc_collect_cycles();
    }
};

// ----------------------------
// 提取响应内容的辅助函数
// ----------------------------
function extractResponseContent($response) {
    // 方法1: 如果响应对象有 getContent 方法

    if (method_exists($response, 'getContent')) {
			  #Worker::log("Content");
        $content = $response->getContent();
        if (is_string($content)) {
            return $content;
        }
    }

    
    // 方法2: 使用输出缓冲捕获
    ob_start();
    $response->send();
    $content = ob_get_clean();
    
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
