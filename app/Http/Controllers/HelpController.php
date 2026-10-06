<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Help & support (placeholder until Story 1.18): the shell for signed-in people, the auth layout for guests. */
final class HelpController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return $request->user() === null
            ? Inertia::render('auth/Help')
            : Inertia::render('Placeholder', ['page' => 'help']);
    }
}
