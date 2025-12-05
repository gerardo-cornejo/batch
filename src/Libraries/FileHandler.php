<?php

namespace Innite\Batch\Libraries;

use InvalidArgumentException;

class FileHandler
{

    public static function getPathToWrite(string $name): string
    {
        $root_files_path = ArgumentHandler::getParam("ca_root_path");
        //if does not ends with slash, add it
        if (substr($root_files_path, -1) !== DIRECTORY_SEPARATOR) {
            $root_files_path .= DIRECTORY_SEPARATOR;
        }
        return $root_files_path . $name;
    }
}
