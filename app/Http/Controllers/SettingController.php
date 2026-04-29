<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Http\Requests\UpdateSettingsRequest;
use App\Services\Daftra\DaftraApiClient;

class SettingController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $branches = null;

        if ($user->hasDaftraConnection()) {
            $branches = app(DaftraApiClient::class)->tryGetBranches();
        }

        return view('settings', [
            'daftraDefaultClientId' => $user->setting(SettingKey::DaftraDefaultClientId),
            'daftraDefaultBranchId' => $user->setting(SettingKey::DaftraDefaultBranchId),
            'branches' => $branches,
        ]);
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
