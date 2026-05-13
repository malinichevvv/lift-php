<?php

declare(strict_types=1);

namespace Lift;

use Lift\Config\Config;
use Lift\Config\Dotenv;
use Lift\Config\Env;
use Lift\Container\Container;
use Lift\Database\Connection;
use Lift\Debug\DebugCollector;
use Lift\Debug\DebugConfig;
use Lift\Debug\DebugToolbarMiddleware;
use Lift\Debug\DebugToolbarRenderer;
use Lift\Debug\ErrorHandler;
use Lift\Events\EventDispatcher;
use Lift\Exception\HttpException;
use Lift\Http\SseResponse;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\JsonRpc\JsonRpcServer;
use Lift\Log\Logger;
use Lift\Pipeline\Pipeline;
use Lift\Queue\JobInterface;
use Lift\Queue\QueueInterface;
use Lift\Queue\SyncQueue;
use Lift\Routing\AttributeLoader;
use Lift\Routing\Route;
use Lift\Routing\RouteGroup;
use Lift\Routing\Router;
use Lift\Validation\ValidationException;
use Lift\View\ViewFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The Lift application — central entry point to the framework.
 *
 * Provides a fluent API for routing, DI container configuration, middleware
 * registration, queue management, and attribute-based controller loading.
 *
 * ```php
 * $app = new App();
 *
 * $app->get('/users/{id}', function (Request $req) {
 *     return Response::json(['id' => $req->param('id')]);
 * });
 *
 * $app->run();
 * ```
 */
final class App
{
    private readonly Container $container;
    private readonly Router    $router;

    /** @var array<MiddlewareInterface|string> Global middleware stack. */
    private array $middleware = [];

    /** @var callable|null Custom error handler. */
    private $errorHandler = null;

    private Config $config;
    private ?DebugConfig $debugConfig = null;
    private ?DebugCollector $debugCollector = null;
    private ?ErrorHandler $debugErrorHandler = null;

    /**
     * @param Container|null $container Custom DI container. Defaults to a fresh {@see Container}.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->router    = new Router($this->container);
        $this->config    = new Config();

        // Register core singletons so they can be injected anywhere
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(App::class, $this);
        $this->container->instance(Config::class, $this->config);
        $this->container->singleton(ViewFactory::class, fn() => new ViewFactory(getcwd() . '/views'));

        // Default queue driver — sync (executes immediately in current process)
        $this->container->singleton(QueueInterface::class, fn() => new SyncQueue());
    }

    public function __destruct()
    {
        $this->debugErrorHandler?->restorePhpHandlers();
    }

    // -----------------------------------------------------------------
    // Routing
    // -----------------------------------------------------------------

    /**
     * Register a GET route.
     *
     * @param  string $path    URL pattern, e.g. "/users/{id:\d+}".
     * @param  mixed  $handler Closure, `[ClassName::class, 'method']`, or invokable class name.
     */
    public function get(string $path, mixed $handler): Route
    {
        return $this->router->add('GET', $path, $handler);
    }

    /** Register a POST route. */
    public function post(string $path, mixed $handler): Route
    {
        return $this->router->add('POST', $path, $handler);
    }

    /** Register a PUT route. */
    public function put(string $path, mixed $handler): Route
    {
        return $this->router->add('PUT', $path, $handler);
    }

    /** Register a PATCH route. */
    public function patch(string $path, mixed $handler): Route
    {
        return $this->router->add('PATCH', $path, $handler);
    }

    /** Register a DELETE route. */
    public function delete(string $path, mixed $handler): Route
    {
        return $this->router->add('DELETE', $path, $handler);
    }

    /**
     * Register a route that responds to all standard HTTP verbs.
     *
     * @see map() for a custom subset of methods.
     */
    public function any(string $path, mixed $handler): Route
    {
        return $this->router->add(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            $path,
            $handler,
        );
    }

    /**
     * Register a route for a specific set of HTTP verbs.
     *
     * @param list<string> $methods HTTP verbs (case-insensitive).
     */
    public function map(array $methods, string $path, mixed $handler): Route
    {
        return $this->router->add($methods, $path, $handler);
    }

    /**
     * Create a route group sharing a common URL prefix.
     *
     * ```php
     * $app->group('/api/v1', function ($g) {
     *     $g->get('/users', [UserController::class, 'index']);
     * })->middleware(AuthMiddleware::class);
     * ```
     */
    public function group(string $prefix, callable $callback): RouteGroup
    {
        return $this->router->group($prefix, $callback);
    }

    /**
     * Generate a URL for a named route.
     *
     * @param  string               $name   Route name as set by {@see Route::name()}.
     * @param  array<string, mixed> $params Parameter values to substitute in the pattern.
     * @throws \RuntimeException If the named route does not exist.
     */
    public function url(string $name, array $params = []): string
    {
        return $this->router->url($name, $params);
    }

    // -----------------------------------------------------------------
    // Attribute-based controller loading
    // -----------------------------------------------------------------

