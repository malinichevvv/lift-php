<?php

declare(strict_types=1);

namespace Lift\Exception;

use Psr\Container\NotFoundExceptionInterface;

class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface {}
