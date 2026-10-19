<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Income changed · AssocMap</title>
    <style>body{font-family:system-ui,sans-serif;margin:24px}main{max-width:960px;margin:auto}table{width:100%;table-layout:fixed;border-collapse:collapse}th,td{padding:8px;text-align:left;overflow-wrap:anywhere;border:1px solid #ddd}textarea{box-sizing:border-box;width:100%;max-width:100%}</style>
</head>
<body>
    <main>
        <h1>Income changed</h1>
        <p role="alert">{{ $message }}</p>
        <p>Your submitted draft is preserved below for copying and comparison. It has not been applied to the saved records.</p>
        @foreach($draft['production'] ?? [] as $production)
            <h2>Product entry {{ $loop->iteration }}</h2>
            <table><caption>Unsaved Income entries</caption><thead><tr><th>Frequency</th><th>Month</th><th>Gross Income</th><th>Remarks</th></tr></thead><tbody>
            @foreach($production['income'] ?? [] as $income)
                <tr><td>{{ $income['period_type'] }}</td><td>{{ $income['month'] ?? 'Quarterly' }}</td><td>{{ $income['gross_income'] }}</td><td>{{ $income['remarks'] ?? '' }}</td></tr>
            @endforeach
            </tbody></table>
            <p>Requested removals: {{ count($production['deleted_income_ids'] ?? []) }}</p>
        @endforeach
        <details><summary>Copy the complete submitted draft for recovery</summary><label for="income-draft">Submitted draft</label>
            <textarea id="income-draft" readonly rows="24" cols="80">{{ json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</textarea>
        </details>
        @if($latestIncomeUrl)
            <p><a href="{{ $latestIncomeUrl }}" target="_blank" rel="noopener noreferrer">Open Latest Saved Income</a></p>
        @endif
        <p>Keep this draft available for comparison before entering your changes again.</p>
    </main>
</body>
</html>
