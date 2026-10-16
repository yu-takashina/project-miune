<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Manager\SitemapController;
use App\Http\Controllers\SearchController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/manager/search', SearchController::class)->middleware('throttle:30,1')->name('search');
    Route::get('/manager', function (): RedirectResponse {
        return redirect()->route('sitemap.index');
    })->name('manager');
    Route::get('/manager/sitemap', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::post('/manager/sitemaps', [SitemapController::class, 'store'])->name('manager.sitemaps.store');
    Route::post('/manager/sitemaps/{sitemap}/crawl', [SitemapController::class, 'crawlNow'])->name('manager.sitemaps.crawl');
    Route::get('/manager/sitemaps/{sitemap}', [SitemapController::class, 'show'])->name('manager.sitemaps.show');
    Route::patch('/manager/sitemaps/{sitemap}/pages/{page}', [SitemapController::class, 'updateSearchable'])->name('manager.sitemaps.pages.searchable');
    Route::post('/manager/sitemaps/{sitemap}/pages/{page}/crawl', [SitemapController::class, 'crawl'])->name('manager.sitemaps.pages.crawl');
    Route::patch('/manager/sitemaps/{sitemap}/absence-limit', [SitemapController::class, 'updateAbsenceLimit'])->name('manager.sitemaps.absence-limit');
    Route::delete('/manager/sitemaps/{sitemap}', [SitemapController::class, 'destroy'])->name('manager.sitemaps.destroy');
});
