<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $options = [
            'timeseries' => [
                'timeField' => 'timestamp',
                'metaField' => 'location',
                'granularity' => 'minutes',
            ],
        ];

        Schema::create('precipitation', null, $options);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('precipitation');
    }
};
