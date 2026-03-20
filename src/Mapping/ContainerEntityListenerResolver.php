<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Mapping;

use function gettype;
use InvalidArgumentException;
use function is_object;
use Psr\Container\Container_Interface;
use RuntimeException;
use function sprintf;
use function trim;
/** @final */
class Container_Entity_Listener_Resolver implements Entity_Listener_Service_Resolver
{
    /** @var object[] Map to store entity listener instances. */
    private array $instances = [];
    /** @var string[] Map to store registered service ids */
    private array $service_ids = [];
    /** @param ContainerInterface $container a service locator for listeners */
    public function __construct(private readonly Container_Interface $container)
    {
    }
    /**
     * {@inheritDoc}
     */
    public function clear($class_name = null): void
    {
        if ($class_name === null) {
            $this->instances = [];
            return;
        }
        $class_name = $this->normalize_class_name($class_name);
        unset($this->instances[$class_name]);
    }
    /**
     * {@inheritDoc}
     *
     * @param mixed $object
     */
    public function register($object): void
    {
        if (!is_object($object)) {
            throw new InvalidArgumentException(sprintf('An object was expected, but got "%s".', gettype($object)));
        }
        $class_name = $this->normalize_class_name($object::class);
        $this->instances[$class_name] = $object;
    }
    /**
     * {@inheritDoc}
     *
     * @param string $className
     * @param string $serviceId
     */
    public function register_service($class_name, $service_id): void
    {
        $this->service_ids[$this->normalize_class_name($class_name)] = $service_id;
    }
    /**
     * {@inheritDoc}
     */
    public function resolve($class_name): object
    {
        $class_name = $this->normalize_class_name($class_name);
        if (!isset($this->instances[$class_name])) {
            if (isset($this->service_ids[$class_name])) {
                $this->instances[$class_name] = $this->resolve_service($this->service_ids[$class_name]);
            } else {
                $this->instances[$class_name] = new $class_name();
            }
        }
        return $this->instances[$class_name];
    }
    private function resolve_service(string $service_id): object
    {
        if (!$this->container->has($service_id)) {
            throw new RuntimeException(sprintf('There is no service named "%s"', $service_id));
        }
        return $this->container->get($service_id);
    }
    private function normalize_class_name(string $class_name): string
    {
        return trim($class_name, '\\');
    }
}