<?php

namespace App\Libraries;

/**
 * QueryBuilder — Constructor de queries SQL fluente (encadenable).
 *
 * Dialectos soportados: 'mysql' | 'sqlsrv'
 *
 * ---------------------------------------------------------------------------
 * Ejemplos de uso
 * ---------------------------------------------------------------------------
 *
 * SELECT
 *   $sql = QueryBuilder::table('users', 'sqlsrv')
 *       ->select('id, name, email')
 *       ->where('active', 1)
 *       ->where('age', '>=', 18)
 *       ->orderBy('name')
 *       ->limit(10)
 *       ->offset(20)
 *       ->toSQL();
 *
 * INSERT
 *   $sql = QueryBuilder::table('users')
 *       ->insert(['name' => 'Juan', 'email' => 'juan@mail.com'])
 *       ->toSQL();
 *
 * INSERT BATCH
 *   $sql = QueryBuilder::table('users')
 *       ->insertBatch([
 *           ['name' => 'Ana',  'email' => 'ana@mail.com'],
 *           ['name' => 'Luis', 'email' => 'luis@mail.com'],
 *       ])
 *       ->toSQL();
 *
 * UPDATE
 *   $sql = QueryBuilder::table('users')
 *       ->update(['name' => 'Pedro', 'active' => 1])
 *       ->where('id', 5)
 *       ->toSQL();
 *
 * DELETE
 *   $sql = QueryBuilder::table('users')
 *       ->delete()
 *       ->where('id', 5)
 *       ->toSQL();
 *
 * PARAMETRIZADO — protección real contra inyección SQL
 *   [$sql, $bindings] = QueryBuilder::table('users')
 *       ->select('id, name')
 *       ->where('active', 1)
 *       ->where('name', 'LIKE', '%juan%')
 *       ->toParamSQL();
 *   // $sql      → "SELECT `id`, `name` FROM `users` WHERE `active` = ? AND `name` LIKE ?"
 *   // $bindings → [1, '%juan%']
 *   $stmt = $pdo->prepare($sql);
 *   $stmt->execute($bindings);
 *
 * Nota: los métodos *Raw aceptan un array $bindings con los valores para los '?'
 * que escribas en la expresión. Úsalos siempre que el valor venga del exterior.
 *
 *   ->whereRaw('YEAR(created_at) = ?', [2024])
 *   ->having('COUNT(*) > ?', [5])
 *   ->selectRaw('DATEDIFF(day, created_at, ?) AS dias', ['2026-01-01'])
 *   ->join('orders o', 'o.user_id = u.id AND o.status = ?', 'INNER', ['active'])
 */
class QueryBuilder
{
    public const DIALECT_MYSQL  = 'mysql';
    public const DIALECT_SQLSRV = 'sqlsrv';

    /** Prefijo interno para identificar expresiones RAW en el SELECT */
    private const RAW_PREFIX = '__RAW__:';

    // -------------------------------------------------------------------------
    // Estado interno
    // -------------------------------------------------------------------------

    private string $dialect;
    private string $table              = '';
    private string $queryType          = '';
    private array  $selectColumns      = ['*'];
    private array  $selectRawBindings  = [];   // bindings de cada selectRaw(), en orden
    private bool   $distinctFlag       = false;
    private array  $conditions         = [];
    private array  $joins              = [];   // [['sql' => string, 'bindings' => array]]
    private array  $orderClauses       = [];
    private array  $groupClauses       = [];
    private array  $havingClauses      = [];   // [['expr' => string, 'bindings' => array]]
    private ?int   $limitValue         = null;
    private ?int   $offsetValue        = null;
    private array  $insertData         = [];
    private array  $updateData         = [];

    // =========================================================================
    // Constructor / Factory estático
    // =========================================================================

    public function __construct(string $dialect = self::DIALECT_MYSQL)
    {
        $this->dialect = strtolower($dialect);
    }

    /**
     * Punto de entrada recomendado.
     *
     * @param string $table   Nombre de la tabla
     * @param string $dialect 'mysql' (default) | 'sqlsrv'
     */
    public static function table(string $table, string $dialect = self::DIALECT_MYSQL): static
    {
        $instance        = new static($dialect);
        $instance->table = $table;

        return $instance;
    }

    /**
     * Alias de toSQL() para usar el builder en contextos de string.
     *
     * Ejemplo: echo QueryBuilder::table('users')->where('id', 1);
     */
    public function __toString(): string
    {
        return $this->toSQL();
    }

