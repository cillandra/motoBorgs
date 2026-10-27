<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreferenceRequest;
use App\Services\Settings\PreferenceService;

class PreferenceController extends Controller
{
    public function update(PreferenceRequest $request, PreferenceService $preferences): mixed
    {
        $preference = $preferences->save($request->user(), $request->validated('values'));

        return $request->expectsJson() ? response()->json(['values' => $preference->value, 'message' => 'Your preferences were saved.']) : redirect()->route('settings.index', ['section' => 'preferences'])->with('status', 'Your preferences were saved.');
    }
}
