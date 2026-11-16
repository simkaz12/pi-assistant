<?php

namespace App\Modules\Oura\Http\Controllers;

use App\Modules\Oura\Http\Requests\OuraOAuthCallbackRequest;
use App\Modules\Oura\Services\OuraClient;
use App\Modules\Oura\Services\OuraConnectionService;
use App\Modules\Oura\Services\OuraDatasetRegistry;
use Illuminate\Http\Response;
use Throwable;

class OuraOAuthController
{
    public function __invoke(
        OuraOAuthCallbackRequest $request,
        OuraConnectionService $connections,
        OuraClient $client,
        OuraDatasetRegistry $registry,
    ): Response {
        try {
            if ($request->input('error') === 'access_denied') {
                $connections->deny((string) $request->string('state'));

                return response('Oura access was denied. You can close this window.', 400);
            }

            $connections->callback(
                (string) $request->string('state'),
                (string) $request->string('code'),
                (string) $request->string('scope'),
                $client,
                $registry,
            );

            return response('Oura connected. You can close this window.');
        } catch (Throwable) {
            return response('Oura connection failed. Ask Hermes for a new authorization link.', 400);
        }
    }
}