    // =========================================================================
    // Definición del tipo de query
    // =========================================================================

    /**
     * SELECT — define las columnas a recuperar.
     *
     * @param string|array $columns '*', 'col1, col2' o ['col1', 'col2']
     */
    public function select(string|array $columns = '*'): static
    {
        $this->queryType     = 'SELECT';
        $this->selectColumns = is_array($columns)
            ? $columns
            : array_map('trim', explode(',', $columns));

        return $this;
    }

    /**
     * Marca la consulta como SELECT DISTINCT.
     */
    public function distinct(): static
    {
        $this->distinctFlag = true;

        return $this;
    }

    /**
     * Agrega una expresión SQL cruda al SELECT (sin escapado de identificadores).
     *
     * Ejemplo: ->selectRaw('COUNT(*) AS total')
     * Con binding: ->selectRaw('DATEDIFF(day, created_at, ?) AS dias', ['2026-01-01'])
     *
     * @param array<mixed> $bindings Valores para los '?' de la expresión (solo toParamSQL)
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        if ($this->queryType !== 'SELECT') {
            $this->queryType = 'SELECT';
        }

        // Evitar duplicar '*' si ya se agregó selectRaw como primera llamada
        if ($this->selectColumns === ['*']) {
            $this->selectColumns = [];
        }

        $this->selectColumns[]    = self::RAW_PREFIX . $expression;
        $this->selectRawBindings[] = $bindings;

        return $this;
    }

    /**
     * INSERT — inserta un único registro.
     *
     * @param array<string, mixed> $data Pares columna => valor
     */
    public function insert(array $data): static
    {
        $this->queryType  = 'INSERT';
        $this->insertData = [$data];

        return $this;
    }

    /**
     * INSERT BATCH — inserta múltiples registros en una sola sentencia.
     *
     * Nota SQL Server: el límite es 1 000 filas por sentencia VALUES.
     *
     * @param array<int, array<string, mixed>> $data Array de filas
     */
    public function insertBatch(array $data): static
    {
        $this->queryType  = 'INSERT_BATCH';
        $this->insertData = $data;

        return $this;
    }

    /**
     * UPDATE — actualiza columnas en registros existentes.
     *
     * @param array<string, mixed> $data Pares columna => nuevo valor
     */
    public function update(array $data): static
    {
        $this->queryType  = 'UPDATE';
        $this->updateData = $data;

        return $this;
    }

    /**
     * DELETE — elimina registros que cumplan las condiciones WHERE.
     */
    public function delete(): static
    {
        $this->queryType = 'DELETE';

        return $this;
    }

    // =========================================================================
    // Cláusulas WHERE
    // =========================================================================

