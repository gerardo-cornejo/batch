<?php

namespace Innite\Batch\Libraries;

use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

class ArgumentHandler
{


    public static function setParams($params)
    {
        $session = session();
        $session->set("params", $params);
    }

    public static function getParam(string $name)
    {
        $paramFlag = CLI::getOption($name);
        if (!$paramFlag) {
            CLI::error("The --$name argument is required.");
            exit(-1);
        }

        $session = session();
        $params = $session->get("params");

        $value = Optional::ofNullable($params[$name])->orElseThrow(function () use ($name) {
            throw new InvalidArgumentException("The value of parameter $name is mandatory.");
        });

        return $value;
    }

    public static function getFromJson(string $name)
    {
        $rawValue = self::getParam($name);
        if (json_last_error() === JSON_ERROR_NONE) {
            $jsonString = self::jsonFix($rawValue);
        }

        if ($jsonString === null) {
            throw new InvalidArgumentException("The value of parameter $name is not a valid JSON.");
        }

        $decoded = json_decode($jsonString, true);
        return $decoded;
    }

    private static function jsonFix(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // Asegurar llaves externas
        if ($input[0] !== '{') {
            $input = '{' . $input;
        }
        if (substr($input, -1) !== '}') {
            $input .= '}';
        }

        // Claves sin comillas → "key":
        $input = preg_replace('/([{,])\s*(\w+)\s*:/', '$1"$2":', $input);

        // Arreglar arrays: [a,b] → ["a","b"]
        $input = preg_replace_callback('/\[(.*?)\]/', function (array $matches): string {
            $rawItems = array_map('trim', explode(',', $matches[1]));

            $fixedItems = array_map(function (string $item): string {
                if ($item === '') {
                    return '""';
                }
                if (preg_match('/^".*"$/', $item)) {
                    return $item; // ya es string
                }
                if (preg_match('/^\d+(\.\d+)?$/', $item)) {
                    return $item; // número
                }
                if (in_array($item, ['true', 'false', 'null'], true)) {
                    return $item; // boolean/null
                }
                return '"' . $item . '"'; // string simple
            }, $rawItems);

            return '[' . implode(',', $fixedItems) . ']';
        }, $input);

        // Valores escalares: "key":value → "key":"value" (si aplica)
        $input = preg_replace_callback(
            '/"(\w+)"\s*:\s*([^,\{\}\[\]]+)/',
            function (array $matches): string {
                $key   = $matches[1];
                $value = trim($matches[2]);

                if ($value === '') {
                    $value = '""';
                }

                if (preg_match('/^".*"$/', $value)) {
                    return "\"$key\":$value";
                }
                if (preg_match('/^\d+(\.\d+)?$/', $value)) {
                    return "\"$key\":$value";
                }
                if (in_array($value, ['true', 'false', 'null'], true)) {
                    return "\"$key\":$value";
                }

                return "\"$key\":\"$value\"";
            },
            $input
        );

        json_decode($input);
        return json_last_error() === JSON_ERROR_NONE ? $input : null;
    }

    public static function getFile($filename): string
    {
        $path = self::getParam("ca_root_path");
        //si termina en directory separator, agregar el filename
        if (substr($path, -1) === DIRECTORY_SEPARATOR) {
            $path .= $filename;
        } else {
            $path .= DIRECTORY_SEPARATOR . $filename;
        }

        return $path;
    }
}
