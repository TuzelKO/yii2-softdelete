<?php

namespace tuzelko\yii\softdelete;

use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\Exception;
use yii\db\Expression;

/**
 * Adds soft-delete and restore behaviour to a {@see \yii\db\ActiveRecord} model.
 *
 * **Requirements.** This trait relies on methods provided by `ActiveRecord` and
 * `yii\base\Component`. It MUST only be used inside a class that extends
 * `\yii\db\ActiveRecord`. The abstract declarations below make this contract
 * explicit at the PHP level, so that IDEs and static analysers catch misuse at
 * development time rather than at runtime.
 *
 * **String conditions.** `deleteAll()`, `updateAll()`, and `restoreAll()` inspect
 * the `$condition` argument to detect whether the soft-delete column is already
 * referenced, in order to avoid applying the automatic scope twice. This detection
 * works for array conditions (both hash-format and operator-format, including nested
 * arrays). Plain string conditions (e.g. `"deleted_at IS NULL"`) are intentionally
 * NOT inspected — parsing raw SQL is too risky for false positives. If you pass a
 * string condition that already targets the soft-delete column, wrap it in an array:
 * `['and', ['is not', 'deleted_at', null], $yourCondition]`.
 *
 * @see \yii\db\ActiveRecord
 */
trait SoftDeleteTrait
{
    // -------------------------------------------------------------------------
    // Type and event constants
    // -------------------------------------------------------------------------

    public const TYPE_TIMESTAMP_INT = 0;
    public const TYPE_TIMESTAMP_DB  = 1;
    public const TYPE_BOOL          = 2;

    public const EVENT_BEFORE_SOFT_DELETE = 'beforeSoftDelete';
    public const EVENT_AFTER_SOFT_DELETE  = 'afterSoftDelete';
    public const EVENT_BEFORE_RESTORE     = 'beforeRestore';
    public const EVENT_AFTER_RESTORE      = 'afterRestore';

    // -------------------------------------------------------------------------
    // Abstract contract — provided by yii\db\ActiveRecord and yii\base\Component
    // -------------------------------------------------------------------------

    abstract public static function tableName();
    abstract public static function getDb();

    abstract public function getPrimaryKey($asArray = false);
    abstract public function setOldAttributes($values);
    abstract public function beforeDelete();
    abstract public function afterDelete();
    abstract public function trigger($name, ?Event $event = null);

    // -------------------------------------------------------------------------
    // Soft-delete configuration — override in the model when needed,
    // same pattern as tableName() / primaryKey() in ActiveRecord.
    // -------------------------------------------------------------------------

    public static function softDeleteColumn(): string
    {
        return 'deleted_at';
    }

