<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWorkflowStatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_states', function (Blueprint $table) {
            $table->increments('id');
            $table->string('wid');
            $table->string('type');
            $table->integer('position_state');
            $table->integer('ws_pro_id');
            $table->string('text');
            $table->string('next_wid')->nullable();
            $table->string('next_type')->nullable();
            $table->integer('meta_type')->nullable();
            $table->integer('meta_value')->nullable();
            $table->text('options')->nullable();

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
        Schema::dropIfExists('bpms_states');
    }
}
