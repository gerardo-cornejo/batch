<?php

namespace Innite\Batch;

use Composer\Script\Event;

class Installer
{
    /**
     * Script post-install: Copia los archivos desde /stubs hacia /app del proyecto CodeIgniter 4
     */
    public static function postInstall(Event $event): void
    {
        self::publishFiles($event, 'post-install');
    }

    /**
     * Script post-update: Copia los archivos desde /stubs hacia /app del proyecto CodeIgniter 4
     */
    public static function postUpdate(Event $event): void
    {
        self::publishFiles($event, 'post-update');
    }

    /**
     * Copia los archivos desde /stubs hacia /app del proyecto CodeIgniter 4
     */
    protected static function publishFiles(Event $event, string $context): void
    {
        $io = $event->getIO();

        // vendor-dir según la config del proyecto donde se está ejecutando Composer
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');

        // Normalizar la ruta del vendor-dir
        $vendorDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $vendorDir);

        // Si viene relativo, lo pasamos a absoluto respecto al cwd
        // En Windows también verificamos si no comienza con una letra de unidad (C:, D:, etc.)
        if (!self::isAbsolutePath($vendorDir)) {
            $vendorDir = getcwd() . DIRECTORY_SEPARATOR . $vendorDir;
        }

        // Raíz del proyecto (carpeta que contiene /vendor)
        $projectRoot = dirname($vendorDir);

        $appDir      = $projectRoot . DIRECTORY_SEPARATOR . 'app';
        $batchTarget = $appDir . DIRECTORY_SEPARATOR . 'Batch';
        $commandsTarget = $appDir . DIRECTORY_SEPARATOR . 'Commands';

        // Ruta de la librería dentro de /vendor
        $libraryDir     = $vendorDir . DIRECTORY_SEPARATOR . 'gerardo-cornejo' . DIRECTORY_SEPARATOR . 'batch';
        $stubsDir       = $libraryDir . DIRECTORY_SEPARATOR . 'stubs';
        $stubsBatchDir  = $stubsDir . DIRECTORY_SEPARATOR . 'Batch';
        $stubsCommandsDir = $stubsDir . DIRECTORY_SEPARATOR . 'Commands';
        
        // Debug: verificar que las rutas existen
        $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Directorio de stubs: %s', $stubsDir));
        if (!is_dir($stubsDir)) {
            $io->write(sprintf('<error>[gerardo-cornejo/batch]</error> No se encontró el directorio stubs en: %s', $stubsDir));
            return;
        }

        // Validar que realmente es un proyecto CI4 (tiene /app)
        if (!is_dir($appDir)) {
            // Intentar buscar la carpeta app en ubicaciones alternativas
            $alternativeAppPaths = [
                $projectRoot . DIRECTORY_SEPARATOR . 'application', // CI3 legacy
                dirname($projectRoot) . DIRECTORY_SEPARATOR . 'app', // estructura diferente
                $projectRoot . DIRECTORY_SEPARATOR . 'src', // estructura PSR-4
            ];
            
            $appFound = false;
            foreach ($alternativeAppPaths as $altPath) {
                if (is_dir($altPath)) {
                    $appDir = $altPath;
                    $batchTarget = $appDir . DIRECTORY_SEPARATOR . 'Batch';
                    $commandsTarget = $appDir . DIRECTORY_SEPARATOR . 'Commands';
                    $appFound = true;
                    $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Carpeta de aplicación encontrada en: %s', $appDir));
                    break;
                }
            }
            
            if (!$appFound) {
                $io->write('<info>[gerardo-cornejo/batch]</info> No se encontró carpeta app/ en este proyecto. Se omite publicación.');
                $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Ruta del proyecto detectada: %s', $projectRoot));
                $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Vendor dir: %s', $vendorDir));
                return;
            }
        }

        // Crear /app/Batch
        if (!is_dir($batchTarget) && !mkdir($batchTarget, 0755, true) && !is_dir($batchTarget)) {
            $io->write('<error>[gerardo-cornejo/batch]</error> No se pudo crear la carpeta app/Batch.');
            return;
        }

        // Crear /app/Commands
        if (!is_dir($commandsTarget) && !mkdir($commandsTarget, 0755, true) && !is_dir($commandsTarget)) {
            $io->write('<error>[gerardo-cornejo/batch]</error> No se pudo crear la carpeta app/Commands.');
            return;
        }

        // Publicar archivos
        self::publishFile(
            $stubsBatchDir . DIRECTORY_SEPARATOR . 'Reader.php',
            $batchTarget . DIRECTORY_SEPARATOR . 'Reader.php',
            $io
        );

        self::publishFile(
            $stubsBatchDir . DIRECTORY_SEPARATOR . 'Processor.php',
            $batchTarget . DIRECTORY_SEPARATOR . 'Processor.php',
            $io
        );

        self::publishFile(
            $stubsBatchDir . DIRECTORY_SEPARATOR . 'Writer.php',
            $batchTarget . DIRECTORY_SEPARATOR . 'Writer.php',
            $io
        );

        self::publishFile(
            $stubsCommandsDir . DIRECTORY_SEPARATOR . 'Execute.php',
            $commandsTarget . DIRECTORY_SEPARATOR . 'Execute.php',
            $io
        );

        $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Archivos publicados (%s).', $context));
    }

    /**
     * Copia archivo si existe en stubs.
     * Si el archivo destino ya existe, NO lo sobreescribe (para no pisar código custom).
     */
    protected static function publishFile(string $source, string $target, $io): void
    {
        if (!file_exists($source)) {
            $io->write(sprintf('<error>[gerardo-cornejo/batch]</error> No se encontró stub: %s', $source));
            return;
        }

        if (file_exists($target)) {
            $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Ya existe, no se modifica: %s', $target));
            return;
        }

        if (!copy($source, $target)) {
            $io->write(sprintf('<error>[gerardo-cornejo/batch]</error> Error al copiar %s a %s', $source, $target));
            return;
        }

        $io->write(sprintf('<info>[gerardo-cornejo/batch]</info> Creado: %s', $target));
    }

    /**
     * Verifica si una ruta es absoluta, compatible con Windows y Unix
     */
    protected static function isAbsolutePath(string $path): bool
    {
        // Unix/Linux: comienza con /
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }
        
        // Windows: comienza con letra de unidad (C:, D:, etc.)
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:/', $path)) {
            return true;
        }
        
        return false;
    }
}
