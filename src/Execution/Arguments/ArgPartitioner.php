<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use ReflectionClass;
use ReflectionNamedType;
use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Execution\ArgumentResolver;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nuwave\Lighthouse\Support\Contracts\Directive;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ArgPartitioner
{
    /**
     * Partition the arguments into nested and regular.
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @param  mixed  $root
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    public static function nestedArgumentResolvers(ArgumentSet $argumentSet, $root): array
    {
        $model = $root instanceof Model
            ? new \ReflectionClass($root)
            : null;

        foreach($argumentSet->arguments as $name => $argument) {
            static::attachNestedArgumentResolver($name, $argument, $model);
        }

        return static::partition(
            $argumentSet,
            static function (string $name, Argument $argument): bool {
                return isset($argument->resolver);
            }
        );
    }

    /**
     * Extract all the arguments that correspond to a relation of a certain type on the model.
     *
     * For example, if the args input looks like this:
     *
     * [
     *  'name' => 'Ralf',
     *  'comments' =>
     *    ['foo' => 'Bar'],
     * ]
     *
     * and the model has a method "comments" that returns a HasMany relationship,
     * the result will be:
     * [
     *   [
     *    'comments' =>
     *      ['foo' => 'Bar'],
     *   ],
     *   [
     *    'name' => 'Ralf',
     *   ]
     * ]
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $relationClass
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    public static function relationMethods(
        ArgumentSet $argumentSet,
        Model $model,
        string $relationClass
    ): array {
        $modelReflection = new ReflectionClass($model);

        return static::partition(
            $argumentSet,
            static function (string $name) use ($modelReflection, $relationClass): bool {
                return static::methodReturnsRelation($modelReflection, $name, $relationClass);
            }
        );
    }

    /**
     * Attach a nested argument resolver to an argument.
     *
     * @param  string  $name
     * @param  \Nuwave\Lighthouse\Execution\Arguments\Argument  $argument
     * @param  \ReflectionClass|null  $model
     * @return void
     */
    protected static function attachNestedArgumentResolver(string $name, Argument &$argument, ?ReflectionClass $model): void
    {
        $resolverDirective = $argument->directives->first(function (Directive $directive): bool {
            return $directive instanceof ArgumentResolver;
        });

        if ($resolverDirective) {
            $argument->resolver = $resolverDirective;
            return;
        }

        if (isset($model)) {
            $isRelation = static function (string $relationClass) use ($model, $name) {
                return static::methodReturnsRelation($model, $name, $relationClass);
            };

            if (
                $isRelation(HasOne::class)
                || $isRelation(MorphOne::class)
            ) {
                $argument->resolver = new ArgResolver(new NestedOneToOne($name));
                return;
            }

            if (
                $isRelation(HasMany::class)
                || $isRelation(MorphMany::class)
            ) {
                $argument->resolver = new ArgResolver(new NestedOneToMany($name));
                return;
            }

            if (
                $isRelation(BelongsToMany::class)
                || $isRelation(MorphToMany::class)
            ) {
                $argument->resolver = new ArgResolver(new NestedManyToMany($name));
                return;
            }
        }
    }

    /**
     * Partition arguments based on a predicate.
     *
     * The predicate will be called for each argument within the ArgumentSet
     * with the following parameters:
     * 1. The name of the argument
     * 2. The argument itself
     *
     * Returns an array of two new ArgumentSet instances:
     * - the first one contains all arguments for which the predicate matched
     * - the second one contains all arguments for which the predicate did not match
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @param  \Closure  $predicate
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    protected static function partition(ArgumentSet $argumentSet, \Closure $predicate)
    {
        $matched = new ArgumentSet();
        $notMatched = new ArgumentSet();

        foreach ($argumentSet->arguments as $name => $argument) {
            if ($predicate($name, $argument)) {
                $matched->arguments[$name] = $argument;
            } else {
                $notMatched->arguments[$name] = $argument;
            }
        }

        return [
            $matched,
            $notMatched,
        ];
    }

    /**
     * Does a method on the model return a relation of the given class?
     *
     * @param  \ReflectionClass  $modelReflection
     * @param  string  $name
     * @param  string  $relationClass
     * @return bool
     */
    protected static function methodReturnsRelation(
        ReflectionClass $modelReflection,
        string $name,
        string $relationClass
    ): bool {
        if (! $modelReflection->hasMethod($name)) {
            return false;
        }

        $relationMethodCandidate = $modelReflection->getMethod($name);
        if (! $returnType = $relationMethodCandidate->getReturnType()) {
            return false;
        }

        if (! $returnType instanceof ReflectionNamedType) {
            return false;
        }

        return is_a($returnType->getName(), $relationClass, true);
    }
}
