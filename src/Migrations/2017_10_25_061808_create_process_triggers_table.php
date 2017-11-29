<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProcessTriggersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_triggers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_pro_id');
            $table->string('title')->unique();
            $table->string('description');
            $table->text('content');
            $table->integer('trigger_type');
            $table->text('options')->nullable();
            $table->timestamps();
            $table->softDeletes();	             
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bpms_triggers');
    }
}
