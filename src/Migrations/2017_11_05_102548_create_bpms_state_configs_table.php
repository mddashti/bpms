<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsStateConfigsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms.bpms_state_configs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('state_id');
            $table->integer('form_id')->nullable();            
            $table->integer('trigger_id')->nullable();
            $table->integer('run_type')->nullable();
            $table->string('condition')->default(true);            
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
        Schema::dropIfExists('bpms.bpms_state_configs');
    }
}
