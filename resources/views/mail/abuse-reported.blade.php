<x-mail::message>
# Abuse report

**Reason:** {{ $flag->reason?->value ?? '—' }}
**Reported:** {{ $flag->url }}
**Matches a code:** {{ $flag->qr_code_id ? 'yes — '.$flag->qr_code_id : 'no (slug unknown or never existed)' }}
**Reporter:** {{ $flag->reporter_email ?? 'anonymous' }}
**Received:** {{ $flag->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB

@if ($flag->qrCode)
<x-mail::panel>
Destination: {{ $flag->qrCode->destination['dest_url'] ?? '—' }}
Status: {{ $flag->qrCode->status->value }}
</x-mail::panel>

To kill it: `php artisan qr:block {{ $flag->qrCode->slug }}`
@endif
</x-mail::message>
