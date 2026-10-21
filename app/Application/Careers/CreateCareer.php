<?php

namespace App\Application\Careers;

use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClubController;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateCareer
{
    public function __construct(private InitialSchedule $schedule) {}

    public function execute(User $owner, array $input): Career
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'coach_name' => ['required', 'string', 'max:100'],
            'club_template_id' => ['required', 'integer', 'exists:club_templates,id'],
            'request_id' => ['required', 'uuid'],
        ])->validate();
        $checksum = hash('sha256', json_encode([$data['name'], $data['coach_name'], (int) $data['club_template_id']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($owner, $data, $checksum): Career {
            // Serialize creation for this owner, including retries of the same request.
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $existing = Career::where('user_id', $owner->id)->where('creation_key', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->creation_checksum, $checksum)) {
                    throw ValidationException::withMessages(['request_id' => 'Esta solicitação já foi usada com outros dados.']);
                }

                return $existing;
            }

            $selected = ClubTemplate::with('competition.ruleset')->findOrFail($data['club_template_id']);
            $competition = $selected->competition;
            $ruleset = $competition->ruleset;
            $templates = $competition->clubs()->with(['players', 'stadium'])->orderBy('code')->get();
            if ($templates->count() !== $ruleset->club_count || $ruleset->format !== 'double_round_robin' || $ruleset->legs !== 2) {
                throw ValidationException::withMessages(['club_template_id' => 'A competição não possui um universo inicial completo.']);
            }
            foreach ($templates as $template) {
                if (! $template->stadium || $template->players->count() < 11 || ! $template->players->contains('primary_position', 'GK')) {
                    throw ValidationException::withMessages(['club_template_id' => 'Há clubes sem estádio ou elenco suficiente nos dados-base.']);
                }
            }

            $start = CarbonImmutable::parse(config('careers.initial_date'));
            $career = Career::create([
                'user_id' => $owner->id,
                'game_database_version_id' => $selected->game_database_version_id,
                'name' => $data['name'],
                'coach_name' => $data['coach_name'],
                'current_date' => $start,
                'state' => CareerState::AwaitingDecision,
                'current_season_number' => 1,
                'creation_key' => $data['request_id'],
                'creation_checksum' => $checksum,
            ]);
            $career->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $season = $career->seasons()->create(['number' => 1, 'starts_on' => $start, 'status' => 'active']);
            $edition = CompetitionEdition::create([
                'career_id' => $career->id,
                'career_season_id' => $season->id,
                'competition_template_id' => $competition->id,
                'name' => $competition->name,
                'rules' => $ruleset->only(['format', 'club_count', 'legs', 'round_count', 'match_count', 'points_for_win', 'points_for_draw', 'points_for_loss', 'tiebreakers']),
                'status' => 'scheduled',
            ]);

            $clubIds = [];
            foreach ($templates as $template) {
                $club = $career->clubs()->create($template->only(['code', 'name', 'short_name', 'city', 'primary_color', 'secondary_color', 'initial_balance_cents', 'default_ticket_price_cents']) + [
                    'club_template_id' => $template->id,
                    'balance_cents' => $template->initial_balance_cents,
                ]);
                $clubIds[] = $club->id;
                $club->stadium()->create($template->stadium->only(['name', 'capacity', 'available_capacity', 'infrastructure_level', 'fixed_operating_cost_cents', 'variable_cost_per_supporter_cents']) + [
                    'career_id' => $career->id,
                    'stadium_template_id' => $template->stadium->id,
                ]);
                $club->support()->create([
                    'career_id' => $career->id,
                    'potential_supporters' => $template->potential_supporters,
                    'loyalty' => $template->initial_loyalty,
                    'engagement' => $template->initial_engagement,
                ]);
                foreach ($template->players as $player) {
                    $club->players()->create($player->only(['name', 'age', 'primary_position', 'secondary_positions', ...\App\Domain\GameDatabase\Models\PlayerTemplate::ATTRIBUTES]) + [
                        'career_id' => $career->id,
                        'player_template_id' => $player->id,
                        'fitness' => $player->initial_fitness,
                        'morale' => $player->initial_morale,
                    ]);
                }
                CareerClubController::create([
                    'career_id' => $career->id,
                    'career_club_id' => $club->id,
                    'user_id' => $template->id === $selected->id ? $owner->id : null,
                    'type' => $template->id === $selected->id ? 'human' : 'ai',
                ]);
                DB::table('competition_entries')->insert([
                    'career_id' => $career->id,
                    'competition_edition_id' => $edition->id,
                    'career_club_id' => $club->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($this->schedule->generate($clubIds, $start->addDays(config('careers.first_match_after_days'))) as $match) {
                $career->matches()->create($match + ['competition_edition_id' => $edition->id]);
            }

            return $career;
        }, 3);
    }
}
