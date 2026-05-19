# 034 — Public Landing Page

## Overview

Add a public landing page at `/` for **Daftrics** — the Foodics × Daftra integration — that explains what the product does and routes a visitor to the right next step. The page is the first surface a prospective operator sees; today, `/` is auth-protected and an unauthenticated visitor is sent straight to the connect screen with no context about what they are agreeing to.

The page must work in both Arabic (primary) and English, respect the existing OKLCH design tokens in `resources/css/app.css`, support light + dark via system preference, and ship with full Pest coverage.

No registration is needed — sign-in is OAuth with Daftra and Foodics. The page has exactly one primary call to action, and that CTA adapts to the visitor's auth state:

- **Guest** → `Connect your accounts` → `/login`
- **Authenticated** → `Dashboard` → `/dashboard`

Authenticated users can browse the landing freely; the page is a public marketing surface and is never gated.

## Context

- `routes/web.php` currently mounts `GET /` inside the `auth` middleware group, served by `DashboardController` and named `dashboard`.
- `bootstrap/app.php` configures `redirectGuestsTo('/login')`, so a guest hitting `/` is bounced to `/login` (the connect screen). There is no public marketing surface anywhere.
- `/login` is served by `AuthController::loginForm` and shows the Daftra + Foodics OAuth connection cards. It already supports Arabic/English and the language switcher.
- The app uses Blade + Tailwind v4 with a custom semantic token system in `resources/css/app.css` (`--surface-0`, `--ink`, `--accent`, `--brand-daftra`, `--brand-foodics`, etc.) and Cairo/Inter typography selected by `lang` attribute.
- The translation convention (see `CLAUDE.md` → Translations) requires every user-facing string to use `__('English copy')` as the key and to have a matching entry in `lang/ar.json`.
- The existing `login.blade.php` is the strongest design reference: it already uses `bg-surface-auth-feature`, `bg-brand-daftra`, `bg-brand-foodics`, brand-logo lockup, language switcher, and an RTL-aware layout.
- Existing animations (`page-enter`, `card-enter`, `badge-pulse`, `status-breathe`, `dot-connected`) are available in `app.css` and respect `prefers-reduced-motion`.

## Decisions

| Concern | Decision |
|---|---|
| Route | Move dashboard to `/dashboard` (route name **stays** `dashboard`). Add new public `GET /` named `landing`. |
| Authenticated visitor on `/` | Render the landing page. Do NOT redirect. The CTA swaps to `Dashboard` → `/dashboard`. |
| Controller | New `LandingController` (single-action, invokable). No business logic; just returns the view. |
| App name | Set `APP_NAME=Daftrics` in `.env.example` (and instruct the user to update `.env`). The page reads `config('app.name')` so no string is hard-coded in Blade. |
| View location | `resources/views/landing.blade.php` (sibling of `login.blade.php`). Does not extend `layouts.app` — the landing is a standalone, full-page composition. |
| Register / aesthetic | **Brand** register. Operator-first framing, accountant-grade reassurance in support copy. |
| Color strategy | **Committed: cyan-led**. The existing `--accent` (cyan, hue 239) carries the page. `--brand-daftra` and `--brand-foodics` appear only inside the sync diagram and the "How it works" steps as small, deliberate moments — never as hero fields. |
| Theme | Auto (light + dark via `prefers-color-scheme`). Both modes use the tokens already defined in `app.css`. No new color variables. |
| Locale | Arabic primary. Page must render correctly in both RTL Arabic and LTR English. Logical CSS properties only (`ps-*`, `pe-*`, `start-*`, `end-*`). |
| Typography | Reuse Cairo (Arabic) + Inter (Latin) already loaded by `app.css` and the existing layouts. No new font stacks. Display headline at `clamp(2.25rem, 4vw, 3.5rem)`; body at base text size with 65–75ch measure. |
| Motion | Reuse `page-enter` and `card-enter`. Add one bespoke sync-line animation (slow, single dot traveling across the line) that respects `prefers-reduced-motion`. |
| Anti-references honored | No identical icon-card grid, no logo wall, no gradient text, no side-stripe borders, no glassmorphism, no hero-metric template, no navy-and-gold finance look. |
| Footer | Minimal: small print + language switcher repeated at the end of the page for parity with the top bar. No social links, no nav menu. |

