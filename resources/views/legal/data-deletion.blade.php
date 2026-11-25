@extends('legal.layout')

@section('title', 'Data Deletion Instructions')
@section('description', 'How to delete the data '.$appName.' holds about you, including data received from Facebook, Instagram and WhatsApp.')

@section('content')
    <p>
        You can ask {{ $company }} to delete the data {{ $appName }} holds about you at any time. Choose the option that
        matches how your data reached us. We complete deletion requests within {{ $deletionDays }} days.
    </p>

    <h2>1. You connected Facebook to {{ $appName }}</h2>
    <p>If you signed in with Facebook to connect your Pages, you can remove the app and its data yourself:</p>
    <ol>
        <li>Open Facebook and go to <strong>Settings &amp; privacy &gt; Settings &gt; Apps and websites</strong>
            (or Business Integrations for Pages).</li>
        <li>Find <strong>{{ $appName }}</strong> and choose <strong>Remove</strong>.</li>
        <li>Facebook then notifies us automatically. We immediately delete the access tokens and connected Pages, and
            erase your Facebook user ID and name. Facebook shows you a confirmation code; you can check the status of
            your request at <a href="{{ route('legal.data-deletion.status') }}">{{ route('legal.data-deletion.status') }}</a>.</li>
    </ol>
    <p>A Workspace administrator can also choose <strong>Disconnect</strong> on the Integrations page of
        {{ $appName }}, which deletes the stored access tokens straight away.</p>

    <h2>2. You submitted a Facebook or Instagram lead form, or messaged a business on WhatsApp</h2>
    <p>
        Your details are held on behalf of the business you contacted, which controls them. Ask that business to
        delete your data, or write to us at @include('legal.contact') with the business's name and the phone number or
        e-mail address you used, and we will pass on and carry out the request. On WhatsApp you can also reply
        <strong>STOP</strong> at any time to opt out of the business's marketing messages.
    </p>

    <h2>3. You have a {{ $appName }} account</h2>
    <p>Ask your Workspace administrator to remove you, or e-mail @include('legal.contact') from the address on your
        account. We will delete or anonymize your account data, keeping only records the law requires.</p>

    <h2>What is deleted, and what is kept</h2>
    <table>
        <thead><tr><th>Data</th><th>On request</th></tr></thead>
        <tbody>
            <tr><td>Facebook user ID, name, granted permissions and Page access tokens</td><td>Deleted</td></tr>
            <tr><td>WhatsApp access token of a disconnected number</td><td>Deleted</td></tr>
            <tr><td>Your lead record, notes, WhatsApp messages and media</td><td>Deleted on your or the business's request</td></tr>
            <tr><td>Security audit records</td><td>Kept only as long as the law or security requires, then deleted</td></tr>
        </tbody>
    </table>

    <h2>Questions</h2>
    <p>E-mail @include('legal.contact'). See also our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>
@endsection
