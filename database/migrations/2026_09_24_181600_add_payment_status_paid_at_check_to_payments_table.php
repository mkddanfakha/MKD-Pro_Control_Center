<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'payments_status_paid_at_coherence';

    private const CHECK_EXPRESSION = '(
        (
            status IN (\'paid\', \'refunded\')
            AND paid_at IS NOT NULL
        )
        OR
        (
            status IN (\'pending\', \'failed\')
            AND paid_at IS NULL
        )
    )';

    private const SQLITE_REBUILD_TABLE = 'payments__status_paid_at_coherence';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->clearPaidAtOnPendingOrFailedPayments();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE payments ADD CONSTRAINT %s CHECK %s',
                self::CONSTRAINT_NAME,
                str_replace('status', '`status`', str_replace('paid_at', '`paid_at`', self::CHECK_EXPRESSION)),
            ));

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildPaymentsTableSqlite(withCheck: true);

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
                'ALTER TABLE payments DROP CHECK %s',
                self::CONSTRAINT_NAME,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildPaymentsTableSqlite(withCheck: false);

            return;
        }

        throw new RuntimeException(sprintf(
            'Migration %s supports only mysql and sqlite; got [%s].',
            self::class,
            $driver,
        ));
    }

    private function rebuildPaymentsTableSqlite(bool $withCheck): void
    {
        $checkClause = $withCheck
            ? sprintf(', CONSTRAINT %s CHECK %s', self::CONSTRAINT_NAME, self::CHECK_EXPRESSION)
            : '';

        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement(sprintf(
            'CREATE TABLE %s (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                subscription_id INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR NOT NULL DEFAULT \'XOF\',
                status VARCHAR NOT NULL DEFAULT \'paid\',
                due_at DATETIME,
                paid_at DATETIME,
                renewal_applied_at DATETIME,
                period_start DATETIME,
                period_end DATETIME,
                payment_method VARCHAR,
                reference VARCHAR,
                notes TEXT,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT%s
            )',
            self::SQLITE_REBUILD_TABLE,
            $checkClause,
        ));

        DB::statement(sprintf(
            'INSERT INTO %s SELECT * FROM payments',
            self::SQLITE_REBUILD_TABLE,
        ));

        DB::statement('DROP TABLE payments');

        DB::statement(sprintf(
            'ALTER TABLE %s RENAME TO payments',
            self::SQLITE_REBUILD_TABLE,
        ));

        DB::statement('CREATE INDEX payments_subscription_id_index ON payments (subscription_id)');
        DB::statement('CREATE INDEX payments_status_index ON payments (status)');
        DB::statement('CREATE INDEX payments_due_at_index ON payments (due_at)');
        DB::statement('CREATE INDEX payments_paid_at_index ON payments (paid_at)');
        DB::statement('CREATE INDEX payments_renewal_applied_at_index ON payments (renewal_applied_at)');

        DB::statement('PRAGMA foreign_keys=ON');
    }

    /**
     * Corrige uniquement les combinaisons non ambiguës (date sur pending/failed).
     * Les lignes paid/refunded sans paid_at ne sont pas modifiées : l'ajout du CHECK échouera.
     */
    private function clearPaidAtOnPendingOrFailedPayments(): void
    {
        DB::table('payments')
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotNull('paid_at')
            ->update(['paid_at' => null]);
    }
};
