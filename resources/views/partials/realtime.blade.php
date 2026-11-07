{{-- Tempo real (Reverb): so com BROADCAST_CONNECTION=reverb. A chave e publica; canais privados pedem autorização. --}}
@if(config('reverb.client.enabled'))
<meta name="realtime" content="{{ json_encode(['key' => config('reverb.apps.apps.0.key'), 'url' => config('reverb.client.url'), 'auth' => '/broadcasting/auth'], JSON_UNESCAPED_SLASHES) }}">
<script src="{{ \App\Support\VersionedAsset::url('js/realtime.js') }}" defer></script>
@endif
