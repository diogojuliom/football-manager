@extends('careers.layout')
@section('title', $career->name.' — Football Manager')
@section('page-label', 'Dashboard')
@section('page-context', $career->name)
@section('content')
@php
    $club = $career->controlledClub->club;
    $clubRow = collect($standings)->firstWhere('club_id', $club->id);
    $clubPosition = collect($standings)->search(fn ($row) => $row['club_id'] === $club->id);
    $averageFitness = (int) round($club->players->avg('fitness') ?? 0);
    $suspended = $club->players->where('suspended_matches', '>', 0)->count();
    $stateLabel = match($career->state) {
        \App\Domain\Career\CareerState::AwaitingDecision => 'Aguardando decisão',
        \App\Domain\Career\CareerState::Processing => 'Processando progressão',
        \App\Domain\Career\CareerState::PreMatch => 'Pré-jogo',
        \App\Domain\Career\CareerState::AwaitingMatch => 'Aguardando partida',
        \App\Domain\Career\CareerState::AwaitingSeason => 'Fim de temporada',
    };
    $monogram = collect(explode(' ', $club->name))->map(fn ($word) => mb_substr($word, 0, 1))->take(3)->implode('');
    $recentResults = $matches->getCollection()->filter(fn ($match) => $match->result)->sortByDesc('scheduled_on')->take(3);
    $topPlayers = $club->players->sortByDesc('fitness')->take(8);
@endphp

