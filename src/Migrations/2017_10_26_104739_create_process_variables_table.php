<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProcessVariablesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_variables', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_pro_id');
            $table->string('name')->unique();
            $table->string('title');
            $table->integer('type_id');
            $table->string('description');
            $table->integer('fetch_id');
            $table->boolean('is_global')->default(true);            
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
        Schema::dropIfExists('bpms_variables');
    }
}
