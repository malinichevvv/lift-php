<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Generates application skeleton classes.
 *
 * The command is intentionally dependency-free and writes plain PHP classes into
 * the current project. It supports the most common Lift building blocks:
 * controllers, form requests, JSON resources, models, and middleware.
 */
final class MakeCommand extends Command
{
    /** @param 'controller'|'request'|'resource'|'model'|'middleware' $type */
    public function __construct(private readonly string $type) {}

    public function getName(): string
    {
        return 'make:' . $this->type;
    }

    public function getDescription(): string
    {
        return 'Create a new ' . $this->type . ' class';
    }

    public function getHelp(): string
    {
        return 'Usage: lift ' . $this->getName() . ' Name [--namespace=App\\...] [--path=src]';
    }

    public function execute(Input $input, Output $output): int
    {
        $name = $input->getArgument(0);
        if ($name === '') {
            $output->error('Class name is required.');
            return 1;
        }

        $namespace = (string) $input->getOption('namespace', $this->defaultNamespace());
        $basePath = rtrim((string) $input->getOption('path', getcwd() . '/src'), '/');
        $class = $this->className($name);
        $target = $basePath . '/' . str_replace('\\', '/', trim($namespace, '\\')) . '/' . $class . '.php';

        if (file_exists($target)) {
            $output->error("File already exists: {$target}");
            return 1;
        }

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            $output->error('Unable to create directory: ' . dirname($target));
            return 1;
        }

        file_put_contents($target, $this->stub($namespace, $class));
        $output->success("Created {$target}");
        return 0;
    }

    private function defaultNamespace(): string
    {
        return match ($this->type) {
            'controller' => 'App\\Http\\Controllers',
            'request' => 'App\\Http\\Requests',
            'resource' => 'App\\Http\\Resources',
            'model' => 'App\\Models',
            'middleware' => 'App\\Http\\Middleware',
        };
    }

    private function className(string $name): string
    {
        $name = trim(str_replace('/', '\\', $name), '\\');
        return basename(str_replace('\\', '/', $name));
    }

    private function stub(string $namespace, string $class): string
    {
        return match ($this->type) {
            'controller' => $this->controllerStub($namespace, $class),
            'request' => $this->requestStub($namespace, $class),
            'resource' => $this->resourceStub($namespace, $class),
            'model' => $this->modelStub($namespace, $class),
            'middleware' => $this->middlewareStub($namespace, $class),
        };
    }

    private function controllerStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Lift\Http\Controller;
use Lift\Http\Request;
use Lift\Http\Response;

final class {$class} extends Controller
{
    public function __invoke(Request \$request): Response
    {
        return \$this->json(['message' => '{$class}']);
    }
}
PHP;
    }

    private function requestStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Lift\Http\FormRequest;

final class {$class} extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'email' => 'required|email',
        ];
    }
}
PHP;
    }

    private function resourceStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Lift\Http\JsonResource;

final class {$class} extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => \$this->value('id'),
        ];
    }
}
PHP;
    }

    private function modelStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Lift\Database\Model;

final class {$class} extends Model
{
    protected static string \$table = '';

    protected array \$fillable = [];
}
PHP;
    }

    private function middlewareStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class {$class} implements MiddlewareInterface
{
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        return \$handler->handle(\$request);
    }
}
PHP;
    }
}
