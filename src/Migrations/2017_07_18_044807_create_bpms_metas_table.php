<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsMetasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_metas', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('element_type')->default(1);
            $table->integer('element_id')->default(0);
            $table->string('element_name')->nullable();
            $table->integer('meta_type')->nullable();
            $table->string('meta_value')->nullable();
            $table->integer('case_id');
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
        Schema::dropIfExists('bpms_metas');
    }
}
