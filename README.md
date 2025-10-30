## SpeedThinkphp

## 简要介绍：
##### **使用 Workerman 作为传统 ThinkPHP 8 框架（原本基于 FPM）的进程启动器**，可以让它变成一个**常驻内存的高性能应用服务器**。无需改变现有架构，少量修改或无需改变任何代码，即可使用。一次编写，性能提升10倍以上。

## 比thinkphp官方提供的简单好用。

#### 感兴趣的也可以看看我的框架，也是支持workerman启动的：
#### https://github.com/xuey490/project

## 关于内存溢出的问题：（这段写到前面）
由于TP框架是为FPM/短生命周期模型设计，而Workerman 是常驻内存，长生命周期模型，每次请求后，框架示例app，静态类,Facade单例，Event，Cache，容器，全局变量，session变量，Logger等都不会自动释放，导致内存溢出的
现在折中办法是对超过内存阈值的时候，自动重载服务，对linux测试，重载几乎无感，顶多刷新一次，但对windows可能会导致进程直接死掉退出。这有待研究吧。

## 特性：
##### ⚠️ 无需 Nginx / Apache
##### 🚀 使用 Workerman 管理进程，ThinkPHP 应用内核常驻内存，性能显著提升,单次请求可以做到30ms之内。
##### 📂 静态文件处理（如 /public/js/、/public/css/、/public/uploads/ 等）
##### ⚡完整支持 ThinkPHP8 框架,保持 ThinkPHP 原生路由与响应逻辑（完全兼容 FPM）
##### 🌀优雅输出与错误处理。
##### ✅ 每个请求自动转换为 ThinkPHP Request 对象交给 Workerman 处理
##### 🔥 支持热更新（检测 app/、config/、route/ 变化自动重启worker）--有待完善
##### 💾支持优雅退出，定期检测内存占用（超过阈值自动优雅重启）
##### 🪵输出运行日志（含响应时间、状态码、URI）
## 目录结构
    project-root/
    ├─ thinkphp/
    ├─ app/
    ├─ config/
    ├─ public/
    │   └─ index.php        ← 原始 FPM 入口文件
    ├─ server.php           ← Workerman 启动器（新增的）
    └─ composer.json

## 使用方法
在你现有的项目目录下，Composer安装：

`composer require workerman/workerman`

**有些composer代理镜像不全，使用以上命令composer config -g --unset repos.packagist 移除代理，再安装**

##### 启动
`php server.php start`

##### 开发模式（带热更新）--有待完善
`php server.php start --debug`

##### 停止
`php server.php stop`

##### 重启
`php server.php restart`

##### 访问：http://127.0.0.1:8787

## 生产部署建议
| 项目               | 推荐值                                  |
| ---------------- | ------------------------------------ |
| `$worker->count` | CPU核心数（建议=4或更多）                      |
| 内存阈值 `$limitMB`  | 256~512 MB 之间                        |
| Cache-Control    | `max-age=3600` 可按需求调整                |
| 守护模式             | `php server.php start -d`            |
| 日志               | 建议使用 Workerman 自带日志 `/workerman.log` |


## 性能优势
| 特性      | 说明                          |
| ------- | --------------------------- |
| 🚀 常驻内存 | 框架核心只初始化一次，性能可提升 5~10 倍     |
| ♻️ 热更新  | 文件改动自动重启 Worker，免手动         |
| 🧠 内存监控 | 超出限制自动重启进程                  |
| 💡 兼容性  | 完全兼容 ThinkPHP 原有代码，不需改任何控制器 |
| ⚙️ 灵活   | 可挂载多应用、API、后台等不同 Worker     |

## 工作机制
| 模块                   | 功能                                        |
| -------------------- | ----------------------------------------- |
| `build_server()`     | 把 Workerman 的请求头映射到 PHP 超全局 `$_SERVER`    |
| `App()->http->run()` | 调用 ThinkPHP 的原生 HTTP 内核，执行路由匹配、控制器调用、返回响应 |
| `Response`           | 直接使用 ThinkPHP 返回对象的内容、状态码、头部              |
| `http->end()`        | 释放上下文（防止请求间内存污染）                          |
## 日志实例
```c
[2025-10-25 18:21:12] GET / 2.14ms
[2025-10-25 18:21:15] GET /public/js/app.js 0.73ms
[2025-10-25 18:21:19] POST /api/user/login 3.81ms
```


