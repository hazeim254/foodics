<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    public function __invoke(): View
    {
        $user = auth()->user();

        return view('dashboard', [
            'invoiceStats' => $this->dashboardService->getInvoiceStats($user),
            'productStats' => $this->dashboardService->getProductStats($user),
            'syncOverTime' => $this->dashboardService->getSyncOverTime($user),
            'defaultSettings' => $this->dashboardService->getDefaultSettings($user),
        ]);
    }
}
