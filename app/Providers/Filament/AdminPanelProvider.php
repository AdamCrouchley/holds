<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** Return the first class that exists from a list of FQCN candidates. */
    private function firstExistingClass(array $candidates): ?string
    {
        foreach ($candidates as $fqcn) {
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }
        return null;
    }

    public function panel(Panel $panel): Panel
    {
        // ---------- Build custom navigation (lazy URLs; safe existence checks) ----------
        $nav = [];

        $defs = [
            // Developer tools
            [
                'candidates' => [
                    'App\\Filament\\Resources\\ApiKeyResource',
                    'App\\Filament\\Admin\\Resources\\ApiKeyResource',
                ],
                'label' => 'API Keys',
                'icon'  => 'heroicon-o-key',
                'group' => 'Developer',
                'sort'  => 90,
            ],
            [
                'candidates' => [
                    'App\\Filament\\Resources\\AutomationResource',
                    'App\\Filament\\Admin\\Resources\\AutomationResource',
                ],
                'label' => 'Automations',
                'icon'  => 'heroicon-o-cog-6-tooth',
                'group' => 'Developer',
                'sort'  => 80,
            ],

            // Printing (optional)
            [
                'candidates' => [
                    'App\\Filament\\Resources\\PrintingJobResource',
                    'App\\Filament\\Admin\\Resources\\PrintingJobResource',
                ],
                'label' => 'Printing Jobs',
                'icon'  => 'heroicon-o-rectangle-stack',
                'group' => 'Printing',
                'sort'  => 70,
            ],
        ];

        foreach ($defs as $d) {
            $class = $this->firstExistingClass($d['candidates']);
            if (! $class) {
                continue;
            }

            /** @var class-string<\Filament\Resources\Resource> $class */
            $slug = $class::getSlug();

            $nav[] = NavigationItem::make($d['label'])
                ->icon($d['icon'])
                ->group($d['group'])
                ->sort($d['sort'])
                // Lazy URL -> routes exist at evaluation time
                ->url(fn () => $class::getUrl('index', panel: 'admin'))
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.' . $slug . '.*'));
        }

        // Magic Link Builder (custom non-Filament page)
        $magicRoute = Route::has('portal.magic')
            ? 'portal.magic'
            : (Route::has('magic.trigger') ? 'magic.trigger' : null);

        if ($magicRoute) {
            $nav[] = NavigationItem::make('Magic Link Builder')
                ->icon('heroicon-o-link')
                ->group('Developer')
                ->sort(10)
                ->url(fn () => route($magicRoute));
        }

        // ------------------------------ Panel definition ------------------------------
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors(['primary' => Color::Amber])
            ->discoverResources(in: app_path('Filament/Resources'),       for: 'App\\Filament\\Resources')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'),       for: 'App\\Filament\\Pages')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class])
            ->navigationItems($nav);
    }
}
