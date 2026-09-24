<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'subscriptions_non_terminated_installation_id_unique';

    private const COLUMN_NAME = 'non_terminated_installation_id';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE subscriptions ADD COLUMN %s BIGINT UNSIGNED AS (
                    CASE
                        WHEN `status` IN (\'active\', \'grace_period\', \'suspended\')
                        THEN `installation_id`
                        ELSE NULL
                    END
                ) STORED',
                self::COLUMN_NAME,
            ));

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON subscriptions (%s)',
                self::INDEX_NAME,
                self::COLUMN_NAME,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(sprintf(
                'ALTER TABLE subscriptions ADD COLUMN %s INTEGER GENERATED ALWAYS AS (
                    CASE
                        WHEN status IN (\'active\', \'grace_period\', \'suspended\')
                        THEN installation_id
                        ELSE NULL
                    END
                ) STORED',
                self::COLUMN_NAME,
            ));

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON subscriptions (%s)',
                self::INDEX_NAME,
                self::COLUMN_NAME,
            ));

            return;
        }

        throw new RuntimeException(sprintf(
            'Migration %s supports only mysql and sqlite; got [%s].',
            self::class,
            $driver,
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'DROP INDEX %s ON subscriptions',
                self::INDEX_NAME,
            ));

            DB::statement(sprintf(
                'ALTER TABLE subscriptions DROP COLUMN %s',
                self::COLUMN_NAME,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(sprintf(
                'DROP INDEX %s',
                self::INDEX_NAME,
            ));

            DB::statement(sprintf(
                'ALTER TABLE subscriptions DROP COLUMN %s',
                self::COLUMN_NAME,
            ));

            return;
        }

        throw new RuntimeException(sprintf(
            'Migration %s supports only mysql and sqlite; got [%s].',
            self::class,
            $driver,
        ));
    }
};
