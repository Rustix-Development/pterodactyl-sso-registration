<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_registration_signups', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            // "password", or "sso:<provider>" for an account created through Single Sign-On.
            $table->string('source', 48);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_registration_signups');
    }
};