## Requirements

### 1. Routing

#### `routes/web.php`

- Add a public route at the top of the file (before the `auth` group):

  ```php
  Route::get('/', LandingController::class)->name('landing');
  ```

- Move the dashboard route out of root and into `/dashboard` while keeping the existing `dashboard` route name:

  ```php
  Route::middleware('auth')->group(function () {
      Route::get('/dashboard', DashboardController::class)->name('dashboard');
      // ...existing routes
  });
  ```

#### `app/Http/Controllers/LandingController.php` (new)

Invokable controller. Behaviour: always return `view('landing')`. The view itself reads `auth()->check()` to swap the CTA, so the controller stays trivial.

Use a single `__invoke(): View` method, typed. No constructor.

#### No middleware changes

`bootstrap/app.php` keeps `redirectGuestsTo('/login')`. The landing route itself is public (not in the `auth` group), so the global redirect does not apply.

### 2. View — `resources/views/landing.blade.php`

A single-file Blade view (no new partials unless a section is genuinely reused elsewhere — it is not). Structure top-to-bottom:

#### Page head

- `<title>` — `__('Daftrics — Foodics sales land in Daftra, automatically')`
- `<meta name="description">` — `__('The Foodics × Daftra integration that turns every sale, return, and product into an up-to-date Daftra record, automatically.')`
- Reuse the existing font preconnects and `@vite` directive from `login.blade.php`. No favicon work in this pass.

#### Top bar (sticky, translucent)

