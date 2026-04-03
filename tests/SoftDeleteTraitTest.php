<?php

namespace tuzelko\yii\softdelete\tests;

use PHPUnit\Framework\TestCase;
use tuzelko\yii\softdelete\tests\fixtures\Article;
use tuzelko\yii\softdelete\tests\fixtures\Post;
use yii\base\ModelEvent;
use yii\db\Expression;

class SoftDeleteTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->db->createCommand('DELETE FROM post')->execute();
        \Yii::$app->db->createCommand('DELETE FROM article')->execute();
        \Yii::$app->db->createCommand("DELETE FROM sqlite_sequence WHERE name IN ('post','article')")->execute();
    }

    // -------------------------------------------------------------------------
    // softDeleteColumn() / softDeleteType()
    // -------------------------------------------------------------------------

    public function testPostUsesDefaultColumn(): void
    {
        $this->assertSame('deleted_at', Post::softDeleteColumn());
    }

    public function testPostUsesDefaultType(): void
    {
        $this->assertSame(Post::TYPE_TIMESTAMP_INT, Post::softDeleteType());
    }

    public function testArticleOverridesColumn(): void
    {
        $this->assertSame('is_deleted', Article::softDeleteColumn());
    }

    public function testArticleOverridesType(): void
    {
        $this->assertSame(Post::TYPE_BOOL, Article::softDeleteType());
    }

    // -------------------------------------------------------------------------
    // buildSoftDeleteValue()
    // -------------------------------------------------------------------------

    public function testBuildSoftDeleteValueTimestampIntReturnsInt(): void
    {
        $before = time();
        $value  = Post::buildSoftDeleteValue(Post::TYPE_TIMESTAMP_INT);
        $after  = time();

        $this->assertIsInt($value);
        $this->assertGreaterThanOrEqual($before, $value);
        $this->assertLessThanOrEqual($after, $value);
    }

    public function testBuildSoftDeleteValueTimestampDbReturnsExpression(): void
    {
        $value = Post::buildSoftDeleteValue(Post::TYPE_TIMESTAMP_DB);
        $this->assertInstanceOf(Expression::class, $value);
        // SQLite is used in tests; other drivers fall back to NOW() by default
        $this->assertSame("datetime('now')", (string) $value);
    }

    public function testBuildSoftDeleteValueBoolReturnsOne(): void
    {
        $this->assertSame(1, Post::buildSoftDeleteValue(Post::TYPE_BOOL));
    }

    // -------------------------------------------------------------------------
    // buildRestoreValue()
    // -------------------------------------------------------------------------

    public function testBuildRestoreValueBoolReturnsZero(): void
    {
        $this->assertSame(0, Post::buildRestoreValue(Post::TYPE_BOOL));
    }

    public function testBuildRestoreValueTimestampIntReturnsNull(): void
    {
        $this->assertNull(Post::buildRestoreValue(Post::TYPE_TIMESTAMP_INT));
    }

    public function testBuildRestoreValueTimestampDbReturnsNull(): void
    {
        $this->assertNull(Post::buildRestoreValue(Post::TYPE_TIMESTAMP_DB));
    }

    // -------------------------------------------------------------------------
    // buildNotDeletedCondition()
    // -------------------------------------------------------------------------

    public function testBuildNotDeletedConditionTimestampType(): void
    {
        $this->assertSame(['is', 'deleted_at', null], Post::buildNotDeletedCondition());
    }

    public function testBuildNotDeletedConditionBoolType(): void
    {
        $this->assertSame(['is_deleted' => 0], Article::buildNotDeletedCondition());
    }

    // -------------------------------------------------------------------------
    // buildDeletedCondition()
    // -------------------------------------------------------------------------

    public function testBuildDeletedConditionTimestampType(): void
    {
        $this->assertSame(['is not', 'deleted_at', null], Post::buildDeletedCondition());
    }

    public function testBuildDeletedConditionBoolType(): void
    {
        $this->assertSame(['is_deleted' => 1], Article::buildDeletedCondition());
    }

    // -------------------------------------------------------------------------
    // isSoftDeleted()
    // -------------------------------------------------------------------------

    public function testIsSoftDeletedReturnsFalseForActiveRecord(): void
    {
        $post = new Post(['title' => 'active']);
        $post->save(false);

        $this->assertFalse($post->isSoftDeleted());
    }

    public function testIsSoftDeletedReturnsTrueAfterDelete(): void
    {
        $post = new Post(['title' => 'gone']);
        $post->save(false);
        $post->delete();

        $this->assertTrue($post->isSoftDeleted());
    }

    public function testIsSoftDeletedReturnsFalseAfterRestore(): void
    {
        $post = new Post(['title' => 'back']);
        $post->save(false);
        $post->delete();
        $post->restore();

        $this->assertFalse($post->isSoftDeleted());
    }

    public function testIsSoftDeletedBoolTypeReturnsFalseForActiveRecord(): void
    {
        $article = new Article(['title' => 'active']);
        $article->save(false);

        $this->assertFalse($article->isSoftDeleted());
    }

    public function testIsSoftDeletedBoolTypeReturnsTrueAfterDelete(): void
    {
        $article = new Article(['title' => 'gone']);
        $article->save(false);
        $article->delete();

        $this->assertTrue($article->isSoftDeleted());
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteSoftDeletesRecord(): void
    {
        $post = new Post(['title' => 'hello']);
        $post->save(false);
        $id = $post->id;

        $result = $post->delete();

        $this->assertSame(1, $result);
        $this->assertNotNull($post->deleted_at, 'Attribute on instance should be updated');
        $this->assertNull(Post::findOne($id), 'Default scope should hide the record');
        $this->assertNotNull(Post::find()->withDeleted()->where(['id' => $id])->one());
    }

    public function testDeleteFiresBeforeAndAfterEvents(): void
    {
        $post = new Post(['title' => 'events']);
        $post->save(false);

        $beforeFired = false;
        $afterFired  = false;

        $post->on(Post::EVENT_BEFORE_SOFT_DELETE, function () use (&$beforeFired) {
            $beforeFired = true;
        });
        $post->on(Post::EVENT_AFTER_SOFT_DELETE, function () use (&$afterFired) {
            $afterFired = true;
        });

        $post->delete();

        $this->assertTrue($beforeFired);
        $this->assertTrue($afterFired);
    }

    public function testDeleteReturnsFalseWhenBeforeSoftDeleteCancelled(): void
    {
        $post = new Post(['title' => 'cancel']);
        $post->save(false);

        $post->on(Post::EVENT_BEFORE_SOFT_DELETE, function (ModelEvent $event) {
            $event->isValid = false;
        });

        $result = $post->delete();

        $this->assertFalse($result);
        $this->assertNull($post->deleted_at, 'Attribute must not be modified on cancelled delete');
        $this->assertNotNull(Post::findOne($post->id), 'Record must remain active');
    }

    // -------------------------------------------------------------------------
    // forceDelete()
    // -------------------------------------------------------------------------

    public function testForceDeletePermanentlyRemovesRecord(): void
    {
        $post = new Post(['title' => 'gone']);
        $post->save(false);
        $id = $post->id;

        $result = $post->forceDelete();

        $this->assertSame(1, $result);
        $this->assertNull(Post::find()->withDeleted()->where(['id' => $id])->one());
    }

    public function testForceDeleteReturnsFalseWhenBeforeDeleteCancelled(): void
    {
        $post = new Post(['title' => 'stay']);
        $post->save(false);
        $id = $post->id;

        $post->on(\yii\db\ActiveRecord::EVENT_BEFORE_DELETE, function (ModelEvent $event) {
            $event->isValid = false;
        });

        $result = $post->forceDelete();

        $this->assertFalse($result);
        $this->assertNotNull(Post::find()->withDeleted()->where(['id' => $id])->one());
    }

    // -------------------------------------------------------------------------
    // restore()
    // -------------------------------------------------------------------------

    public function testRestoreRestoresSoftDeletedRecord(): void
    {
        $post = new Post(['title' => 'back']);
        $post->save(false);
        $post->delete();

        $result = $post->restore();

        $this->assertSame(1, $result);
        $this->assertNull($post->deleted_at, 'Attribute on instance should be cleared');
        $this->assertNotNull(Post::findOne($post->id), 'Record should be visible with default scope again');
    }

    public function testRestoreFiresBeforeAndAfterEvents(): void
    {
        $post = new Post(['title' => 'restore-events']);
        $post->save(false);
        $post->delete();

        $beforeFired = false;
        $afterFired  = false;

        $post->on(Post::EVENT_BEFORE_RESTORE, function () use (&$beforeFired) {
            $beforeFired = true;
        });
        $post->on(Post::EVENT_AFTER_RESTORE, function () use (&$afterFired) {
            $afterFired = true;
        });

        $post->restore();

        $this->assertTrue($beforeFired);
        $this->assertTrue($afterFired);
    }

    public function testRestoreReturnsFalseWhenBeforeRestoreCancelled(): void
    {
        $post = new Post(['title' => 'no-restore']);
        $post->save(false);
        $post->delete();
        $deletedAt = $post->deleted_at;

        $post->on(Post::EVENT_BEFORE_RESTORE, function (ModelEvent $event) {
            $event->isValid = false;
        });

        $result = $post->restore();

        $this->assertFalse($result);
        $this->assertSame($deletedAt, $post->deleted_at, 'Attribute must not be changed on cancelled restore');
    }

    // -------------------------------------------------------------------------
    // Article instance methods (TYPE_BOOL)
    // -------------------------------------------------------------------------

    public function testArticleDeleteSetsIsDeletedToOne(): void
    {
        $article = new Article(['title' => 'to-delete']);
        $article->save(false);
        $id = $article->id;

        $result = $article->delete();

        $this->assertSame(1, $result);
        $this->assertSame(1, (int) $article->is_deleted, 'Attribute on instance should be updated to 1');
        $this->assertNull(Article::findOne($id), 'Default scope should hide the record');
        $this->assertNotNull(Article::find()->withDeleted()->where(['id' => $id])->one());
    }

    public function testArticleRestoreSetsIsDeletedToZero(): void
    {
        $article = new Article(['title' => 'to-restore']);
        $article->save(false);
        $article->delete();

        $result = $article->restore();

        $this->assertSame(1, $result);
        $this->assertSame(0, (int) $article->is_deleted, 'Attribute on instance should be reset to 0');
        $this->assertNotNull(Article::findOne($article->id), 'Record should be visible with default scope again');
    }

    public function testArticleForceDeletePermanentlyRemovesRecord(): void
    {
        $article = new Article(['title' => 'to-force-delete']);
        $article->save(false);
        $id = $article->id;

        $result = $article->forceDelete();

        $this->assertSame(1, $result);
        $this->assertNull(Article::find()->withDeleted()->where(['id' => $id])->one());
    }

    // -------------------------------------------------------------------------
    // conditionContainsSoftDeleteColumn()
    // -------------------------------------------------------------------------

    public function testConditionContainsSoftDeleteColumnReturnsFalseForString(): void
    {
        $this->assertFalse(Post::conditionContainsSoftDeleteColumn('deleted_at IS NULL', 'deleted_at'));
    }

    public function testConditionContainsSoftDeleteColumnReturnsFalseForNull(): void
    {
        $this->assertFalse(Post::conditionContainsSoftDeleteColumn(null, 'deleted_at'));
    }

    public function testConditionContainsSoftDeleteColumnReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse(Post::conditionContainsSoftDeleteColumn(['title' => 'foo'], 'deleted_at'));
    }

    public function testConditionContainsSoftDeleteColumnDetectsColumnAsKey(): void
    {
        $this->assertTrue(Post::conditionContainsSoftDeleteColumn(['deleted_at' => 100], 'deleted_at'));
    }

    public function testConditionContainsSoftDeleteColumnDetectsColumnAsValue(): void
    {
        // Operator format: ['is not', 'deleted_at', null] — column appears as array value
        $this->assertTrue(
            Post::conditionContainsSoftDeleteColumn(['is not', 'deleted_at', null], 'deleted_at')
        );
    }

    public function testConditionContainsSoftDeleteColumnDetectsColumnInNestedArray(): void
    {
        $condition = ['and', ['title' => 'foo'], ['is', 'deleted_at', null]];
        $this->assertTrue(Post::conditionContainsSoftDeleteColumn($condition, 'deleted_at'));
    }

    public function testConditionContainsSoftDeleteColumnDetectsTableQualifiedColumnAsKey(): void
    {
        // Hash format: ['post.deleted_at' => 100]
        $this->assertTrue(
            Post::conditionContainsSoftDeleteColumn(['post.deleted_at' => 100], 'deleted_at')
        );
    }

    public function testConditionContainsSoftDeleteColumnDetectsTableQualifiedColumnAsValue(): void
    {
        // Operator format: ['is not', 'post.deleted_at', null]
        $this->assertTrue(
            Post::conditionContainsSoftDeleteColumn(['is not', 'post.deleted_at', null], 'deleted_at')
        );
    }

    public function testConditionContainsSoftDeleteColumnDetectsYiiPrefixedTableQualifiedColumn(): void
    {
        // Yii2 table-prefix notation: {{%post}}.deleted_at
        $this->assertTrue(
            Post::conditionContainsSoftDeleteColumn(['{{%post}}.deleted_at' => 100], 'deleted_at')
        );
    }

    public function testConditionContainsSoftDeleteColumnDetectsAliasQualifiedColumn(): void
    {
        // Alias format: ['t.deleted_at' => 100]
        $this->assertTrue(
            Post::conditionContainsSoftDeleteColumn(['t.deleted_at' => 100], 'deleted_at')
        );
    }

    public function testConditionContainsSoftDeleteColumnDoesNotMatchDifferentColumnWithSameSuffix(): void
    {
        // 'not_deleted_at' must not be treated as a reference to 'deleted_at'
        $this->assertFalse(
            Post::conditionContainsSoftDeleteColumn(['not_deleted_at' => 100], 'deleted_at')
        );
    }

    // -------------------------------------------------------------------------
    // buildNotDeletedCondition() / buildDeletedCondition() with table prefix
    // -------------------------------------------------------------------------

    public function testBuildNotDeletedConditionWithTablePrefixTimestampType(): void
    {
        $this->assertSame(
            ['is', 'post.deleted_at', null],
            Post::buildNotDeletedCondition('post')
        );
    }

    public function testBuildNotDeletedConditionWithTablePrefixBoolType(): void
    {
        $this->assertSame(
            ['article.is_deleted' => 0],
            Article::buildNotDeletedCondition('article')
        );
    }

    public function testBuildDeletedConditionWithTablePrefixTimestampType(): void
    {
        $this->assertSame(
            ['is not', 'post.deleted_at', null],
            Post::buildDeletedCondition('post')
        );
    }

    public function testBuildDeletedConditionWithTablePrefixBoolType(): void
    {
        $this->assertSame(
            ['article.is_deleted' => 1],
            Article::buildDeletedCondition('article')
        );
    }

    // -------------------------------------------------------------------------
    // deleteAll()
    // -------------------------------------------------------------------------

    public function testDeleteAllSoftDeletesAllActiveRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'a'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'b'])->execute();

        $count = Post::deleteAll();

        $this->assertSame(2, $count);
        $this->assertSame(0, (int) Post::find()->count());
        $this->assertSame(2, (int) Post::find()->withDeleted()->count());
    }

    public function testDeleteAllIgnoresAlreadyDeletedRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 100])->execute();

        $count = Post::deleteAll();

        $this->assertSame(1, $count, 'Auto-scope must exclude already-deleted records');
    }

    public function testDeleteAllWithCondition(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'keep'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'remove'])->execute();

        Post::deleteAll(['title' => 'remove']);

        $this->assertSame(1, (int) Post::find()->count());
        $this->assertSame('keep', Post::find()->one()->title);
    }

    public function testDeleteAllSkipsAutoScopeWhenColumnAlreadyInCondition(): void
    {
        // An already-deleted record. Without the conditionContainsSoftDeleteColumn check
        // the auto-scope would add "AND deleted_at IS NULL", making the condition impossible.
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'old', 'deleted_at' => 100])->execute();

        $count = Post::deleteAll(['deleted_at' => 100]);

        $this->assertSame(1, $count, 'Column already in condition must suppress auto-scope');
    }

    // -------------------------------------------------------------------------
    // forceDeleteAll()
    // -------------------------------------------------------------------------

    public function testForceDeleteAllPermanentlyRemovesMatchingRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'a', 'deleted_at' => 1])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'b'])->execute();

        Post::forceDeleteAll(['deleted_at' => 1]);

        $this->assertSame(1, (int) Post::find()->withDeleted()->count());
        $this->assertSame('b', Post::find()->withDeleted()->one()->title);
    }

    public function testForceDeleteAllWithNoConditionRemovesAll(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'a'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'b', 'deleted_at' => 1])->execute();

        Post::forceDeleteAll();

        $this->assertSame(0, (int) Post::find()->withDeleted()->count());
    }

    // -------------------------------------------------------------------------
    // updateAll()
    // -------------------------------------------------------------------------

    public function testUpdateAllAppliesNotDeletedScope(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        $count = Post::updateAll(['title' => 'updated']);

        $this->assertSame(1, $count, 'Only active record should be updated');
        $this->assertSame('updated', Post::find()->one()->title);
    }

    public function testUpdateAllSkipsAutoScopeWhenColumnInCondition(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'old', 'deleted_at' => 100])->execute();
        $id = (int) \Yii::$app->db->getLastInsertID();

        // Condition explicitly targets a soft-deleted record — auto-scope must be suppressed
        $count = Post::updateAll(['title' => 'patched'], ['deleted_at' => 100]);

        $this->assertSame(1, $count);
        $post = Post::find()->withDeleted()->where(['id' => $id])->one();
        $this->assertSame('patched', $post->title);
    }

    // -------------------------------------------------------------------------
    // restoreAll()
    // -------------------------------------------------------------------------

    public function testRestoreAllClearsSoftDeleteColumn(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'restore-me', 'deleted_at' => 100])->execute();
        $id = (int) \Yii::$app->db->getLastInsertID();

        $count = Post::restoreAll(['id' => $id]);

        $this->assertSame(1, $count);
        $post = Post::findOne($id);
        $this->assertNotNull($post, 'Restored record must be visible with default scope');
        $this->assertNull($post->deleted_at);
    }

    public function testRestoreAllBoolTypeSetsFlagToZero(): void
    {
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'gone', 'is_deleted' => 1])->execute();
        $id = (int) \Yii::$app->db->getLastInsertID();

        Article::restoreAll(['id' => $id]);

        $article = Article::findOne($id);
        $this->assertNotNull($article);
        $this->assertSame(0, (int) $article->is_deleted);
    }

    public function testRestoreAllWithNoConditionRestoresAllDeleted(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'a', 'deleted_at' => 100])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'b', 'deleted_at' => 200])->execute();

        $count = Post::restoreAll();

        $this->assertSame(2, $count);
        $this->assertSame(2, (int) Post::find()->count());
    }
}