<?php

declare(strict_types=1);

namespace Lift\OpenApi;

use Lift\Attribute\Delete;
use Lift\Attribute\Get;
use Lift\Attribute\Patch;
use Lift\Attribute\Post;
use Lift\Attribute\Put;
use Lift\Attribute\Route;
use Lift\OpenApi\Attribute\ApiOperation;
use Lift\OpenApi\Attribute\ApiParam;
use Lift\OpenApi\Attribute\ApiResponse;
use Lift\OpenApi\Attribute\ApiSchema;
use Lift\OpenApi\Attribute\ApiSecurity;
use Lift\OpenApi\Attribute\ApiTag;
use ReflectionClass;
use ReflectionMethod;

/**
 * Generates an OpenAPI 3.0 specification from route attributes.
 *
 * ```php
 * $gen  = new Generator(title: 'My API', version: '1.0.0');
 * $gen->addController(UserController::class);
 * $spec = $gen->generate();  // returns array
 * file_put_contents('openapi.json', json_encode($spec, JSON_PRETTY_PRINT));
 * ```
 */
final class Generator
{
    /** @var array<class-string> */
    private array $controllers = [];

    /** @var array<class-string> Schema component classes. */
    private array $schemas = [];

    /** @var array<string, mixed> Extra securitySchemes to include verbatim. */
    private array $securitySchemes = [];

    public function __construct(
        private readonly string  $title,
        private readonly string  $version,
        private readonly string  $description = '',
        private readonly string  $serverUrl = '/',
    ) {}

    /** @param class-string $controller */
    public function addController(string $controller): self
    {
        $this->controllers[] = $controller;
        return $this;
    }

    /** @param class-string $schema */
    public function addSchema(string $schema): self
    {
        $this->schemas[] = $schema;
        return $this;
    }

    /**
     * Add a verbatim security scheme definition.
     *
     * @param array<string, mixed> $definition
     */
    public function addSecurityScheme(string $name, array $definition): self
    {
        $this->securitySchemes[$name] = $definition;
        return $this;
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $spec = [
            'openapi' => '3.0.3',
            'info'    => array_filter([
                'title'       => $this->title,
                'version'     => $this->version,
                'description' => $this->description,
            ]),
            'servers' => [['url' => $this->serverUrl]],
            'paths'   => [],
        ];

        foreach ($this->controllers as $class) {
            $this->processController($class, $spec);
        }

        $components = $this->buildComponents();
        if (!empty($components)) {
            $spec['components'] = $components;
        }

        ksort($spec['paths']);
        return $spec;
    }

