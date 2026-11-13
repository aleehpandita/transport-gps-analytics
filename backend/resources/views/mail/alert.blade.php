<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $label }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1d2433;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e1e4ea;border-radius:8px;">
        <tr>
          <td style="padding:20px 24px 8px;font-size:13px;color:#5b6475;">EkMira · Alerta</td>
        </tr>
        <tr>
          <td style="padding:0 24px;">
            <h1 style="margin:0 0 6px;font-size:20px;line-height:1.3;color:#1d2433;">{{ $label }}</h1>
            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#1d2433;">{{ $alert->message }}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 24px 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.6;color:#1d2433;">
              <tr><td style="padding-right:16px;color:#5b6475;">Unidad</td><td><strong>{{ $alert->vehicle?->name }}</strong></td></tr>
              <tr><td style="padding-right:16px;color:#5b6475;">Hora</td><td>{{ $when }} (hora de Cancún)</td></tr>
              @if ($ruleName)
              <tr><td style="padding-right:16px;color:#5b6475;">Regla</td><td>{{ $ruleName }}</td></tr>
              @endif
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 24px 24px;">
            <a href="{{ $mapUrl }}" style="display:inline-block;background:#1f7a5c;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:bold;">Ver en el mapa</a>
            <a href="{{ $panelUrl }}" style="display:inline-block;margin-left:8px;color:#1f7a5c;text-decoration:underline;padding:10px 4px;font-size:14px;">Abrir la bandeja de alertas</a>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 24px;border-top:1px solid #e1e4ea;font-size:12px;line-height:1.5;color:#5b6475;">
            Recibes este correo porque tu correo está en esta alerta del panel de EkMira. Para dejar de recibirla, quítalo de la regla en Alertas.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