- Compact wordmark on the leading edge: a small cyan dot tile (matching the sidebar's `D` tile in `layouts/app.blade.php`) followed by `{{ config('app.name') }}` — resolves to `Daftrics`.
- Language switch button on the trailing edge — same POST form pattern as `login.blade.php`.
- A subtle navigation link, trailing edge, immediately before/after the language switcher (RTL-aware order). Auth-aware:
  - Guest: `Sign in` → `route('login')`
  - Authenticated: `Dashboard` → `route('dashboard')`
- Background: `bg-surface-0/85 backdrop-blur-md` + `border-b border-line/60`. Sticky at top.

#### Hero

- One-line eyebrow (small, uppercase-tracked, cyan, sentence-cased in Arabic): e.g. `Foodics × Daftra`.
- Display headline, two lines max. Operator outcome, not feature. English copy: `Your Foodics sales land in Daftra. Automatically.` Two sentences are intentional — the period is the punch. The verb "land" is deliberate (concrete, accountant-recognisable as an entry arriving in a ledger); do not soften to "appear" or "show up".
- Sub-headline: `Stop double-entering invoices. Connect both accounts once, and every sale, return, and product flows into Daftra on its own.` followed by a short trailing sentence on its own line (or visually de-emphasised inline): `ZATCA compliance included.` Render the two as separate `__()` keys so they can be styled / wrapped independently. The trailing sentence sits in `text-ink-muted` and `text-sm`, sized down from the main sub-headline.
- Primary CTA — **auth-aware**:
  - Guest: `Connect your accounts` → `route('login')`
  - Authenticated: `Dashboard` → `route('dashboard')`
  Same visual treatment in both cases. Full-width on small screens; auto-width on `sm:` and up. Uses the existing `btn-shadow` style and `bg-accent text-on-accent`.
- Secondary link, immediately below CTA: `See how it works` — same-page anchor to `#how-it-works`. Plain text link in `text-ink-muted`, hover `text-ink`.
- Right side (LG and up): the **sync diagram** (see §3 below). On mobile, the diagram sits beneath the CTA.

#### What's automatic — three capabilities (varied layout, NOT identical cards)

Render as a single grid of three horizontal bands, each with: a large oklch-tinted ordinal numeral (`01`, `02`, `03`) on the leading edge, a short outcome-framed title, one sentence of plain-language description. Each band has its own subtle, distinct treatment so the three are visually related but not interchangeable:

| Band | Title (EN) | Body (EN) | Visual differentiator |
|---|---|---|---|
| 01 | Sales become invoices | Every Foodics order lands in Daftra as an invoice within seconds, with customer details attached. | Solid surface, faint cyan rule along the bottom edge. |
| 02 | Products stay matched | Your Foodics catalogue mirrors into Daftra, so every invoice line item matches the product that sold. | Tinted `surface-1` band, no rule. |
| 03 | Returns become credit notes | Cancelled and returned orders turn into Daftra credit notes. No manual cleanup. | Solid surface, faint cyan rule along the top edge. |

Bands are roughly equal height but copy may make them slightly uneven on purpose. **No icon medallions**, **no equal cards**, no shadow.

#### How it works — three steps

A vertical numbered list (`<ol>`), each step a row with: a step number (cyan circle, 24px), an Arabic/English step title, one line. This is the section where the Daftra and Foodics brand colors finally appear — small logo tiles inline within steps 1 and 2 (re-using the brand-logo tile pattern from `login.blade.php`, lines 44–73).

1. **Connect Daftra** — small Daftra-blue tile + line of copy.
2. **Connect Foodics** — small Foodics-red tile + line of copy.
3. **Carry on running your restaurant** — no tile; one line of copy.

End the section with the same primary CTA repeated (auth-aware — `Connect your accounts` for guests, `Dashboard` for authenticated users).

#### Reliability strip

Four short, equally-weighted bullet items on one horizontal row (wraps to a 2×2 grid under `md:` and a vertical stack under `sm:`). Plain text, no cards. Operator-readable language for the first two, accountant-readable language for the last two:

- `Syncs within seconds of every sale.`
- `Failed syncs retry on their own.`
- `A full audit trail for every invoice.`
- `Every ZATCA phase, cleared in Daftra.`

Use `text-ink-muted` with the bullets rendered as cyan `•` glyphs (a `before:` pseudo or a real glyph; match `app.css` conventions).

#### Footer

- A single horizontal rule (`bg-gradient-to-r from-transparent via-line to-transparent`, matching the sidebar pattern in `layouts/app.blade.php` line 48).
- One row: app name on leading edge, language switcher on trailing edge, copyright line in `text-xs text-ink-muted` centred between (or below on mobile).

### 3. Sync diagram (hero visual)

A single inline SVG, ≤200 lines, drawn at intrinsic `width="320" height="240"` and scaled responsively. Composition:

- Two rounded-rectangle nodes: Foodics red (leading edge), Daftra blue (trailing edge). Each has a 1-line logo treatment — inline `<img>` overlays inside the SVG container are acceptable, mirroring the approach in `login.blade.php` lines 44–73.
- A cyan curved path connecting the two nodes — single stroke, no fill, `stroke-linecap="round"`, drawn with `var(--accent)`.
- A single small cyan dot traveling along the path via `animateMotion` with `dur="4s"` and `repeatCount="indefinite"`.
- A faint cyan glow behind the path using an SVG `<filter>` (Gaussian blur, low opacity).
- Wrap the entire diagram in a div that gets `motion-reduce:hidden` *only on the animated dot*; the static diagram remains visible. Alternatively, condition the `animateMotion` with the `@media (prefers-reduced-motion)` CSS rule that already exists in `app.css` (mirror the existing pattern at lines 291–302).
- Provide an `aria-label` and `role="img"` describing the diagram in plain language: `Foodics connects to Daftra` (translatable).

### 4. Copy

All user-facing strings must use `__()` with the **English copy itself as the key** per `CLAUDE.md` → Translations. Strings to introduce, with their Arabic translations to add in `lang/ar.json`.

**Copy principles applied** (operator-first, accountant-validatable):

- **Outcomes over features.** Section + band titles describe what *happens* to the operator's work (`Sales become invoices`, `Products stay matched`, `Returns become credit notes`), not the mechanism (`Live sales sync`, `Product catalogue sync`).
- **Plain language over jargon.** No `webhook`, `polling`, `push`, `endpoint`, or `event` in operator-facing copy. The reliability strip's third item keeps `audit trail` deliberately — accountants recognise it and it is a feature finance staff specifically want.
- **Active voice.** `Failed syncs retry on their own.` not `Retries are performed when syncs fail.`
- **No em dashes** in body copy (also banned in the global behavior guidelines). Use periods, commas, or colons.
- **Name the product.** Once the brand exists, use it: `Daftrics keeps both systems in sync` is concrete; `We keep…` is anonymous.
- **Match upstream terminology.** Daftra calls them *accounts*, Foodics calls them *businesses*. The "How it works" copy reflects each system's own word.
- **British English** throughout (`catalogue`, `authorise`) for consistency with the existing translation file convention.

**Brand name:** `Daftrics` is rendered via `config('app.name')` and is NOT wrapped in `__()` — brand names do not localise. The Arabic locale will still show `Daftrics` in Latin letters in the wordmark, which is intentional (matches how Foodics and Daftra display their own brand marks on Arabic surfaces).

**Pre-existing keys reused** (already in `lang/ar.json`, do not duplicate): `Dashboard`, `English`, `العربية`.

**ZATCA terminology — Arabic.** Anywhere the English copy uses `ZATCA`, the Arabic translation MUST use the spelled-out authority name `هيئة الزكاة والدخل` (e.g., `متطلبات هيئة الزكاة والدخل`). Do not transliterate as `زاتكا`. Saudi accountants and restaurant operators expect the authority's name, not the loanword. Compliance is attributed to **Daftra** in both languages — Daftrics is integration middleware and is not itself ZATCA-certified; Daftra is.

**New keys to add:**

| Key | Arabic |
|---|---|
| `Foodics × Daftra` | `فودكس × دفترة` |
| `Your Foodics sales land in Daftra.` | `مبيعات فودكس تصل إلى دفترة.` |
| `Automatically.` | `تلقائيًا.` |
| `Stop double-entering invoices. Connect both accounts once, and every sale, return, and product flows into Daftra on its own.` | `أوقف إدخال الفواتير مرتين. اربط الحسابين مرة واحدة، فينساب كل بيع ومرتجع ومنتج إلى دفترة من تلقاء نفسه.` |
| `ZATCA compliance included.` | `متوافق مع متطلبات هيئة الزكاة والدخل.` |
| `Connect your accounts` | `اربط حسابيك` |
| `See how it works` | `شاهد كيف يعمل` |
| `Sign in` | `تسجيل الدخول` |
| `What's automatic` | `ما يحدث تلقائيًا` |
| `Sales become invoices` | `المبيعات تصبح فواتير` |
| `Every Foodics order lands in Daftra as an invoice within seconds, with customer details attached.` | `كل طلب من فودكس يصل إلى دفترة كفاتورة خلال ثوانٍ، مع تفاصيل العميل.` |
| `Products stay matched` | `المنتجات تظل متطابقة` |
| `Your Foodics catalogue mirrors into Daftra, so every invoice line item matches the product that sold.` | `كتالوج فودكس يتطابق مع دفترة، فيطابق كل بند في الفاتورة المنتجَ المُباع.` |
| `Returns become credit notes` | `المرتجعات تصبح إشعارات دائنة` |
| `Cancelled and returned orders turn into Daftra credit notes. No manual cleanup.` | `الطلبات الملغاة والمرتجعة تتحول إلى إشعارات دائنة في دفترة. دون أي تنظيف يدوي.` |
| `How it works` | `كيف يعمل` |
| `Connect Daftra` | `اربط دفترة` |
| `Authorise your Daftra account in one click.` | `فوّض حساب دفترة بنقرة واحدة.` |
| `Connect Foodics` | `اربط فودكس` |
| `Authorise your Foodics business in one click.` | `فوّض حساب فودكس بنقرة واحدة.` |
| `Carry on running your restaurant` | `تابع إدارة مطعمك` |
| `Daftrics keeps both systems in sync, quietly.` | `Daftrics يحافظ على تزامن النظامين بهدوء.` |
| `Syncs within seconds of every sale.` | `مزامنة خلال ثوانٍ من كل عملية بيع.` |
| `Failed syncs retry on their own.` | `المزامنات الفاشلة تُعاد تلقائيًا.` |
| `A full audit trail for every invoice.` | `سجل تدقيق كامل لكل فاتورة.` |
| `Every ZATCA phase, cleared in Daftra.` | `دفترة متوافقة مع كل مراحل متطلبات هيئة الزكاة والدخل.` |
| `Foodics connects to Daftra` | `فودكس متصل بدفترة` |
| `Daftrics — Foodics sales land in Daftra, automatically` | `Daftrics — مبيعات فودكس تصل إلى دفترة، تلقائيًا` |
| `The Foodics × Daftra integration that turns every sale, return, and product into an up-to-date Daftra record, automatically.` | `تكامل Foodics × Daftra الذي يحوّل كل بيع ومرتجع ومنتج إلى سجل محدّث في دفترة، تلقائيًا.` |

No em dashes (`—`) inside copy keys are used except where they read naturally in English. The Arabic translations use Arabic punctuation only.

### 5. Design direction

- Use only existing OKLCH tokens from `resources/css/app.css`. Do not add new CSS variables.
- Surfaces: `bg-surface-0` for the page, `bg-surface-1` for the second-band of the "What's automatic" section. Never use `bg-white` or `bg-black`.
- Text: `text-ink` for primary, `text-ink-muted` for secondary, `text-accent` for the eyebrow and inline emphasis.
- Borders: `border-line` only, with `/60` opacity where softness is wanted.
- Cyan accent: always via `bg-accent` / `text-accent` / `border-accent`. Never via raw OKLCH literals.
- Brand colors: `bg-brand-daftra` and `bg-brand-foodics` appear only in the sync diagram and the "How it works" tiles. They never touch headings, body copy, CTAs, or section backgrounds.
- Spacing rhythm: vary section padding deliberately. Hero `py-20 lg:py-28`, "What's automatic" `py-16`, "How it works" `py-20`, reliability strip `py-10`, footer `py-8`. Do not use the same value everywhere.
- Typography hierarchy: display headline `font-semibold`, sub-headline `text-lg`, section titles `text-2xl font-semibold`, body `text-base leading-relaxed`. Maintain ≥1.25 ratio between adjacent steps.
- Body text width: cap at `max-w-[65ch]` for prose blocks; the hero sub-headline at `max-w-[60ch]`.
- Motion: hero block uses `.page-content` animation already defined. The "How it works" steps use `.card-stagger` with incremental `--stagger` values (0, 1, 2). No new keyframes.

### 6. Accessibility

- All interactive controls have visible focus rings using `focus:ring-2 focus:ring-accent-ring focus:ring-offset-2 focus:ring-offset-surface-0` (the exact pattern from `login.blade.php`).
- SVG diagram has `role="img"` and a translated `aria-label`.
- Heading order: single `<h1>` (the hero display headline), `<h2>` for each section title, `<h3>` for sub-titles in capability bands and how-it-works steps.
- The top-bar nav link and the primary CTA share a label in both auth states (`Sign in` for guests, `Dashboard` for authenticated) and navigate to the same place. They are visually distinct (plain text link vs solid accent button) so this is not an accessibility duplication concern; screen readers will announce both with their distinct roles (link vs button).
- Skip-to-content link is not required given the page has no left-side nav and the hero CTA is reachable in one tab.
- Reduced motion: the existing `prefers-reduced-motion` rule in `app.css` already disables `.page-content`, `.card-stagger`, `.badge-pulse`, and `.dot-connected`. The SVG dot animation must be conditioned the same way (additional CSS rule inside `app.css`, OR an inline `<style>` inside the SVG that respects the media query — choose the in-`app.css` approach for consistency).

## Files to Create

1. `app/Http/Controllers/LandingController.php` — invokable, returns view or redirects auth'd users to dashboard.
2. `resources/views/landing.blade.php` — the standalone landing view.
3. `tests/Feature/LandingPageTest.php` — Pest feature tests (see §Tests).

## Files to Modify

1. `routes/web.php` — add `GET /` named `landing`, move dashboard to `/dashboard`.
2. `lang/ar.json` — append Arabic translations from §4 Copy. Insert alphabetically or at the end; match existing file's JSON style. Skip keys already present (`Dashboard`, `English`, `العربية`).
3. `resources/css/app.css` — add **one** new keyframe / rule for the SVG dot animation, gated by the existing `@media (prefers-reduced-motion: reduce)` block. No token changes.
4. `.env.example` — set `APP_NAME=Daftrics` so new clones default to the right brand name. Instruct the user (in the implementation hand-off) to update their local `.env` likewise; do not edit `.env` directly.

## Edge Cases

| Scenario | Behaviour |
|---|---|
| Authenticated user visits `/` | Render landing view. Top-bar link reads `Dashboard` → `/dashboard`; primary CTA reads `Dashboard` → `/dashboard`. |
| Guest visits `/` | Render landing view. Top-bar link reads `Sign in` → `/login`; primary CTA reads `Connect your accounts` → `/login`. |
| Guest visits `/dashboard` | Existing `auth` middleware redirects to `/login` (unchanged). |
| User clicks `Sign in` or primary CTA | Navigated to `/login`; existing connect screen handles the rest. |
| Locale is Arabic | Page renders RTL with Cairo, sync diagram mirrors logically (cyan path direction reverses naturally because SVG `direction` is preserved). |
| `prefers-reduced-motion` is on | Hero / staggered enters skip; SVG dot is static. |
| `prefers-color-scheme: dark` | All tokens swap via the existing `@media (prefers-color-scheme: dark)` block — no extra code. |
| Existing tests referencing `GET /` for dashboard | Update them to `GET /dashboard`. The route name `dashboard` stays valid for `route()` helpers in code. |

## Tests

Create `tests/Feature/LandingPageTest.php` using Pest. Required coverage:

- **Guests see the landing page on `/`**: status 200, response contains the translated headline.
- **Guests do NOT get redirected** from `/` to `/login`.
- **Guest CTA**: response body contains the URL of `route('login')` and the literal `Connect your accounts` (translated when locale is `ar`).
- **Authenticated users on `/`** also see the landing page (status 200, NOT a redirect).
- **Authenticated CTA**: when an authenticated user hits `/`, the response body contains the URL of `route('dashboard')` and the literal `Dashboard` (translated when locale is `ar`). The guest copy (`Connect your accounts`) is absent.
- **Landing page renders in Arabic** when locale is set to `ar`: response contains the Arabic headline, and the `<html dir="rtl">` attribute is present.
- **Language switcher form** posts to `route('language.switch')` (assert form action).
- **App name is "Daftrics"** in the response (regression catching any hard-coded fallback).
- **Dashboard remains accessible at `/dashboard`** for authenticated users (basic regression: status 200).
- **Dashboard at `/dashboard` redirects guests** to `/login` (regression).

If feasible without too much weight, add a Pest 4 browser test (`tests/Browser/LandingPageTest.php`) that:

- Visits `/`, asserts no JavaScript errors, asserts the `Connect your accounts` button is visible, clicks it, and lands on `/login`.

Run:

```bash
php artisan test --compact tests/Feature/LandingPageTest.php
vendor/bin/pint --dirty --format agent
npm run build
```

## Tasks

- [ ] Set `APP_NAME=Daftrics` in `.env.example` and prompt the user to mirror the change in their local `.env`.
- [ ] Create `LandingController` (invokable) at `app/Http/Controllers/LandingController.php`.
- [ ] Update `routes/web.php`: add `GET /` named `landing`, move dashboard to `/dashboard`.
- [ ] Audit existing route references to `/` (tests, controllers, views) and update any that assumed `/` was the dashboard.
- [ ] Create `resources/views/landing.blade.php` with all sections from §2, including the auth-aware top-bar link and primary CTA.
- [ ] Build the inline SVG sync diagram (§3) with reduced-motion handling.
- [ ] Add the SVG dot animation rule to `resources/css/app.css` inside the existing reduced-motion block.
- [ ] Add all new translation strings from §4 to `lang/ar.json` (skip pre-existing keys).
- [ ] Verify visual rendering in: light Arabic, light English, dark Arabic, dark English, both guest and authenticated states.
- [ ] Create `tests/Feature/LandingPageTest.php` covering the cases in §Tests.
- [ ] (Optional) Create `tests/Browser/LandingPageTest.php` smoke test covering both auth states.
- [ ] Run `php artisan test --compact tests/Feature/LandingPageTest.php`.
- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `npm run build`.

## Out of Scope

- No marketing copy beyond the headlines, capability bands, and steps defined in §4. No FAQ, testimonials, pricing section, screenshots gallery, video, blog, or contact form.
- No new components in `resources/views/components/`; the landing page is a one-off composition.
- No analytics, tracking pixels, or cookie banner.
- No SEO meta tags beyond the `<title>` and `<meta name="description">` defined in §2 → Page head. No Open Graph, Twitter cards, JSON-LD, or canonical tags in this pass.
- No marketing site routing (e.g., `/features`, `/pricing`). The landing is a single page.
- No changes to the existing `/login` connect screen.
- No new color tokens, font stacks, or design primitives.
- No public sitemap, robots.txt change, or feed.
