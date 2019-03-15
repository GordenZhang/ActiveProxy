<?php

require_once dirname(__FILE__) . '/../vendor/autoload.php';

$pid = pcntl_fork();
if ($pid == 0) {
    //子进程
    $server = new ActiveProxy\Server\ActiveProxyServer(8888, 22, 'entorick.myds.me', 22);
    $server->start();
} else {
    sleep(1);
    $client = new ActiveProxy\Client\ActiveProxyClient('127.0.0.1', 8888);
    $client->start();
}