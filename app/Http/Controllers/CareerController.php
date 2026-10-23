<?php

namespace App\Http\Controllers;

use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CreateCareer;
use App\Application\Competitions\StandingsService;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerEvent;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Career\Models\StadiumProject;
use App\Domain\GameDatabase\Models\GameDatabaseVersion;
use App\Http\Requests\StoreCareerRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CareerController extends Controller
{
    public function index(Request $request)
    {
        $careers = Career::where('user_id', $request->user()->id)->with('controlledClub.club')->latest('id')->paginate(20);

        return view('careers.index', compact('careers'));
    }

    public function create()
    {
        $version = GameDatabaseVersion::orderByDesc('id')->first();
        $clubs = $version?->clubs()->with('stadium')->orderBy('name')->get() ?? collect();
        $requestId = (string) Str::uuid();

        return view('careers.create', compact('version', 'clubs', 'requestId'));
    }

    public function store(StoreCareerRequest $request, CreateCareer $create)
    {
        $career = $create->execute($request->user(), $request->validated());

        return redirect()->route('careers.show', $career);
    }

    public function show(Request $request, Career $career, CareerCalendar $calendar, StandingsService $tables)
    {
        $this->authorize('view', $career);
        $career->load(['version', 'controlledClub.club.stadium', 'controlledClub.club.support', 'controlledClub.club.players', 'seasons']);
        $season = $calendar->season($career);
        $requestedSeason = $request->query('season');
        abort_if($requestedSeason !== null && (! is_string($requestedSeason) || ! ctype_digit($requestedSeason)), 404);
        $displaySeason = $requestedSeason === null ? $season : $career->seasons->firstWhere('number', (int) $requestedSeason);
        abort_unless($displaySeason, 404);
        $edition = CompetitionEdition::where('career_id', $career->id)->where('career_season_id', $displaySeason->id)->sole();
        $matches = $career->matches()->where('competition_edition_id', $edition->id)
            ->with(['homeClub', 'awayClub', 'result:id,match_id,home_goals,away_goals'])
            ->orderBy('scheduled_on')->orderBy('id')->paginate(10)->withQueryString();
        $standings = $displaySeason->status === 'completed' ? $displaySeason->summary[0]['standings'] : $tables->standings($edition);
        $scorers = $displaySeason->status === 'completed' ? $displaySeason->summary[0]['scorers'] : $tables->scorers($edition);
        $nextMatch = $calendar->nextMatch($career);
        $nextEvent = $calendar->nextEvent($career);
        $decision = $calendar->decision($career);
        $pendingOperation = CareerOperation::where('career_id', $career->id)->where('status', 'pending')->first();
        $events = CareerEvent::where('career_id', $career->id)->orderByDesc('scheduled_on')->orderByDesc('id')->take(10)->get();
        $completedSeasons = $career->seasons->where('status', 'completed')->sortByDesc('number');
        $activeWork = StadiumProject::where('career_id', $career->id)->where('status', 'active')->first();
        $requestId = (string) Str::uuid();

        return view('careers.show', compact('career', 'matches', 'season', 'displaySeason', 'edition', 'standings', 'scorers', 'nextMatch', 'nextEvent', 'decision', 'pendingOperation', 'events', 'completedSeasons', 'activeWork', 'requestId'));
    }
}
