<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountDeletionRequest;
use App\Services\AccountDeletionRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountDeletionRequestController extends Controller
{
    public function show(): View
    {
        return view('legal.delete-account');
    }

    public function store(
        StoreAccountDeletionRequest $request,
        AccountDeletionRequestService $service
    ): RedirectResponse {
        $deletionRequest = $service->submit($request->validated(), $request);

        return redirect()
            ->route('legal.delete-account')
            ->with('success', 'Your deletion request '.$deletionRequest->reference.' was submitted. We will verify your identity and process it within 30 days. A confirmation may be sent to your email.')
            ->with('deletion_reference', $deletionRequest->reference);
    }
}
