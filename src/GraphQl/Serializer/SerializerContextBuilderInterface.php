<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\GraphQl\Serializer;

/**
 * Builds the context used by the Symfony Serializer.
 *
 * @experimental
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
interface SerializerContextBuilderInterface
{
    public function create(string $resourceClass, string $operationName, array $resolverContext, bool $normalization): array;
}
