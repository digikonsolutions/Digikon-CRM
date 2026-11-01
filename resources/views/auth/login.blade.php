<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — CRM</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="eyebrow">Lead &amp; Sales CRM</div>
        <h1>{{ \App\Models\Setting::get('company_name', config('app.name')) }}</h1>
        <p class="muted">Sign in to your account</p>

        @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="check"><input type="checkbox" name="remember"> Remember me</label>
            </div>
            <button class="btn" style="width:100%">Login</button>
        </form>
    </div>
</div>
</body>
</html>
