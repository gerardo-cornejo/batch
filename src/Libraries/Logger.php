<?php

namespace Innite\Batch\Libraries;

class Logger
{

    public static function info(string $message, array $data = []): void
    {
        self::_message($message, "info", $data);
    }

    public static function error(string $message, array $data = []): void
    {
        self::_message($message, "error", $data);
    }

    public static function debug(string $message, array $data = []): void
    {
        self::_message($message, "debug", $data);
    }

    public static function warning(string $message, array $data = []): void
    {
        self::_message($message, "warning", $data);
    }

    public static function critical(string $message, array $data = []): void
    {
        self::_message($message, "critical", $data);
    }

    public static function assertTrue(string $testName, mixed $value, mixed $expected): void
    {
        self::info("$testName: {} = {} ?  {}", [$value, $expected, $value == $expected ? "SUCCESS" : "FAIL"]);
    }


    public static function _message(string $message, string $level, array $data = []): void
    {
        $class = debug_backtrace()[2]['class'];
        $methods = debug_backtrace()[2]['function'];

        $parts = explode('{}', $message);
        $message = '';

        foreach ($parts as $index => $part) {
            $message .= $part;
            if (isset($data[$index])) {
                if (is_array($data[$index]) || is_object($data[$index])) {
                    $message .= json_encode($data[$index]);
                } else {
                    $message .= $data[$index];
                }
            }
        }

        log_message($level, "[$class::$methods] $message");
    }
}
