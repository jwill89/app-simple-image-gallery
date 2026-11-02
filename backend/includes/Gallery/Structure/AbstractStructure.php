<?php

namespace Gallery\Structure;

use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Abstract class AbstractStructure
 *
 * This abstract class implements the JsonSerializable interface and provides
 * a generic constructor and methods for setting properties and serializing
 * the object to JSON.
 */
abstract class AbstractStructure implements JsonSerializable
{
    /**
     * Cached ReflectionProperty lists keyed by concrete class name, so each
     * class is reflected only once rather than on every jsonSerialize() call.
     *
     * @var array<class-string, \ReflectionProperty[]>
     */
    private static array $propertyCache = [];

    /**
     * Constructor method
     *
     * @param array<string, mixed> $params An associative array of properties to set on the object.
     */
    public function __construct(array $params = [])
    {
        $this->setProperties($params);
    }

    /**
     * Set properties method
     *
     * This method sets the properties of the object based on the provided associative array.
     *
     * @param array<string, mixed> $params An associative array of properties to set on the object.
     */
    public function setProperties(array $params = []): void
    {
        foreach ($params as $property => $value) {
            if (property_exists($this, $property)) {
                // Assign via reflection: properties are private to the concrete
                // subclass, so a direct `$this->$property =` from this parent
                // scope would be rejected. Reflection writes them across scope.
                new ReflectionProperty($this, $property)->setValue($this, $value);
            }
        }
    }

    /**
     * JSON serialize method
     *
     * This method uses ReflectionClass to get the properties of the object and
     * adds them to an associative array which is returned.
     *
     * @return array<string, mixed> An associative array of the object's properties.
     */
    public function jsonSerialize(): array
    {
        $class = static::class;

        self::$propertyCache[$class] ??= new ReflectionClass($this)->getProperties();

        $data = [];
        foreach (self::$propertyCache[$class] as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
