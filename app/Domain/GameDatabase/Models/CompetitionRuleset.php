<?php

namespace App\Domain\GameDatabase\Models;

use App\Domain\Football\Engine\V6\AddedTimeRules;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionRuleset extends Template
{
    protected $casts = [
        'tiebreakers' => 'array', 'hydration_breaks_enabled' => 'boolean',
        'dynamic_added_time_enabled' => 'boolean', 'added_time_rules' => 'array',
    ];

    protected function rules(): array
    {
        return [
            'max_substitutes' => ['sometimes', 'integer', 'between:0,12'],
            'max_substitutions' => ['sometimes', 'integer', 'between:0,5'],
            'max_substitution_windows' => ['sometimes', 'integer', 'between:0,5'],
            'hydration_breaks_enabled' => ['sometimes', 'boolean'],
            'match_engine_version' => ['sometimes', 'in:5.0.0,6.0.0'],
            'dynamic_added_time_enabled' => ['sometimes', 'boolean'],
            'added_time_rules' => ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    AddedTimeRules::normalize($value);
                } catch (\InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'code' => ['required', 'string', 'max:64'],
            'format' => ['required', 'in:double_round_robin'],
            'club_count' => ['required', 'integer', 'min:2', 'multiple_of:2'],
            'legs' => ['required', 'integer', 'in:2'],
            'round_count' => ['required', 'integer', 'in:'.(($this->club_count - 1) * 2)],
            'match_count' => ['required', 'integer', 'in:'.($this->club_count * ($this->club_count - 1))],
            'points_for_win' => ['required', 'integer', 'min:0'],
            'points_for_draw' => ['required', 'integer', 'min:0'],
            'points_for_loss' => ['required', 'integer', 'min:0'],
            'tiebreakers' => ['required', 'array', 'min:1'],
            'tiebreakers.*' => ['required', 'distinct', 'in:wins,goal_difference,goals_for,club_code'],
        ];
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(CompetitionTemplate::class);
    }
}
