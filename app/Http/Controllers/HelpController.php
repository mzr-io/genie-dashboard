<?php

namespace App\Http\Controllers;

use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help & support: inside the shell for signed-in people, in the auth layout for guests. Only a signed-in
 * person's page reads the Workspace's links and contact address (read-only; Epic 8 edits them); a guest has
 * no Workspace, so the list is empty and the contact line is plain text.
 */
final class HelpController extends Controller
{
    public function __invoke(Request $request, WorkspaceSettings $settings): Response
    {
        if ($request->user() === null) {
            return Inertia::render('auth/Help', ['helpLinks' => [], 'contactHref' => null]);
        }

        return Inertia::render('Help', [
            'helpLinks' => $settings->helpLinks(),
            'contactHref' => $settings->contactHref(),
        ]);
    }
}
