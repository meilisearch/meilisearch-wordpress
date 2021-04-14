<?php

/**
 * Annotation based hooking for classes.
 *
 * @package meilisearch/meilisearch-wordpress
 * @author MeiliSearch <bonjour@meilisearch.com>
 * @copyright 2021 MeiliSearch
 * @license https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE MIT
 * @link https://wordpress.meilisearch.dev
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress\Tools;

use ReflectionClass;
use ReflectionMethod;

use function add_filter;

/**
 * Implement hooking in method annotation.
 *
 * Format: @hook hook_name 10
 *
 * mindplay/annotations may be a better solution.
 *
 * @see https://github.com/szepeviktor/debian-server-tools/blob/master/webserver/wordpress/WordPress-hooks.md
 */
trait HookAnnotation
{
    protected function hookMethods(int $defaultPriority = 10): void
    {
        $classReflection = new ReflectionClass(self::class);
        // Look for hook tag in all public methods.
        foreach ($classReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Do not hook constructor, use HookConstructorTo.
            if ($method->isConstructor()) {
                continue;
            }
            $hookDetails = $this->getMetadata((string)$method->getDocComment(), $defaultPriority);
            if ($hookDetails === null) {
                continue;
            }

            add_filter(
                $hookDetails['name'],
                [$this, $method->name],
                $hookDetails['priority'],
                $method->getNumberOfParameters()
            );
        }
    }

    /**
     * Read hook tag from docblock.
     *
     * @return array{name: string, priority: int}|null
     */
    protected function getMetadata(string $docComment, int $defaultPriority): ?array
    {
        $matches = [];
        if (
            \preg_match(
                '/^\s+\*\s+@hook\s+([\w\/_=-]+)(?:\s+(\d+))?\s*$/m',
                $docComment,
                $matches
            ) !== 1
        ) {
            return null;
        }

        return [
            'name' => $matches[1],
            'priority' => \intval($matches[2] ?? $defaultPriority),
        ];
    }
}
