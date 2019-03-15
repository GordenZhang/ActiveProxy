<?php

namespace ActiveProxy\Server;

use Swoole\Http\Server;
use Swoole\Server\Port;
use ActiveProxy\Utils\Log;
use ActiveProxy\Utils\Signal;
use ActiveProxy\Utils\Command;

/**
 * ActiveProxy的服务端
 *
 * User: zhangyang
 * Date: 2018/8/8
 * Time: 下午7:59
 */
class ActiveProxyServer
{

    private $server;

    private $proxyPort   = 0;
    private $appPort     = 0;
    private $controlPort = 0;

    private $nextConnectClientName = '';
    private $targetServerHost      = '';
    private $targetServerPort      = 0;

    private $freeProxyFds   = []; //clientName => [proxyFd => proxyFd]
    private $proxyFdToAppFd = []; //proxyFd => appFd
    private $appFdToProxyFd = []; //appFd => proxyFd

    private $proxyConnections = []; //fd => [$fd, $status, $createTime, $modifyTime, $name]

    public function __construct($proxyPort, $appPort, $controlPort)
    {
        $this->proxyPort = $proxyPort;
        $this->appPort = $appPort;
        $this->controlPort = $controlPort;
    }

    public function setTargetAddress($targetServerHost, $targetServerPort)
    {
        $this->targetServerHost = $targetServerHost;
        $this->targetServerPort = $targetServerPort;
    }

    public function start()
    {
        $server = new Server('0.0.0.0', $this->controlPort);
        $server->set(array(
            'worker_num' => 1,
            'daemonize'  => false,
            'backlog'    => 128,
        ));
        $server->on('WorkerStart', function ($server, $workerId) {
            $server->tick(10000, function () {
                $this->forceCutNoHeartbeatConnections();
            });
        });
        $this->bindHttpEvents($server);

        $proxyPort = $server->listen('0.0.0.0', $this->proxyPort, SWOOLE_SOCK_TCP);
        $proxyPort->set([]);
        $this->bindProxyServerEvents($proxyPort);

        $appPort = $server->listen('0.0.0.0', $this->appPort, SWOOLE_SOCK_TCP);
        $appPort->set([]);
        $this->bindAppServerEvents($appPort);

        $this->server = $server;
        $this->server->start();
    }

    public function getServerStatus($includeClientList = false)
    {

        $clientInfo = $this->targetServerPort ? "[ActiveProxy-Client {$this->nextConnectClientName}] ----> 💻 {$this->targetServerHost} : {$this->targetServerPort}" : ("[ActiveProxy-Client (⚠️  NOT SET) ️]");

        $networkStatus = <<<info
\t----------- 系统状态 -------------
        

\t📡 $clientInfo
\t        |
\t        |
\t      ☁️ ☁️
\t        |
\t        |
\t     🔄 {$this->proxyPort}  (proxy-port)
\t        |
\t        |
\t🛰  [ActiveProxy-Server] ---- 🔄 {$this->controlPort}  (control-port)
\t        |
\t        |
\t     🔄 {$this->appPort}  (app-port)



info;
        if (!$includeClientList) {
            return $networkStatus . PHP_EOL;
        }
        $proxyStatus = "\t--------- 当前连接状态 -----------\n\n\tID\tStatus\tCreateTime\t\tModifyTime\t\tName\n\n";
        foreach ($this->proxyConnections as $fdInfo) {

            switch ($fdInfo['status']) {
                case 'admin':
                    $fdInfo['status'] = "💌";
                    break;
                case 'free':
                    $fdInfo['status'] = "☕️";
                    break;
                case 'work':
                    $fdInfo['status'] = "🔗";
                    break;
                default:
                    break;
            }

            $proxyStatus .= "\t" . implode("\t", $fdInfo) . PHP_EOL;
        }

        return $networkStatus . $proxyStatus . PHP_EOL;
    }

    private function bindProxyServerEvents(Port $server)
    {
        $server->on('connect', function ($server, $fd) {
            Log::debug('PROXY_CONNECT', $fd);
            $this->proxyConnections[$fd] = ['fd' => $fd, 'status' => 'connect', 'createTime' => date('Y-m-d H:i:s'), 'modifyTime' => date('Y-m-d H:i:s'), 'name' => '-'];
        });
        $server->on('receive', function ($server, $fd, $from_id, $data) {
            $this->proxyConnections[$fd]['modifyTime'] = date('Y-m-d H:i:s');
            //接收到连接指令
            if ($cmd = Command::parse($data)) {
                switch ($cmd['cmd']) {
                    case Command::CMD_LOGIN:
                        $clientName = $cmd['data']['name'];

                        $this->proxyConnections[$fd]['name'] = $clientName;

                        if ($cmd['data']['type'] == 'control') {
                            $this->proxyConnections[$fd]['status'] = 'admin';
                        } else {
                            $this->proxyConnections[$fd]['status'] = 'free';
                            $this->freeProxyFds[$clientName][$fd] = $fd;
                            $server->send($fd,
                                Command::makeCommand(Command::CMD_SET_TARGET_ADDRESS, ['host' => $this->targetServerHost, 'port' => $this->targetServerPort])
                            );
                        }

                        break;
                    case Command::CMD_HEARTBEAT:
                        break;
                }
                if ($cmd['cmd'] != Command::CMD_HEARTBEAT) {
                    Log::debug('PROXY_RECEIVE', [$fd, $data]);
                }
                return;
            }
            Log::debug('PROXY_RECEIVE', [$fd, $data]);
            //传输数据中
            $this->proxyConnections[$fd]['status'] = 'work';
            unset($this->freeProxyFds[$this->proxyConnections[$fd]['name']][$fd]);

            $appFd = $this->proxyFdToAppFd[$fd];
            $server->send($appFd, $data);
            Log::debug('APP_SEND', [$fd, $this->appFdToProxyFd[$fd], $data]);
        });
        $server->on('close', function ($server, $fd) {
            Log::debug('PROXY_CLOSE', $fd);
            $info = $this->proxyConnections[$fd];
            if (isset($this->freeProxyFds[$info['name']][$fd])) {
                unset($this->freeProxyFds[$info['name']][$fd]);
            }
            unset($this->proxyConnections[$fd]);
        });
    }

