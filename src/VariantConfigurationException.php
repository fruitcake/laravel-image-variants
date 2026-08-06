<?php

namespace Fruitcake\ImageVariants;

use RuntimeException;

/**
 * The package is misconfigured: no source is set, the configured disk does not
 * exist, the source directory is missing.
 *
 * Deliberately not a VariantException. A bad URL is the visitor's problem and
 * gets a 404; this is the application's problem, and answering it with a 404
 * would turn a broken deployment into images that quietly never appear.
 */
class VariantConfigurationException extends RuntimeException
{
}
