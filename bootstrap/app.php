<?php

use App\Console\Commands\ClearApp;
use App\Console\Commands\ExpireP2POfferPromotions;
use App\Console\Commands\ExpireP2POrders;
use App\Console\Commands\MakeBackendController;
use App\Console\Commands\OptimizeApp;
use App\Console\Commands\ProcessSubscriptions;
use App\Console\Commands\ProcessWalletEarnRewards;
use App\Console\Commands\SyncUserFeatures;
use App\Http\Controllers\Frontend\PwaController;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Middleware\BlockIp;
use App\Http\Middleware\CheckUserFeature;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\DemoMode;
use App\Http\Middleware\EnsureAdminFeatureEnabled;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureKYCVerified;
use App\Http\Middleware\EnsureP2PCountryAllowed;
use App\Http\Middleware\EnsureP2PEnabled;
use App\Http\Middleware\EnsureTwoFactorAuthenticated;
use App\Http\Middleware\FilterBoostBrowserLoggerScript;
use App\Http\Middleware\HandleReferralLinks;
use App\Http\Middleware\LockScreen;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\MerchantApiAuth;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetPaginationView;
use App\Http\Middleware\Translate;
use App\Http\Middleware\VerifyBitnobSignature;
use App\Http\Middleware\XSS;
use App\Support\FrontendAuthRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use JoeDixon\Translation\Console\Commands\SynchroniseMissingTranslationKeys;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // PWA endpoints intentionally have NO session / CSRF middleware. A new
            // session per manifest or service-worker fetch would attach Set-Cookie
            // headers and force Cache-Control: private, which interferes with
            // browser PWA install detection and bloats session storage.
            Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
            Route::get('/service-worker.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');
            Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
            Route::get('/launch', [PwaController::class, 'launcher'])->name('pwa.launcher');
            Route::get('/install-app', [PwaController::class, 'install'])->name('pwa.install');

            Route::middleware('web')
                ->group(base_path('routes/auth.php'));

            Route::middleware(['web', 'auth:admin', 'verified', 'XSS', 'lock_screen', '2fa', 'demo'])
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (Request $request): string => FrontendAuthRoutes::loginUrlFor($request));
        $middleware->redirectUsersTo(fn (Request $request): string => FrontendAuthRoutes::authenticatedUrlFor($request));

        $middleware->validateCsrfTokens(except: [
            'ipn/*',
            'webhooks/bitnob',
        ]);
        $middleware->append([
            SecureHeaders::class,

        ]);

        // Apply EnsureFrontendRequestsAreStateful only to API routes
        $middleware->appendToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->appendToGroup('web', [
            FilterBoostBrowserLoggerScript::class,
            MaintenanceMode::class,
            SetPaginationView::class,
            HandleReferralLinks::class,
            Translate::class,

        ]);

        $middleware->alias([
            'auth'                 => AuthenticateMiddleware::class,
            'auth.session'         => AuthenticateSession::class,
            'account.status.check' => CheckUserStatus::class,
            'merchant.auth'        => MerchantApiAuth::class,
            'kyc.verified'         => EnsureKYCVerified::class,
            'guest'                => RedirectIfAuthenticated::class,
            'role'                 => RoleMiddleware::class,
            'permission'           => PermissionMiddleware::class,
            'role_or_permission'   => RoleOrPermissionMiddleware::class,
            'XSS'                  => XSS::class,
            'block.ip'             => BlockIp::class,
            'lock_screen'          => LockScreen::class,
            '2fa'                  => EnsureTwoFactorAuthenticated::class,
            'prevent.duplicate'    => PreventDuplicateSubmission::class,
            'feature'              => CheckUserFeature::class,
            'feature.enabled'      => EnsureFeatureEnabled::class,
            'admin.feature'        => EnsureAdminFeatureEnabled::class,
            'p2p.enabled'          => EnsureP2PEnabled::class,
            'p2p.country'          => EnsureP2PCountryAllowed::class,
            'demo'                 => DemoMode::class,
            'bitnob.signature'     => VerifyBitnobSignature::class,

        ]);
    })
    ->withCommands([
        SynchroniseMissingTranslationKeys::class,
        ClearApp::class,
        OptimizeApp::class,
        SyncUserFeatures::class,
        MakeBackendController::class,
        ExpireP2POrders::class,
        ExpireP2POfferPromotions::class,
        ProcessWalletEarnRewards::class,
        ProcessSubscriptions::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        // Auto-expire P2P orders
        $schedule->command('p2p:orders:expire')->everyMinute();

        $schedule->command('p2p:promotions:expire')->everyMinute();

        $schedule->command('wallet-earn:process')->everyMinute();

        $schedule->command('subscription:process --renewals')->hourly();

        $schedule->call(function () {
            $disk   = Storage::disk('public');
            $folder = 'images/temp/'.now()->subDay()->format('Y/m/d');

            if ($disk->exists($folder)) {
                $disk->deleteDirectory($folder);
                logger()->info("Summernote temp folder deleted: {$folder}");
            } else {
                logger()->info("No summernote temp folder found for deletion: {$folder}");
            }
        })->dailyAt('02:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
