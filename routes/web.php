<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use TCG\Voyager\Facades\Voyager;
use Wave\Facades\Wave;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\AddTransactionController;

// Authentication routes
Auth::routes();

// Voyager Admin routes
Route::group(['prefix' => 'admin'], function () {
    Voyager::routes();
});
Route::get('/admin/Dashboard', [DashboardController::class, 'index'])->name('voyager.dashboard');

Route::get('/customer/transactions/filter', [CustomerController::class, 'filterTransactions'])->name('customer.transactions.filter');
Route::get('/transactions/filter', [DashboardController::class, 'getAllTransactions'])->name('dashboard.transactions.filter');
Route::get('/transactions/user-transaction/{id}', [AddTransactionController::class, 'userTransaction'])->name('user.transaction');


// Wave routes
Wave::routes();
