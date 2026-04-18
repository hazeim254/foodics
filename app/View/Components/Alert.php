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
            'success' => 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',
            'error' => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',
            'warning' => 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300',
            'info' => 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',
            default => 'bg-[#F5F5F3] dark:bg-[#262625] border-[#e3e3e0] dark:border-[#3E3E3A] text-[#1b1b18] dark:text-[#EDEDEC]',
        };
    }

    public function iconClasses(): string
    {
        return match ($this->type) {
            'success' => 'text-green-500 dark:text-green-400',
            'error' => 'text-red-500 dark:text-red-400',
            'warning' => 'text-yellow-500 dark:text-yellow-400',
            'info' => 'text-blue-500 dark:text-blue-400',
            default => 'text-[#706f6c] dark:text-[#A1A09A]',
        };
    }

    public function render(): View|Closure|string
    {
        return view('components.alert');
    }
}
