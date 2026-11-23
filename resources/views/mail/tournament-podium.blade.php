<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $podium['placement_label'] }} em {{ $podium['tournament'] }}</title>
</head>
{{-- E-mail não usa o CSS do site: os clientes de e-mail só respeitam estilo na própria tag e layout em tabela. --}}
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Você terminou {{ $podium['tournament'] }} em {{ $podium['placement_label'] }}. Veja seu resultado.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
<tr><td align="center" style="padding:24px 16px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
    <tr><td style="padding:0 4px 12px;font-size:14px;font-weight:700;color:#3f3f46;">ManaLeague</td></tr>
    <tr><td style="background-color:#ffffff;border:1px solid #e4e4e7;border-radius:10px;padding:28px 24px;">
        <div style="font-size:40px;line-height:48px;" aria-hidden="true">{{ $podium['medal'] }}</div>
        <h1 style="margin:8px 0 8px;font-size:24px;line-height:30px;font-weight:800;color:#18181b;">Você ficou em {{ $podium['placement_label'] }}!</h1>
        <p style="margin:0 0 4px;font-size:16px;line-height:24px;color:#3f3f46;">Parabéns, {{ $name }}. Este foi o seu resultado em <strong style="color:#18181b;">{{ $podium['tournament'] }}</strong>.</p>
        <p style="margin:0 0 20px;font-size:13px;line-height:20px;color:#71717a;">{{ collect([$podium['format'], $podium['date'], $podium['players'].' '.($podium['players'] === 1 ? 'jogador' : 'jogadores')])->filter()->join(' · ') }}</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;">
        <tr>
            <td width="50%" style="padding:0 4px 8px 0;">
                <div style="background-color:#f4f4f5;border-radius:6px;padding:12px;">
                    <div style="font-size:22px;line-height:28px;font-weight:800;color:#18181b;">{{ $podium['placement'] }}º</div>
                    <div style="font-size:12px;line-height:16px;color:#71717a;">Colocação</div>
                </div>
            </td>
            <td width="50%" style="padding:0 0 8px 4px;">
                <div style="background-color:#f4f4f5;border-radius:6px;padding:12px;">
                    <div style="font-size:22px;line-height:28px;font-weight:800;color:#18181b;">{{ $podium['winrate'] }}%</div>
                    <div style="font-size:12px;line-height:16px;color:#71717a;">Taxa de vitória</div>
                </div>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding:0 4px 0 0;">
                <div style="background-color:#f4f4f5;border-radius:6px;padding:12px;">
                    <div style="font-size:22px;line-height:28px;font-weight:800;color:#18181b;">{{ $podium['wins'] }}–{{ $podium['losses'] }}@if($podium['draws'])–{{ $podium['draws'] }}@endif</div>
                    <div style="font-size:12px;line-height:16px;color:#71717a;">{{ $podium['draws'] ? 'Vitórias–derrotas–empates' : 'Vitórias–derrotas' }}</div>
                </div>
            </td>
            <td width="50%" style="padding:0 0 0 4px;">
                <div style="background-color:#f4f4f5;border-radius:6px;padding:12px;">
                    <div style="font-size:22px;line-height:28px;font-weight:800;color:#18181b;">{{ $podium['played'] }}</div>
                    <div style="font-size:12px;line-height:16px;color:#71717a;">{{ $podium['played'] === 1 ? 'Partida' : 'Partidas' }}</div>
                </div>
            </td>
        </tr>
        </table>
        @if($podium['games'])
        <p style="margin:12px 0 0;font-size:13px;line-height:20px;color:#71717a;">Games a favor e contra: <strong style="color:#3f3f46;">{{ $podium['games'] }}</strong></p>
        @endif

        @if($podium['prizes'])
        <h2 style="margin:24px 0 8px;font-size:15px;line-height:20px;font-weight:700;color:#18181b;">Seus prêmios</h2>
        <ul style="margin:0 0 8px;padding:0 0 0 18px;font-size:14px;line-height:22px;color:#3f3f46;">
            @foreach($podium['prizes'] as $prize)
            <li>{{ $prize }}</li>
            @endforeach
        </ul>
        <p style="margin:0;font-size:14px;line-height:20px;"><a href="{{ $podium['prizes_url'] }}" style="color:#18181b;font-weight:600;">Ver meus prêmios</a></p>
        @endif

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;">
        <tr><td style="background-color:#18181b;border-radius:6px;">
            <a href="{{ $podium['url'] }}" style="display:inline-block;padding:12px 20px;font-size:15px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;">Ver resultado do {{ $podium['kind'] }}</a>
        </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:16px 4px 0;font-size:12px;line-height:18px;color:#71717a;">
        Você recebeu este e-mail porque subiu ao pódio de um {{ $podium['kind'] }} no ManaLeague. Para não receber mais, desligue o e-mail de <strong>Eventos</strong> em <a href="{{ $podium['preferences_url'] }}" style="color:#52525b;">Editar perfil › Notificações</a>.
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
