<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use PDO;
use Dotenv\Dotenv;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Bootstrappers\BroadcastChannelPrefixBootstrapper;
use Stancl\Tenancy\Facades\GlobalCache;
use Stancl\Tenancy\TenancyServiceProvider;
use Stancl\Tenancy\Facades\Tenancy as TenancyFacade;
use Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();

        file_put_contents(database_path('central.sqlite'), '');
        pest()->artisan('migrate:fresh', [
            '--force' => true,
            '--path' => __DIR__ . '/../assets/migrations',
            '--realpath' => true,
        ]);

        // Laravel 6.x support todo@refactor clean up
        $testResponse = class_exists('Illuminate\Testing\TestResponse') ? 'Illuminate\Testing\TestResponse' : 'Illuminate\Foundation\Testing\TestResponse';
        $testResponse::macro('assertContent', function ($content) {
            $assertClass = class_exists('Illuminate\Testing\Assert') ? 'Illuminate\Testing\Assert' : 'Illuminate\Foundation\Testing\Assert';
            $assertClass::assertSame($content, $this->baseResponse->getContent());

            return $this;
        });
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        if (file_exists(__DIR__ . '/../.env')) {
            Dotenv::createImmutable(__DIR__ . '/..')->load();
        }

        $app['config']->set([
            'database.default' => 'central',
            'cache.default' => 'redis',
            'database.redis.cache.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.default.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.options.prefix' => 'foo',
            'database.redis.client' => 'predis',
            'database.connections.central' => [
                'driver' => 'mysql',
                'url' => env('DATABASE_URL'),
                'host' => 'mysql',
                'port' => env('DB_PORT', '3306'),
                'database' => 'main',
                'username' => env('DB_USERNAME', 'forge'),
                'password' => env('DB_PASSWORD', ''),
                'unix_socket' => env('DB_SOCKET', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => extension_loaded('pdo_mysql') ? array_filter([
                    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                ]) : [],
            ],
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.mysql.charset' => 'utf8mb4',
            'database.connections.mysql.collation' => 'utf8mb4_unicode_ci',
            'database.connections.mysql.host' => env('TENANCY_TEST_MYSQL_HOST', '127.0.0.1'),
            'database.connections.sqlsrv.username' => env('TENANCY_TEST_SQLSRV_USERNAME', 'sa'),
            'database.connections.sqlsrv.password' => env('TENANCY_TEST_SQLSRV_PASSWORD', 'P@ssword'),
            'database.connections.sqlsrv.host' => env('TENANCY_TEST_SQLSRV_HOST', '127.0.0.1'),
            'database.connections.sqlsrv.database' => null,
            'database.connections.sqlsrv.trust_server_certificate' => true,
            'database.connections.pgsql.host' => env('TENANCY_TEST_PGSQL_HOST', '127.0.0.1'),
            'tenancy.filesystem.disks' => [
                'local',
                'public',
                's3',
            ],
            'filesystems.disks.s3.bucket' => 'foo',
            'tenancy.redis.tenancy' => env('TENANCY_TEST_REDIS_TENANCY', true),
            'database.redis.client' => env('TENANCY_TEST_REDIS_CLIENT', 'phpredis'),
            'tenancy.redis.prefixed_connections' => ['default'],
            'tenancy.migration_parameters' => [
                '--path' => [database_path('../migrations')],
                '--realpath' => true,
                '--force' => true,
            ],
            'tenancy.central_domains' => ['localhost', '127.0.0.1'],
            'tenancy.bootstrappers' => [
                DatabaseTenancyBootstrapper::class, // todo@tests find why some tests are failing without this (especially 'the tenant connection is fully removed')
            ],
            'queue.connections.central' => [
                'driver' => 'sync',
                'central' => true,
            ],
            'tenancy.seeder_parameters' => [],
            'tenancy.models.tenant' => Tenant::class, // Use test tenant w/ DBs & domains
        ]);

        $app->singleton(RedisTenancyBootstrapper::class); // todo@samuel use proper approach eg config for singleton registration
        $app->singleton(PrefixCacheTenancyBootstrapper::class); // todo@samuel use proper approach eg config for singleton registration
        $app->singleton(BroadcastingConfigBootstrapper::class);
        $app->singleton(BroadcastChannelPrefixBootstrapper::class);
        $app->singleton(MailTenancyBootstrapper::class);
        $app->singleton(RootUrlBootstrapper::class);
        $app->singleton(UrlGeneratorBootstrapper::class);
    }

    protected function getPackageProviders($app)
    {
        return [
            TenancyServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Tenancy' => TenancyFacade::class,
            'GlobalCache' => GlobalCache::class,
        ];
    }

    /**
     * Resolve application HTTP Kernel implementation.
     *
     * @param  Application  $app
     * @return void
     */
    protected function resolveApplicationHttpKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Http\Kernel', Etc\HttpKernel::class);
    }

    /**
     * Resolve application Console Kernel implementation.
     *
     * @param  Application  $app
     * @return void
     */
    protected function resolveApplicationConsoleKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Console\Kernel', Etc\Console\ConsoleKernel::class);
    }

    public function randomString(int $length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', (int) (ceil($length / strlen($x))))), 1, $length);
    }

    public function assertArrayIsSubset($subset, $array, string $message = ''): void
    {
        parent::assertTrue(array_intersect($subset, $array) == $subset, $message);
    }
}
