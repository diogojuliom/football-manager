<?php

namespace App\Http\Controllers;

use App\Application\Attendance\MatchAttendanceService;
use App\Domain\Attendance\Models\MatchAttendance;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Football\Models\MatchSimulation;

class StadiumController extends Controller
{
    public function show(Career $career, MatchAttendanceService $attendance)
    {
        $this->authorize('view', $career);
        $club = $career->controlledClub->club->load(['stadium', 'support']);
        $history = MatchAttendance::where('career_id', $career->id)->where('home_club_id', $club->id)
            ->with('match.awayClub')->orderByDesc('played_on')->orderByDesc('match_id')->paginate(20);
        $seasonId = $career->seasons()->where('number', $career->current_season_number)->value('id');
        $editionId = CompetitionEdition::where('career_id', $career->id)->where('career_season_id', $seasonId)->value('id');
        $averages = MatchAttendance::where('career_id', $career->id)->where('home_club_id', $club->id)
            ->whereIn('match_id', $career->matches()->where('competition_edition_id', $editionId)->select('id'))
            ->selectRaw('COUNT(*) as games, AVG(realized) as average_attendance, AVG(occupancy_bps) as average_occupancy_bps')->first();
        $nextHome = $career->matches()->where('home_club_id', $club->id)->where('status', 'scheduled')
            ->orderBy('scheduled_on')->orderBy('id')->with('awayClub')->first();
        $forecast = $nextHome ? $attendance->forecast($career, $club, $nextHome->awayClub, $nextHome) : null;
        $friendlies = MatchSimulation::where('career_id', $career->id)->where('home_club_id', $club->id)
            ->whereNotNull('attendance_plan')->latest('id')->limit(10)->get();
        $revenue = CareerFinancialTransaction::where('career_id', $career->id)->where('career_club_id', $club->id)
            ->where('kind', 'ticket_revenue')->sum('amount_cents');
        $receipts = CareerFinancialTransaction::where('career_id', $career->id)->where('career_club_id', $club->id)
            ->where('kind', 'ticket_revenue')->whereIn('match_id', $history->getCollection()->pluck('match_id'))
            ->pluck('amount_cents', 'match_id');

        return view('careers.stadium', compact('career', 'club', 'history', 'averages', 'nextHome', 'forecast', 'friendlies', 'revenue', 'receipts'));
    }
}
