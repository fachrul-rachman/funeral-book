<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/staf');
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $error, Request $request): Response {
            if ($response->getStatusCode() !== 419 || ! $request->isMethod('POST') || ! $request->is('keluar')) {
                return $response;
            }

            $authenticated = $request->user() !== null;

            return to_route($authenticated ? 'staff' : 'login')->withErrors([
                'session' => $authenticated
                    ? 'Halaman telah diperbarui. Klik Keluar sekali lagi.'
                    : 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ]);
        });
        $exceptions->dontFlash(['applicant_name', 'applicant_whatsapp', 'relationship_to_deceased', 'deceased_name', 'deceased_date_of_death', 'funeral_home_location', 'lot_number', 'turnstile_token', 'submission_key', 'website', 'reason']);
        $exceptions->render(function (ValidationException $error, Request $request) {
            if ($request->is('api/v1/funeral-requests')) {
                return response()->json([
                    'message' => 'Periksa kembali data pengajuan.',
                    'code' => 'VALIDATION_FAILED',
                    'errors' => $error->errors(),
                ], 422);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
