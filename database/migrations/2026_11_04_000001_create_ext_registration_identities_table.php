<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_registration_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 32);
            // The provider's stable id for the account: a user id, an OpenID subject or a SteamID64.
            $table->string('subject', 191);
            $table->string('name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('ext_registration_signups', function (Blueprint $table) {
            // True while the account only has the random password it was created with.
            $table->boolean('random_password')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('ext_registration_signups', function (Blueprint $table) {
            $table->dropColumn('random_password');
        });
        Schema::dropIfExists('ext_registration_identities');
    }
};
