<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Account</title>
  <style>/* same styles as login */</style>
</head><body>
  <div class="wrap">
    <h1>Create Account</h1>

    @if($errors->any())
      <div class="alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('customer.register.post') }}">
      @csrf
      <label>First name</label>
      <input name="first_name" value="{{ old('first_name') }}">

      <label>Last name</label>
      <input name="last_name" value="{{ old('last_name') }}">

      <label>Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required>

      <label>Password (min 8)</label>
      <input type="password" name="password" required>

      <label>Confirm password</label>
      <input type="password" name="password_confirmation" required>

      <div class="row" style="margin-top:8px">
        <a class="link" href="{{ route('customer.login') }}">Back to login</a>
        <button class="btn">Create account</button>
      </div>
    </form>
  </div>
</body></html>
