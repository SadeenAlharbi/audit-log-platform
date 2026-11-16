<?php

namespace App\Console\Commands;

use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OneLogin\Saml2\IdPMetadataParser;
use Slides\Saml2\Models\Tenant;
use Throwable;

/**
 * Writes the identity provider from .env (config/audit.php 'saml') to the
 * saml2_tenants table read by the SAML toolkit (decision D15). Safe to run
 * again: it updates the one tenant in place.
 */
#[Signature('audit:saml-sync')]
#[Description('Configure SAML single sign-on from SAML2_* in .env (IdP metadata URL or explicit values)')]
class SamlSyncCommand extends Command
{
    public function handle(PlatformAuditLogger $audit): int
    {
        $config = (array) config('audit.saml');
        $uuid = (string) ($config['tenant_uuid'] ?? '');

        if (! ($config['enabled'] ?? false)) {
            $this->warn('SAML2_ENABLED is off: nothing to do.');

            return self::SUCCESS;
        }
        if (! Str::isUuid($uuid)) {
            $this->error('SAML2_TENANT_UUID must be a UUID.');

            return self::FAILURE;
        }

        $idp = (array) ($config['idp'] ?? []);
        $fromMetadata = [];
        if (filled($idp['metadata_url'] ?? null)) {
            try {
                $xml = Http::timeout(10)->get((string) $idp['metadata_url'])->throw()->body();
                $fromMetadata = IdPMetadataParser::parseXML($xml)['idp'] ?? [];
            } catch (Throwable $e) {
                $this->error('Could not read the IdP metadata: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $values = [
            'idp_entity_id' => $idp['entity_id'] ?? $fromMetadata['entityId'] ?? null,
            'idp_login_url' => $idp['login_url'] ?? $fromMetadata['singleSignOnService']['url'] ?? null,
            'idp_logout_url' => $idp['logout_url'] ?? $fromMetadata['singleLogoutService']['url'] ?? '',
            'idp_x509_cert' => $idp['x509_cert'] ?? $fromMetadata['x509cert'] ?? $fromMetadata['x509certMulti']['signing'][0] ?? null,
        ];

        foreach (['idp_entity_id' => 'entity id', 'idp_login_url' => 'login URL', 'idp_x509_cert' => 'signing certificate'] as $key => $label) {
            if (blank($values[$key])) {
                $this->error("The IdP {$label} is missing: set SAML2_IDP_METADATA_URL or the explicit SAML2_IDP_* value.");

                return self::FAILURE;
            }
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::withTrashed()->firstOrNew(['uuid' => $uuid]);
        $tenant->forceFill($values + [
            'key' => 'default',
            // NameID carries the e-mail; accounts are matched by it.
            'name_id_format' => 'emailAddress',
            'relay_state_url' => '/admin',
            'metadata' => [],
            'deleted_at' => null,
        ])->save();

        $audit->record('sso.configured', null, ['idp' => $values['idp_entity_id'], 'uuid' => $uuid]);

        $this->info('SAML is configured.');
        $this->table(['', ''], [
            ['IdP', $values['idp_entity_id']],
            ['SP entity id / metadata', url("/saml2/{$uuid}/metadata")],
            ['SP ACS (POST)', url("/saml2/{$uuid}/acs")],
            ['SP logout', url("/saml2/{$uuid}/sls")],
        ]);

        return self::SUCCESS;
    }
}
