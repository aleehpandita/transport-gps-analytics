{{ $label }}

{{ $alert->message }}

Unidad: {{ $alert->vehicle?->name }}
Hora: {{ $when }} (hora de Cancún)
@if ($ruleName)
Regla: {{ $ruleName }}
@endif

Ver en el mapa: {{ $mapUrl }}
Bandeja de alertas: {{ $panelUrl }}

Recibes este correo porque tu correo está en esta alerta del panel de EkMira.
