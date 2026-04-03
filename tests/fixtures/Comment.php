<?php

namespace tuzelko\yii\softdelete\tests\fixtures;

use tuzelko\yii\softdelete\SoftDeleteTrait;
use yii\db\ActiveRecord;

/**
 * Test model using TYPE_TIMESTAMP_INT with column 'deleted_at' (trait defaults).
 * Belongs to Post via post_id.
 *
 * @property int      $id
 * @property int      $post_id
 * @property string   $body
 * @property int|null $deleted_at
 */
class Comment extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return 'comment';
    }

    public function getPost(): \tuzelko\yii\softdelete\SoftDeleteActiveQuery
    {
        return $this->hasOne(Post::class, ['id' => 'post_id']);
    }
}