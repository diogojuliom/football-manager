@extends('auth.layout')
@section('title', 'Criar conta — Football Manager Web')
@section('content')
<div class="auth-intro auth-intro-register">
    <h1 id="auth-title">Criar Nova Conta</h1>
    <p>Assuma o cargo de treinador e leve seu time à glória.</p>
</div>
@include('auth.partials.feedback')
<form method="post" action="{{ route('register') }}" data-auth-form data-loading-text="Cadastrando...">
    @csrf
    <x-auth.field name="name" label="Nome do Treinador" icon="user" autocomplete="name" maxlength="255"
        placeholder="Ex: Carlos Alberto" :value="is_string(old('name')) ? old('name') : ''" required />
    <x-auth.field name="email" label="E-mail" type="email" autocomplete="email"
        placeholder="manager@footballweb.com" :value="is_string(old('email')) ? old('email') : ''" required />
    <x-auth.field name="password" label="Senha" type="password" icon="lock" autocomplete="new-password"
        placeholder="••••••••" minlength="8" describedby="password-hints" required toggle />
    <ul class="auth-password-hints" id="password-hints" aria-label="Orientações para a senha">
        <li data-strength="length">✓ Mínimo 8 caracteres</li>
        <li data-strength="uppercase">+ Maiúscula (opcional)</li>
        <li data-strength="number">+ Número (opcional)</li>
        <li data-strength="special">+ Símbolo (opcional)</li>
    </ul>
    <x-auth.field name="password_confirmation" label="Confirmar Senha" type="password" icon="lock"
        autocomplete="new-password" placeholder="••••••••" minlength="8" required toggle />
    <button class="auth-submit" type="submit">
        <svg class="auth-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true" hidden><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"/><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
        <span data-submit-label>Criar Conta de Treinador</span>
        <svg class="auth-submit-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7"/></svg>
    </button>
</form>
<p class="auth-switch">Já possui uma conta? <a href="{{ route('login') }}">Fazer Login</a></p>
@endsection
