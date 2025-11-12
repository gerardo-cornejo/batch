# Innite Batch Plugin

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.x-orange.svg)](https://codeigniter.com)

Plugin de Composer para CodeIgniter 4 que proporciona una estructura completa para procesos batch (por lotes) con patrones de lectura, procesamiento y escritura.

## 📋 Descripción

Este plugin instala automáticamente una estructura de clases para manejar procesos batch en proyectos CodeIgniter 4, siguiendo el patrón **Read-Process-Write** que es común en el procesamiento por lotes.

### Componentes incluidos:

- **Reader**: Clase para leer datos desde diversas fuentes
- **Processor**: Clase para procesar y transformar los datos
- **Writer**: Clase para escribir/guardar los resultados
- **Execute**: Comando CLI para ejecutar procesos batch
- **Optional**: Biblioteca auxiliar opcional

## 🚀 Instalación

### Requisitos

- PHP 8.0 o superior
- CodeIgniter 4.x
- Composer

### Instalar via Composer

```bash
composer require gerardo-cornejo/batch
```

El plugin se ejecutará automáticamente después de la instalación y creará la siguiente estructura en tu proyecto:

```
app/
├── Batch/
│   ├── Reader.php
│   ├── Processor.php
│   └── Writer.php
├── Commands/
│   └── Execute.php
└── Libraries/
    └── Optional.php
```

## 📁 Estructura de Archivos

### Reader.php
Responsable de leer datos desde diversas fuentes (archivos, bases de datos, APIs, etc.)

```php
<?php
namespace App\Batch;

class Reader
{
    public static function execute($params): array
    {
        // Implementa la lógica de lectura
        return $data;
    }
}
```

### Processor.php
Procesa y transforma los datos leídos

```php
<?php
namespace App\Batch;

class Processor
{
    public static function execute(array &$data)
    {
        // Implementa la lógica de procesamiento
        // Los datos se pasan por referencia para modificación
    }
}
```

### Writer.php
Escribe o guarda los datos procesados

```php
<?php
namespace App\Batch;

class Writer
{
    public static function execute(array &$data)
    {
        // Implementa la lógica de escritura
    }
}
```

### Execute.php (Comando CLI)
Comando de CodeIgniter 4 para ejecutar el proceso batch

```bash
# Ejecutar desde la línea de comandos
php spark command:run [parámetros]
```

## 💡 Uso

### 1. Personalizar las clases

Después de la instalación, personaliza cada clase según tus necesidades:

#### Reader
```php
<?php
namespace App\Batch;

use CodeIgniter\CLI\CLI;

class Reader
{
    public static function execute($params): array
    {
        CLI::write('Iniciando lectura de datos...');
        
        // Ejemplo: leer desde archivo CSV
        $file = $params['file'] ?? '';
        $data = [];
        
        if (file_exists($file)) {
            $handle = fopen($file, 'r');
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }
        
        CLI::write(sprintf('Leídos %d registros', count($data)));
        return $data;
    }
}
```

#### Processor
```php
<?php
namespace App\Batch;

use CodeIgniter\CLI\CLI;
use Config\Services;

class Processor
{
    public static function execute(array &$data)
    {
        CLI::write('Procesando datos...');
        
        $db = Services::database();
        
        foreach ($data as &$row) {
            // Ejemplo: validar y transformar datos
            $row['processed_at'] = date('Y-m-d H:i:s');
            $row['status'] = 'processed';
            
            // Realizar validaciones
            if (empty($row['email'])) {
                $row['status'] = 'error';
                $row['error'] = 'Email requerido';
            }
        }
        
        CLI::write('Procesamiento completado');
    }
}
```

#### Writer
```php
<?php
namespace App\Batch;

use CodeIgniter\CLI\CLI;
use Config\Services;

class Writer
{
    public static function execute(array &$data)
    {
        CLI::write('Guardando datos...');
        
        $db = Services::database();
        $builder = $db->table('batch_results');
        
        $successful = 0;
        $errors = 0;
        
        foreach ($data as $row) {
            if ($row['status'] === 'processed') {
                $builder->insert($row);
                $successful++;
            } else {
                CLI::error('Error en registro: ' . ($row['error'] ?? 'Error desconocido'));
                $errors++;
            }
        }
        
        CLI::write(sprintf('Guardados: %d, Errores: %d', $successful, $errors));
    }
}
```

### 2. Personalizar el comando

Modifica `app/Commands/Execute.php` para agregar parámetros específicos:

```php
<?php
namespace App\Commands;

use App\Batch\Processor;
use App\Batch\Reader;
use App\Batch\Writer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Execute extends BaseCommand
{
    protected $group = 'Batch';
    protected $name = 'batch:execute';
    protected $description = 'Ejecuta un proceso batch completo';
    protected $usage = 'batch:execute [options]';
    
    protected $arguments = [
        'file' => 'Archivo de entrada para procesar'
    ];
    
    protected $options = [
        '--dry-run' => 'Ejecutar sin guardar cambios'
    ];

    public function run(array $params)
    {
        CLI::write('=== Iniciando Proceso Batch ===', 'green');
        
        try {
            // Leer datos
            $data = Reader::execute($params);
            
            if (empty($data)) {
                CLI::error('No se encontraron datos para procesar');
                return;
            }
            
            // Procesar datos
            Processor::execute($data);
            
            // Escribir datos (solo si no es dry-run)
            if (!CLI::getOption('dry-run')) {
                Writer::execute($data);
            } else {
                CLI::write('Modo dry-run: No se guardaron cambios', 'yellow');
            }
            
            CLI::write('=== Proceso Completado ===', 'green');
            
        } catch (\Exception $e) {
            CLI::error('Error en el proceso batch: ' . $e->getMessage());
        }
    }
}
```

### 3. Ejecutar el proceso batch

```bash
# Ejecutar proceso básico
php spark batch:execute

# Ejecutar con archivo específico
php spark batch:execute data.csv

# Ejecutar en modo dry-run (sin guardar)
php spark batch:execute data.csv --dry-run
```

## ⚙️ Características

- ✅ **Instalación automática**: Se instala automáticamente con Composer
- ✅ **Estructura organizada**: Sigue las mejores prácticas de CodeIgniter 4
- ✅ **Comando CLI incluido**: Listo para usar desde línea de comandos
- ✅ **Patrón Read-Process-Write**: Arquitectura clara y mantenible
- ✅ **Compatible con Windows y Unix**: Manejo correcto de rutas
- ✅ **No sobrescribe archivos**: Respeta personalizaciones existentes
- ✅ **Detección inteligente**: Encuentra automáticamente la carpeta `app`

## 🛠️ Casos de Uso

- **Importación de datos**: CSV, Excel, JSON hacia base de datos
- **Migración de datos**: Entre diferentes sistemas
- **Procesamiento de archivos**: Transformación de grandes volúmenes
- **Sincronización**: Entre sistemas externos y la aplicación
- **Reportes batch**: Generación de reportes periódicos
- **Limpieza de datos**: Mantenimiento de base de datos

## 🔧 Configuración Avanzada

### Logging personalizado

```php
// En Processor.php
use Config\Services;

$logger = Services::logger();
$logger->info('Procesando lote', ['count' => count($data)]);
```

### Manejo de errores

```php
// En cualquier clase
try {
    // Lógica del proceso
} catch (\Exception $e) {
    CLI::error('Error: ' . $e->getMessage());
    // Log del error
    log_message('error', 'Batch process failed: ' . $e->getMessage());
}
```

## 📝 Contribuir

1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -am 'Agregar nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Crea un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver el archivo [LICENSE](LICENSE) para más detalles.

## 👥 Autores

- **Innite Solutions Perú** - [gerardo.cornejo@innite.net](mailto:gerardo.cornejo@innite.net)

## 🆘 Soporte

Si encuentras algún problema o tienes sugerencias:

1. Revisa los [Issues existentes](../../issues)
2. Crea un nuevo Issue con descripción detallada
3. Incluye información del entorno (PHP, CodeIgniter, OS)

## 📚 Recursos Adicionales

- [Documentación de CodeIgniter 4](https://codeigniter4.github.io/userguide/)
- [Guía de CLI Commands](https://codeigniter4.github.io/userguide/cli/cli_commands.html)
- [Composer Plugins](https://getcomposer.org/doc/articles/plugins.md)