    /**
     * Scan one or more controller classes for route attributes and register
     * their routes automatically.
     *
     * ```php
     * $app->loadControllers(UserController::class, PostController::class);
     * ```
     *
     * @param  class-string ...$controllers
     * @throws \ReflectionException
     */
    public function loadControllers(string ...$controllers): self
    {
        $loader = new AttributeLoader($this->router, $this->container);
        $loader->loadMany(array_values($controllers));
        return $this;
    }

    // -----------------------------------------------------------------
    // JSON-RPC
    // -----------------------------------------------------------------

    /**
     * Mount a {@see JsonRpcServer} on a POST route.
     *
     * ```php
     * $rpc = new JsonRpcServer($app->container());
     * $rpc->register('ping', fn() => 'pong');
     * $app->rpc('/rpc', $rpc);
     * ```
     */
    public function rpc(string $path, JsonRpcServer $server): Route
    {
        return $this->router->add(['GET', 'POST'], $path, $server);
    }

    // -----------------------------------------------------------------
    // Global middleware
    // -----------------------------------------------------------------

    /**
     * Add one or more middleware to the global stack.
     *
     * Middleware runs for every request in the order added.
     * Pass class names to have them resolved from the DI container.
     *
     * @param  MiddlewareInterface|string ...$middleware
     */
    public function use(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    // -----------------------------------------------------------------
    // DI Container
    // -----------------------------------------------------------------

    /**
     * Bind an abstract type to a concrete factory or class name.
     *
     * @param  string          $abstract Interface or class name.
     * @param  callable|string $concrete Factory callable or concrete class name.
     */
    public function bind(string $abstract, callable|string $concrete): self
    {
        $this->container->bind($abstract, $concrete);
        return $this;
    }

    /**
     * Bind an abstract type as a singleton (resolved once, reused).
     *
     * @param  string               $abstract  Interface or class name.
     * @param  callable|string|null $concrete  Factory or class. If null, the abstract is autowired.
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): self
    {
        $this->container->singleton($abstract, $concrete);
        return $this;
    }

    /**
     * Register a pre-built object instance.
     *
     * @param string $abstract Interface or class name to bind to.
     * @param mixed  $instance The object instance.
     */
    public function instance(string $abstract, mixed $instance): self
    {
        $this->container->instance($abstract, $instance);
        return $this;
    }

    /**
     * Resolve an abstract from the container.
     *
     * @template T
     * @param  class-string<T>      $abstract
     * @param  array<string, mixed> $overrides Named constructor argument overrides.
     * @return T
     */
    public function make(string $abstract, array $overrides = []): mixed
    {
        return $this->container->make($abstract, $overrides);
    }

    /** Return the underlying DI container. */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Merge configuration from an array, a PHP file, or a YAML file.
     *
     * @param array<string, mixed>|string $config
     */
    public function config(array|string $config): self
    {
        $loaded = is_string($config) ? Config::fromFile($config) : Config::fromArray($config);
        $this->config->merge($loaded->all());
        return $this;
    }

    /** Return the application configuration repository. */
    public function configuration(): Config
    {
        return $this->config;
    }

    /** Load environment variables from a .env file. */
    public function loadEnv(string $path = '.env', bool $overwrite = false): self
    {
        Dotenv::load($path, $overwrite);
        return $this;
    }

    /** Return the current application environment name. */
    public function environment(): string
    {
        return (string) Env::get('APP_ENV', $this->config->get('app.env', 'production'));
    }

    /** Configure and return the view factory. */
    public function views(?string $path = null, string $extension = 'php', string $assetBase = '/assets'): ViewFactory
    {
        if ($path !== null) {
            $this->container->instance(ViewFactory::class, new ViewFactory($path, $extension, $assetBase));
        }

        return $this->container->make(ViewFactory::class);
    }

    /** Render a view into an HTML response. */
    public function view(string $view, array $data = [], ?string $layout = null, int $status = 200): Response
    {
        return $this->views()->response($view, $data, $layout, $status);
    }

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    /**
     * Replace the default {@see SyncQueue} with a custom queue driver.
     *
     * ```php
     * $app->setQueue(new RedisQueue(new RedisClient()));
     * ```
     */
    public function setQueue(QueueInterface $queue): self
    {
        $this->container->instance(QueueInterface::class, $queue);
        return $this;
    }

    /**
     * Return the configured queue driver.
     */
    public function queue(): QueueInterface
    {
        return $this->container->make(QueueInterface::class);
    }

    /**
     * Dispatch a job to the queue.
     *
     * Shorthand for `$app->queue()->push($job)`.
     *
     * @return string The job ID assigned by the driver.
     */
    public function dispatch(JobInterface $job): string
    {
        return $this->queue()->push($job);
    }

    // -----------------------------------------------------------------
    // Error handling
    // -----------------------------------------------------------------

    /**
     * Register a custom error handler.
     *
     * The callable receives `(\Throwable $e, Request $request)` and must return
     * a {@see Response}.
     *
     * ```php
     * $app->onError(function (\Throwable $e, Request $req) {
     *     if ($e instanceof NotFoundException) {
     *         return Response::html('<h1>404</h1>', 404);
     *     }
     *     return Response::json(['error' => $e->getMessage()], 500);
     * });
     * ```
     */
    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;
        if ($this->debugErrorHandler !== null) {
            $this->debugErrorHandler->fallback($handler);
        }
        return $this;
    }

