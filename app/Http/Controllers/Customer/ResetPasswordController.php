<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

class ResetPasswordController extends Controller
{
    public function create($token = null)
    {
        return view('customers.reset-password')->with([
            'token' => $token,
            'email' => request('email'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $response = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($customer, $password) {
                $customer->password = Hash::make($password);
                $customer->save();
            }
        );

        if ($response == Password::PASSWORD_RESET) {
            return redirect()->route('customer.session.index')->with('success', 'Your password has been reset successfully! Please login with your new password.');
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Unable to reset password. Please try again or request a new reset link.']);
    }
}
