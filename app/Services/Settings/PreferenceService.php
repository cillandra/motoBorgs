<?php

namespace App\Services\Settings;

use App\Models\Preference;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\System\OperationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PreferenceService
{
    public const DEFAULTS = ['theme' => 'dark', 'density' => 'comfortable', 'rows' => 20, 'reduced_motion' => false, 'sound' => false];

    public function read(User $actor): array
    {
        return array_replace(self::DEFAULTS, $actor->preference?->value ?? []);
    }

    public function rows(User $actor): int
    {
        $rows = (int) $this->read($actor)['rows'];

        return in_array($rows, [10, 20, 25, 50], true) ? $rows : 20;
    }

    public function rules(): array
    {
        return ['theme' => ['required', 'in:dark,light'], 'density' => ['required', 'in:comfortable,compact'], 'rows' => ['required', 'integer', 'in:10,20,25,50'], 'reduced_motion' => ['required', 'boolean'], 'sound' => ['required', 'boolean']];
    }

    public function save(User $actor, array $values): Preference
    {
        abort_unless($actor->fresh()?->active, 403);
        $data = Validator::make(['values' => $values], ['values' => ['required', 'array:theme,density,rows,reduced_motion,sound'], ...collect($this->rules())->mapWithKeys(fn ($rules, $key) => ['values.'.$key => $rules])->all()])->validate()['values'];
        $data['rows'] = (int) $data['rows'];
        $data['reduced_motion'] = (bool) $data['reduced_motion'];
        $data['sound'] = (bool) $data['sound'];

        return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($actor, $data): Preference {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->active, 403);
            $preference = Preference::updateOrCreate(['user_id' => $actor->id], ['value' => $data]);
            app(AuditRecorder::class)->record($actor, 'Preferences updated', 'settings', 'employee', (string) $actor->id, 'success', ['changes' => $data]);

            return $preference;
        }, 3));
    }
}
