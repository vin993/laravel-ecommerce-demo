<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use App\Http\Controllers\Controller;

class ForgotPasswordController extends Controller
{
    public function create()
    {
        return view('customers.forgot-password');
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        try {
            $response = Password::broker('customers')->sendResetLink(
                $request->only('email')
            );

            if ($response == Password::RESET_LINK_SENT) {
                return redirect()->back()->with('success', 'We have emailed your password reset link!');
            }

            if ($response == Password::INVALID_USER) {
                return redirect()->back()->with('error', 'We could not find a user with that email address.');
            }

            return redirect()->back()->with('error', 'Unable to send reset link. Please try again.');
        } catch (\Exception $e) {
            \Log::error('Password reset exception', [
                'error' => $e->getMessage(),
                'email' => $request->email
            ]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}
