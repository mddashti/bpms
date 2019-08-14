<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsElementTriggersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_element_triggers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('element_id');            
            $table->integer('trigger_id');
            $table->integer('event_type_id');
            $table->json('options')->nullable();
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
        Schema::dropIfExists('bpms_element_triggers');
    }
}
