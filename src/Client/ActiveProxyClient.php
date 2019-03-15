<?php

namespace ActiveProxy\Client;

use ActiveProxy\Utils\Log;
use Swoole\Client;
use ActiveProxy\Utils\Command;
use Swoole\Timer;

use ActiveProxy\Utils\Signal;

class ActiveProxyClient
{
    private $controlConnection = null;

    private $proxyConnectionPool = [];
    private $connections         = []; //clientId => [$id, $status, $createTime, $modifyTime]

    private $name             = '';
    private $remoteServerHost = '';
    private $remoteServerPort = '';

    public function __construct($name, $host, $port)
    {
        $this->name = $name;
        $this->remoteServerHost = $host;
        $this->remoteServerPort = $port;
    }

    public function start()
    {
        $this->addControlConnection();
        Timer::tick(10000, function () {
            if ($this->controlConnection) {
                $this->controlConnection->send(Command::makeCommand(Command::CMD_HEARTBEAT, []));
            } else {
                Log::error('CONTROL_CONNECTION_LOST', $this->connections);
            }
        });

        $this->addProxyConnection();
    }

    private function addControlConnection()
    {
        $clientId = uniqid('proxy_control_');
        $controlClient = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $controlClient->on("connect", function ($client) use ($clientId) {
            $this->connections[$clientId] = ['id' => $clientId, 'status' => 'work', 'createTime' => date('Y-m-d H:i:s'), 'modifyTime' => date('Y-m-d H:i:s')];
            Log::debug('CONTROL_CONNECT', $this->connections);

            $client->send(Command::makeCommand(Command::CMD_LOGIN, ['name' => $this->name, 'type' => 'control']));
            $this->controlConnection = $client;
        });
        $controlClient->on("receive", function ($client, $data) {
            Log::debug('CONTROL_RECEIVE', $data);
            $client->send(Command::makeCommand(Command::CMD_HEARTBEAT, []));
        });
        $controlClient->on("error", function ($client) use ($clientId) {
            Log::error('CONTROL_ERROR', $client);
            unset($this->connections[$clientId]);
            $this->controlConnection = null;
            $this->addControlConnection();
        });
        $controlClient->on("close", function ($client) use ($clientId) {
            Log::debug('CONTROL_CLOSE', $client);
            unset($this->connections[$clientId]);
            $this->controlConnection = null;
            $this->addControlConnection();
        });
        $controlClient->connect($this->remoteServerHost, $this->remoteServerPort);
    }

    private function addProxyConnection()
    {

        $clientId = uniqid('proxy_client_');
        $proxyClient = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $targetClient = null;
        $targetHost = '';
        $targetPort = 0;
        $missingData = [];
        $targetClientId = '';

        $proxyClient->on("connect", function ($proxyClient) use ($clientId, &$targetClient) {
            $this->connections[$clientId] = ['id' => $clientId, 'status' => 'free', 'createTime' => date('Y-m-d H:i:s'), 'modifyTime' => date('Y-m-d H:i:s')];
            Log::debug('PROXY_CONNECT', [$clientId, $this->connections]);
            $proxyClient->send(Command::makeCommand(Command::CMD_LOGIN, ['name' => $this->name, 'type' => 'proxy']));
        });
        $proxyClient->on("receive", function ($proxyClient, $data) use ($clientId, &$targetClient, &$missingData, &$targetClientId, &$targetHost, &$targetPort) {
            Log::debug('PROXY_RECEIVE', [$clientId, $data]);

            //收到控制指令
            if ($command = Command::parse($data)) {
                //收到地址设置命令
                if ($command['cmd'] == Command::CMD_SET_TARGET_ADDRESS) {
                    $targetHost = $command['data']['host'];
                    $targetPort = $command['data']['port'];
                }
                return;
            }

            //应用数据传输（非指令模式）
            if (empty($targetClient)) {
                //首次建立连接
                $missingData[] = $data;
                $targetClientId = uniqid('target_client_');
                $targetClient = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
                $targetClient->on("connect", function ($targetClient) use ($proxyClient, $targetClientId, &$missingData) {
                    Log::debug('SERVICE_CONNECT', $targetClientId);
                    foreach ($missingData as $eachMissingData) {
                        $targetClient->send($eachMissingData);
                    }
                    $missingData = [];
                });
                $targetClient->on("receive", function ($targetClient, $data) use ($targetClientId, $clientId, $proxyClient) {
                    Log::debug('SERVICE_RECEIVE', [$targetClientId, $data]);
                    $proxyClient->send($data);
                    Log::debug('PROXY_SEND', [$clientId, $data]);
                });
                $targetClient->on("error", function ($targetClient) use ($targetClientId, $proxyClient) {
                    Log::error('SERVICE_ERROR', [$targetClientId, $targetClient]);
                    if ($proxyClient->isConnected()) {
                        $proxyClient->close();
                    }
                });
                $targetClient->on("close", function ($targetClient) use ($targetClientId, $proxyClient) {
                    Log::debug('SERVICE_CLOSE', $targetClientId);
                    if ($proxyClient->isConnected()) {
                        $proxyClient->close();
                    }
                });
                Log::debug('ADD_CONNECTION', [$targetClientId, $targetHost, $targetPort]);
                $targetClient->connect($targetHost, $targetPort);

                $this->connections[$clientId]['status'] = 'work';
                $this->connections[$clientId]['modifyTime'] = date('Y-m-d H:i:s');

                //补充一条新的连接
                $this->addProxyConnection();

                return;
            }

            //连接建立后/建立中时的数据传输
            if ($targetClient->isConnected()) {
                $targetClient->send($data);
                Log::debug('SERVICE_SEND', [$targetClientId, $data]);
            } else {
                //连接尚未建立完成，先把数据缓存
                $missingData[] = $data;
                Log::debug('SERVICE_CACHE_DATA', [$clientId, $missingData]);
            }
        });
        $proxyClient->on("error", function ($proxyClient) use ($clientId) {
            Log::error('PROXY_ERROR', [$clientId, $this->connections]);
            unset($this->connections[$clientId]);
            $this->keepFreeProxyConnection();
        });
        $proxyClient->on("close", function ($proxyClient) use ($clientId) {
            Log::debug('PROXY_CLOSE', [$clientId, $this->connections]);
            unset($this->connections[$clientId]);
            $this->keepFreeProxyConnection();
        });
        Log::debug('ADD_CONNECTION', [$clientId, $this->remoteServerHost, $this->remoteServerPort]);
        $proxyClient->connect($this->remoteServerHost, $this->remoteServerPort);
        $this->proxyConnectionPool[$clientId] = $proxyClient;
    }

    private function keepFreeProxyConnection()
    {
        $freeConnectionAmount = 0;
        foreach ($this->connections as $connectionInfo) {
            if ($connectionInfo['status'] == 'free') {
                $freeConnectionAmount++;
            }
        }
        if ($freeConnectionAmount < 1) {
            sleep(3);
            //补充一条新的连接
            $this->addProxyConnection();
        }
    }
}