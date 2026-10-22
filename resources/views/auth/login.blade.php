@extends('careers.layout')
@section('title', 'Entrar — Football Manager')
@section('content')
<h1>Entrar</h1>
<form method="post" action="{{ route('login') }}">
    @csrf
    <label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
    <label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Entrar</button>
</form>
<p><a href="{{ route('register') }}">Criar uma conta</a></p>
<p><a href="{{ route('password.request') }}">Esqueci minha senha</a></p>
@endsection
