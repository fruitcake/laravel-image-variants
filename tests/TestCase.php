<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\ImageVariantsServiceProvider;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ImageVariantsServiceProvider::class];
    }

    /**
     * Run an Artisan command with its output captured.
     *
     * artisan() hands back a bare exit code when console output is not being
     * mocked, which would make every assertion below it a fatal error rather
     * than a failure.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            $this->fail('Console output is not being mocked, so the command cannot be asserted on.');
        }

        return $pending;
    }
}
