<?php

namespace Innite\Batch\Libraries;

use RuntimeException;

/**
 * QueryExecutor
 *
 * Cliente HTTP para comunicarse con un servidor control-a.
 * Lee CONTROL_A_URL y CONTROL_A_KEY del entorno (.env del proyecto CI4).
 *
 * Uso:
 *   $client = new QueryExecutor();
 *   $rows   = $client->run('mysql-produccion', ['SELECT * FROM clientes']);
 *   // SELECT  → array de filas
 *   // DML     → bool (true si affected_rows >= 0)
 *   // Error   → null  ($client->getError() describe el problema)
 */
class QueryExecutor
{
    private string  $baseUrl;
    private string  $apiKey;
    private ?string $lastError = null;
    private int $timeout;

    // Builder properties
    private string $builderAlias = '';
    private bool $builderAutocommit = true;
    private array $builderQueries = [];
    private $builderOnError = null;
    private $builderOnSuccess = null;
    private bool $builderErrorOccurred = false;

    /**
     * @param int    $timeout  Timeout en segundos (default 30)
     * @param string $urlEnvKey  Nombre de la variable de entorno para la URL
     * @param string $keyEnvKey  Nombre de la variable de entorno para la API key
     *
     * @throws \RuntimeException si alguna variable de entorno no está definida
     */
    public function __construct(
        int    $timeout   = 30,
        string $urlEnvKey = 'CA_SERVER_URL',
        string $keyEnvKey = 'CA_API_KEY'
    ) {
        $url = getenv($urlEnvKey) ?: ($_ENV[$urlEnvKey] ?? null);
        $key = getenv($keyEnvKey) ?: ($_ENV[$keyEnvKey] ?? null);

        if (empty($url)) {
            throw new \RuntimeException(
                "QueryExecutor: variable '{$urlEnvKey}' no disponible. " .
                    'Asegúrate de ejecutar este código dentro de un job lanzado por control-a.'
            );
        }
        if (empty($key)) {
            throw new \RuntimeException(
                "QueryExecutor: variable '{$keyEnvKey}' no disponible. " .
                    'Asegúrate de ejecutar este código dentro de un job lanzado por control-a.'
            );
        }

        $this->baseUrl = rtrim($url, '/');
        $this->apiKey  = $key;
        $this->timeout = $timeout;
    }

    // -------------------------------------------------------------------------
    // Builder API
    // -------------------------------------------------------------------------

    /**
     * Establece el alias de conexión.
     */
    public function alias(string $alias): self
    {
        $this->builderAlias = $alias;
        return $this;
    }

    /**
     * Establece el modo autocommit.
     */
    public function autocommit(bool $autocommit): self
    {
        $this->builderAutocommit = $autocommit;
        return $this;
    }

    /**
     * Agrega una query a la lista de queries a ejecutar.
     */
    public function run(string $query): self
    {
        $this->builderQueries[] = $query;
        return $this;
    }

    /**
     * Define un callback para manejar errores.
     * El callback recibe el mensaje de error como argumento.
     */
    public function onError(callable $callback): self
    {
        $this->builderOnError = $callback;
        return $this;
    }

    /**
     * Define un callback para manejar éxito.
     * El callback recibe el resultado como argumento.
     */
    public function onSuccess(callable $callback): self
    {
        $this->builderOnSuccess = $callback;
        return $this;
    }

    /**
     * Ejecuta todas las queries acumuladas.
     */
    public function execute(): array|bool|null
    {
        $this->lastError = null;
        $this->builderErrorOccurred = false;

        if (empty($this->builderAlias)) {
            $this->lastError = 'Alias de conexión no definido.';
            $this->builderErrorOccurred = true;
            if ($this->builderOnError) {
                ($this->builderOnError)($this->lastError);
            }
            return null;
        }
        if (empty($this->builderQueries)) {
            $this->lastError = 'No hay queries para ejecutar.';
            $this->builderErrorOccurred = true;
            if ($this->builderOnError) {
                ($this->builderOnError)($this->lastError);
            }
            return null;
        }

        $payload = json_encode([
            'alias' => $this->builderAlias,
            'queries' => $this->builderQueries,
            'autocommit' => $this->builderAutocommit,
        ]);

        $timestamp = (string) time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-ControlA-Timestamp: ' . $timestamp,
            'X-ControlA-Signature: sha256=' . $signature,
        ];

        $response = $this->post('/api/query', $payload, $headers);

        if ($response === null) {
            $this->builderErrorOccurred = true;
            if ($this->builderOnError) {
                ($this->builderOnError)($this->lastError);
            }
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = 'Respuesta inválida del servidor (JSON malformado).';
            $this->builderErrorOccurred = true;
            if ($this->builderOnError) {
                ($this->builderOnError)($this->lastError);
            }
            return null;
        }

        if (empty($data['success'])) {
            $this->lastError = $data['message'] ?? 'El servidor reportó un error sin descripción.';
            $this->builderErrorOccurred = true;
            if ($this->builderOnError) {
                ($this->builderOnError)($this->lastError);
            }
            // Si el servidor respondió con un DML fallido explícito, devolver false
            return isset($data['type']) && $data['type'] === 'write' ? false : null;
        }

        // SELECT
        if (($data['type'] ?? '') === 'select') {
            $result = $data['result'] ?? [];
            if ($this->builderOnSuccess) {
                ($this->builderOnSuccess)($result);
            }
            return $result;
        }

        // INSERT / UPDATE / DELETE / DDL
        if ($this->builderOnSuccess) {
            ($this->builderOnSuccess)(true);
        }
        return true;
    }

    /**
     * Indica si ocurrió error en la última ejecución builder.
     */
    public function errorOccurred(): bool
    {
        return $this->builderErrorOccurred;
    }

    /**
     * Retorna el mensaje del último error ocurrido, o null si no hubo error.
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Genera la firma HMAC-SHA256 del payload + timestamp.
     * El mensaje que se firma es: timestamp + "." + payload (body JSON)
     */
    private function sign(string $payload, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $this->apiKey);
    }

    /**
     * Realiza un POST HTTP con curl.
     *
     * @return string|null  Cuerpo de la respuesta, o null en caso de error curl/HTTP
     */
    private function post(string $path, string $payload, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            $this->lastError = 'La extensión cURL no está disponible en este entorno PHP.';
            return null;
        }

        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Verificación SSL: desactivar solo en desarrollo con certificados self-signed.
            // En producción debe ser true.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if ($curlErr && strlen($curlErr) > 0) {
            $this->lastError = $curlErr;
            return null;
        } else if ($httpCode >= 400 && $httpCode <= 503) {
            $this->lastError = "[Error {$httpCode}]: " . ($body ?? $curlErr);
            return null;
        }

        return (string) $body;
    }

    public function clear(): void
    {
        $this->builderAlias = '';
        $this->builderAutocommit = true;
        $this->builderQueries = [];
        $this->builderOnError = null;
        $this->builderErrorOccurred = false;
        $this->lastError = null;
    }

    
}
