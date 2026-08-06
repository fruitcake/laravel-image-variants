<?php

namespace Fruitcake\ImageVariants;

use InvalidArgumentException;

/**
 * A variant could not be described or generated from the given input.
 *
 * Always the result of a bad URL rather than a server fault, so the controller
 * answers these with a 404.
 */
class VariantException extends InvalidArgumentException
{
}
