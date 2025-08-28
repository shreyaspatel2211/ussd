<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use TCG\Voyager\Facades\Voyager;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class VoyagerAuthController extends Controller
{
    use AuthenticatesUsers;

    public function login()
    {
        if ($this->guard()->user()) {
            return redirect()->route('voyager.dashboard');
        }

        return Voyager::view('voyager::login');
    }

    public function postLogin(Request $request)
    {
        $this->validateLogin($request);

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        $credentials = $this->credentials($request);

        if ($this->guard()->attempt($credentials, $request->has('remember'))) {
            $user = $this->guard()->user();

            if($user->role_id == 7) {
                if ($user->company_id) {
                    $company = $user->company; // Assuming relationship is defined in the User model

                    if ($company) {
                        $current_time = Carbon::now();
                        $opening_time = Carbon::parse($company->opening_time);
                        $closing_time = Carbon::parse($company->closing_time);
                        
                        // Check if current time is within the allowed range
                        if (!$current_time->between($opening_time, $closing_time)) {
                            Auth::logout();

                            return redirect()->route('voyager.login')->withErrors([
                                'error' => 'Login is allowed only between ' . $opening_time->format('H:i') . ' and ' . $closing_time->format('H:i'),
                            ]);
                        }
                    } else {
                        Auth::logout();

                        return redirect()->route('voyager.login')->withErrors([
                            'error' => 'Company details are missing for this user.',
                        ]);
                    }
                } else {
                    Auth::logout();

                    return redirect()->route('voyager.login')->withErrors([
                        'error' => 'User is not associated with any company.',
                    ]);
                }
            } else {
                $current_time = Carbon::now();
                $opening_time = Carbon::parse('1:00');
                $closing_time = Carbon::parse('23:59');
                
                // Check if current time is within the allowed range
                if (!$current_time->between($opening_time, $closing_time)) {
                    Auth::logout();

                    return redirect()->route('voyager.login')->withErrors([
                        'error' => 'Login is allowed only between ' . $opening_time->format('H:i') . ' and ' . $closing_time->format('H:i'),
                    ]);
                }
            }

            return $this->sendLoginResponse($request);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        $this->incrementLoginAttempts($request);

        return $this->sendFailedLoginResponse($request);
    }

    /*
     * Preempts $redirectTo member variable (from RedirectsUsers trait)
     */
    public function redirectTo()
    {
        return config('voyager.user.redirect', route('voyager.dashboard'));
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard(app('VoyagerGuard'));
    }
}
