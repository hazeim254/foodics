<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Alert extends Component
{
    public function __construct(
        public string $type = 'success',
    ) {}

    public function iconPath(): string
    {
        return match ($this->type) {
            'success' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'error' => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
            'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
            'info' => 'M11.25 11.25l.041-.02a.75.75 0 011.063.91l-.109.161a.75.75 0 00.91 1.063l.041-.02M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
            default => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        };
    }

    public function containerClasses(): string
    {
        return match ($this->type) {
            'success' => 'border-tone-success-border bg-tone-success-soft text-ink',
            'error' => 'border-tone-danger-border bg-tone-danger-soft text-ink',
            'warning' => 'border-tone-warn-border bg-tone-warn-soft text-ink',
            'info' => 'border-tone-info-border bg-tone-info-soft text-ink',
            default => 'bg-surface-2 border-line text-ink',
        };
    }

    public function iconClasses(): string
    {
        return match ($this->type) {
            'success' => 'text-tone-success',
            'error' => 'text-tone-danger',
            'warning' => 'text-tone-warn',
            'info' => 'text-tone-info',
            default => 'text-ink-muted',
        };
    }

    public function render(): View|Closure|string
    {
        return view('components.alert');
    }
}
