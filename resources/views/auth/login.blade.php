@extends('auth.layout')
@section('title', 'Entrar — Football Manager Web')
@section('content')
<div class="auth-intro">
    <h1 id="auth-title">Bem-vindo de volta!</h1>
    <p>Gerencie seu clube, monte táticas e conquiste títulos.</p>
</div>
@include('auth.partials.feedback')
<form method="post" action="{{ route('login') }}" data-auth-form data-loading-text="Autenticando...">
    @csrf
    <x-auth.field name="email" label="E-mail do Treinador" type="email" autocomplete="email"
        placeholder="manager@footballweb.com" :value="is_string(old('email')) ? old('email') : ''" required />
    <x-auth.field name="password" label="Senha" type="password" icon="lock" autocomplete="current-password"
        placeholder="••••••••" :forgot-url="route('password.request')" required toggle />
    <label class="auth-remember"><input name="remember" type="checkbox" value="1" @checked((bool) old('remember'))><span>Lembrar de mim</span></label>
    <button class="auth-submit" type="submit">
        <svg class="auth-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true" hidden><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"/><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
        <span data-submit-label>Entrar no Jogo</span>
        <svg class="auth-submit-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7"/></svg>
    </button>
</form>
<p class="auth-switch">Ainda não comanda um clube? <a href="{{ route('register') }}">Criar minha conta</a></p>
@endsection
