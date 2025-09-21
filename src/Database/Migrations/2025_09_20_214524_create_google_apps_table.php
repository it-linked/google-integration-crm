<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('google_apps', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret'); // we'll encrypt in model
            $table->string('redirect_uri');
            $table->string('webhook_uri')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('google_apps');
    }
};
