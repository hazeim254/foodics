<?php

use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Listeners\WarmConfigCache;

return [

    'server' => env('OCTANE_SERVER', 'swoole'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            WarmConfigCache::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
            FlushOnce::class,
        ],

        WorkerErrorOccurred::class => [
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        //
    ],

    'swoole' => [
        'max_request' => (int) env('OCTANE_MAX_REQUESTS', 500),
        'task_worker_num' => (int) env('OCTANE_TASK_WORKERS', 0),
        'task_max_request' => (int) env('OCTANE_TASK_MAX_REQUESTS', 500),
    ],

    'cache' => [
        'rows' => (int) env('OCTANE_CACHE_ROWS', 1000),
        'bytes' => (int) env('OCTANE_CACHE_BYTES', 10000),
    ],

    'tick_interval' => (int) env('OCTANE_TICK_INTERVAL', 5),

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'resources/views',
        'routes',
    ],

    'garbage_collection_threshold' => (int) env('OCTANE_GC_THRESHOLD', 50),

];
