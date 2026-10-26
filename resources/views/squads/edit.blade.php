@extends('careers.layout')
@section('title', 'Elenco e táticas — '.$career->name)
@section('content')
@php
    $roles = \App\Domain\Tactics\Tactics::FORMATIONS[$settings['formation']];
    $slots = \App\Domain\Tactics\Tactics::slots($settings['formation']);
    $tacticalModel = \App\Domain\Tactics\Tactics::frontendModel();
    $profile = \App\Domain\Tactics\Tactics::PROFILES[$settings['formation']];
    $formationGroups = collect(\App\Domain\Tactics\Tactics::FORMATIONS)->keys()->groupBy(fn($formation) => mb_substr($formation, 0, 1));
    $starters = old('starters',$settings['starters']); $substitutes = old('substitutes',$settings['substitutes']);
    $starters = is_array($starters)?$starters:[]; $substitutes = is_array($substitutes)?$substitutes:[];
    $submittedRevision = old('revision',$settings['revision']);
    $revision = is_scalar($submittedRevision) && filter_var($submittedRevision,FILTER_VALIDATE_INT)!==false ? $submittedRevision : $settings['revision'];
    $available = $players->filter(fn($player)=>$player->isAvailableOn($career->current_date))->count();
    $selectedPlayerIds = collect([...$starters, ...$substitutes])->filter(fn($id) => is_scalar($id))->map(fn($id) => (int)$id);
    $outsidePlayers = $players->reject(fn($player) => $selectedPlayerIds->contains($player->id));
    $playerDirectory = $players->mapWithKeys(fn($player) => [(string)$player->id => [
        'id' => (string)$player->id,
        'name' => $player->name,
        'position' => $player->primary_position,
        'positionLabel' => \App\Domain\Tactics\Tactics::positionLabel($player->primary_position),
        'positionName' => \App\Domain\Tactics\Tactics::positionName($player->primary_position),
        'fitness' => (int)$player->fitness,
        'isGoalkeeper' => $player->canPlay('GK'),
        'available' => $player->isAvailableOn($career->current_date),
        'attributes' => collect(\App\Domain\Tactics\Tactics::ATTRIBUTES)->map(fn($label, $attribute) => [
            'key' => $attribute,
            'label' => $label,
            'value' => (int)$player->$attribute,
        ])->values()->all(),
    ]]);
