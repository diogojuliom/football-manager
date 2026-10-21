<?php

namespace App\Http\Controllers;

use App\Application\Careers\CreateCareer;
use App\Domain\Career\Models\Career;
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

    public function show(Career $career)
    {
        $this->authorize('view', $career);
        $career->load(['version', 'controlledClub.club.stadium', 'controlledClub.club.support', 'controlledClub.club.players', 'seasons']);
        $matches = $career->matches()->with(['homeClub', 'awayClub'])->orderBy('scheduled_on')->orderBy('id')->paginate(10);

        return view('careers.show', compact('career', 'matches'));
    }
}
