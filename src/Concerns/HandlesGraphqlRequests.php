<?php

namespace Butler\Graphql\Concerns;

use Butler\Graphql\DataLoader;
use Exception;
use GraphQL\Error\Debug;
use GraphQL\Error\Error as GraphqlError;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\BuildSchema;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait HandlesGraphqlRequests
{
    /**
     * Invoke the Graphql request handler.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function __invoke(Request $request)
    {
        $loader = app(DataLoader::class);
        $schema = BuildSchema::build(file_get_contents($this->schemaPath()));
        $result = null;

        GraphQL::promiseToExecute(
            app(PromiseAdapter::class),
            $schema,
            $request->input('query'),
            null, // root
            compact('loader'), // context
            $request->input('variables'),
            null, // operationName
            [$this, 'resolveField'],
            null // validationRules
        )->then(function ($value) use (&$result) {
            $result = $value;
        });

        $loader->run();

        $result->setErrorFormatter([$this, 'errorFormatter']);

        return $this->decorateResponse($result->toArray($this->debugFlags()));
    }

    public function errorFormatter(GraphqlError $graphqlError)
    {
        $formattedError = FormattedError::createFromException($graphqlError);
        $throwable = $graphqlError->getPrevious();

        $this->reportException(
            $throwable instanceof Exception
            ? $throwable
            : $graphqlError
        );

        if ($throwable instanceof ModelNotFoundException) {
            return array_merge($formattedError, [
                'message' => class_basename($throwable->getModel()) . ' not found.',
                'category' => 'client',
            ]);
        }

        if ($throwable instanceof ValidationException) {
            return array_merge($formattedError, [
                'message' => $throwable->getMessage(),
                'category' => 'validation',
                'validation' => $throwable->errors(),
            ]);
        }

        return $formattedError;
    }

    public function reportException(Exception $exception)
    {
        app(ExceptionHandler::class)->report($exception);
    }

    public function schemaPath()
    {
        return config('butler.graphql.schema');
    }

    public function debugFlags()
    {
        $flags = 0;
        if (config('butler.graphql.include_debug_message')) {
            $flags |= Debug::INCLUDE_DEBUG_MESSAGE;
        }
        if (config('butler.graphql.include_trace')) {
            $flags |= Debug::INCLUDE_TRACE;
        }
        return $flags;
    }

    public function resolveField($source, $args, $context, ResolveInfo $info)
    {

        $field = $this->fieldFromResolver($source, $args, $context, $info)
            ?? $this->fieldFromArray($source, $args, $context, $info)
            ?? $this->fieldFromObject($source, $args, $context, $info);

        return $field instanceof \Closure
            ? $field($source, $args, $context, $info)
            : $field;
    }

    public function fieldFromResolver($source, $args, $context, ResolveInfo $info)
    {
        $className = $this->resolveClassName($info);
        $methodName = $this->resolveMethodName($info);

        if (app()->has($className) || class_exists($className)) {
            $resolver = app($className);
            if (method_exists($resolver, $methodName)) {
                return $resolver->{$methodName}($source, $args, $context, $info);
            }
        }
    }

    public function fieldFromArray($source, $args, $context, ResolveInfo $info)
    {
        $propertyName = $this->propertyName($info);

        if (is_array($source) || $source instanceof \ArrayAccess) {
            return $source[$propertyName] ?? null;
        }
    }

    public function fieldFromObject($source, $args, $context, ResolveInfo $info)
    {
        $propertyName = $this->propertyName($info);

        if (is_object($source)) {
            return $source->{$propertyName} ?? null;
        }
    }

    public function propertyName(ResolveInfo $info): string
    {
        return Str::snake($info->fieldName);
    }

    protected function resolveClassName(ResolveInfo $info): string
    {
        if ($info->parentType->name === 'Query') {
            return $this->queriesNamespace() . Str::studly($info->fieldName);
        }

        if ($info->parentType->name === 'Mutation') {
            return $this->mutationsNamespace() . Str::studly($info->fieldName);
        }

        return $this->typesNamespace() . Str::studly($info->parentType->name);
    }

    public function resolveMethodName(ResolveInfo $info): string
    {
        if (in_array($info->parentType->name, ['Query', 'Mutation'])) {
            return '__invoke';
        }

        return Str::camel($info->fieldName);
    }

    public function namespace(): string
    {
        return config('butler.graphql.namespace');
    }

    public function queriesNamespace(): string
    {
        return $this->namespace() . 'Queries\\';
    }

    public function mutationsNamespace(): string
    {
        return $this->namespace() . 'Mutations\\';
    }

    public function typesNamespace(): string
    {
        return $this->namespace() . 'Types\\';
    }

    public function decorateResponse(array $data): array
    {
        if (
            app()->bound('debugbar') &&
            app('debugbar')->isEnabled()
        ) {
            $data['debug'] = app('debugbar')->getData();
        }
        return $data;
    }
}
