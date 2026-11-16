<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAML identity providers for dashboard single sign-on (decision D15),
 * read by scaler-tech/laravel-saml2. One row in practice, written from .env
 * by `php artisan audit:saml-sync`. Replaces the package's three migrations
 * (one migration per table, all columns in it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saml2_tenants', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('key')->nullable();
            $table->string('idp_entity_id');
            $table->string('idp_login_url');
            $table->string('idp_logout_url');
            $table->text('idp_x509_cert');
            $table->string('relay_state_url')->nullable();
            $table->string('name_id_format')->default('persistent');
            $table->json('metadata');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml2_tenants');
    }
};
