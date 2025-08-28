<?php

use Illuminate\Http\Request;
use App\Http\Controllers\HubtelController;
use App\Http\Controllers\APIController;
use App\Http\Controllers\DatamonkApiController;

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

Route::post('/transaction-summary', [APIController::class, 'getTransactionSummary']);
Route::post('/get-customers', [APIController::class, 'getCustomers']);
Route::post('/generate-report', [APIController::class, 'generateReport']);

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return auth()->user();
});

//datamonk new integration APIs
Route::post('/datamonk/customers', [DatamonkApiController::class, 'getCustomersByBusinessId']);

Route::post('/datamonk/transaction-data', [DatamonkApiController::class, 'getTransactionSummary']);
Route::post('/datamonk/get-customers', [DatamonkApiController::class, 'getCustomers']);

Wave::api();

