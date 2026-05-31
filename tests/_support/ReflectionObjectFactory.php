<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ReflectionClass;

/**
 * Builds objects without calling their constructor so unit tests can inject
 * Mockery doubles for final dependencies (PHP 8.4 rejects those at ctor boundaries).
 */
final class ReflectionObjectFactory
{
    /**
     * @template T of object
     *
     * @param class-string<T> $className
     * @param array<string, mixed> $properties
     *
     * @return T
     */
    public static function create(string $className, array $properties): object
    {
        $ref = new ReflectionClass($className);
        $instance = $ref->newWithoutConstructor();

        foreach ($properties as $name => $value) {
            $property = $ref->getProperty($name);
            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