    /** Serialise to a JSON string. */
    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return (string) json_encode($this->generate(), $flags);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $spec */
    private function processController(string $class, array &$spec): void
    {
        if (!class_exists($class)) {
            return;
        }
        $ref = new ReflectionClass($class);

        // Class-level tags
        $classTags     = $this->readAttributes($ref, ApiTag::class);
        $classSecurity = $this->readAttributes($ref, ApiSecurity::class);

        $tagNames = array_map(fn(ApiTag $t) => $t->name, $classTags);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttr = $this->firstAttribute($method, Route::class)
                ?? $this->firstAttribute($method, Get::class)
                ?? $this->firstAttribute($method, Post::class)
                ?? $this->firstAttribute($method, Put::class)
                ?? $this->firstAttribute($method, Patch::class)
                ?? $this->firstAttribute($method, Delete::class);

            if ($routeAttr === null) {
                continue;
            }

            $httpMethod = $this->attributeToHttpMethod($routeAttr);
            $path       = $this->normalizeOpenApiPath($routeAttr->getPath());

            /** @var ApiOperation|null $op */
            $op        = $this->firstAttribute($method, ApiOperation::class);
            /** @var ApiParam[]     $params */
            $params    = $this->readAttributes($method, ApiParam::class);
            /** @var ApiResponse[]  $responses */
            $responses = $this->readAttributes($method, ApiResponse::class);
            /** @var ApiSecurity[]  $security */
            $security  = $this->readAttributes($method, ApiSecurity::class);

            $operationTags = $op?->tags ?? $tagNames;
            $allSecurity   = [...$classSecurity, ...$security];

            $operation = array_filter([
                'summary'     => $op?->summary ?? '',
                'description' => $op?->description ?? '',
                'operationId' => $op?->operationId !== '' ? $op?->operationId : null,
                'tags'        => $operationTags ?: null,
                'parameters'  => $params ? $this->buildParameters($params) : null,
                'responses'   => $this->buildResponses($responses),
                'security'    => $allSecurity ? $this->buildSecurity($allSecurity) : null,
            ]);

            // Extract path params not already listed
            preg_match_all('/\{(\w+)(?::[^}]+)?\}/', $path, $m);
            $paramNames = array_map(fn(ApiParam $p) => $p->name, $params);
            foreach ($m[1] as $pname) {
                if (!in_array($pname, $paramNames, true)) {
                    $operation['parameters'][] = [
                        'name'     => $pname,
                        'in'       => 'path',
                        'required' => true,
                        'schema'   => ['type' => 'string'],
                    ];
                }
            }

            // Convert {id:\d+} → {id} for OpenAPI compatibility
            $cleanPath = (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $path);
            $spec['paths'][$cleanPath][strtolower($httpMethod)] = $operation;
        }
    }

    /** @return array<string, mixed> */
    private function buildComponents(): array
    {
        $schemas = [];
        foreach ($this->schemas as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $ref    = new ReflectionClass($class);
            $attr   = $this->firstAttribute($ref, ApiSchema::class);
            $name   = $attr?->name !== '' ? $attr->name : $ref->getShortName();
            $schemas[$name] = $this->classToSchema($ref, $attr?->description ?? '');
        }

        $components = [];
        if (!empty($schemas)) {
            $components['schemas'] = $schemas;
        }
        if (!empty($this->securitySchemes)) {
            $components['securitySchemes'] = $this->securitySchemes;
        }
        return $components;
    }

    /** @return array<string, mixed> */
    private function classToSchema(ReflectionClass $ref, string $description): array
    {
        $properties = [];

        foreach ($ref->getProperties() as $prop) {
            $type = $prop->getType();
            $name = $prop->getName();

            $schema = ['type' => 'string'];
            if ($type instanceof \ReflectionNamedType) {
                $schema = $this->phpTypeToSchema($type->getName());
                if (!$type->allowsNull() && !$prop->isInitialized(new $ref->name())) {
                    // can't reliably detect required without instantiation; skip
                }
            }
            $properties[$name] = $schema;
        }

        return array_filter([
            'type'        => 'object',
            'description' => $description,
            'properties'  => $properties ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    private function phpTypeToSchema(string $phpType): array
    {
        return match ($phpType) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number', 'format' => 'float'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'array'           => ['type' => 'array', 'items' => ['type' => 'string']],
            default           => ['type' => 'string'],
        };
    }

    /** @param ApiParam[] $params */
    private function buildParameters(array $params): array
    {
        $out = [];
        foreach ($params as $param) {
            $entry = array_filter([
                'name'        => $param->name,
                'in'          => $param->in,
                'description' => $param->description,
                'required'    => $param->in === 'path' ? true : ($param->required ?: null),
                'schema'      => array_filter([
                    'type'   => $param->type,
                    'format' => $param->format ?: null,
                ]),
                'example'     => $param->example,
            ]);
            $out[] = $entry;
        }
        return $out;
    }

    /** @param ApiResponse[] $responses */
    private function buildResponses(array $responses): array
    {
        if (empty($responses)) {
            return ['200' => ['description' => 'OK']];
        }

        $out = [];
        foreach ($responses as $r) {
            $entry = ['description' => $r->description];
            if ($r->schema !== '') {
                $schemaRef = class_exists($r->schema)
                    ? ['$ref' => '#/components/schemas/' . (new ReflectionClass($r->schema))->getShortName()]
                    : json_decode($r->schema, true) ?? [];
                $entry['content'] = [$r->mediaType => ['schema' => $schemaRef]];
            }
            $out[(string) $r->status] = $entry;
        }
        return $out;
    }

    /** @param ApiSecurity[] $security */
    private function buildSecurity(array $security): array
    {
        return array_map(fn(ApiSecurity $s) => [$s->scheme => $s->scopes], $security);
    }

    private function normalizeOpenApiPath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    private function attributeToHttpMethod(object $attr): string
    {
        return match (true) {
            $attr instanceof Get    => 'get',
            $attr instanceof Post   => 'post',
            $attr instanceof Put    => 'put',
            $attr instanceof Patch  => 'patch',
            $attr instanceof Delete => 'delete',
            $attr instanceof Route  => strtolower($attr->getMethod()),
            default                 => 'get',
        };
    }

    /**
     * Read all instances of an attribute from a reflector.
     *
     * @template T
     * @param  ReflectionClass|ReflectionMethod $ref
     * @param  class-string<T>                  $attrClass
     * @return T[]
     */
    private function readAttributes(ReflectionClass|ReflectionMethod $ref, string $attrClass): array
    {
        $out = [];
        foreach ($ref->getAttributes($attrClass) as $attr) {
            $out[] = $attr->newInstance();
        }
        return $out;
    }

    /**
     * Return the first instance of an attribute, or null.
     *
     * @template T
     * @param  ReflectionClass|ReflectionMethod $ref
     * @param  class-string<T>                  $attrClass
     * @return T|null
     */
    private function firstAttribute(ReflectionClass|ReflectionMethod $ref, string $attrClass): ?object
    {
        $attrs = $ref->getAttributes($attrClass);
        return $attrs ? $attrs[0]->newInstance() : null;
    }
}