    /**
     * Agrega una condición AND WHERE.
     *
     * Formas aceptadas:
     *   ->where('column', 'value')          →  column = 'value'
     *   ->where('column', '=', 'value')     →  column = 'value'
     *   ->where('column', '>', 100)         →  column > 100
     *   ->where('column', 'LIKE', '%foo%')  →  column LIKE '%foo%'
     *
     * Operadores válidos: =, !=, <>, <, >, <=, >=, LIKE, NOT LIKE
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $val] = $this->parseOperatorValue($operatorOrValue, $value);

        $this->conditions[] = [
            'type'     => 'AND',
            'raw'      => false,
            'column'   => $column,
            'operator' => $operator,
            'value'    => $val,
        ];

        return $this;
    }

    /**
     * Agrega una condición OR WHERE.
     */
    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $val] = $this->parseOperatorValue($operatorOrValue, $value);

        $this->conditions[] = [
            'type'     => 'OR',
            'raw'      => false,
            'column'   => $column,
            'operator' => $operator,
            'value'    => $val,
        ];

        return $this;
    }

    /**
     * Agrega una expresión WHERE cruda.
     *
     * Ejemplo sin binding:  ->whereRaw("YEAR(created_at) = 2024")
     * Ejemplo con binding:  ->whereRaw("YEAR(created_at) = ?", [2024])
     * Ejemplo OR:           ->whereRaw("status = ?", ['active'], 'OR')
     *
     * @param array<mixed> $bindings Valores para los '?' (solo aplica en toParamSQL)
     * @param string       $type     'AND' | 'OR'
     */
    public function whereRaw(string $expression, array $bindings = [], string $type = 'AND'): static
    {
        $this->conditions[] = [
            'type'     => strtoupper($type),
            'raw'      => true,
            'expr'     => $expression,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * WHERE column IN (v1, v2, ...).
     *
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        $this->conditions[] = [
            'type'    => 'AND',
            'raw'     => false,
            'subtype' => 'IN',
            'column'  => $column,
            'values'  => $values,
        ];

        return $this;
    }

    /**
     * WHERE column NOT IN (v1, v2, ...).
     *
     * @param array<mixed> $values
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->conditions[] = [
            'type'    => 'AND',
            'raw'     => false,
            'subtype' => 'NOT IN',
            'column'  => $column,
            'values'  => $values,
        ];

        return $this;
    }

    /**
     * WHERE column IS NULL.
     */
    public function whereNull(string $column): static
    {
        $this->conditions[] = [
            'type'     => 'AND',
            'raw'      => true,
            'expr'     => $this->quoteIdentifier($column) . ' IS NULL',
            'bindings' => [],
        ];

        return $this;
    }

    /**
     * WHERE column IS NOT NULL.
     */
    public function whereNotNull(string $column): static
    {
        $this->conditions[] = [
            'type'     => 'AND',
            'raw'      => true,
            'expr'     => $this->quoteIdentifier($column) . ' IS NOT NULL',
            'bindings' => [],
        ];

        return $this;
    }

    /**
     * WHERE column BETWEEN val1 AND val2.
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->conditions[] = [
            'type'    => 'AND',
            'raw'     => false,
            'subtype' => 'BETWEEN',
            'column'  => $column,
            'min'     => $min,
            'max'     => $max,
        ];

        return $this;
    }

    // =========================================================================
    // JOINs
    // =========================================================================

    /**
     * Agrega un JOIN.
     *
     * @param string       $table     Nombre de la tabla a unir
     * @param string       $condition Condición ON (ej. 'orders.user_id = users.id')
     * @param string       $type      INNER | LEFT | RIGHT | FULL | CROSS
     * @param array<mixed> $bindings  Valores para los '?' del ON (solo toParamSQL)
     */
    public function join(string $table, string $condition, string $type = 'INNER', array $bindings = []): static
    {
        $this->joins[] = [
            'sql'      => strtoupper($type) . ' JOIN '
                . $this->quoteIdentifier($table)
                . ' ON ' . $condition,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /** LEFT JOIN. */
    public function leftJoin(string $table, string $condition, array $bindings = []): static
    {
        return $this->join($table, $condition, 'LEFT', $bindings);
    }

    /** RIGHT JOIN. */
    public function rightJoin(string $table, string $condition, array $bindings = []): static
    {
        return $this->join($table, $condition, 'RIGHT', $bindings);
    }

    /** FULL OUTER JOIN. */
    public function fullJoin(string $table, string $condition, array $bindings = []): static
    {
        return $this->join($table, $condition, 'FULL OUTER', $bindings);
    }

    // =========================================================================
    // ORDER BY
    // =========================================================================

    /**
     * ORDER BY column [ASC|DESC].
     *
     * Puede llamarse varias veces para ordenar por múltiples columnas.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $this->orderClauses[] = $this->quoteIdentifier($column) . ' ' . $dir;

        return $this;
    }

    // =========================================================================
    // GROUP BY / HAVING
    // =========================================================================

    /**
     * GROUP BY columna(s).
     *
     * @param string|array $columns 'col1, col2' o ['col1', 'col2']
     */
    public function groupBy(string|array $columns): static
    {
        $cols = is_array($columns)
            ? $columns
            : array_map('trim', explode(',', $columns));

        foreach ($cols as $col) {
            $this->groupClauses[] = $this->quoteIdentifier(trim($col));
        }

        return $this;
    }

    /**
     * HAVING — condición sobre grupos.
     *
     * Ejemplo sin binding: ->having('COUNT(*) > 5')
     * Ejemplo con binding: ->having('COUNT(*) > ?', [5])
     *
     * @param array<mixed> $bindings Valores para los '?' (solo aplica en toParamSQL)
     */
    public function having(string $condition, array $bindings = []): static
    {
        $this->havingClauses[] = [
            'expr'     => $condition,
            'bindings' => $bindings,
        ];

        return $this;
    }

    // =========================================================================
    // LIMIT / OFFSET
    // =========================================================================

    /**
     * Limita el número de filas devueltas.
     *
     * En MySQL  → LIMIT n
     * En SQLSRV → TOP n  (sin OFFSET) / FETCH NEXT n ROWS ONLY (con OFFSET)
     */
    public function limit(int $limit): static
    {
        $this->limitValue = $limit;

        return $this;
    }

    /**
     * Desplazamiento de filas (paginación).
     *
     * En MySQL  → OFFSET n
     * En SQLSRV → OFFSET n ROWS (requiere ORDER BY; se agrega ficticio si falta)
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;

        return $this;
    }

    // =========================================================================
    // Generación de la query final
    // =========================================================================

    /**
     * Construye y devuelve la query SQL final como string.
     *
     * @throws \RuntimeException si falta tabla o tipo de query
     */
    public function toSQL(): string
    {
        if ($this->table === '') {
            throw new \RuntimeException(
                'QueryBuilder: tabla no especificada. Usa QueryBuilder::table("nombre").'
            );
        }

        // Si nunca se llamó a select/insert/update/delete, asumir SELECT *
        if ($this->queryType === '') {
            $this->queryType = 'SELECT';
        }

        return match ($this->queryType) {
            'SELECT'       => $this->buildSelect(),
            'INSERT',
            'INSERT_BATCH' => $this->buildInsert(),
            'UPDATE'       => $this->buildUpdate(),
            'DELETE'       => $this->buildDelete(),
            default        => throw new \RuntimeException(
                "QueryBuilder: tipo de query desconocido '{$this->queryType}'."
            ),
        };
    }

    /**
     * Resetea el estado interno para reutilizar la instancia con otra query.
     */
    public function reset(): static
    {
        $this->queryType          = '';
        $this->selectColumns      = ['*'];
        $this->selectRawBindings  = [];
        $this->distinctFlag       = false;
        $this->conditions         = [];
        $this->joins              = [];
        $this->orderClauses       = [];
        $this->groupClauses       = [];
        $this->havingClauses      = [];
        $this->limitValue         = null;
        $this->offsetValue        = null;
        $this->insertData         = [];
        $this->updateData         = [];

        return $this;
    }

    /**
     * Construye la query usando placeholders (?) y retorna [$sql, $bindings].
     *
     * Recomendado sobre toSQL() cuando los valores provienen del exterior,
     * ya que delega el escapado al driver PDO/SQLSRV evitando inyección SQL.
     *
     * Ejemplo:
     *   [$sql, $bindings] = QueryBuilder::table('users')
     *       ->where('id', 5)
     *       ->toParamSQL();
     *   $pdo->prepare($sql)->execute($bindings);
     *
     * @return array{0: string, 1: list<mixed>}  [$sql, $bindings]
     */
    public function toParamSQL(): array
    {
        if ($this->table === '') {
            throw new \RuntimeException(
                'QueryBuilder: tabla no especificada. Usa QueryBuilder::table("nombre").'
            );
        }

        if ($this->queryType === '') {
            $this->queryType = 'SELECT';
        }

        $bindings = [];

        $sql = match ($this->queryType) {
            'SELECT'       => $this->buildSelectParam($bindings),
            'INSERT',
            'INSERT_BATCH' => $this->buildInsertParam($bindings),
            'UPDATE'       => $this->buildUpdateParam($bindings),
            'DELETE'       => $this->buildDeleteParam($bindings),
            default        => throw new \RuntimeException(
                "QueryBuilder: tipo de query desconocido '{$this->queryType}'."
            ),
        };

        return [$sql, $bindings];
    }

    // =========================================================================
    // Métodos privados de construcción
    // =========================================================================

    private function buildSelect(): string
    {
        $colParts = array_map(function (string $col): string {
            if (str_starts_with($col, self::RAW_PREFIX)) {
                return substr($col, strlen(self::RAW_PREFIX));
            }

            return $col === '*' ? '*' : $this->quoteIdentifier($col);
        }, $this->selectColumns);

        $cols     = implode(', ', $colParts);
        $table    = $this->quoteIdentifier($this->table);
        $distinct = $this->distinctFlag ? 'DISTINCT ' : '';

        // SQL Server con LIMIT pero sin OFFSET → TOP n
        if (
            $this->dialect === self::DIALECT_SQLSRV
            && $this->limitValue !== null
            && $this->offsetValue === null
        ) {
            $sql = "SELECT {$distinct}TOP {$this->limitValue} {$cols} FROM {$table}";
        } else {
            $sql = "SELECT {$distinct}{$cols} FROM {$table}";
        }

        $sql .= $this->buildJoins();
        $sql .= $this->buildWhere();
        $sql .= $this->buildGroupBy();
        $sql .= $this->buildHaving();
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimitOffset();

        return $sql;
    }

    private function buildInsert(): string
    {
        if (empty($this->insertData)) {
            throw new \RuntimeException('QueryBuilder: no se proporcionaron datos para INSERT.');
        }

        $rows       = $this->insertData;
        $columns    = array_keys($rows[0]);
        $quotedCols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $table      = $this->quoteIdentifier($this->table);

        $valueSets = [];

        foreach ($rows as $row) {
            $vals = [];

            foreach ($columns as $col) {
                $vals[] = $this->escapeValue($row[$col] ?? null);
            }

            $valueSets[] = '(' . implode(', ', $vals) . ')';
        }

        return "INSERT INTO {$table} ({$quotedCols}) VALUES\n"
            . implode(",\n", $valueSets);
    }

    private function buildUpdate(): string
    {
        if (empty($this->updateData)) {
            throw new \RuntimeException('QueryBuilder: no se proporcionaron datos para UPDATE.');
        }

        $table = $this->quoteIdentifier($this->table);
        $sets  = [];

        foreach ($this->updateData as $col => $val) {
            $sets[] = $this->quoteIdentifier($col) . ' = ' . $this->escapeValue($val);
        }

        return 'UPDATE ' . $table
            . ' SET ' . implode(', ', $sets)
            . $this->buildWhere();
    }

    private function buildDelete(): string
    {
        return 'DELETE FROM ' . $this->quoteIdentifier($this->table)
            . $this->buildWhere();
    }

    private function buildWhere(): string
    {
        if (empty($this->conditions)) {
            return '';
        }

        $parts = [];

        foreach ($this->conditions as $index => $cond) {
            $expr = $this->buildConditionInline($cond);

            // La primera condición nunca lleva AND/OR delante
            $parts[] = $index === 0 ? $expr : $cond['type'] . ' ' . $expr;
        }

        return ' WHERE ' . implode(' ', $parts);
    }

    private function buildConditionInline(array $cond): string
    {
        if ($cond['raw']) {
            return $cond['expr'];
        }

        $subtype = $cond['subtype'] ?? 'SIMPLE';
        $col     = $this->quoteIdentifier($cond['column']);

        if ($subtype === 'IN' || $subtype === 'NOT IN') {
            $escaped = implode(', ', array_map([$this, 'escapeValue'], $cond['values']));

            return $col . ' ' . $subtype . ' (' . $escaped . ')';
        }

        if ($subtype === 'BETWEEN') {
            return $col
                . ' BETWEEN ' . $this->escapeValue($cond['min'])
                . ' AND '     . $this->escapeValue($cond['max']);
        }

        // SIMPLE — where('col', op, value)
        if ($cond['value'] === null) {
            return $col . ' IS NULL';
        }

        return $col . ' ' . $cond['operator'] . ' ' . $this->escapeValue($cond['value']);
    }

    /**
     * Genera el fragmento JOIN.
     * Si se pasa $bindings por referencia, acumula los bindings de cada ON.
     */
    private function buildJoins(?array &$bindings = null): string
    {
        if (empty($this->joins)) {
            return '';
        }

        $parts = [];

        foreach ($this->joins as $j) {
            $parts[] = $j['sql'];

            if ($bindings !== null && ! empty($j['bindings'])) {
                array_push($bindings, ...$j['bindings']);
            }
        }

        return ' ' . implode(' ', $parts);
    }

    private function buildOrderBy(): string
    {
        return empty($this->orderClauses)
            ? ''
            : ' ORDER BY ' . implode(', ', $this->orderClauses);
    }

    private function buildGroupBy(): string
    {
        return empty($this->groupClauses)
            ? ''
            : ' GROUP BY ' . implode(', ', $this->groupClauses);
    }

    /**
     * Genera el fragmento HAVING.
     * Si se pasa $bindings por referencia, acumula los bindings de cada condición.
     */
    private function buildHaving(?array &$bindings = null): string
    {
        if (empty($this->havingClauses)) {
            return '';
        }

        $exprs = [];

        foreach ($this->havingClauses as $h) {
            $exprs[] = $h['expr'];

            if ($bindings !== null && ! empty($h['bindings'])) {
                array_push($bindings, ...$h['bindings']);
            }
        }

        return ' HAVING ' . implode(' AND ', $exprs);
    }

    private function buildLimitOffset(): string
    {
        if ($this->dialect === self::DIALECT_SQLSRV) {
            // TOP ya fue gestionado en buildSelect cuando no hay OFFSET
            if ($this->offsetValue === null) {
                return '';
            }

            // OFFSET … FETCH requiere ORDER BY en SQL Server
            $extra = empty($this->orderClauses) ? ' ORDER BY (SELECT NULL)' : '';

            $sql = $extra . " OFFSET {$this->offsetValue} ROWS";

            if ($this->limitValue !== null) {
                $sql .= " FETCH NEXT {$this->limitValue} ROWS ONLY";
            }

            return $sql;
        }

        // MySQL / genérico
        $sql = '';

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        return $sql;
    }

    // =========================================================================
    // Métodos privados de construcción — modo parametrizado
    // =========================================================================

    private function buildSelectParam(array &$bindings): string
    {
        // 1. Recolectar bindings de expresiones selectRaw, en orden de aparición
        $rawIndex = 0;

        $colParts = array_map(function (string $col) use (&$bindings, &$rawIndex): string {
            if (str_starts_with($col, self::RAW_PREFIX)) {
                $rawBindings = $this->selectRawBindings[$rawIndex++] ?? [];

                if (! empty($rawBindings)) {
                    array_push($bindings, ...$rawBindings);
                }

                return substr($col, strlen(self::RAW_PREFIX));
            }

            return $col === '*' ? '*' : $this->quoteIdentifier($col);
        }, $this->selectColumns);

        $cols     = implode(', ', $colParts);
        $table    = $this->quoteIdentifier($this->table);
        $distinct = $this->distinctFlag ? 'DISTINCT ' : '';

        if (
            $this->dialect === self::DIALECT_SQLSRV
            && $this->limitValue !== null
            && $this->offsetValue === null
        ) {
            $sql = "SELECT {$distinct}TOP {$this->limitValue} {$cols} FROM {$table}";
        } else {
            $sql = "SELECT {$distinct}{$cols} FROM {$table}";
        }

        // 2. JOIN bindings
        $sql .= $this->buildJoins($bindings);
        // 3. WHERE bindings
        $sql .= $this->buildWhereParam($bindings);
        $sql .= $this->buildGroupBy();
        // 4. HAVING bindings
        $sql .= $this->buildHaving($bindings);
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimitOffset();

        return $sql;
    }

    private function buildInsertParam(array &$bindings): string
    {
        if (empty($this->insertData)) {
            throw new \RuntimeException('QueryBuilder: no se proporcionaron datos para INSERT.');
        }

        $rows       = $this->insertData;
        $columns    = array_keys($rows[0]);
        $quotedCols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $table      = $this->quoteIdentifier($this->table);

        $valueSets = [];

        foreach ($rows as $row) {
            $placeholders = [];

            foreach ($columns as $col) {
                $val = $row[$col] ?? null;

                if ($val === null) {
                    $placeholders[] = 'NULL';
                } else {
                    $placeholders[] = '?';
                    $bindings[]     = $val;
                }
            }

            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
        }

        return "INSERT INTO {$table} ({$quotedCols}) VALUES\n"
            . implode(",\n", $valueSets);
    }

    private function buildUpdateParam(array &$bindings): string
    {
        if (empty($this->updateData)) {
            throw new \RuntimeException('QueryBuilder: no se proporcionaron datos para UPDATE.');
        }

        $table = $this->quoteIdentifier($this->table);
        $sets  = [];

        foreach ($this->updateData as $col => $val) {
            if ($val === null) {
                $sets[] = $this->quoteIdentifier($col) . ' = NULL';
            } else {
                $sets[]     = $this->quoteIdentifier($col) . ' = ?';
                $bindings[] = $val;
            }
        }

        return 'UPDATE ' . $table
            . ' SET ' . implode(', ', $sets)
            . $this->buildWhereParam($bindings);
    }

    private function buildDeleteParam(array &$bindings): string
    {
        return 'DELETE FROM ' . $this->quoteIdentifier($this->table)
            . $this->buildWhereParam($bindings);
    }

    private function buildWhereParam(array &$bindings): string
    {
        if (empty($this->conditions)) {
            return '';
        }

        $parts = [];

        foreach ($this->conditions as $index => $cond) {
            $expr = $this->buildConditionParam($cond, $bindings);

            $parts[] = $index === 0 ? $expr : $cond['type'] . ' ' . $expr;
        }

        return ' WHERE ' . implode(' ', $parts);
    }

    private function buildConditionParam(array $cond, array &$bindings): string
    {
        // Expresiones raw: si tienen bindings declarados, los recolectamos.
        if ($cond['raw']) {
            if (! empty($cond['bindings'])) {
                array_push($bindings, ...$cond['bindings']);
            }

            return $cond['expr'];
        }

        $subtype = $cond['subtype'] ?? 'SIMPLE';
        $col     = $this->quoteIdentifier($cond['column']);

        if ($subtype === 'IN' || $subtype === 'NOT IN') {
            $placeholders = array_fill(0, count($cond['values']), '?');
            array_push($bindings, ...$cond['values']);

            return $col . ' ' . $subtype . ' (' . implode(', ', $placeholders) . ')';
        }

        if ($subtype === 'BETWEEN') {
            $bindings[] = $cond['min'];
            $bindings[] = $cond['max'];

            return $col . ' BETWEEN ? AND ?';
        }

        // SIMPLE
        if ($cond['value'] === null) {
            return $col . ' IS NULL';
        }

        $bindings[] = $cond['value'];

        return $col . ' ' . $cond['operator'] . ' ?';
    }

    // =========================================================================
    // Utilidades privadas
    // =========================================================================

    /**
     * Normaliza la forma (operador, valor) desde los argumentos de where().
     *
     * where('col', 'val')       → ['=', 'val']
     * where('col', '>', 10)     → ['>', 10]
     */
    private function parseOperatorValue(mixed $operatorOrValue, mixed $value): array
    {
        $validOperators = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

        if (func_num_args() === 1 || $value === null && ! is_string($operatorOrValue)) {
            // Forma de dos argumentos: where('col', 'val')
            return ['=', $operatorOrValue];
        }

        // Detectar forma 3-arg: where('col', '=', null) → IS NULL
        $op = strtoupper(trim((string) $operatorOrValue));

        if (in_array($op, $validOperators, true)) {
            return [$op, $value];
        }

        // Si el operador no es válido en forma 3-arg, es forma 2-arg
        if ($value === null) {
            return ['=', $operatorOrValue];
        }

        throw new \InvalidArgumentException(
            "QueryBuilder: operador '{$operatorOrValue}' no válido. "
            . 'Usa: ' . implode(', ', $validOperators)
        );
    }

    /**
     * Escapa y cita un identificador (tabla / columna).
     * Soporta notación tabla.columna.
     */
    private function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        // Notación tabla.columna
        if (str_contains($identifier, '.')) {
            return implode(
                '.',
                array_map([$this, 'quoteSingleIdentifier'], explode('.', $identifier))
            );
        }

        return $this->quoteSingleIdentifier($identifier);
    }

    /**
     * Cita un identificador simple según el dialecto.
     */
    private function quoteSingleIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        // No citar: *, identificadores ya citados
        if (
            $identifier === '*'
            || str_starts_with($identifier, '[')
            || str_starts_with($identifier, '`')
            || str_starts_with($identifier, '"')
        ) {
            return $identifier;
        }

        // Alias explícito: "tabla AS alias" o "columna AS alias"
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $identifier, $m)) {
            return $this->wrapIdent(trim($m[1])) . ' AS ' . $this->wrapIdent(trim($m[2]));
        }

        // Alias implícito: "tabla alias" (2 palabras, sin punto)
        if (preg_match('/^(\S+)\s+(\S+)$/', $identifier, $m)) {
            return $this->wrapIdent($m[1]) . ' ' . $this->wrapIdent($m[2]);
        }

        return $this->wrapIdent($identifier);
    }

    /**
     * Envuelve un identificador simple con los delimitadores del dialecto.
     */
    private function wrapIdent(string $name): string
    {
        return match ($this->dialect) {
            self::DIALECT_SQLSRV => '[' . str_replace(']', ']]', $name) . ']',
            default              => '`' . str_replace('`', '``', $name) . '`',
        };
    }

    /**
     * Escapa un valor para su inclusión inline en la query.
     *
     * - null       → NULL
     * - bool       → 1 | 0
     * - int/float  → número sin comillas
     * - string     → 'valor' con comillas simples escapadas
     */
    private function escapeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Escapar comillas simples duplicándolas (estándar SQL)
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
