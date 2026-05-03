<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Http\Requests\SearchClientsRequest;
use App\Http\Requests\UpdateSettingsRequest;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\DaftraApiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SettingController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $daftraDefaultClient = null;

        $branches = app(DaftraApiClient::class)->tryGetBranches();

        $clientId = $user->setting(SettingKey::DaftraDefaultClientId);
        if ($clientId !== null && $clientId !== '') {
            $daftraDefaultClient = app(ClientService::class)->getDefaultClient((int) $clientId);
        }

        return view('settings', [
            'daftraDefaultClientId' => $clientId,
            'daftraDefaultClient' => $daftraDefaultClient,
            'daftraDefaultBranchId' => $user->setting(SettingKey::DaftraDefaultBranchId),
            'branches' => $branches,
        ]);
    }

    public function searchClients(SearchClientsRequest $request): JsonResponse
    {
        try {
            $results = app(ClientService::class)
                ->searchClients($request->input('query'));
        } catch (RuntimeException) {
            return response()->json(['data' => []], 503);
        }

        return response()->json(['data' => $results]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        $request->user()->setSetting(
            SettingKey::DaftraDefaultClientId,
            $request->input('daftra_default_client_id'),
        );

        $branchId = $request->input('daftra_default_branch_id');

        $request->user()->setSetting(
            SettingKey::DaftraDefaultBranchId,
            $branchId == 1 ? null : $branchId,
        );

        return redirect()->route('settings')
            ->with('status', __('Settings updated successfully.'));
    }
}
