@extends('careers.layout')
@section('title', 'Recuperar acesso — Football Manager')
@section('content')
<h1>Recuperar acesso</h1>
<p>Informe seu e-mail para receber um link de redefinição de senha.</p>
<form method="post" action="{{ route('password.email') }}">
    @csrf
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" autocomplete="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
    <button type="submit">Enviar link</button>
</form>
@endsection
