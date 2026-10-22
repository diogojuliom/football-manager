@if(session('status'))
    <div class="auth-feedback auth-feedback-success" role="status">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
        <span>{{ session('status') }}</span>
    </div>
@endif
@if($errors->any())
    <div class="auth-feedback auth-feedback-error" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
        <div><strong>Confira os dados informados:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    </div>
@endif
