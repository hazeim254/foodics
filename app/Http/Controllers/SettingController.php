<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Http\Requests\UpdateSettingsRequest;

class SettingController extends Controller
{
    public function index()
    {
        return view('settings', [
            'daftraDefaultClientId' => auth()->user()->setting(SettingKey::DaftraDefaultClientId),
            'daftraDefaultBranchId' => auth()->user()->setting(SettingKey::DaftraDefaultBranchId),
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
            ->with('status', 'Settings updated successfully.');
    }
}
