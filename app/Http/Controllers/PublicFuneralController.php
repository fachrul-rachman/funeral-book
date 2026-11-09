<?php

namespace App\Http\Controllers;

use App\Services\FuneralRequests;
use App\Services\PublicFuneralSubmission;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicFuneralController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('funeral-form', [
            'siteKey' => config('funeral.turnstile.site_key'),
            'privacyUrl' => config('funeral.privacy_url'),
            'consentText' => FuneralRequests::CONSENT,
            'receipt' => $request->session()->get('funeral_receipt'),
        ]);
    }

    public function store(Request $request, PublicFuneralSubmission $submission): JsonResponse|RedirectResponse
    {
        $api = $request->is('api/*');
        try {
            $receipt = $submission->submit($request);
        } catch (LockTimeoutException) {
            if ($api) {
                return response()->json(['message' => 'Pengajuan sedang sibuk. Coba lagi.', 'code' => 'SUBMISSION_UNAVAILABLE'], 503);
            }

            return to_route('home')->withErrors(['submission' => 'Pengajuan sedang sibuk. Coba lagi.']);
        } catch (HttpException $error) {
            $message = $error->getMessage() ?: 'Pengajuan belum dapat dikirim. Coba lagi.';
            if ($api) {
                $code = match ($error->getStatusCode()) {
                    409 => 'SUBMISSION_CONFLICT',
                    413 => 'PAYLOAD_TOO_LARGE',
                    429 => 'SUBMISSION_RATE_LIMITED',
                    default => 'SUBMISSION_UNAVAILABLE',
                };

                return response()->json(['message' => $message, 'code' => $code], $error->getStatusCode());
            }

            return to_route('home')->withErrors(['submission' => $message]);
        }

        return $api
            ? response()->json($receipt, 201)
            : to_route('home')->with('funeral_receipt', $receipt);
    }
}
