<?php

namespace App\Http\Controllers;

use App\Application\Stadiums\StadiumProjectService;
use App\Domain\Career\Models\Career;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StadiumProjectController extends Controller
{
    public function store(Request $request, Career $career, StadiumProjectService $projects)
    {
        $this->authorize('view', $career);
        $data = $request->validate([
            'type' => ['required', Rule::in(['expansion', 'modernization'])],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        $projects->start($request->user(), $career, $data['type'], (int) $data['revision']);

        return redirect()->route('careers.stadium.show', $career)->with('status', 'Obra iniciada. O investimento foi debitado do clube.');
    }
}
