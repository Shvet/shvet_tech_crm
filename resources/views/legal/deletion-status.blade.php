@extends('legal.layout')

@section('title', 'Data Deletion Status')
@section('description', 'Check the status of a data deletion request sent to '.$appName.' from Facebook.')

@section('content')
    @if ($record)
        <p>
            Request <strong>{{ $record->confirmation_code }}</strong>, received {{ $record->created_at->toDateString() }}:
            <strong>{{ $record->status === \App\Models\MetaDataDeletionRequest::STATUS_COMPLETED ? 'Completed' : 'In progress' }}</strong>.
        </p>
        @if ($record->status === \App\Models\MetaDataDeletionRequest::STATUS_COMPLETED)
            <p>The Facebook access tokens, connected Pages, and your Facebook user ID and name have been deleted from
                {{ $appName }}.</p>
        @endif
    @elseif ($code !== '')
        <p>We could not find a request with the code <strong>{{ $code }}</strong>. Check the code Facebook gave you, or
            e-mail @include('legal.contact').</p>
    @endif

    <form method="GET" action="{{ route('legal.data-deletion.status') }}" class="flex flex-wrap items-end gap-2 pt-2">
        <label class="text-sm">Confirmation code
            <input name="code" value="{{ $code }}" maxlength="32" class="mt-1 block rounded-md border border-slate-300 px-3 py-2 text-sm">
        </label>
        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">Check status</button>
    </form>

    <p>See <a href="{{ route('legal.data-deletion') }}">Data Deletion Instructions</a> for other ways to delete your data.</p>
@endsection
