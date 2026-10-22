<?php

namespace App\Application\Stadiums;

use App\Domain\AI\AdministrationPlanner;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CareerClubController;
use App\Domain\Career\Models\CareerStadium;
use App\Domain\Career\Models\StadiumProject;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StadiumProjectService
{
    public function __construct(private AdministrationPlanner $planner) {}

    public function offers(CareerStadium $stadium): array
    {
        $rules = config('stadium_projects');
        $offers = [];
        $seats = (int) $rules['expansion']['capacity_added'];
        if ($stadium->capacity + $seats <= $rules['max_capacity']) {
            $offers['expansion'] = [
                'id' => 'expansion', 'type' => 'capacity', 'label' => 'Ampliação de capacidade',
                'cost_cents' => (int) $rules['expansion']['base_cost_cents'] + $seats * (int) $rules['expansion']['cost_per_seat_cents'],
                'days' => (int) $rules['expansion']['duration_days'], 'capacity_added' => $seats, 'infrastructure_added' => 0,
            ];
        }
        if ($stadium->infrastructure_level < $rules['max_infrastructure_level']) {
            $offers['modernization'] = [
                'id' => 'modernization', 'type' => 'modernization', 'label' => 'Modernização',
                'cost_cents' => (int) $rules['modernization']['base_cost_cents'] + (int) $stadium->infrastructure_level * (int) $rules['modernization']['cost_per_level_cents'],
                'days' => (int) $rules['modernization']['duration_days'], 'capacity_added' => 0, 'infrastructure_added' => 1,
            ];
        }

        return $offers;
    }

    public function start(User $user, Career $career, string $type, int $revision): StadiumProject
    {
        Gate::forUser($user)->authorize('view', $career);

        return DB::transaction(function () use ($user, $career, $type, $revision) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('careers')->where('id', $career->id)->update(['progression_revision' => DB::raw('progression_revision')]);
            }
            $career = Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('view', $career);
            if ($career->state === CareerState::Processing || $career->state === CareerState::AwaitingSeason) {
                $this->invalid('project', 'Conclua a operação pendente ou aguarde a próxima temporada.');
            }
            if ($career->progression_revision !== $revision) {
                $this->invalid('revision', 'A carreira mudou em outra aba. Recarregue a página.');
            }
            $club = CareerClub::where('career_id', $career->id)->whereKey($career->controlledClub->career_club_id)->lockForUpdate()->firstOrFail();
            $project = $this->startForClub($career, $club, $type, 'human');
            $career->increment('progression_revision');

            return $project;
        }, 5);
    }

    /** Called inside the authorized progression transaction, after the career lock. */
    public function completeDue(Career $career): void
    {
        $due = StadiumProject::where('career_id', $career->id)->where('status', 'active')
            ->where('due_on', '<', $career->current_date->addDay()->toDateString())->orderBy('due_on')->orderBy('id')->lockForUpdate()->get();
        foreach ($due as $project) {
            $stadium = CareerStadium::where('career_id', $career->id)->whereKey($project->career_stadium_id)->lockForUpdate()->firstOrFail();
            $stadium->update([
                'capacity' => $stadium->capacity + $project->capacity_added,
                'available_capacity' => $stadium->available_capacity + $project->capacity_added,
                'infrastructure_level' => $stadium->infrastructure_level + $project->infrastructure_added,
            ]);
            $project->update(['status' => 'completed', 'active_slot' => null, 'completed_on' => $career->current_date->toDateString()]);
        }
    }

    /** Called at the first internal day of each month, within the progression transaction. */
    public function planForAi(Career $career): void
    {
        if ((int) $career->current_date->day !== 1 || $career->current_date->eq($career->seasons()->where('number', $career->current_season_number)->sole()->starts_on)) {
            return;
        }
        $controllers = CareerClubController::where('career_id', $career->id)->where('type', 'ai')->orderBy('career_club_id')->get();
        foreach ($controllers as $controller) {
            $club = CareerClub::where('career_id', $career->id)->whereKey($controller->career_club_id)
                ->with(['stadium', 'support'])->lockForUpdate()->firstOrFail();
            $stadium = $club->stadium;
            $support = $club->support;
            if (StadiumProject::where('career_stadium_id', $stadium->id)->where('status', 'active')->exists()) {
                continue;
            }
            $offers = array_values($this->offers($stadium));
            $plan = $this->planner->plan([
                'balance_cents' => (int) $club->balance_cents, 'ticket_price_cents' => (int) $club->default_ticket_price_cents,
                'capacity' => (int) $stadium->available_capacity, 'infrastructure_level' => (int) $stadium->infrastructure_level,
                'fixed_cost_cents' => (int) $stadium->fixed_operating_cost_cents,
                'variable_cost_cents' => (int) $stadium->variable_cost_per_supporter_cents,
                'potential_supporters' => (int) $support->potential_supporters, 'loyalty' => (int) $support->loyalty,
                'engagement' => (int) $support->engagement, 'active_project' => false,
            ], $offers);
            if ($plan['investment']) {
                $this->startForClub($career, $club, $plan['investment']['id'], 'ai');
            }
        }
    }

    private function startForClub(Career $career, CareerClub $club, string $type, string $source): StadiumProject
    {
        $stadium = CareerStadium::where('career_id', $career->id)->where('career_club_id', $club->id)->lockForUpdate()->firstOrFail();
        $offer = $this->offers($stadium)[$type] ?? null;
        if (! $offer) {
            $this->invalid('project', 'Esta obra não está disponível para o estádio.');
        }
        if (StadiumProject::where('career_stadium_id', $stadium->id)->where('status', 'active')->exists()) {
            $this->invalid('project', 'Já existe uma obra ativa neste estádio.');
        }
        if ($club->balance_cents < $offer['cost_cents']) {
            $this->invalid('project', 'Saldo insuficiente para iniciar esta obra.');
        }
        $season = $career->seasons()->where('number', $career->current_season_number)->sole();
        $project = StadiumProject::create([
            'career_id' => $career->id, 'career_club_id' => $club->id, 'career_stadium_id' => $stadium->id,
            'career_season_id' => $season->id, 'type' => $type, 'rule_version' => config('stadium_projects.version'),
            'decision_source' => $source, 'cost_cents' => $offer['cost_cents'],
            'capacity_added' => $offer['capacity_added'], 'infrastructure_added' => $offer['infrastructure_added'],
            'started_on' => $career->current_date->toDateString(), 'duration_days' => $offer['days'],
            'due_on' => $career->current_date->addDays($offer['days'])->toDateString(), 'status' => 'active', 'active_slot' => 1,
        ]);
        $balance = $club->balance_cents - $project->cost_cents;
        $club->update(['balance_cents' => $balance]);
        CareerFinancialTransaction::create([
            'career_id' => $career->id, 'career_club_id' => $club->id, 'career_season_id' => $season->id,
            'source_key' => 'stadium-project:'.$project->id, 'kind' => 'investment',
            'occurred_on' => $career->current_date->toDateString(), 'amount_cents' => -$project->cost_cents,
            'balance_after_cents' => $balance,
        ]);

        return $project;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
