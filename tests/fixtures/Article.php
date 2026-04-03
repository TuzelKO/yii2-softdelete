<?php

namespace tuzelko\yii\softdelete\tests\fixtures;

use tuzelko\yii\softdelete\SoftDeleteTrait;
use yii\db\ActiveRecord;

/**
 * Test model using TYPE_BOOL with column 'is_deleted'.
 *
 * @property int    $id
 * @property string $title
 * @property int    $is_deleted
 */
class Article extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return 'article';
    }

    public static function softDeleteColumn(): string
    {
        return 'is_deleted';
    }

    public static function softDeleteType(): int
    {
        return self::TYPE_BOOL;
    }
}