    public static function softDeleteType(): int
    {
        return self::TYPE_TIMESTAMP_INT;
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    public static function find(): SoftDeleteActiveQuery
    {
        return new SoftDeleteActiveQuery(static::class);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Soft-deletes the record.
     * Fires EVENT_BEFORE_SOFT_DELETE / EVENT_AFTER_SOFT_DELETE.
     * @throws Exception
     */
    public function delete(): int|false
    {
        if (!$this->beforeSoftDelete()) {
            return false;
        }

        $column = static::softDeleteColumn();
        $value  = static::buildSoftDeleteValue(static::softDeleteType());

        $result = static::getDb()
            ->createCommand()
            ->update(static::tableName(), [$column => $value], $this->getPrimaryKey(true))
            ->execute();

        if ($result) {
            $this->{$column} = $value;
        }

        $this->afterSoftDelete();

        return $result;
    }

    /**
     * Permanently deletes the record.
     * Fires the standard Yii2 EVENT_BEFORE_DELETE / EVENT_AFTER_DELETE.
     * @throws Exception
     */
    public function forceDelete(): int|false
    {
        if (!$this->beforeDelete()) {
            return false;
        }

        $result = static::getDb()
            ->createCommand()
            ->delete(static::tableName(), $this->getPrimaryKey(true))
            ->execute();

        $this->setOldAttributes(null);
        $this->afterDelete();

        return $result;
    }

    /**
     * Restores a soft-deleted record.
     * Fires EVENT_BEFORE_RESTORE / EVENT_AFTER_RESTORE.
     * @throws Exception
     */
    public function restore(): int|false
    {
        if (!$this->beforeRestore()) {
            return false;
        }

        $column = static::softDeleteColumn();
        $value  = static::buildRestoreValue(static::softDeleteType());

        $result = static::getDb()
            ->createCommand()
            ->update(static::tableName(), [$column => $value], $this->getPrimaryKey(true))
            ->execute();

        if ($result) {
            $this->{$column} = $value;
        }

        $this->afterRestore();

        return $result;
    }

    public function isSoftDeleted(): bool
    {
        $column = static::softDeleteColumn();
        return static::softDeleteType() === self::TYPE_BOOL
            ? (bool) $this->{$column}
            : $this->{$column} !== null;
    }

    public function beforeSoftDelete(): bool
    {
        $event = new ModelEvent();
        $this->trigger(self::EVENT_BEFORE_SOFT_DELETE, $event);
        return $event->isValid;
    }

    public function afterSoftDelete(): void
    {
        $this->trigger(self::EVENT_AFTER_SOFT_DELETE);
    }

    public function beforeRestore(): bool
    {
        $event = new ModelEvent();
        $this->trigger(self::EVENT_BEFORE_RESTORE, $event);
        return $event->isValid;
    }

    public function afterRestore(): void
    {
        $this->trigger(self::EVENT_AFTER_RESTORE);
    }

    // -------------------------------------------------------------------------
    // Static methods
    // -------------------------------------------------------------------------

    /**
     * Soft-deletes all matching records.
     *
     * The "not deleted" condition is added automatically unless $condition already
     * references the soft-delete column (checked recursively for array conditions).
     * @throws Exception
     */
    public static function deleteAll($condition = '', $params = []): int
    {
        $column = static::softDeleteColumn();

        if (!static::conditionContainsSoftDeleteColumn($condition, $column)) {
            $notDeleted = static::buildNotDeletedCondition();
            $condition  = !empty($condition) ? ['and', $notDeleted, $condition] : $notDeleted;
        }

        return static::getDb()
            ->createCommand()
            ->update(
                static::tableName(),
                [$column => static::buildSoftDeleteValue(static::softDeleteType())],
                $condition,
                $params,
            )
            ->execute();
    }

    /**
     * Permanently deletes all matching records. No automatic scope is applied.
     * @throws Exception
     */
    public static function forceDeleteAll($condition = '', $params = []): int
    {
        return static::getDb()
            ->createCommand()
            ->delete(static::tableName(), $condition ?? '', $params)
            ->execute();
    }

    /**
     * Updates all matching records.
     *
     * The "not deleted" condition is added automatically unless $condition already
     * references the soft-delete column (checked recursively for array conditions).
     * @throws Exception
     */
    public static function updateAll($attributes, $condition = '', $params = []): int
    {
        $column = static::softDeleteColumn();

        if (!static::conditionContainsSoftDeleteColumn($condition, $column)) {
            $notDeleted = static::buildNotDeletedCondition();
            $condition  = !empty($condition) ? ['and', $notDeleted, $condition] : $notDeleted;
        }

        return static::getDb()
            ->createCommand()
            ->update(static::tableName(), $attributes, $condition, $params)
            ->execute();
    }

    /**
     * Restores all matching records. No automatic scope is applied.
     * @throws Exception
     */
    public static function restoreAll($condition = '', $params = []): int
    {
        $column = static::softDeleteColumn();

        if (!static::conditionContainsSoftDeleteColumn($condition, $column)) {
            $deleted = static::buildDeletedCondition();
            $condition  = !empty($condition) ? ['and', $deleted, $condition] : $deleted;
        }

        return static::getDb()
            ->createCommand()
            ->update(
                static::tableName(),
                [$column => static::buildRestoreValue(static::softDeleteType())],
                $condition,
                $params,
            )
            ->execute();
    }

    // -------------------------------------------------------------------------
    // Helpers (used internally and by SoftDeleteActiveQuery)
    // -------------------------------------------------------------------------

    public static function buildSoftDeleteValue(int $type): Expression|int
    {
        return match ($type) {
            self::TYPE_TIMESTAMP_INT => time(),
            self::TYPE_TIMESTAMP_DB  => new Expression(match (static::getDb()->driverName) {
                'sqlite'                   => "datetime('now')",
                'sqlsrv', 'mssql', 'dblib' => "GETDATE()",
                'oci'                      => "SYSDATE",
                default                    => "NOW()",
            }),
            self::TYPE_BOOL          => 1,
        };
    }

    public static function buildRestoreValue(int $type): ?int
    {
        return match ($type) {
            self::TYPE_BOOL => 0,
            default         => null,
        };
    }

    /**
     * Builds the "not deleted" condition.
     *
     * @param string $tablePrefix Optional table name or alias (e.g. 'post', '{{%post}}').
     *                            When provided the column is qualified: "post"."deleted_at".
     *                            Omit (or pass '') for an unqualified column name.
     */
    public static function buildNotDeletedCondition(string $tablePrefix = ''): array
    {
        $col    = static::softDeleteColumn();
        $column = $tablePrefix !== '' ? "$tablePrefix.$col" : $col;

        return static::softDeleteType() === self::TYPE_BOOL
            ? [$column => 0]
            : ['is', $column, null];
    }

    /**
     * Builds the "is deleted" condition.
     *
     * @param string $tablePrefix Optional table name or alias (e.g. 'post', '{{%post}}').
     *                            When provided the column is qualified: "post"."deleted_at".
     *                            Omit (or pass '') for an unqualified column name.
     */
    public static function buildDeletedCondition(string $tablePrefix = ''): array
    {
        $col    = static::softDeleteColumn();
        $column = $tablePrefix !== '' ? "$tablePrefix.$col" : $col;

        return static::softDeleteType() === self::TYPE_BOOL
            ? [$column => 1]
            : ['is not', $column, null];
    }

    /**
     * Recursively checks whether the given array $condition references $column
     * either as an array key (hash format) or as an array value (operator format).
     * Both plain column names ('deleted_at') and table-qualified names
     * ('post.deleted_at', '{{%post}}.deleted_at', 't.deleted_at') are detected.
     * String conditions are never inspected — too risky for false positives.
     */
    public static function conditionContainsSoftDeleteColumn(mixed $condition, string $column): bool
    {
        if (!is_array($condition)) {
            return false;
        }

        foreach ($condition as $key => $value) {
            if (static::isSoftDeleteColumnRef($key, $column) ||
                (is_string($value) && static::isSoftDeleteColumnRef($value, $column))) {
                return true;
            }

            if (is_array($value) && static::conditionContainsSoftDeleteColumn($value, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when $candidate refers to $column, either as a plain name
     * ('deleted_at') or as a table-qualified name ('post.deleted_at',
     * '{{%post}}.deleted_at', 't.deleted_at').
     */
    private static function isSoftDeleteColumnRef(mixed $candidate, string $column): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        if ($candidate === $column) {
            return true;
        }

        $dotPos = strrpos($candidate, '.');
        return $dotPos !== false && substr($candidate, $dotPos + 1) === $column;
    }
}