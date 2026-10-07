You have been invited to join the {{ $workspaceName }} workspace on Dashflow as {{ $roleName ?? 'an Admin' }}.

Open this link to choose your name and password:

{{ $link }}

The link works once and expires on {{ $expiresAt->utc()->format('Y-m-d H:i') }} UTC. Anyone with the link can use it, so do not forward it.
If you did not expect this email, ignore it.
