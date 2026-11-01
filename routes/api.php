<?php

use App\Http\Controllers\PublicLeadController;
use Illuminate\Support\Facades\Route;

/*
| Public API (stateless). Landing-page forms post here.
| POST /api/v1/public/leads
*/
Route::prefix('v1/public')->group(function () {
    Route::post('leads', [PublicLeadController::class, 'store'])
        ->middleware('throttle:public-leads')
        ->name('api.public.leads.store');
});
