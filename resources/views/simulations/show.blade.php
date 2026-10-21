@extends('careers.layout')
@section('title', 'Resultado do amistoso — '.$career->name)
@section('content')
@php
    $names = [];
    foreach (['home', 'away'] as $side) {
        foreach ($result[$side]['players'] as $player) { $names[$player['player_id']] = $player['name']; }
    }
    $reasons = ['chasing_goal' => 'Buscar empate ou virada', 'protecting_lead' => 'Proteger a vantagem', 'numerical_disadvantage' => 'Reorganizar após expulsão', 'numerical_advantage' => 'Explorar vantagem numérica', 'conserve_energy' => 'Poupar energia', 'fatigue' => 'Desgaste físico', 'booking' => 'Jogador amarelado', 'refresh' => 'Renovar a equipe'];
    $labels = ['kickoff' => 'Início da partida', 'possession' => 'Posse de bola', 'build_up' => 'Construção de jogada', 'opportunity' => 'Oportunidade', 'shot' => 'Finalização', 'save' => 'Defesa do goleiro', 'goal' => 'Gol', 'assist' => 'Assistência', 'foul' => 'Falta', 'yellow_card' => 'Cartão amarelo', 'red_card' => 'Expulsão', 'substitution' => 'Substituição', 'fatigue' => 'Desgaste físico', 'half_time' => 'Intervalo', 'full_time' => 'Fim da partida', 'abandoned' => 'Partida interrompida', 'tactical_change' => 'Ajuste tático'];
@endphp
<p><a href="{{ route('careers.friendlies.create', $career) }}">Voltar aos amistosos</a></p>
<h1>{{ $result['home']['name'] }} {{ $result['home']['stats']['goals'] }} × {{ $result['away']['stats']['goals'] }} {{ $result['away']['name'] }}</h1>
<p>Amistoso · {{ $result['minutes'] }} minutos · Motor {{ $simulation->engine_version }} · Seed: {{ $simulation->seed }}</p>
@if($result['status'] === 'abandoned')<p role="alert">Partida interrompida por equipe com menos de sete jogadores. O placar acima é o registro até a interrupção.</p>@endif
<p>Resultado de treinamento: a liga, a data interna, o saldo e a condição física oficial dos atletas foram preservados.</p>
<h2>Estatísticas das equipes</h2>
<div class="table-scroll"><table><thead><tr><th>Indicador</th><th>{{ $result['home']['name'] }}</th><th>{{ $result['away']['name'] }}</th></tr></thead><tbody>
@foreach(['possession' => 'Posse (%)', 'opportunities' => 'Oportunidades', 'shots' => 'Finalizações', 'shots_on_target' => 'Finalizações no gol', 'xg' => 'Gols esperados (xG)', 'saves' => 'Defesas', 'fouls' => 'Faltas', 'yellow_cards' => 'Amarelos', 'red_cards' => 'Expulsões', 'substitutions' => 'Substituições'] as $key => $label)
<tr><th>{{ $label }}</th><td>{{ $result['home']['stats'][$key] }}</td><td>{{ $result['away']['stats'][$key] }}</td></tr>
@endforeach
</tbody></table></div>
<p>Público, ocupação e bilheteria: não aplicáveis a este amistoso.</p>
<h2>Eventos destacados</h2>
<ol>
@foreach($result['events'] as $event)
    @if(in_array($event['type'], ['goal', 'assist', 'yellow_card', 'red_card', 'substitution', 'abandoned', 'tactical_change'], true))
    <li>{{ $event['minute'] }}' — {{ $labels[$event['type']] }}: {{ $names[$event['player_id']] ?? '' }}
        @if($event['type'] === 'substitution') no lugar de {{ $names[$event['data']['out_player_id']] }} @endif
        @if(isset($event['data']['reason']) && isset($reasons[$event['data']['reason']])) — {{ $reasons[$event['data']['reason']] }} @endif
        @if($event['type'] === 'tactical_change') ({{ $result[$event['side']]['name'] }}) @endif
        @if($event['type'] === 'red_card' && $event['data']['reason'] === 'second_yellow') (segundo amarelo) @endif
    </li>
    @endif
@endforeach
</ol>
@foreach(['home', 'away'] as $side)
<h2>{{ $result[$side]['name'] }} — Estatísticas individuais</h2>
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Minutos</th><th>Gols</th><th>Assistências</th><th>Finalizações</th><th>No gol</th><th>xG</th><th>Defesas</th><th>Amarelos</th><th>Expulsões</th><th>Condição inicial</th><th>Condição final simulada</th></tr></thead><tbody>
@foreach($result[$side]['players'] as $player)
<tr><td>{{ $player['name'] }}</td>@foreach(['minutes', 'goals', 'assists', 'shots', 'shots_on_target', 'xg', 'saves', 'yellow_cards', 'red_cards', 'initial_fitness', 'final_fitness'] as $key)<td>{{ $player[$key] }}</td>@endforeach</tr>
@endforeach
</tbody></table></div>
@endforeach
@foreach(['home', 'away'] as $side)
    @if(($result[$side]['controller'] ?? 'human') === 'ai')
    <section class="card">
        <h2>{{ $result[$side]['name'] }} — decisões da IA</h2>
        <p>Formação: {{ $simulation->input[$side]['tactics']['formation'] }}.</p>
        @foreach(\App\Domain\Tactics\Tactics::OPTIONS as $field => $choices)
        <p>{{ \App\Domain\Tactics\Tactics::LABELS[$field] }}: {{ $choices[$simulation->input[$side]['tactics'][$field]] }} → {{ $choices[$result[$side]['final_tactics'][$field]] }}.</p>
        @endforeach
        @if(isset($simulation->input[$side]['administration_plan']))
            @php($plan = $simulation->input[$side]['administration_plan'])
            <h3>Recomendações administrativas</h3>
            <p>Ingresso sugerido: R$ {{ number_format($plan['ticket_price_cents'] / 100, 2, ',', '.') }}. Estimativa inicial de procura: {{ $plan['estimated_attendance'] }} torcedores.</p>
            <p>Reserva operacional: R$ {{ number_format($plan['operating_reserve_cents'] / 100, 2, ',', '.') }}. Limite para investimentos: R$ {{ number_format($plan['investment_budget_cents'] / 100, 2, ',', '.') }}.</p>
            <p>São recomendações: o amistoso não cobra ingressos, debita valores nem inicia obras.</p>
        @endif
    </section>
    @endif
@endforeach
<details><summary>Todas as jogadas</summary>
<div class="table-scroll"><table><thead><tr><th>Ordem</th><th>Minuto</th><th>Evento</th><th>Equipe</th><th>Jogador</th></tr></thead><tbody>
@foreach($result['events'] as $event)
<tr><td>{{ $event['sequence'] }}</td><td>{{ $event['minute'] }}</td><td>{{ $labels[$event['type']] }}</td><td>{{ $event['side'] ? $result[$event['side']]['name'] : '—' }}</td><td>{{ $names[$event['player_id']] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
</details>
@endsection
