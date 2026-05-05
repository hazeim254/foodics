<?php

namespace App\Http\Controllers;

use App\Models\EntityMapping;
use App\Services\Daftra\TaxService;
use App\Services\Daftra\DaftraApiClient;
use App\Services\Foodics\BranchService;
use App\Services\Foodics\TaxService as FoodicsTaxService;
use App\Http\Requests\StoreBranchMappingRequest;
use App\Http\Requests\StoreTaxMappingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MappingController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $branchMappings = EntityMapping::query()
            ->where('user_id', $user->id)
            ->where('type', 'branch')
            ->get()
            ->keyBy('foodics_id');

        $taxMappings = EntityMapping::query()
            ->where('user_id', $user->id)
            ->where('type', 'tax')
            ->get()
            ->keyBy('foodics_id');

        return view('mappings', [
            'branchMappings' => $branchMappings,
            'taxMappings' => $taxMappings,
            'foodicsBranches' => session('foodics_branches', []),
            'daftraBranches' => session('daftra_branches'),
            'foodicsTaxes' => session('foodics_taxes', []),
            'daftraTaxes' => session('daftra_taxes', []),
        ]);
    }

    public function syncBranches(BranchService $branchService, DaftraApiClient $daftraClient): RedirectResponse
    {
        $foodicsBranches = $branchService->fetchBranches();
        $daftraBranches = $daftraClient->tryGetBranches();

        return redirect()
            ->route('mappings')
            ->withInput()
            ->with('foodics_branches', $foodicsBranches)
            ->with('daftra_branches', $daftraBranches);
    }

    public function syncTaxes(FoodicsTaxService $foodicsTaxService, TaxService $daftraTaxService): RedirectResponse
    {
        $foodicsTaxes = $foodicsTaxService->fetchTaxes();
        $daftraTaxes = $daftraTaxService->listTaxes();

        return redirect()
            ->route('mappings')
            ->withInput()
            ->with('foodics_taxes', $foodicsTaxes)
            ->with('daftra_taxes', $daftraTaxes);
    }

    public function storeBranchMapping(StoreBranchMappingRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->input('mappings', []) as $mapping) {
            $foodicsId = (string) $mapping['foodics_id'];
            $daftraId = $mapping['daftra_id'];

            if ($daftraId === '' || $daftraId === null) {
                EntityMapping::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'branch')
                    ->where('foodics_id', $foodicsId)
                    ->delete();

                continue;
            }

            EntityMapping::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'branch',
                    'foodics_id' => $foodicsId,
                ],
                [
                    'daftra_id' => (int) $daftraId,
                    'metadata' => [],
                    'status' => 'synced',
                ],
            );
        }

        return redirect()
            ->route('mappings')
            ->with('status', __('Branch mappings saved successfully.'));
    }

    public function storeTaxMapping(StoreTaxMappingRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->input('mappings', []) as $mapping) {
            $foodicsId = (string) $mapping['foodics_id'];
            $daftraId = $mapping['daftra_id'];

            if ($daftraId === '' || $daftraId === null) {
                EntityMapping::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'tax')
                    ->where('foodics_id', $foodicsId)
                    ->delete();

                continue;
            }

            EntityMapping::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'tax',
                    'foodics_id' => $foodicsId,
                ],
                [
                    'daftra_id' => (int) $daftraId,
                    'metadata' => [],
                    'status' => 'synced',
                ],
            );
        }

        return redirect()
            ->route('mappings')
            ->with('status', __('Tax mappings saved successfully.'));
    }
}