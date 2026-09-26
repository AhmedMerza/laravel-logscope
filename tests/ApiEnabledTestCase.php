<?php

declare(strict_types=1);

namespace LogScope\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use LogScope\LogScopeServiceProvider;
use LogScope\Tests\Fixtures\User;

/**
 * Boots with the v1 API enabled (#64) and real Sanctum, so requests go
 * through the bearer-token guard rather than Sanctum::actingAs. Routes load
 * at boot, so the flag has to be set here, not in the test.
 */
abstract class ApiEnabledTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            LogScopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logscope.routes.api.enabled', true);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Built here rather than from Testbench's skeleton migrations, whose
        // path differs between the Testbench versions CI runs.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // Sanctum 4 only publishes its migration; it doesn't load it.
        $this->artisan('migrate', ['--realpath' => true, '--path' => [
            realpath(__DIR__.'/../vendor/laravel/sanctum/database/migrations'),
            realpath(__DIR__.'/../database/migrations'),
        ]]);
    }
}
