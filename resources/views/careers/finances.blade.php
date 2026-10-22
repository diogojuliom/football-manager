@extends('careers.layout')
@section('title', 'Finanças — '.$career->name)
@section('content')
@php($money = fn ($cents) => 'R$ '.number_format($cents / 100, 2, ',', '.'))
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a> · <a href="{{ route('careers.tickets.index', $career) }}">Ingressos</a></p>
<h1>Finanças — {{ $club->name }}</h1>
<section class="card" aria-label="Resumo financeiro">
    <h2>Temporada {{ $season->number }}</h2>
    <p>Saldo inicial da carreira: <strong>{{ $money($club->initial_balance_cents) }}</strong> · Saldo atual: <strong>{{ $money($club->balance_cents) }}</strong>.</p>
    <p>Bilheteria acumulada: {{ $money($totals->revenue) }} · Despesas operacionais: {{ $money($totals->operating_cost) }}.</p>
    <p>Investimentos realizados: {{ $money($totals->investments) }} · Resultado financeiro da temporada: <strong>{{ $money($totals->revenue - $totals->operating_cost - $totals->investments) }}</strong>.</p>
    <p>Receita média por partida em casa: {{ $money($totals->home_matches ? round($totals->revenue / $totals->home_matches) : 0) }} ({{ $totals->home_matches }} jogos).</p>
    <p><a href="{{ route('careers.stadium.show', $career) }}">Consultar obras e infraestrutura</a></p>
</section>
<h2>Histórico das movimentações</h2>
@if($history->isEmpty())
<p>Nenhum lançamento financeiro realizado nesta carreira.</p>
@else
<div class="table-scroll"><table><thead><tr><th>Data interna</th><th>Origem</th><th>Tipo</th><th>Valor</th><th>Saldo após</th></tr></thead><tbody>
@foreach($history as $entry)
<tr><td>{{ $entry->occurred_on->format('d/m/Y') }}</td><td>@if($entry->match)<a href="{{ route('careers.results.show', [$career, $entry->match->result]) }}">Jogo em casa contra {{ $entry->match->awayClub->name }}</a>@elseif($entry->kind === 'investment')<a href="{{ route('careers.stadium.show', $career) }}">Obra no estádio #{{ substr($entry->source_key, strlen('stadium-project:')) }}</a>@else {{ $entry->source_key }} @endif</td><td>{{ match($entry->kind) { 'ticket_revenue' => 'Bilheteria', 'match_operating_cost' => 'Operação do jogo', 'investment' => 'Investimento', default => $entry->kind } }}</td><td>{{ $money($entry->amount_cents) }}</td><td>{{ $money($entry->balance_after_cents) }}</td></tr>
@endforeach
</tbody></table></div>
{{ $history->links() }}
@endif
@endsection
