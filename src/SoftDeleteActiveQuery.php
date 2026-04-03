<?php

namespace tuzelko\yii\softdelete;

use yii\db\ActiveQuery;
use yii\db\Query;

class SoftDeleteActiveQuery extends ActiveQuery
{
    private const SCOPE_NOT_DELETED  = 0;
    private const SCOPE_WITH_DELETED = 1;
    private const SCOPE_ONLY_DELETED = 2;

    private int $softDeleteScope = self::SCOPE_NOT_DELETED;

    /**
     * The condition we placed on $this->on.
     * Tracked so withDeleted() / onlyDeleted() can remove or replace it.
     */
    private ?array $ownOnCondition = null;

    public function __construct($modelClass, $config = [])
    {
        parent::__construct($modelClass, $config);
        $this->initOnConditionScope();
    }

    /**
     * Eagerly places the "not deleted" condition on $this->on.
     *
     * Yii2's ActiveQuery::prepare() always moves $this->on to the query's
     * WHERE (via $query->andWhere($this->on)), so standalone queries and
     * with() are handled automatically.
     *
     * joinWith() reads $relation->on before prepare() is ever called and
     * places it in the JOIN's ON clause — which preserves correct LEFT JOIN
     * semantics: a parent record with no matching active related records still
     * appears (with NULL relation columns) instead of being silently dropped.
     */
    private function initOnConditionScope(): void
    {
        /** @var \yii\db\ActiveRecord|SoftDeleteTrait $modelClass */
        $modelClass = $this->modelClass;
        $condition  = $modelClass::buildNotDeletedCondition($modelClass::tableName());

        $this->ownOnCondition = $condition;
        $this->andOnCondition($condition);
    }

    /**
     * Disables the automatic soft-delete filter — returns all records.
     */
    public function withDeleted(): static
    {
        $this->softDeleteScope = self::SCOPE_WITH_DELETED;
        $this->removeOwnOnCondition();
        return $this;
    }

    /**
     * Restricts the query to soft-deleted records only.
     */
    public function onlyDeleted(): static
    {
        $this->softDeleteScope = self::SCOPE_ONLY_DELETED;
        $this->removeOwnOnCondition();

        /** @var \yii\db\ActiveRecord|SoftDeleteTrait $modelClass */
        $modelClass = $this->modelClass;
        $condition  = $modelClass::buildDeletedCondition($modelClass::tableName());

        $this->ownOnCondition = $condition;
        $this->andOnCondition($condition);
        return $this;
    }

    public function prepare($builder): Query
    {
        // If the caller has explicitly referenced the soft-delete column in
        // $this->where (e.g. to query deleted records), remove our on-condition
        // so that Yii2 does not add a conflicting IS NULL / IS NOT NULL to WHERE.
        if ($this->softDeleteScope !== self::SCOPE_WITH_DELETED && $this->ownOnCondition !== null) {
            $modelClass = $this->modelClass;
            $column     = $modelClass::softDeleteColumn();

            if ($modelClass::conditionContainsSoftDeleteColumn($this->where, $column)) {
                $this->removeOwnOnCondition();
            }
        }

        return parent::prepare($builder);
    }

    /**
     * Removes the condition we added to $this->on, leaving any user-set
     * conditions intact.
     */
    private function removeOwnOnCondition(): void
    {
        if ($this->ownOnCondition === null) {
            return;
        }

        if ($this->on === $this->ownOnCondition) {
            $this->on = null;
        } elseif (is_array($this->on) && isset($this->on[0]) && $this->on[0] === 'and') {
            $items = array_values(array_filter(
                array_slice($this->on, 1),
                fn($c) => $c !== $this->ownOnCondition
            ));
            $this->on = match (count($items)) {
                0       => null,
                1       => $items[0],
                default => array_merge(['and'], $items),
            };
        }

        $this->ownOnCondition = null;
    }
}