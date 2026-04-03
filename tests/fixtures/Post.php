<?php

namespace tuzelko\yii\softdelete\tests\fixtures;

use tuzelko\yii\softdelete\SoftDeleteTrait;
use yii\db\ActiveRecord;

/**
 * Test model using TYPE_TIMESTAMP_INT with column 'deleted_at' (trait defaults).
 *
 * @property int         $id
 * @property string      $title
 * @property int|null    $deleted_at
 */
class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return 'post';
    }

    public function getComments(): \tuzelko\yii\softdelete\SoftDeleteActiveQuery
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }
}