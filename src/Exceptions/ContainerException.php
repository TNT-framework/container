<?php

namespace TNT\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements NotFoundExceptionInterface
{

}
