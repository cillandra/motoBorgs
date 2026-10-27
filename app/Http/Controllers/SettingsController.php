<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Models\BackupManifest;
use App\Services\Auth\Reauthentication;
use App\Services\Settings\PreferenceService;
use App\Services\Settings\SettingsService;
use App\Services\System\DiagnosticsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public const LABELS = ['general' => 'General', 'sales' => 'Sales & payments', 'receipts' => 'Receipts & printing', 'inventory' => 'Inventory rules', 'notifications' => 'Notifications', 'security' => 'Security & access', 'backup' => 'Backup & recovery', 'system' => 'System & maintenance', 'preferences' => 'Preferences', 'account' => 'My account', 'about' => 'About MOTO'];

    public function index(Request $request, SettingsService $settings, PreferenceService $preferences): mixed
    {
        $section = $request->validate(['section' => ['nullable', 'in:'.implode(',', array_keys(self::LABELS))]])['section'] ?? ($request->user()->isManager() ? 'general' : 'preferences');
        abort_unless($request->user()->isManager() || in_array($section, ['preferences', 'account', 'about'], true), 403, 'This section requires Manager access.');
        $categories = $request->user()->isManager() ? array_diff_key(self::LABELS, ['about' => true]) : array_intersect_key(self::LABELS, array_flip(['preferences', 'account', 'about']));
        try {
            $read = isset(SettingsService::DEFAULTS[$section]) ? $settings->read($request->user(), $section) : ['values' => $preferences->read($request->user()), 'version' => 0];
            $values = $read['values'];
            $version = $read['version'];
            $diagnostics = in_array($section, ['system', 'about'], true) ? $this->diagnostics($request->user()->isManager()) : [];
            $backupHistory = $section === 'backup' ? BackupManifest::latest('id')->limit(12)->get() : collect();
            $scheduler = $section === 'backup' ? app(DiagnosticsService::class)->scheduler() : [];
            $sessionCount = $section === 'account' ? DB::table('sessions')->where('user_id', $request->user()->id)->count() : 0;

            return view('settings.index', compact('section', 'categories', 'values', 'version', 'diagnostics', 'sessionCount', 'backupHistory', 'scheduler'));
        } catch (QueryException $error) {
            report($error);

            return response()->view('settings.index', compact('section', 'categories') + ['unavailable' => true], 503);
        }
    }

    private function diagnostics(bool $manager): array
    {
        return app(DiagnosticsService::class)->facts($manager);
    }

    public function update(SettingsRequest $request, string $section, SettingsService $settings, Reauthentication $reauthentication): mixed
    {
        $data = $request->validated();
        if ($section === 'security') {
            $reauthentication->confirm($request->user(), $data['manager_password'], 'Security settings change');
        }
        $saved = $settings->save($request->user(), $section, $data['values'], (int) $data['version']);

        return $request->expectsJson() ? response()->json($saved + ['message' => 'Settings saved.']) : redirect()->route('settings.index', ['section' => $section])->with('status', 'Settings saved.');
    }

    public function receiptPreview(Request $request, SettingsService $settings): mixed
    {
        return view('settings.receipt-preview', ['values' => $settings->read($request->user(), 'receipts')['values'], 'shop' => $settings->read($request->user(), 'general')['values']]);
    }
}
