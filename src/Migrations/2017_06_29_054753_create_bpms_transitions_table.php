<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsTransitionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_transitions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_pro_id');
            $table->tinyInteger('type')->default(0);
            $table->integer('order_transition')->nullable();
            $table->string('gate_wid')->nullable();
            $table->string('from_state');
            $table->string('to_state');
            $table->string('meta')->nullable();
            $table->json('options')->nullable();            
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
        Schema::dropIfExists('bpms_transitions');
    }
}