<div class="career-dashboard-view">
    <h1 class="game-sr-only">Dashboard da carreira {{ $career->name }}</h1>
    <section class="career-match-hero" aria-labelledby="next-match-title">
        <div class="career-match-overlay" aria-hidden="true"></div>
        <div class="career-match-content">
            <div class="career-match-info">
                <p class="career-match-kicker"><span aria-hidden="true"></span>{{ $edition->name }} · {{ $nextMatch?->scheduled_on?->format('d/m/Y') ?? 'Temporada encerrada' }}</p>
                @if($nextMatch)
                    <div class="career-teams" id="next-match-title">
                        <div class="career-team"><span class="career-team-badge">{{ collect(explode(' ', $nextMatch->homeClub->name))->map(fn ($word) => mb_substr($word, 0, 1))->take(3)->implode('') }}</span><strong>{{ $nextMatch->homeClub->name }}</strong><small>Mandante</small></div>
                        <div class="career-versus"><b>×</b><span>{{ $career->current_date->diffInDays($nextMatch->scheduled_on) }} dias</span></div>
                        <div class="career-team"><span class="career-team-badge away">{{ collect(explode(' ', $nextMatch->awayClub->name))->map(fn ($word) => mb_substr($word, 0, 1))->take(3)->implode('') }}</span><strong>{{ $nextMatch->awayClub->name }}</strong><small>Visitante</small></div>
                    </div>
                    <p class="career-match-caption">Seu clube joga <strong>{{ $nextMatch->home_club_id === $club->id ? 'em casa' : 'fora de casa' }}</strong> · Rodada {{ $nextMatch->round }}</p>
                @else
                    <div class="career-no-match" id="next-match-title"><span class="career-team-badge">{{ $monogram }}</span><div><strong>Temporada concluída</strong><p>Não há mais partidas do seu clube nesta edição.</p></div></div>
                @endif
            </div>

            <div class="career-progress-card" aria-labelledby="progress-title">
                <div class="career-progress-heading"><div><h2 id="progress-title">Central de progressão</h2><p>{{ $stateLabel }}</p></div><span class="career-state-dot" aria-hidden="true"></span></div>
                @if($pendingOperation)
                    <p class="career-progress-message">Uma operação autorizada foi interrompida e pode ser retomada com segurança.</p>
                    <form data-progression method="post" action="{{ route('careers.operations.resume', [$career, $pendingOperation]) }}">@csrf<button class="career-main-action" data-loading-label="Retomando…">Retomar operação autorizada</button></form>
                @elseif($decision)
                    <p class="career-progress-message"><strong>{{ $decision->title }}</strong><br>{{ $decision->scheduled_on->format('d/m/Y') }} · decisão obrigatória</p>
                    <form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><input type="hidden" name="event_id" value="{{ $decision->id }}"><button class="career-main-action" name="action" value="ACKNOWLEDGE_EVENT">Confirmar acontecimento</button></form>
                @elseif($career->state === \App\Domain\Career\CareerState::PreMatch && $nextMatch)
                    <div class="career-ready"><span aria-hidden="true">⚽</span><p><strong>Chegou o dia do jogo!</strong><br>O elenco aguarda no vestiário.</p></div>
                    <a class="career-main-action amber" href="{{ route('careers.matches.pre-match', [$career, $nextMatch]) }}">Jogar partida</a>
                @elseif($career->state === \App\Domain\Career\CareerState::AwaitingSeason)
                    <p class="career-progress-message"><strong>Temporada encerrada</strong><br>Campeão: {{ $season->summary[0]['standings'][0]['name'] }}</p>
                    @if(!$pendingOperation)<form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><button class="career-main-action blue" name="action" value="START_NEXT_SEASON">Iniciar temporada {{ $career->current_season_number + 1 }}</button></form>@endif
                @else
                    <form class="career-progress-actions" data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><button class="career-main-action" name="action" value="ADVANCE_TO_NEXT_MATCH" data-loading-label="Simulando dias…">Próxima partida</button><div><button name="action" value="ADVANCE_ONE_DAY">Avançar 1 dia</button><button name="action" value="ADVANCE_TO_NEXT_EVENT">Próximo acontecimento</button></div></form>
                @endif
                @if($nextEvent)<p class="career-next-event">Próximo: {{ $nextEvent->title }} · {{ $nextEvent->scheduled_on->format('d/m/Y') }}</p>@endif
            </div>
        </div>
    </section>

    <section class="career-summary-strip" aria-label="Resumo da carreira">
        <article><span>Temporada</span><strong>{{ $career->current_season_number }}</strong><small>{{ $stateLabel }}</small></article>
        <article><span>Classificação</span><strong>{{ $clubPosition !== false ? ($clubPosition + 1).'º' : '—' }}</strong><small>{{ $clubRow['points'] ?? 0 }} pts · {{ $clubRow['played'] ?? 0 }} jogos</small></article>
        <article><span>Condição do elenco</span><strong>{{ $averageFitness }}%</strong><small>{{ $suspended }} suspenso(s)</small></article>
        <article><span>Obra em andamento</span><strong>{{ $activeWork ? ($activeWork->type === 'expansion' ? 'Ampliação' : 'Modernização') : 'Nenhuma' }}</strong><small>{{ $activeWork ? 'Conclusão '.$activeWork->due_on->format('d/m/Y') : 'Infraestrutura nível '.$club->stadium->infrastructure_level }}</small></article>
    </section>

    <div class="career-reference-grid">
        <section class="career-dashboard-card career-table-card" id="classificacao">
            <header><div><h2>Classificação — {{ $edition->name }}</h2><p>Temporada {{ $displaySeason->number }}</p></div><span>Atualizada</span></header>
            <div class="table-scroll" tabindex="0" aria-label="Classificação completa"><table><thead><tr><th>Pos</th><th>Clube</th><th>Pts</th><th>J</th><th>V</th><th>E</th><th>D</th><th>SG</th></tr></thead><tbody>@foreach($standings as $row)<tr @class(['is-controlled' => $row['club_id'] === $club->id])><td><span class="position">{{ $loop->iteration }}</span></td><td><strong>{{ $row['name'] }}</strong></td><td><strong>{{ $row['points'] }}</strong></td><td>{{ $row['played'] }}</td><td>{{ $row['wins'] }}</td><td>{{ $row['draws'] }}</td><td>{{ $row['losses'] }}</td><td>{{ $row['goal_difference'] > 0 ? '+' : '' }}{{ $row['goal_difference'] }}</td></tr>@endforeach</tbody></table></div>
        </section>

        <section class="career-dashboard-card career-activity-card">
            <header><div><h2>Últimos jogos e eventos</h2><p>Ordem do calendário interno</p></div></header>
            <div class="career-activity-list">
                @if($nextEvent)<article class="important"><span class="career-activity-icon">!</span><div><time>{{ $nextEvent->scheduled_on->format('d/m/Y') }}</time><strong>{{ $nextEvent->title }}</strong><small>Próximo acontecimento</small></div></article>@endif
                @if($activeWork)<article><span class="career-activity-icon">▰</span><div><time>{{ $activeWork->due_on->format('d/m/Y') }}</time><strong>{{ $activeWork->type === 'expansion' ? 'Ampliação do estádio' : 'Modernização do estádio' }}</strong><small>Obra em andamento</small></div></article>@endif
                @foreach($events->reject(fn ($event) => $nextEvent && $event->id === $nextEvent->id)->take(3) as $event)<article><span class="career-activity-icon">{{ $event->status === 'processed' ? '✓' : '◷' }}</span><div><time>{{ $event->scheduled_on->format('d/m/Y') }}</time><strong>{{ $event->title }}</strong><small>{{ $event->status === 'processed' ? 'Processado' : 'Pendente' }}</small></div></article>@endforeach
                @foreach($recentResults as $match)<article><span class="career-activity-icon">⚽</span><div><time>{{ $match->scheduled_on->format('d/m/Y') }}</time><strong><a href="{{ route('careers.results.show', [$career, $match->result]) }}">{{ $match->homeClub->name }} {{ $match->result->home_goals }} × {{ $match->result->away_goals }} {{ $match->awayClub->name }}</a></strong><small>Resultado oficial</small></div></article>@endforeach
                @if(!$nextEvent && !$activeWork && $events->isEmpty() && $recentResults->isEmpty())<p class="empty-state">Nenhum acontecimento registrado.</p>@endif
            </div>
        </section>

        <section class="career-dashboard-card career-squad-card" id="elenco">
            <header><div><h2>Elenco principal</h2><p>Condição física dos atletas</p></div><a href="{{ route('careers.squad.edit', $career) }}">Gerenciar táticas</a></header>
            <div class="career-player-grid">@foreach($topPlayers as $player)<article><span class="career-position">{{ $player->primary_position }}</span><div><strong>{{ $player->name }}</strong><span><i style="width: {{ $player->fitness }}%"></i></span></div><b>{{ $player->fitness }}%</b></article>@endforeach</div>
        </section>

        <section class="career-dashboard-card career-scorers-card" id="estatisticas">
            <header><div><h2>Artilharia</h2><p>Temporada {{ $displaySeason->number }}</p></div></header>
            @if($scorers)<ol class="career-scorers">@foreach(array_slice($scorers, 0, 5) as $row)<li><span>{{ $loop->iteration }}º</span><div><strong>{{ $row['name'] }}</strong><small>{{ $row['club_name'] }}</small></div><b>{{ $row['goals'] }}</b></li>@endforeach</ol>@else<p class="empty-state">Nenhum gol oficial registrado.</p>@endif
        </section>
    </div>

    <section class="career-dashboard-card career-calendar-card" id="calendario">
        <header><div><h2>Calendário da liga</h2><p>Rodadas e resultados · Temporada {{ $displaySeason->number }}</p></div><span>{{ $matches->total() }} partidas</span></header>
        <div class="table-scroll" tabindex="0" aria-label="Calendário da liga"><table><thead><tr><th>Data</th><th>Rodada</th><th>Mandante</th><th>Visitante</th><th>Resultado</th></tr></thead><tbody>@foreach($matches as $match)<tr><td>{{ $match->scheduled_on->format('d/m/Y') }}</td><td>{{ $match->round }}</td><td>{{ $match->homeClub->name }}</td><td>{{ $match->awayClub->name }}</td><td>@if($match->result)<a href="{{ route('careers.results.show', [$career, $match->result]) }}"><strong>{{ $match->result->home_goals }} × {{ $match->result->away_goals }}</strong></a>@else<span class="badge">Agendada</span>@endif</td></tr>@endforeach</tbody></table></div><div class="pagination">{{ $matches->links() }}</div>
    </section>

    <div class="career-bottom-grid">
        <section class="career-dashboard-card"><header><div><h2>O clube</h2><p>Administração e infraestrutura</p></div></header><dl class="career-club-data"><div><dt>Saldo</dt><dd>R$ {{ number_format($club->balance_cents / 100, 2, ',', '.') }}</dd></div><div><dt>Estádio</dt><dd>{{ $club->stadium->name }}</dd></div><div><dt>Capacidade</dt><dd>{{ number_format($club->stadium->available_capacity, 0, ',', '.') }}</dd></div><div><dt>Dados-base</dt><dd>{{ $career->version->version }}</dd></div></dl><div class="career-club-actions"><a href="{{ route('careers.finances.show', $career) }}">Finanças</a><a href="{{ route('careers.stadium.show', $career) }}">Estádio e obras</a><a href="{{ route('careers.tickets.index', $career) }}">Ingressos</a><a href="{{ route('careers.friendlies.create', $career) }}">Amistosos</a></div></section>
        <section class="career-dashboard-card" id="historico"><header><div><h2>Histórico de temporadas</h2><p>Conquistas e arquivos da carreira</p></div></header>@if($completedSeasons->isNotEmpty())<div class="career-season-list">@foreach($completedSeasons as $completed)<article><span>Temporada {{ $completed->number }}</span><strong>{{ $completed->summary[0]['standings'][0]['name'] }}</strong><small>{{ $completed->starts_on->format('d/m/Y') }} a {{ $completed->ended_on->format('d/m/Y') }}</small><a href="{{ route('careers.show', ['career' => $career, 'season' => $completed->number]) }}">Ver classificação, artilharia e jogos</a></article>@endforeach</div>@else<p class="empty-state">A primeira temporada ainda está em andamento.</p>@endif</section>
    </div>

    <p class="career-save-note">Carreira “{{ $career->name }}” · Treinador {{ $career->coach_name }} · O calendário só avança por uma ação explícita.</p>
</div>

<nav class="career-mobile-nav" aria-label="Atalhos do dashboard"><a href="#main-content"><span>⌂</span>Resumo</a><a href="#elenco"><span>♟</span>Elenco</a><a href="#classificacao"><span>≡</span>Tabela</a><a href="{{ route('careers.stadium.show', $career) }}"><span>▰</span>Clube</a></nav>
@endsection
