<x-mail::message>
# Redirect canary failed

Every scan of every QR code goes through the path this checks.

**URL:** {{ $url }}
**Reason:** {{ $reason }}
**Consecutive failures:** {{ $failures }}
**At:** {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB

<x-mail::panel>
Check, in order: Cloudflare status and any recent rule change, the certificate, DNS,
then the origin. The canary goes through the whole path, so PHP being up proves
nothing on its own.
</x-mail::panel>
</x-mail::message>