@endphp
<header class="page-header"><div class="page-header-copy"><a class="breadcrumb" href="{{ route('careers.show',$career) }}">← Voltar à carreira</a><p class="eyebrow">Departamento de futebol</p><h1>Elenco e táticas</h1><p>{{ $club->name }} · {{ $career->current_date->format('d/m/Y') }}. Defina titulares, banco e estratégia para as próximas partidas.</p></div><span class="badge">Formação {{ $settings['formation'] }}</span></header>
<div class="stat-grid"><article class="stat-card"><span>Jogadores</span><strong>{{ $players->count() }}</strong><small>no elenco principal</small></article><article class="stat-card"><span>Disponíveis</span><strong>{{ $available }}</strong><small>na data interna atual</small></article><article class="stat-card"><span>Condição média</span><strong>{{ (int)round($players->avg('fitness')) }}%</strong><small>condição física</small></article><article class="stat-card"><span>Moral médio</span><strong>{{ (int)round($players->avg('morale')) }}</strong><small>de 100 pontos</small></article></div>
@if(!$lineup)<div class="notice notice-warning"><span>!</span><p>A seleção abaixo é uma sugestão inicial. Confirme em “Salvar escalação e táticas” para persistir.</p></div>@endif
@if(!$editable)<div class="notice notice-error" role="alert"><span>!</span><p>A edição está bloqueada enquanto uma partida ou ação da carreira está em processamento.</p></div>@endif
<section class="panel"><div class="panel-header"><div><h2>Esquema tático</h2><p>Veja a distribuição imediatamente e aplique para receber uma nova sugestão</p></div><div class="formation-profile" aria-label="Tendências da formação"><span>Ataque <b data-formation-profile="attack">{{ number_format($profile['attack'] * 100, 0) }}</b></span><span>Defesa <b data-formation-profile="defense">{{ number_format($profile['defense'] * 100, 0) }}</b></span><span>Controle <b data-formation-profile="control">{{ number_format($profile['control'] * 100, 0) }}</b></span></div></div><form class="button-row" method="get" action="{{ route('careers.squad.edit',$career) }}" data-formation-picker><div style="flex:1"><label for="formation">Formação</label><select id="formation" name="formation" data-formation-select>@foreach($formationGroups as $defenders=>$formations)<optgroup label="{{ $defenders }} defensores">@foreach($formations as $formation)<option value="{{ $formation }}" @selected($settings['formation']===$formation)>{{ $formation }}</option>@endforeach</optgroup>@endforeach</select></div><button type="submit">Aplicar formação</button></form><p class="form-help">A seleção atualiza a prévia. Aplicar a formação descarta alterações ainda não salvas e recalcula a sugestão de escalação. Os índices mostram as tendências relativas consideradas pelo motor.</p></section>
<form class="squad-form" method="post" action="{{ route('careers.squad.update',$career) }}" data-loading-form data-loading-label="Salvando escalação…">@csrf @method('PUT')<input type="hidden" name="formation" value="{{ $settings['formation'] }}"><input type="hidden" name="revision" value="{{ $revision }}">
<section class="tactical-board" aria-labelledby="tactical-board-title" data-tactical-board data-current-formation="{{ $settings['formation'] }}" data-preview-formation="{{ $settings['formation'] }}" data-interaction-mode="fixed">
    <script type="application/json" data-tactical-model>@json($tacticalModel)</script>
    <script type="application/json" data-squad-players>@json($playerDirectory)</script>
    <div class="squad-skills-tooltip" role="tooltip" data-player-skills-tooltip hidden>
        <header><div><strong data-skills-player-name></strong><span data-skills-player-meta></span></div><small>Habilidades</small></header>
        <dl data-player-skills></dl>
    </div>
    <div class="panel-header"><div><h2 id="tactical-board-title" data-board-title>Distribuição em campo — {{ $settings['formation'] }}</h2><p>Selecione dois atletas para trocar suas vagas antes de salvar.</p></div><span class="fixed-position-badge">Troca por clique</span></div>
    <div class="interactive-squad-layout">
    <div class="tactical-field-column">
    <div class="tactical-pitch" data-pitch role="group" aria-label="Titulares da formação {{ $settings['formation'] }}">
        <svg class="tactical-pitch-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <rect class="pitch-line" x="1" y="1" width="98" height="98" rx="1" />
            <path class="pitch-line" d="M1 50h98" />
            <ellipse class="pitch-circle" cx="50" cy="50" rx="11" ry="8.25" />
            <circle class="pitch-fill" cx="50" cy="50" r=".65" />
            <path class="pitch-line" d="M29 1v15h42V1M39 1v7h22V1M29 99V84h42v15M39 99v-7h22v7" />
            <path class="pitch-line pitch-arc" d="M41 16c2.7 4.5 15.3 4.5 18 0M41 84c2.7-4.5 15.3-4.5 18 0" />
            <circle class="pitch-fill" cx="50" cy="11" r=".5" /><circle class="pitch-fill" cx="50" cy="89" r=".5" />
        </svg>
        @foreach($slots as $slot=>$position)
            @php($selectedPlayer = isset($starters[$slot]) && is_scalar($starters[$slot]) ? $players->firstWhere('id', (int)$starters[$slot]) : null)
            <button class="pitch-player" type="button" style="--pitch-x: {{ $position['x'] }}%; --pitch-y: {{ $position['y'] }}%" aria-pressed="false" aria-label="Vaga {{ $slot + 1 }}, {{ $position['name'] }}: {{ $selectedPlayer?->name ?? 'Escolha atleta' }}{{ $selectedPlayer ? ', condição '.$selectedPlayer->fitness.'%' : '' }}" data-squad-player data-unit="starter" data-slot="{{ $slot }}" data-pitch-player="{{ $slot }}" data-role="{{ $position['role'] }}" data-x="{{ $position['x'] }}" data-y="{{ $position['y'] }}" data-player-id="{{ $selectedPlayer?->id }}" data-draggable="false" data-interact-enabled="false" @disabled(!$editable || !$selectedPlayer)>
                <span data-pitch-shirt>{{ $slot + 1 }}</span><strong data-pitch-position title="{{ $position['name'] }}">{{ $position['label'] }}</strong><small data-pitch-name>{{ $selectedPlayer?->name ?? 'Escolha atleta' }}</small>
                <span class="pitch-player-condition" data-pitch-fitness @hidden(!$selectedPlayer)><i style="--fitness: {{ $selectedPlayer?->fitness ?? 0 }}%"></i><b>{{ $selectedPlayer?->fitness ?? 0 }}%</b></span>
            </button>
        @endforeach
    </div>
    </div>
    <aside class="squad-selection-panel" aria-label="Banco e jogadores fora da convocação">
        <section class="squad-player-section"><header><div><h3>Banco de reservas</h3><p>Até {{ $limit }} atletas</p></div><span data-bench-count>{{ collect($substitutes)->filter(fn($id) => is_scalar($id) && $id !== '')->count() }}/{{ $limit }}</span></header><div class="squad-player-list" data-bench-list>
            @for($slot=0;$slot<$limit;$slot++)
                @php($benchPlayer = isset($substitutes[$slot]) && is_scalar($substitutes[$slot]) ? $players->firstWhere('id', (int)$substitutes[$slot]) : null)
                <button class="squad-player-card" type="button" data-squad-player data-unit="reserve" data-slot="{{ $slot }}" data-player-id="{{ $benchPlayer?->id }}" aria-pressed="false" @disabled(!$editable || !$benchPlayer)>
                    <span class="squad-player-position" data-card-position title="{{ $benchPlayer ? \App\Domain\Tactics\Tactics::positionName($benchPlayer->primary_position) : '' }}">{{ $benchPlayer ? \App\Domain\Tactics\Tactics::positionLabel($benchPlayer->primary_position) : '—' }}</span><span class="squad-player-copy"><strong data-card-name>{{ $benchPlayer?->name ?? 'Vaga livre' }}</strong><small data-card-meta>{{ $benchPlayer ? 'Condição '.$benchPlayer->fitness.'%' : 'Sem jogador relacionado' }}</small><i><span data-card-fitness style="--fitness: {{ $benchPlayer?->fitness ?? 0 }}%"></span></i></span><b data-card-fitness-label>{{ $benchPlayer?->fitness ?? '—' }}{{ $benchPlayer ? '%' : '' }}</b>
                </button>
            @endfor
        </div></section>
        <section class="squad-player-section outside-section"><header><div><h3>Fora da convocação</h3><p>Disponíveis para o banco</p></div><span data-outside-count>{{ $outsidePlayers->count() }}</span></header><div class="squad-player-list outside-list" data-outside-list>
            @forelse($outsidePlayers as $outsidePlayer)
                <button class="squad-player-card" type="button" data-squad-player data-unit="outside" data-player-id="{{ $outsidePlayer->id }}" aria-pressed="false" @disabled(!$editable || !$outsidePlayer->isAvailableOn($career->current_date))>
                    <span class="squad-player-position" data-card-position title="{{ \App\Domain\Tactics\Tactics::positionName($outsidePlayer->primary_position) }}">{{ \App\Domain\Tactics\Tactics::positionLabel($outsidePlayer->primary_position) }}</span><span class="squad-player-copy"><strong data-card-name>{{ $outsidePlayer->name }}</strong><small data-card-meta>{{ $outsidePlayer->isAvailableOn($career->current_date) ? 'Condição '.$outsidePlayer->fitness.'%' : 'Indisponível' }}</small><i><span data-card-fitness style="--fitness: {{ $outsidePlayer->fitness }}%"></span></i></span><b data-card-fitness-label>{{ $outsidePlayer->fitness }}%</b>
                </button>
            @empty
                <p class="empty-squad-list">Todo o elenco disponível está relacionado.</p>
            @endforelse
        </div></section>
    </aside>
    </div>
    <p class="tactical-board-status" role="status" aria-live="polite" data-tactical-status>Selecione dois atletas para trocar. Pressione Esc para cancelar.</p>
