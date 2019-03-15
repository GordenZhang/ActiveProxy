<?php
require_once 'bootstrap.php';

$command = new Commando\Command();

$command->option('n')
    ->aka('name')
    ->require()
    ->describedAs("The ActiveProxy-Client's name, each client should have a special name.");

$command->option('h')
    ->aka('host')
    ->require()
    ->describedAs("The ActiveProxy-Server's host");

$command->option('p')
    ->aka('port')
    ->require()
    ->describedAs("The ActiveProxy-Server's port");

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

echo " == connect to {$command['host']} {$command['port']} ==" . PHP_EOL;

$client = new ActiveProxy\Client\ActiveProxyClient($command['name'], $command['host'], $command['port']);
$client->start();