<?php

namespace ActiveProxy\Utils;

class Command
{
    const CMD_LOGIN = 'SSHPROXY_CMD_LOGIN';
    const CMD_HEARTBEAT = 'SSHPROXY_HEARTBEAT';
    const CMD_SET_TARGET_ADDRESS = 'SSHPROXY_CMD_SET_TARGET_ADDRESS'; //host, port

    public static function makeCommand($cmd, $data)
    {
        return json_encode(['cmd' => $cmd, 'data' => $data]);
    }

    public static function parse($data)
    {
        $command = json_decode($data, true);
        if (empty($command) || empty($command['cmd'])) {
            return false;
        }
        return $command;
    }
}