</section>
<div data-lineup-selects hidden aria-hidden="true">
<fieldset @disabled(!$editable)><legend>Titulares — {{ $settings['formation'] }}</legend><p class="form-help">Selecione onze atletas distintos, com um goleiro na primeira vaga. A posição ocupada influencia o desempenho no motor.</p><div class="lineup-grid">@foreach($roles as $slot=>$role)<div><label for="starter-{{ $slot }}">{{ $slot+1 }}. {{ \App\Domain\Tactics\Tactics::positionLabel($role) }}</label><select id="starter-{{ $slot }}" name="starters[{{ $slot }}]" data-starter-select="{{ $slot }}" required><option value="" data-player-name="Escolha atleta">Selecione um jogador</option>@foreach($players as $player)@php($selected=is_scalar($starters[$slot]??null)&&(string)$starters[$slot]===(string)$player->id) @php($allowedRole=($role==='GK')===$player->canPlay('GK')) @if($allowedRole||$selected)<option value="{{ $player->id }}" data-player-name="{{ $player->name }}" data-player-fitness="{{ $player->fitness }}" @selected($selected) @disabled(!$player->isAvailableOn($career->current_date)&&!$selected)>{{ $player->name }} ({{ \App\Domain\Tactics\Tactics::positionLabel($player->primary_position) }}){{ !$player->isAvailableOn($career->current_date)?' — Indisponível':'' }}{{ !$player->canPlay($role)?' — Fora da posição':'' }}</option>@endif @endforeach</select></div>@endforeach</div></fieldset>
<fieldset @disabled(!$editable)><legend>Reservas — até {{ $limit }}</legend><p class="form-help">As vagas são opcionais. Um jogador não pode aparecer também entre os titulares.</p><div class="lineup-grid">@for($slot=0;$slot<$limit;$slot++)<div><label for="substitute-{{ $slot }}">Reserva {{ $slot+1 }}</label><select id="substitute-{{ $slot }}" name="substitutes[]" data-reserve-select="{{ $slot }}"><option value="">Sem reserva</option>@foreach($players as $player)@php($selected=is_scalar($substitutes[$slot]??null)&&(string)$substitutes[$slot]===(string)$player->id)<option value="{{ $player->id }}" data-player-name="{{ $player->name }}" data-player-fitness="{{ $player->fitness }}" data-player-position="{{ $player->primary_position }}" @selected($selected) @disabled(!$player->isAvailableOn($career->current_date)&&!$selected)>{{ $player->name }} ({{ \App\Domain\Tactics\Tactics::positionLabel($player->primary_position) }}){{ !$player->isAvailableOn($career->current_date)?' — Indisponível':'' }}</option>@endforeach</select></div>@endfor</div></fieldset>
</div>
<fieldset class="tactical-settings" @disabled(!$editable)><legend>Configurações táticas</legend><div class="form-grid">@foreach(\App\Domain\Tactics\Tactics::OPTIONS as $field=>$options)@php($help = \App\Domain\Tactics\Tactics::OPTION_HELP[$field])<div class="tactical-setting"><div class="tactical-setting-label"><label for="{{ $field }}">{{ \App\Domain\Tactics\Tactics::LABELS[$field] }}</label><button class="tactical-info" type="button" aria-label="Como {{ strtolower(\App\Domain\Tactics\Tactics::LABELS[$field]) }} interfere na equipe" aria-describedby="tactical-help-{{ $field }}" data-tactical-info="{{ $field }}"><span aria-hidden="true">i</span></button><div class="tactical-help" id="tactical-help-{{ $field }}" role="tooltip" data-tactical-help="{{ $field }}"><strong>{{ \App\Domain\Tactics\Tactics::LABELS[$field] }}</strong><p>{{ $help['summary'] }}</p><dl>@foreach($options as $value=>$label)<div><dt>{{ $label }}</dt><dd>{{ $help['options'][$value] }}</dd></div>@endforeach</dl></div></div><select id="{{ $field }}" name="{{ $field }}" required>@foreach($options as $value=>$label)<option value="{{ $value }}" @selected(old($field,$settings[$field])===$value)>{{ $label }}</option>@endforeach</select></div>@endforeach</div></fieldset>
<div class="button-row"><button class="primary" type="submit" @disabled(!$editable) @if(!$editable)data-initially-disabled @endif>Salvar escalação e táticas</button><a class="btn" href="{{ route('careers.squad.edit',$career) }}">Recarregar dados salvos</a></div></form>
<section class="panel" style="margin-top:1.25rem"><div class="panel-header"><div><h2>Jogadores e atributos</h2><p>Atributos de 0 a 100; lesões são consideradas até a data indicada, inclusive.</p></div></div><div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Posição</th><th>Posições secundárias</th><th>Idade</th><th>Condição física</th><th>Moral</th><th>Disponibilidade</th>@foreach(\App\Domain\Tactics\Tactics::ATTRIBUTES as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>@foreach($players as $player)<tr><td><strong>{{ $player->name }}</strong></td><td>{{ \App\Domain\Tactics\Tactics::positionLabel($player->primary_position) }}</td><td>{{ collect($player->secondary_positions)->map(fn($position) => \App\Domain\Tactics\Tactics::positionLabel($position))->implode(', ') ?: '—' }}</td><td>{{ $player->age }}</td><td>{{ $player->fitness }}</td><td>{{ $player->morale }}</td><td>@if($player->isAvailableOn($career->current_date))<span class="positive">Disponível</span>@else @if($player->fitness<=0)Sem condição física. @endif @if($player->injured_until&&$player->injured_until->gte($career->current_date))Lesionado até {{ $player->injured_until->format('d/m/Y') }}. @endif @if($player->suspended_matches>0)Suspenso por {{ $player->suspended_matches }} partida(s).@endif @endif</td>@foreach(\App\Domain\Tactics\Tactics::ATTRIBUTES as $attribute=>$label)<td>{{ $player->$attribute }}</td>@endforeach</tr>@endforeach</tbody></table></div></section>
@endsection
