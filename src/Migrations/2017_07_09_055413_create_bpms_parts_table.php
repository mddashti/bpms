<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsPartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_parts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_pro_id');
            $table->string('from');
            $table->string('to')->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_ended');
            $table->integer('transition_id');
            $table->string('gate_id')->nullable();
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
        Schema::dropIfExists('bpms_parts');
    }
}
