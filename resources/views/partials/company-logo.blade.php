@php
    $brandingCompany = ($company ?? null) instanceof \App\Models\Company ? $company : \App\Models\Company::first();
    $documentLogo = $brandingCompany?->document_logo;
@endphp
@if($documentLogo)
    <img src="{{ $documentLogo }}" alt="{{ $brandingCompany->name }} logo" style="display:block;max-width:180px;max-height:80px;width:auto;height:auto;object-fit:contain;margin:0 0 16px;" />
@endif
