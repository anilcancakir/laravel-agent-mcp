<?php

namespace Anilcancakir\LaravelAgentMcp\Tests;

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for laravel-agent-mcp.
 *
 * Registers the package provider via testbench and configures a readonly
 * SQLite in-memory connection so every test that hits the DB uses the same
 * engine the package expects.
 */
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Guard on class_exists so a missing provider never fatals the testbench
        // boot ("class not found"); the real provider is registered when present.
        return class_exists(AgentMcpServiceProvider::class) ? [AgentMcpServiceProvider::class] : [];
    }

    /**
     * Configure the test environment.
     *
     * Sets both the default connection and the mandatory readonly connection to
     * SQLite in-memory so tests never touch a real database and the readonly
     * connection resolver finds a valid target.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');

        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Readonly connection: separate in-memory SQLite for tests.
        // ReadonlyConnectionResolver picks this up via config('agent-mcp.connection').
        $app['config']->set('database.connections.readonly', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Load the package config from its file so config('agent-mcp.*') resolves even
        // before the provider's mergeConfigFrom runs; that later merge is a harmless
        // no-op (it only fills absent keys). Guarded in case the config file is absent.
        $configPath = dirname(__DIR__).'/config/agent-mcp.php';

        if (is_file($configPath)) {
            $app['config']->set('agent-mcp', require $configPath);
        }
    }
}
