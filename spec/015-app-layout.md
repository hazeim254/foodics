# 015 - Application Layout with Sidebar Navigation

## Overview

Add a shared layout for all authenticated pages with a fixed sidebar (desktop) and hamburger menu (mobile). The layout includes navigation links, a user profile section showing connection metadata from both Foodics and Daftra, and supports light/dark mode via Tailwind's `dark:` classes (respects system preference).

## Context

- The app uses Blade templates with Tailwind CSS v4 (via Vite).
- Current authenticated pages (`welcome.blade.php`) are standalone with no shared layout.
- `login.blade.php` remains outside this layout (public page).
- Session stores `daftra_account` (subdomain) and `foodics_account` (business_name) from the OAuth flow (spec 007, 008).
- Auth middleware redirects unauthenticated users to `/login` (spec 008).
- The app logo and brand colors: Daftra (`#4A90D9`), Foodics (`#FF4433`).

## Layout Structure

```
┌──────────────────────────────────────────────────┐
│ ┌─────────┐ ┌──────────────────────────────────┐ │
│ │         │ │ Top bar (page title, mobile menu) │ │
│ │ Sidebar │ ├──────────────────────────────────┤ │
│ │         │ │                                  │ │
│ │  Logo   │ │     @yield('content')            │ │
│ │  -----  │ │                                  │ │
│ │  Nav    │ │                                  │ │
│ │  links  │ │                                  │ │
│ │  -----  │ │                                  │ │
│ │  User   │ │                                  │ │
│ │  profile│ │                                  │ │
│ └─────────┘ └──────────────────────────────────┘ │
└──────────────────────────────────────────────────┘
```

### Sidebar (Desktop: fixed, Mobile: hamburger toggle)

**Header area:**
- App name/logo at top

**Navigation links:**
- Dashboard
- Invoices
- Products
- Settings

**User profile section (bottom of sidebar):**
- Daftra: subdomain + connection status (green dot / grey dot)
- Foodics: business name + connection status (green dot / grey dot)

### Top Bar
- Mobile: hamburger button to toggle sidebar
- Desktop: optional page title area

### Dark Mode
- Follows system preference via Tailwind `dark:` variant
- Sidebar: white background in light mode, dark background (`#161615`) in dark mode
- Main content: `#FDFDFC` light / `#0a0a0a` dark (matching existing theme)

## Routes

```php
// Guest routes (no auth)
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::get('/daftra/auth', ...)->name('daftra.auth');
Route::get('/daftra/auth/callback', ...)->name('daftra.callback');
Route::get('/foodics/auth', ...)->name('foodics.auth');
Route::get('/foodics/auth/callback', ...)->name('foodics.callback');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/invoices', InvoiceController::class)->name('invoices');
    Route::get('/products', ProductController::class)->name('products');
    Route::get('/settings', SettingController::class)->name('settings');
});

// Webhooks
Route::post('webhooks/foodics', ...)->name('webhooks');
```

## Files to Create

### 1. `resources/views/layouts/app.blade.php`

Shared layout with sidebar navigation. Key elements:

- `<html>` and `<head>` with Vite, fonts, and meta tags (extracted from current standalone pages)
- Sidebar component with navigation links and user profile
- Mobile hamburger toggle using Alpine.js or vanilla JS
- `@yield('content')` for page-specific content
- `@stack('scripts')` for page-specific JS
- Navigation links use `route()` helper and `request()->routeIs()` for active state highlighting
- User profile reads from `session('daftra_account')` and `session('foodics_account')`

### 2. `app/Http/Controllers/DashboardController.php`

Invokable controller replacing the current closure route:

```php
class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('dashboard');
    }
}
```

### 3. `app/Http/Controllers/InvoiceController.php`

Invokable controller for invoices page:

```php
class InvoiceController extends Controller
{
    public function __invoke()
    {
        return view('invoices');
    }
}
```

### 4. `app/Http/Controllers/ProductController.php`

Invokable controller for products page:

```php
class ProductController extends Controller
{
    public function __invoke()
    {
        return view('products');
    }
}
```

### 5. `app/Http/Controllers/SettingController.php`

Invokable controller for settings page:

```php
class SettingController extends Controller
{
    public function __invoke()
    {
        return view('settings');
    }
}
```

### 6. `resources/views/dashboard.blade.php`

Replaces the current `welcome.blade.php` content wrapped in the new layout:

```blade
@extends('layouts.app')

@section('content')
    {{-- Dashboard content (from current welcome.blade.php, simplified) --}}
@endsection
```

### 7. `resources/views/invoices.blade.php`

Placeholder page:

```blade
@extends('layouts.app')

@section('content')
    {{-- Invoices content placeholder --}}
@endsection
```

### 8. `resources/views/products.blade.php`

Placeholder page:

```blade
@extends('layouts.app')

@section('content')
    {{-- Products content placeholder --}}
@endsection
```

### 9. `resources/views/settings.blade.php`

Placeholder page:

```blade
@extends('layouts.app')

@section('content')
    {{-- Settings content placeholder --}}
@endsection
```

## Files to Modify

### 10. `routes/web.php`

- Replace the closure route for `/` with `DashboardController::class`
- Add routes for `/invoices`, `/products`, `/settings` with their invokable controllers
- Keep the home route name as `dashboard` (or alias `home` for backward compatibility)

## Files to Keep Unchanged

- `resources/views/login.blade.php` — public page, no layout
- `resources/views/welcome.blade.php` — can be kept for reference or removed
- `resources/css/app.css` — no changes needed, Tailwind handles everything

## Mobile Behavior

- Sidebar is hidden by default on screens below `lg` breakpoint
- Hamburger icon in top bar toggles sidebar visibility
- Sidebar overlays content on mobile (fixed position, z-indexed above content)
- Clicking outside sidebar or a nav link closes it
- Backdrop/dim overlay when sidebar is open on mobile

## User Profile Section

Displayed at the bottom of the sidebar:

```
┌─────────────────────┐
│ ● Daftra            │
│   subdomain.daftra  │
│                     │
│ ● Foodics           │
│   Business Name     │
└─────────────────────┘
```

- Green dot (`bg-green-500`) when session has the account data
- Grey dot (`bg-gray-300 dark:bg-gray-600`) when not connected
- Account info shown below each provider name

## Edge Cases

- **Session expired / no provider data:** Profile section shows both providers as disconnected (grey dots, no info text).
- **Direct URL access:** All routes under `auth` middleware redirect to `/login` if not authenticated.
- **Browser back/forward:** Sidebar state should reset (closed on mobile) on navigation.

## Tasks

- [x] Create `resources/views/layouts/app.blade.php` with sidebar + top bar
- [x] Create `app/Http/Controllers/DashboardController.php`
- [x] Create `app/Http/Controllers/InvoiceController.php`
- [x] Create `app/Http/Controllers/ProductController.php`
- [x] Create `app/Http/Controllers/SettingController.php`
- [x] Create `resources/views/dashboard.blade.php`
- [x] Create `resources/views/invoices.blade.php`
- [x] Create `resources/views/products.blade.php`
- [x] Create `resources/views/settings.blade.php`
- [x] Update `routes/web.php` with new controllers and routes
- [x] Write feature tests for all routes (authenticated + unauthenticated)
