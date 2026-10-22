<?php

namespace App\Application\Careers;

use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerEvent;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CareerSeason;
use Carbon\CarbonImmutable;

class CareerCalendar
{
    public function season(Career $career): CareerSeason
    {
        return $career->seasons()->where('number', $career->current_season_number)->sole();
    }

    public function nextMatch(Career $career): ?CareerMatch
    {
        $clubId = $career->controlledClub->career_club_id;

        return $career->matches()->with(['homeClub', 'awayClub'])->where('status', 'scheduled')
            ->where(fn ($q) => $q->where('home_club_id', $clubId)->orWhere('away_club_id', $clubId))
            ->orderBy('scheduled_on')->orderBy('id')->first();
    }

    public function nextEvent(Career $career): ?CareerEvent
    {
        return CareerEvent::where('career_id', $career->id)->where('status', 'pending')
            ->where(fn ($q) => $q->where('requires_decision', true)->orWhere('kind', 'season_end'))
            ->orderBy('scheduled_on')->orderBy('id')->first();
    }

    public function decision(Career $career): ?CareerEvent
    {
        return CareerEvent::where('career_id', $career->id)->where('status', 'pending')->where('requires_decision', true)
            ->where('scheduled_on', '<', $career->current_date->addDay()->toDateString())->orderBy('scheduled_on')->orderBy('id')->first();
    }

    public function scheduleEnd(Career $career): void
    {
        $season = $this->season($career);
        $last = $career->matches()->whereIn('competition_edition_id', function ($q) use ($season) {
            $q->select('id')->from('competition_editions')->where('career_season_id', $season->id);
        })->max('scheduled_on');
        if ($last !== null) {
            CareerEvent::firstOrCreate(['career_id' => $career->id, 'event_key' => 'season-end-'.$season->id], [
                'career_season_id' => $season->id, 'kind' => 'season_end', 'title' => 'Encerramento da temporada',
                'scheduled_on' => CarbonImmutable::parse($last)->addDay(), 'requires_decision' => false, 'status' => 'pending',
            ]);
        }
    }
}
