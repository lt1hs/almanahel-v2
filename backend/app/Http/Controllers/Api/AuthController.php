<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->with('branch')->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            ActivityLogger::record(
                'auth',
                'login_failed',
                'ورود ناموفق — رمز یا ایمیل اشتباه',
                null,
                ['email' => $request->email],
                null,
                $user?->id,
            );
            throw ValidationException::withMessages([
                'email' => ['اعتبارسنجی با شکست مواجه شد. اطلاعات وارد شده اشتباه است.'],
            ]);
        }

        if ($user->status !== 'active') {
            ActivityLogger::record(
                'auth',
                'login_failed',
                'ورود ناموفق — حساب غیرفعال',
                $user,
                ['email' => $request->email],
                $user->branch_id ? (int) $user->branch_id : null,
                (int) $user->id,
            );
            throw ValidationException::withMessages([
                'email' => ['حساب کاربری غیرفعال یا معلق است. با مدیر سیستم تماس بگیرید.'],
            ]);
        }

        $token = $user->createToken('al-manahel-token')->plainTextToken;

        ActivityLogger::record(
            'auth',
            'login',
            "ورود موفق — {$user->name}",
            $user,
            ['email' => $user->email],
            $user->branch_id ? (int) $user->branch_id : null,
            (int) $user->id,
        );

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'خوش آمدید'
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        ActivityLogger::record(
            'auth',
            'logout',
            "خروج — {$user->name}",
            $user,
            ['email' => $user->email],
            $user->branch_id ? (int) $user->branch_id : null,
            (int) $user->id,
        );

        return response()->json(['message' => 'خروج با موفقیت انجام شد']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user()->load('branch'));
    }
}
