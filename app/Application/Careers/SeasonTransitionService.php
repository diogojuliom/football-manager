<?php

namespace App\Application\Careers;

use App\Application\Stadiums\StadiumProjectService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use Illuminate\Support\Facades\DB;
use LogicException;

class SeasonTransitionService
{
    public function __construct(private InitialSchedule $schedule, private CareerCalendar $calendar,
        private PlayerLifecycle $players, private StadiumProjectService $projects) {}

    public function execute(Career $career): void
    {
        $previous = $this->calendar->season($career);
        if ($career->state !== CareerState::Processing || $previous->status !== 'completed' || ! $previous->ended_on) {
            throw new LogicException('A carreira não está pronta para iniciar uma nova temporada.');
        }
        $number = $previous->number + 1;
        if ($career->seasons()->where('number', $number)->exists()) {
            throw new LogicException('A temporada seguinte já existe.');
        }
        $previousEdition = CompetitionEdition::where('career_id', $career->id)->where('career_season_id', $previous->id)->sole();
        $start = $previous->ended_on->addDays((int) config('careers.offseason_days'));
        $career->current_date = $start;
        $career->save();
        $evolution = $this->players->process($career, $number);
        $season = $career->seasons()->create([
            'number' => $number, 'starts_on' => $start->toDateString(), 'status' => 'active',
            'transition_summary' => $evolution,
        ]);
        $edition = CompetitionEdition::create([
            'career_id' => $career->id, 'career_season_id' => $season->id,
            'competition_template_id' => $previousEdition->competition_template_id,
            'name' => $previousEdition->name, 'rules' => $previousEdition->rules, 'status' => 'scheduled',
        ]);
        $clubIds = DB::table('competition_entries')->where('career_id', $career->id)
            ->where('competition_edition_id', $previousEdition->id)->orderBy('career_club_id')->pluck('career_club_id')->all();
        if (count($clubIds) !== (int) $edition->rules['club_count']) {
            throw new LogicException('A edição anterior não possui todos os clubes esperados.');
        }
        foreach ($clubIds as $clubId) {
            DB::table('competition_entries')->insert([
                'career_id' => $career->id, 'competition_edition_id' => $edition->id, 'career_club_id' => $clubId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($this->schedule->generate($clubIds, $start->addDays((int) config('careers.first_match_after_days'))) as $match) {
            $career->matches()->create($match + ['competition_edition_id' => $edition->id]);
        }
        $career->current_season_number = $number;
        $career->save();
        $this->projects->completeDue($career);
        $this->calendar->scheduleEnd($career);
    }
}
