<?php

namespace TNT\Container;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use TNT\Container\Exceptions\ContainerException;

/**
 * @phpstan-type InjectionEntity
 */
class Container implements ContainerInterface
{

	protected const INJECTION_TYPE_TRANSIENT = 1;
	protected const INJECTION_TYPE_SINGLETON = 2;
	protected const INJECTION_TYPE_CLOSURE   = 3;

	/** @var list<string, array{concrete: mixed, injectionType: self::INJECTION_TYPE_TRANSIENT|self::INJECTION_TYPE_SINGLETON|self::INJECTION_TYPE_CLOSURE, instance?: mixed}> */
	protected array $entryList = [];


	/** @inheritDoc */
	public function has(string $id): bool
	{
		return isset($this->entryList[$id]);
	}


	/** @inheritDoc
	 *
	 * @template T of object
	 * @param class-string<T>|Closure $id Abstract id = interface name, class name, Closure or any custom id
	 *
	 * @return T Concrete class instance or Closure or custom value for custom id
	 * @throws ContainerExceptionInterface
	 */
	public function get($id)
	{
		if ($id instanceof Closure) {
			return $id;
		}

		if (!$this->has($id)) {
			throw new ContainerException("Can not find container entry with id $id.");
		}

		$entry = $this->entryList[$id];

		$injectionType = $entry['injectionType'];
		if (isset($entry['instance']) && $injectionType === self::INJECTION_TYPE_SINGLETON) {
			return $entry['instance'];
		}

		$concreteClassName = $entry['concrete'];
		if (is_object($concreteClassName) && $injectionType === self::INJECTION_TYPE_TRANSIENT) {
			return clone $concreteClassName;
		}

		if (is_string($concreteClassName) && class_exists($concreteClassName)) {
			$concreteClassName = $this->createInstance($concreteClassName);
		} elseif (is_callable($concreteClassName) && $injectionType !== self::INJECTION_TYPE_CLOSURE) {
			$concreteClassName = $this->call($concreteClassName);
		}

		if ($injectionType === self::INJECTION_TYPE_SINGLETON) {
			$this->entryList[$id]['instance'] = $concreteClassName;
		}

		return $concreteClassName;
	}


	/**
	 * Add abstract id to concrete class mapping as transient (new instance on each get call)
	 *
	 * @param string $id       Abstract id = interface name, class name or any custom id
	 * @param mixed  $concrete If not set, same class/value as $id is used, useful for registering concrete classes
	 *
	 * @return $this
	 */
	public function registerTransient(string $id, $concrete = null): self
	{
		$this->entryList[$id] = ['concrete' => $concrete ?? $id, 'injectionType' => self::INJECTION_TYPE_TRANSIENT, 'instance' => null];
		return $this;
	}


	/**
	 * Add abstract id to concrete class mapping as singleton (re-use single instance)
	 *
	 * @param string $id       Abstract id = interface name, class name or any custom id
	 * @param mixed  $concrete If not set, same class/value as $id is used, useful for registering concrete classes
	 *
	 * @return $this
	 */
	public function registerSingleton(string $id, $concrete = null): self
	{
		$this->entryList[$id] = ['concrete' => $concrete ?? $id, 'injectionType' => self::INJECTION_TYPE_SINGLETON, 'instance' => null];
		return $this;
	}


	/**
	 * Add abstract id to closure mapping
	 *
	 * @param string  $id Abstract id = interface name, class name or any custom id
	 * @param Closure $closure
	 *
	 * @return $this
	 */
	public function registerClosure(string $id, Closure $closure): self
	{
		$this->entryList[$id] = ['concrete' => $closure, 'injectionType' => self::INJECTION_TYPE_CLOSURE, 'instance' => null];
		return $this;
	}


	/**
	 * Remove abstract id - concrete class mapping from container
	 *
	 * @param string $id Abstract id = interface name, class name or any custom id
	 *
	 * @return $this
	 */
	public function unregister(string $id): self
	{
		unset($this->entryList[$id]);
		return $this;
	}


	/**
	 * @param class-string $className
	 *
	 * @return mixed
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	protected function createInstance(string $className)
	{
		try {
			$reflection = new ReflectionClass($className);

			$params = [];
			if ($reflection->hasMethod('__construct')) {
				$constructMethod = $reflection->getMethod('__construct');
				$constructMethodParams = $constructMethod->getParameters();
				$params = $this->resolveMethodParams($constructMethodParams);
			}

			return count($params) ? $reflection->newInstanceArgs($params) : new $className;
		} catch (ReflectionException $e) {
			throw new ContainerException("dependency injection class $className failed to instantiate", 0, $e);
		}
	}


	/**
	 * @param ReflectionParameter[] $reflectionParams
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface|ReflectionException
	 */
	protected function resolveMethodParams(array $reflectionParams = []): array
	{
		$params = [];

		foreach ($reflectionParams as $param) {
			$paramType = $param->getType();
			if (!$paramType) {
				continue;
			}

			$paramTypeName = $paramType->getName();
			$paramIsClass = class_exists($paramTypeName) || interface_exists($paramTypeName);
			$params[] = $paramIsClass ? $this->get($paramTypeName) : $param->getDefaultValue();
		}

		return $params;
	}


	/**
	 * @param callable $callable
	 *
	 * @return mixed
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	protected function call(callable $callable)
	{
		try {
			$reflection = is_array($callable) ? new ReflectionMethod($callable[0], $callable[1]) : new ReflectionFunction($callable);
			$reflectionParams = $reflection->getParameters();
			$params = $this->resolveMethodParams($reflectionParams);
			return call_user_func_array($callable, $params);
		} catch (ReflectionException $e) {
			throw new ContainerException('container entry CALL failed', 0, $e);
		}
	}

}
