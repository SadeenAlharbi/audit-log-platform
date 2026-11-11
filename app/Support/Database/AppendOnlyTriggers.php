<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database-level immutability (plan §4, §13).
 *
 * Creates BEFORE UPDATE / BEFORE DELETE triggers that raise SQLSTATE 45000.
 * DELETE can optionally be allowed for one MariaDB account (the archiver).
 *
 * Notes:
 *  - USER() is used, not CURRENT_USER(): inside a trigger CURRENT_USER()
 *    returns the trigger DEFINER, while USER() is the account that connected.
 *  - Triggers do not fire for TRUNCATE or ALTER TABLE ... DROP PARTITION.
 *    Those need DROP/ALTER privileges, which the runtime account must not
 *    have in production (see docs/decisions.md, D6).
 */
final class AppendOnlyTriggers
{
    public static function create(string $table, ?string $deleteAllowedFor = null): void
    {
        self::assertIdentifier($table);

        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE UPDATE ON `%s` FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is append-only: UPDATE is not allowed'",
            self::updateTrigger($table), $table, $table,
        ));

        if ($deleteAllowedFor === null) {
            DB::unprepared(sprintf(
                "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is append-only: DELETE is not allowed'",
                self::deleteTrigger($table), $table, $table,
            ));

            return;
        }

        self::assertIdentifier($deleteAllowedFor);

        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW
             BEGIN
                 IF SUBSTRING_INDEX(USER(), '@', 1) <> '%s' THEN
                     SIGNAL SQLSTATE '45000'
                         SET MESSAGE_TEXT = '%s is append-only: DELETE is allowed for the archiver account only';
                 END IF;
             END",
            self::deleteTrigger($table), $table, $deleteAllowedFor, $table,
        ));
    }

    public static function drop(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS `%s`', self::updateTrigger($table)));
        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS `%s`', self::deleteTrigger($table)));
    }

    /**
     * Blocks DELETE only (rows may still be updated). Used for projects,
     * which are archived instead of deleted because audit_logs has no FK.
     */
    public static function preventDelete(string $table, string $hint): void
    {
        self::assertIdentifier($table);

        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'",
            self::deleteTrigger($table), $table, addslashes($hint),
        ));
    }

    public static function dropPreventDelete(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS `%s`', self::deleteTrigger($table)));
    }

    public static function updateTrigger(string $table): string
    {
        return "{$table}_block_update";
    }

    public static function deleteTrigger(string $table): string
    {
        return "{$table}_block_delete";
    }

    private static function assertIdentifier(string $value): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $value)) {
            throw new InvalidArgumentException("Invalid SQL identifier [{$value}].");
        }
    }
}