    /**
     * Enable and configure the debug toolbar and debug-aware error handling.
     *
     * @param array<string, mixed>|bool $config
     */
    public function debug(array|bool $config = true): self
    {
        $items = is_bool($config) ? ['enabled' => $config] : $config;
        $this->debugConfig = DebugConfig::fromArray($items);
        $this->debugCollector = new DebugCollector($this->debugConfig);
        $renderer = new DebugToolbarRenderer($this->debugConfig);
        $this->debugErrorHandler = new ErrorHandler($this->debugConfig, $this->debugCollector);

        if ($this->errorHandler !== null) {
            $this->debugErrorHandler->fallback($this->errorHandler);
        }

        $this->container->instance(DebugConfig::class, $this->debugConfig);
        $this->container->instance(DebugCollector::class, $this->debugCollector);
        $this->container->instance(DebugToolbarRenderer::class, $renderer);
        $this->container->instance(ErrorHandler::class, $this->debugErrorHandler);

        if ($this->debugConfig->enabled()) {
            $this->use(new DebugToolbarMiddleware($this->debugConfig, $this->debugCollector, $renderer));
            $this->debugErrorHandler->trackPhpErrors();
        }

        return $this;
    }

    /** Return the debug error handler, creating it with disabled defaults when necessary. */
    public function debugErrorHandler(): ErrorHandler
    {
        if ($this->debugErrorHandler === null) {
            $this->debug(false);
        }

        return $this->debugErrorHandler;
    }

    /**
     * Register an exception-specific renderer.
     *
     * @param class-string $exceptionClass
     */
    public function onException(string $exceptionClass, callable $handler): self
    {
        $this->debugErrorHandler()->render($exceptionClass, $handler);
        return $this;
    }

    // -----------------------------------------------------------------
    // Dispatch & emit
    // -----------------------------------------------------------------

    /**
     * Dispatch the request through the middleware stack and route it.
     * Emits the response to the HTTP client.
     *
     * @param Request|null $request Defaults to {@see Request::fromGlobals()}.
     */
    public function run(?Request $request = null): void
    {
        $request ??= Request::fromGlobals();

        try {
            $response = $this->router->dispatch($request, $this->middleware);
        } catch (\Throwable $e) {
            $response = $this->handleError($e, $request);
        }

        $this->emit($response);
    }

    /**
     * Dispatch a request and return the response without emitting.
     *
     * Ideal for testing — avoids touching headers or output buffers.
     *
     * Global middleware runs **before** route matching so that, for example,
     * {@see \Lift\Middleware\CorsMiddleware} can respond to OPTIONS preflight
     * requests even when no route is registered for that path.
     */
    public function handle(Request $request): Response
    {
        $pipeline = new Pipeline($this->container);
        try {
            return $pipeline->run(
                $request,
                $this->middleware,
                function (ServerRequestInterface $req): Response {
                    $r = $req instanceof Request ? $req : Request::fromGlobals();
                    return $this->router->dispatch($r, []);
                },
            );
        } catch (\Throwable $e) {
            return $this->handleError($e, $request);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Shortcuts for commonly bound services
    // -----------------------------------------------------------------

    /**
     * Retrieve the bound {@see Connection} from the container.
     *
     * Requires the caller to have bound Connection (or a subclass) beforehand:
     * ```php
     * $app->singleton(Connection::class, fn() => Connection::fromConfig($cfg));
     * $app->db()->table('users')->get();
     * ```
     */
    public function db(): Connection
    {
        return $this->container->make(Connection::class);
    }

    /**
     * Retrieve the bound {@see Logger} from the container.
     */
    public function logger(): Logger
    {
        return $this->container->make(Logger::class);
    }

    /**
     * Retrieve the bound {@see EventDispatcher} from the container.
     */
    public function events(): EventDispatcher
    {
        return $this->container->make(EventDispatcher::class);
    }

    // -----------------------------------------------------------------
    // Error handling (internal)
    // -----------------------------------------------------------------

    private function handleError(\Throwable $e, Request $request): Response
    {
        if ($this->debugErrorHandler !== null) {
            return $this->debugErrorHandler->handle($e, $request);
        }

        if ($this->errorHandler !== null) {
            return ($this->errorHandler)($e, $request);
        }

        if ($e instanceof ValidationException) {
            return Response::json(['errors' => $e->errors()], 422);
        }

        if ($e instanceof HttpException) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return Response::json(['error' => 'Internal Server Error'], 500);
    }

    private function emit(Response $response): void
    {
        if (headers_sent()) {
            return;
        }

        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        ), true, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            $replace = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
            }
        }

        if ($response instanceof SseResponse) {
            $response->stream();
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        echo $body->getContents();
    }
}
