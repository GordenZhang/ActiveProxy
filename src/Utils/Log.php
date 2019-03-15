<?php

namespace ActiveProxy\Utils;

class Log
{

    public static function debug($tag, $message)
    {
        self::log(__FUNCTION__, $tag, $message);
    }

    public static function info($tag, $message)
    {
        self::log(__FUNCTION__, $tag, $message);
    }

    public static function warn($tag, $message)
    {
        self::log(__FUNCTION__, $tag, $message);
    }

    public static function error($tag, $message)
    {
        self::log(__FUNCTION__, $tag, $message);
    }

    public static function fatal($tag, $message)
    {
        self::log(__FUNCTION__, $tag, $message);
    }

    private static function log($level, $tag, $message)
    {
        if (is_array($message)) {
            foreach ($message as $key => $value) {
                if (!json_encode($value)) {
                    @$message[$key] = "binary(" . strlen($value) . ")";
                }
            }
        } else {
            if (!json_encode($message)) {
                $message = "binary(" . strlen($message) . ")";
            }
        }

        echo implode([
                date("[Y-m-d H:i:s]"),
                $level,
                strtoupper($tag),
                json_encode($message, JSON_UNESCAPED_UNICODE)
            ], "\t") . PHP_EOL;
    }
}