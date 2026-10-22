@extends('careers.layout')
@section('title', 'Criar conta — Football Manager')
@section('content')
<h1>Criar conta</h1>
<form method="post" action="{{ route('register') }}">
    @csrf
    <label for="name">Nome</label><input id="name" name="name" autocomplete="name" value="{{ is_string(old('name')) ? old('name') : '' }}" maxlength="255" required>
    <label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
    <label for="password">Senha (mínimo de 8 caracteres)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
    <label for="password_confirmation">Confirme a senha</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
    <button type="submit">Criar conta</button>
</form>
@endsection
