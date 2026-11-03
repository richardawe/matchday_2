<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
        // Set by the page script (not Laravel), so it is read as is for analytics. The look preference
        // (md_style: classic or cartoon) is not secret either and stays plain.
        $middleware->encryptCookies(except: ['md_visitor', \App\Support\SiteStyle::COOKIE]);
        // Global: a request for a real file may not match a route, so the web group never runs.
        $middleware->prepend(\App\Http\Middleware\ServePublicFiles::class);
        $middleware->web(append: [\App\Http\Middleware\CaptureAttribution::class, \App\Http\Middleware\SiteStylePreview::class]);
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
