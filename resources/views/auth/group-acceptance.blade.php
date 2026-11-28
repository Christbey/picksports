<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join group · PickSports</title>
</head>
<body>
    <main>
        <h1>Join {{ $groupName }}</h1>
        <p>Confirm that you want to join this group with your signed-in account.</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">Join group</button>
        </form>
        <a href="{{ route('march-madness-bracket') }}">Cancel</a>
    </main>
</body>
</html>
