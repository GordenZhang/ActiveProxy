<?php
require_once 'bootstrap.php';

$command = new Commando\Command();

$command->option('p')
    ->aka('proxy-port')
    ->require()
    ->describedAs("The listening port for ActiveProxy-Client.");

$command->option('a')
    ->aka('app-port')
    ->describedAs("The listening port for app.");

$command->option('c')
    ->aka('control-port')
    ->describedAs("The listening port for control.");

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

$appPort = $command['app-port'] ? $command['app-port'] : 22;
$controlPort = $command['control-port'] ? $command['control-port'] : 80;

$server = new \ActiveProxy\Server\ActiveProxyServer($command['proxy-port'], $appPort, $controlPort);
echo $server->getServerStatus();
$server->start();