    private function bindAppServerEvents(Port $server)
    {
        $server->on('connect', function ($server, $fd) {
            Log::debug('APP_CONNECT', $fd);
        });
        $server->on('receive', function ($server, $fd, $from_id, $data) {
            if (empty($this->nextConnectClientName)) {
                Log::error('NOT_SET_ADDRESS', 'Target address MUST be set before someone connect this server!');
                $server->send($fd, PHP_EOL);
                $server->close($fd);
                return;
            }
            Log::debug('APP_RECEIVE', [$fd, $data]);
            if (!isset($this->appFdToProxyFd[$fd])) {
                if ($freeFd = current($this->freeProxyFds[$this->nextConnectClientName])) {
                    unset($this->freeProxyFds[$freeFd][$this->nextConnectClientName]);
                    $this->appFdToProxyFd[$fd] = $freeFd;
                    $this->proxyFdToAppFd[$freeFd] = $fd;
                } else {
                    Log::error('NO_FREE_CONNECTION', $this->getDebugInfo());
                    return;
                }
            }
            $server->send($this->appFdToProxyFd[$fd], $data);
            Log::debug('PROXY_SEND', [$fd, $this->appFdToProxyFd[$fd], $data]);
        });
        $server->on('close', function ($server, $fd) {
            Log::debug('APP_CLOSE', $fd);
            if ($proxyFd = $this->appFdToProxyFd[$fd]) {
                $server->close($proxyFd);
                unset($this->appFdToProxyFd[$fd]);
                unset($this->proxyFdToAppFd[$proxyFd]);
            }
        });
    }

    private function bindHttpEvents(Server $server)
    {
        $server->on("request", function ($request, $response) {
            $uri = trim($request->server['request_uri'], "/");
            switch ($uri) {
                case 'debug':
                    $debug = [];
                    $ref = new \ReflectionClass(__CLASS__);
                    foreach ($ref->getProperties() as $property) {
                        $propertyName = $property->name;
                        if ($propertyName == 'server') {
                            continue;
                        }
                        $debug[$propertyName] = $this->$propertyName;
                    }

                    $clientList = [];
                    $index = 0;
                    while ($newPage = $this->server->getClientList($index, 100)) {
                        foreach ($newPage as $eachClient) {
                            $clientList[] = $eachClient;
                        }
                        $index += 100;
                    }
                    $debug['client-list'] = $clientList;

                    $output = '';
                    if ($request->get['type'] == 'json') {
                        $output = json_encode($debug);
                    } else {
                        $output = var_export($debug, true);
                    }
                    $response->end($output);
                    break;
                case 'status':
                    $response->end($this->getServerStatus(true));
                    break;
                case 'setTargetAddress':
                    try {
                        $clientName = $request->get['name'];
                        $targetHost = $request->get['host'];
                        $targetPort = $request->get['port'];
                        if (empty($this->freeProxyFds[$clientName])) {
                            $response->status(304);
                            $response->end("== 无效的目标名称! 请从以下地址中选择其中一个 == \n" . implode(PHP_EOL, array_unique(array_keys($this->freeProxyFds))));
                            return;
                        }
                        $this->nextConnectClientName = $clientName;
                        $this->targetServerHost = $targetHost;
                        $this->targetServerPort = $targetPort;
                        foreach ($this->freeProxyFds[$clientName] as $targetFd => $anything) {
                            $this->server->send($anything, Command::makeCommand(Command::CMD_SET_TARGET_ADDRESS, ['host' => $this->targetServerHost, 'port' => $this->targetServerPort]));
                        }
                        $response->end("OK!\n" . $this->getServerStatus());
                    } catch (\Exception $e) {
                        $response->status(304);
                        $response->end($e->getMessage());
                    }
                    break;
                default:
                    $response->end('ActiveProxy Ready');
                    break;
            }
        });
    }

    private function forceCutNoHeartbeatConnections()
    {
        foreach ($this->proxyConnections as $connection) {
            if ($connection['status'] == 'admin' && strtotime($connection['modifyTime']) < time() - 60) {
                $this->server->close($connection['fd']);
                Log::warn('FORCE_CUT_CONNECTION', $connection);
            }
        }
    }
}