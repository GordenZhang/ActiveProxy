<?php
require_once 'bootstrap.php';

$command = new Commando\Command();

$command->option('n')
    ->aka('client-name')
    ->require()
    ->describedAs("The client name you will connect.");

$command->option('h')
    ->aka('target-host')
    ->require()
    ->describedAs("The remote-LAN host you will connect.");

$command->option('p')
    ->aka('target-port')
    ->describedAs("The remote-LAN port you will connect.");

$command->option('s')
    ->aka('server-host')
    ->describedAs("The host for ActiveProxy-Server.");

$command->option('c')
    ->aka('control-port')
    ->describedAs("The listening port for control.");

$command->option('u')
    ->aka('user-name')
    ->describedAs("The user name you will use.");

//自定义错误显示方式
$command->trapErrors(false);
try {
    $command->parse();
} catch (Exception $e) {
    $command->printHelp();

    $color = new \Colors\Color();
    $error = sprintf('ERROR: %s ', $e->getMessage());
    echo PHP_EOL . PHP_EOL . $color($error)->bg('red')->bold()->white() . PHP_EOL . PHP_EOL . PHP_EOL;

    exit(1);
}

$clientName = urlencode($command['client-name']);
$host = $command['target-host'];
$port = $command['target-port'] ? $command['target-port'] : 22;
$serverHost = $command['server-host'] ? $command['server-host'] : '127.0.0.1';
$serverControlPort = $command['control-port'] ? $command['control-port'] : '80';

$url = "http://$serverHost:$serverControlPort/setTargetAddress?name=$clientName&host=$host&port=$port";
echo file_get_contents($url) . PHP_EOL;
if (strpos($http_response_header[0], '200') === false) {
    exit(1);
}
