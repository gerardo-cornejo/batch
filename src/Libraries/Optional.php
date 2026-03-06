<?php

namespace Innite\Batch\Libraries;

use Exception;
use InvalidArgumentException;
use RuntimeException;

class Optional
{
    /**
     * The contained value (if any)
     *
     * @var mixed
     */
    private $value;

    /**
     * Singleton instance for empty Optional
     *
     * @var Optional|null
     */
    private static $EMPTY = null;

    /**
     * Private constructor. Use static factories.
     *
     * @param mixed $value
     */
    private function __construct($value = null)
    {
        $this->value = $value;
    }

    /**
     * Returns an empty Optional instance (singleton).
     *
     * @return Optional
     */
    public static function empty(): Optional
    {
        if (self::$EMPTY === null) {
            self::$EMPTY = new Optional();
        }

        return self::$EMPTY;
    }

    /**
     * Returns an Optional with the specified non-null value.
     *
     * @param mixed $value
     * @return Optional
     * @throws InvalidArgumentException if $value is null
     */
    public static function of($value): Optional
    {
        if ($value === null) {
            throw new InvalidArgumentException('Optional::of() no acepta null. Use ofNullable() en su lugar.');
        }

        return new Optional($value);
    }

    /**
     * Returns an Optional describing the specified value, or an empty Optional if the value is null.
     *
     * @param mixed $value
     * @return Optional
     */
    public static function ofNullable($value): Optional
    {
        if ($value === null) {
            return self::empty();
        }

        return new Optional($value);
    }

    /**
     * Returns true if there is a value present, otherwise false.
     *
     * @return bool
     */
    public function isPresent(): bool
    {
        return $this->value !== null;
    }

    /**
     * Returns true if there is no value present.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return !$this->isPresent();
    }

    /**
     * If a value is present, returns the value, otherwise throws an exception.
     *
     * @return mixed
     * @throws RuntimeException if no value present
     */
    public function get()
    {
        if ($this->value === null) {
            throw new RuntimeException('No value present');
        }

        return $this->value;
    }

    /**
     * If a value is present, invoke the specified consumer with the value.
     *
     * @param callable $consumer function($value): void
     * @return void
     */
    public function ifPresent(callable $consumer): void
    {
        if ($this->isPresent()) {
            $consumer($this->value);
        }
    }

    /**
     * If a value is present, apply the provided mapping function to it, and if the result is non-null, return an Optional describing the result.
     *
     * @param callable $mapper function($value)
     * @return Optional
     */
    public function map(callable $mapper): Optional
    {
        if ($this->isEmpty()) {
            return self::empty();
        }

        $result = $mapper($this->value);

        return self::ofNullable($result);
    }

    /**
     * If a value is present, apply the provided Optional-bearing mapping function to it, return that result, otherwise return an empty Optional.
     *
     * @param callable $mapper function($value): Optional
     * @return Optional
     * @throws InvalidArgumentException if mapper does not return an Optional
     */
    public function flatMap(callable $mapper): Optional
    {
        if ($this->isEmpty()) {
            return self::empty();
        }

        $result = $mapper($this->value);

        if (!($result instanceof Optional)) {
            throw new InvalidArgumentException('El mapeador pasado a flatMap debe devolver una instancia de Optional');
        }

        return $result;
    }

    /**
     * If a value is present, and the value matches the given predicate, return an Optional describing the value, otherwise return an empty Optional.
     *
     * @param callable $predicate function($value): bool
     * @return Optional
     */
    public function filter(callable $predicate): Optional
    {
        if ($this->isEmpty()) {
            return self::empty();
        }

        $keep = (bool)$predicate($this->value);

        return $keep ? $this : self::empty();
    }

    /**
     * Return the value if present, otherwise return $other.
     *
     * @param mixed $other
     * @return mixed
     */
    public function orElse($other)
    {
        return $this->isPresent() ? $this->value : $other;
    }

    /**
     * Return the value if present, otherwise invoke $supplier and return the result.
     *
     * @param callable $supplier function(): mixed
     * @return mixed
     */
    public function orElseGet(callable $supplier)
    {
        return $this->isPresent() ? $this->value : $supplier();
    }

    /**
     * Return the contained value, if present, otherwise throw an exception created by the provided supplier.
     *
     * @param callable|null $exceptionSupplier function(): \Throwable
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function orElseThrow(callable $exceptionSupplier): mixed
    {
        if ($this->isPresent()) {
            return $this->value;
        }

        if ($exceptionSupplier === null) {
            throw new InvalidArgumentException('No value present');
        }

        $ex = $exceptionSupplier();

        if ($ex instanceof InvalidArgumentException) {
            throw $ex;
        }

        throw new InvalidArgumentException('El supplier proporcionado a orElseThrow no devolvió una excepción');
    }

    /**
     * Returns string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->isPresent()) {
            return 'Optional[' . var_export($this->value, true) . ']';
        }

        return 'Optional.empty';
    }
}
