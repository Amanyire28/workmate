{{-- The brand is fixed here so emails say WorkMate whatever APP_NAME is set to on the server --}}
@component('mail::layout')
{{-- Header --}}
@slot('header')
@component('mail::header', ['url' => url('/')])
WorkMate
@endcomponent
@endslot

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
@component('mail::subcopy')
{{ $subcopy }}
@endcomponent
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
© {{ date('Y') }} WorkMate by ResNet Systems. Kampala, Uganda.
@endcomponent
@endslot
@endcomponent
