<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsStatesTable extends Migration
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
            $table->string('loop');
            $table->integer('position_state');
            $table->integer('ws_pro_id');
            $table->string('text');
            $table->string('next_wid')->nullable();
            $table->string('next_type')->nullable();
            $table->integer('meta_type')->nullable();
            $table->integer('meta_value')->nullable();
            $table->tinyInteger('meta_user')->default(0);
            $table->boolean('has_successor')->default(false);
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
        Schema::dropIfExists('bpms_states');
    }
}
