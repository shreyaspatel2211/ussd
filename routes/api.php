<?php

use Illuminate\Http\Request;
use App\Http\Controllers\HubtelController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::post('{company_id}/ussd', [HubtelController::class, 'hubtelUSSD']);
Route::post('ussd-test', [HubtelController::class, 'hubtelUSSDtest']);
Route::post('{company_id}/ussd/callback', [HubtelController::class, 'hubtelUSSDCallback']);

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return auth()->user();
});

Wave::api();

