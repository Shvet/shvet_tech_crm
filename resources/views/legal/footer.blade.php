<footer class="mx-auto max-w-3xl px-4 pb-10 text-center text-xs text-slate-500">
    <p>&copy; {{ now()->year }} {{ $company }}.
        <a href="{{ route('legal.privacy') }}" class="hover:underline">Privacy Policy</a> ·
        <a href="{{ route('legal.terms') }}" class="hover:underline">Terms of Service</a> ·
        <a href="{{ route('legal.data-deletion') }}" class="hover:underline">Data Deletion</a>
    </p>
</footer>
