<?php

namespace App\Filament\Support;

use App\Models\Project;

/**
 * Ready-to-copy examples for the "Integration guide" (plan §5 Onboarding).
 */
final class IntegrationSnippets
{
    /** @return array<string, array{label: string, code: string}> */
    public static function for(Project $project): array
    {
        $base = rtrim((string) config('app.url'), '/').'/api/v1';
        $slug = $project->slug;

        $body = '{"external_id":"order-1001-paid","event":"order.paid","action":"update","severity":"info","occurred_at":"2026-01-01T10:00:00Z","actor":{"type":"user","id":"42","name":"Sara"},"subject":{"type":"order","id":"1001"},"old_values":{"status":"pending"},"new_values":{"status":"paid"}}';

        return [
            'curl' => ['label' => 'cURL', 'code' => <<<SH
                # Unsigned key
                curl -X POST {$base}/logs \\
                  -H "Authorization: Bearer \$AUDIT_API_KEY" \\
                  -H "X-Project: {$slug}" \\
                  -H "Content-Type: application/json" \\
                  -d '{$body}'

                # Signed key: X-Timestamp + X-Signature
                BODY='{$body}'
                TS=\$(date +%s)
                SIG=\$(printf '%s\\n%s\\n%s\\n%s' "\$TS" "POST" "/api/v1/logs" "\$BODY" | openssl dgst -sha256 -hmac "\$AUDIT_HMAC_SECRET" -hex | sed 's/^.* //')
                curl -X POST {$base}/logs \\
                  -H "Authorization: Bearer \$AUDIT_API_KEY" -H "X-Project: {$slug}" \\
                  -H "X-Timestamp: \$TS" -H "X-Signature: \$SIG" \\
                  -H "Content-Type: application/json" -d "\$BODY"
                SH],

            'laravel' => ['label' => 'Laravel (company/audit-client)', 'code' => <<<PHP
                composer require company/audit-client
                php artisan vendor:publish --tag=audit-config

                # .env
                AUDIT_ENDPOINT={$base}
                AUDIT_PROJECT={$slug}
                AUDIT_API_KEY=alh_...
                AUDIT_HMAC_SECRET=...

                // In code
                Audit::log('order.paid', \$user, \$order, ['status' => 'pending'], ['status' => 'paid']);
                PHP],

            'php' => ['label' => 'PHP (Guzzle)', 'code' => <<<PHP
                \$body = json_encode(\$log);
                \$ts = (string) time();
                \$sig = hash_hmac('sha256', "\$ts\\nPOST\\n/api/v1/logs\\n\$body", getenv('AUDIT_HMAC_SECRET'));

                (new GuzzleHttp\\Client())->post('{$base}/logs', [
                    'headers' => [
                        'Authorization' => 'Bearer '.getenv('AUDIT_API_KEY'),
                        'X-Project' => '{$slug}',
                        'X-Timestamp' => \$ts,
                        'X-Signature' => \$sig,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => \$body,
                ]);
                PHP],

            'node' => ['label' => 'Node.js', 'code' => <<<JS
                import crypto from 'node:crypto';

                const body = JSON.stringify(log);
                const ts = Math.floor(Date.now() / 1000).toString();
                const sig = crypto.createHmac('sha256', process.env.AUDIT_HMAC_SECRET)
                  .update(`\${ts}\\nPOST\\n/api/v1/logs\\n\${body}`).digest('hex');

                await fetch('{$base}/logs', {
                  method: 'POST',
                  headers: {
                    Authorization: `Bearer \${process.env.AUDIT_API_KEY}`,
                    'X-Project': '{$slug}',
                    'X-Timestamp': ts,
                    'X-Signature': sig,
                    'Content-Type': 'application/json',
                  },
                  body,
                });
                JS],

            'python' => ['label' => 'Python', 'code' => <<<PY
                import hashlib, hmac, json, os, time, requests

                body = json.dumps(log, separators=(",", ":"))
                ts = str(int(time.time()))
                msg = f"{ts}\\nPOST\\n/api/v1/logs\\n{body}".encode()
                sig = hmac.new(os.environ["AUDIT_HMAC_SECRET"].encode(), msg, hashlib.sha256).hexdigest()

                requests.post("{$base}/logs", data=body, headers={
                    "Authorization": f"Bearer {os.environ['AUDIT_API_KEY']}",
                    "X-Project": "{$slug}",
                    "X-Timestamp": ts,
                    "X-Signature": sig,
                    "Content-Type": "application/json",
                }, timeout=5)
                PY],
        ];
    }
}
