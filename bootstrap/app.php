<?php

use App\Models\ErrorLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('api', [\App\Http\Middleware\PreventApiCaching::class]);
        $middleware->alias([
            'gm' => \App\Http\Middleware\EnsureIsGm::class,
            'not-banned' => \App\Http\Middleware\EnsureNotBanned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Lightweight in-house crash log surfaced in the GM Console's Errors tab — only genuine
        // unhandled exceptions (500s, or anything that isn't a deliberate 4xx abort_if/abort_unless
        // control-flow throw) get recorded; validation/auth/404s are expected traffic, not crashes.
        // Wrapped in try/catch so a DB hiccup while logging an error never becomes a second exception.
        $exceptions->reportable(function (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status < 500) {
                return;
            }

            try {
                ErrorLog::create([
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => request()?->fullUrl(),
                    'method' => request()?->method(),
                    'user_id' => request()?->user()?->id,
                    'trace' => $e->getTraceAsString(),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                // Never let error-logging itself throw.
            }
        });
    })->create();
