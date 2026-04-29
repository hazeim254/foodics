<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SortableLink extends Component
{
    public bool $isActive;

    public string $newDir;

    public string $url;

    public function __construct(
        public string $column,
        public string $label,
    ) {
        $currentSort = request('sort_by');
        $currentDir = request('sort_dir', 'desc');
        $this->isActive = $currentSort === $column;
        $this->newDir = $this->isActive && $currentDir === 'asc' ? 'desc' : 'asc';
        $this->url = '?'.http_build_query(array_merge(request()->query(), ['sort_by' => $column, 'sort_dir' => $this->newDir]));
    }

    public function render(): View|Closure|string
    {
        return view('components.sortable-link');
    }
}
