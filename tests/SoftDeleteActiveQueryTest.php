<?php

namespace tuzelko\yii\softdelete\tests;

use PHPUnit\Framework\TestCase;
use tuzelko\yii\softdelete\SoftDeleteActiveQuery;
use tuzelko\yii\softdelete\tests\fixtures\Article;
use tuzelko\yii\softdelete\tests\fixtures\Post;

class SoftDeleteActiveQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->db->createCommand('DELETE FROM post')->execute();
        \Yii::$app->db->createCommand('DELETE FROM article')->execute();
        \Yii::$app->db->createCommand("DELETE FROM sqlite_sequence WHERE name IN ('post','article')")->execute();
    }

    // -------------------------------------------------------------------------
    // find() returns SoftDeleteActiveQuery
    // -------------------------------------------------------------------------

    public function testFindReturnsSoftDeleteActiveQuery(): void
    {
        $this->assertInstanceOf(SoftDeleteActiveQuery::class, Post::find());
        $this->assertInstanceOf(SoftDeleteActiveQuery::class, Article::find());
    }

    // -------------------------------------------------------------------------
    // Default scope (SCOPE_NOT_DELETED) — TYPE_TIMESTAMP_INT
    // -------------------------------------------------------------------------

    public function testDefaultScopeExcludesSoftDeletedRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        $posts = Post::find()->all();

        $this->assertCount(1, $posts);
        $this->assertSame('active', $posts[0]->title);
    }

    public function testDefaultScopeGeneratesSqlWithIsNullCondition(): void
    {
        $sql = Post::find()->createCommand()->rawSql;

        $this->assertStringContainsStringIgnoringCase('deleted_at', $sql);
        $this->assertStringContainsStringIgnoringCase('null', $sql);
    }

    // -------------------------------------------------------------------------
    // withDeleted()
    // -------------------------------------------------------------------------

    public function testWithDeletedReturnsAllRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        $posts = Post::find()->withDeleted()->all();

        $this->assertCount(2, $posts);
    }

    public function testWithDeletedDoesNotAddWhereClauseForSoftDeleteColumn(): void
    {
        $sql = Post::find()->withDeleted()->createCommand()->rawSql;

        // The soft-delete column must not appear in the generated WHERE clause
        $this->assertStringNotContainsStringIgnoringCase('deleted_at', $sql);
    }

    // -------------------------------------------------------------------------
    // onlyDeleted()
    // -------------------------------------------------------------------------

    public function testOnlyDeletedReturnsOnlySoftDeletedRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        $posts = Post::find()->onlyDeleted()->all();

        $this->assertCount(1, $posts);
        $this->assertSame('deleted', $posts[0]->title);
    }

    public function testOnlyDeletedGeneratesSqlWithIsNotNullCondition(): void
    {
        $sql = Post::find()->onlyDeleted()->createCommand()->rawSql;

        $this->assertStringContainsStringIgnoringCase('deleted_at', $sql);
        $this->assertStringContainsStringIgnoringCase('not', $sql);
    }

    // -------------------------------------------------------------------------
    // TYPE_BOOL (Article model)
    // -------------------------------------------------------------------------

    public function testDefaultScopeBoolTypeExcludesDeletedRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'active', 'is_deleted' => 0])->execute();
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'gone',   'is_deleted' => 1])->execute();

        $articles = Article::find()->all();

        $this->assertCount(1, $articles);
        $this->assertSame('active', $articles[0]->title);
    }

    public function testOnlyDeletedBoolTypeReturnsDeletedRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'active', 'is_deleted' => 0])->execute();
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'gone',   'is_deleted' => 1])->execute();

        $articles = Article::find()->onlyDeleted()->all();

        $this->assertCount(1, $articles);
        $this->assertSame('gone', $articles[0]->title);
    }

    public function testWithDeletedBoolTypeReturnsAllRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'active', 'is_deleted' => 0])->execute();
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'gone',   'is_deleted' => 1])->execute();

        $this->assertCount(2, Article::find()->withDeleted()->all());
    }

    // -------------------------------------------------------------------------
    // Idempotency of prepare() (DataProvider double-call scenario)
    // -------------------------------------------------------------------------

    public function testPrepareIsIdempotentAcrossMultipleCalls(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'a'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'b', 'deleted_at' => 1])->execute();

        $query = Post::find();

        // First call (e.g. count query in DataProvider)
        $sql1 = $query->createCommand()->rawSql;

        // Second call on the same query object (e.g. rows query in DataProvider)
        $sql2 = $query->createCommand()->rawSql;

        $this->assertSame($sql1, $sql2, 'SQL must not change between repeated createCommand() calls');

        // The condition must appear exactly once — not duplicated
        $occurrences = substr_count(strtolower($sql1), 'deleted_at');
        $this->assertSame(1, $occurrences, 'Soft-delete condition must not be applied twice');
    }

    // -------------------------------------------------------------------------
    // Auto-scope suppression when where() already contains soft-delete column
    // -------------------------------------------------------------------------

    public function testScopeIsSkippedWhenWhereContainsSoftDeleteColumn(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        // Operator format — column appears as a value in the array, not a key
        $posts = Post::find()->where(['is not', 'deleted_at', null])->all();

        $this->assertCount(1, $posts);
        $this->assertSame('deleted', $posts[0]->title);
    }

    public function testScopeColumnOccursOnceWhenWhereAlreadyContainsIt(): void
    {
        $sql = Post::find()->where(['is not', 'deleted_at', null])->createCommand()->rawSql;

        $occurrences = substr_count(strtolower($sql), 'deleted_at');
        $this->assertSame(1, $occurrences, 'Auto-scope must not be appended when column is already in where');
    }

    public function testScopeIsSkippedForBoolTypeWhenWhereContainsSoftDeleteColumn(): void
    {
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'active', 'is_deleted' => 0])->execute();
        \Yii::$app->db->createCommand()->insert('article', ['title' => 'gone',   'is_deleted' => 1])->execute();

        $articles = Article::find()->where(['is_deleted' => 1])->all();

        $this->assertCount(1, $articles);
        $this->assertSame('gone', $articles[0]->title);
    }

    // -------------------------------------------------------------------------
    // Qualified column name in generated SQL
    // -------------------------------------------------------------------------

    public function testDefaultScopeUsesTableQualifiedColumnName(): void
    {
        $sql = Post::find()->createCommand()->rawSql;

        // The condition must reference the column as "post"."deleted_at" (or post.deleted_at),
        // not the bare unqualified name, so JOINs with other soft-delete tables are unambiguous.
        $this->assertMatchesRegularExpression('/`post`\s*\.\s*`deleted_at`/i', $sql);
    }

    public function testOnlyDeletedUsesTableQualifiedColumnName(): void
    {
        $sql = Post::find()->onlyDeleted()->createCommand()->rawSql;

        $this->assertMatchesRegularExpression('/`post`\s*\.\s*`deleted_at`/i', $sql);
    }

    public function testDefaultScopeBoolTypeUsesTableQualifiedColumnName(): void
    {
        $sql = Article::find()->createCommand()->rawSql;

        $this->assertMatchesRegularExpression('/`article`\s*\.\s*`is_deleted`/i', $sql);
    }

    // -------------------------------------------------------------------------
    // Auto-scope suppression with table-qualified column names in where()
    // -------------------------------------------------------------------------

    public function testScopeIsSkippedWhenWhereContainsTableQualifiedColumn(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        // Table-qualified column in operator format — auto-scope must be suppressed
        $posts = Post::find()->where(['is not', 'post.deleted_at', null])->all();

        $this->assertCount(1, $posts);
        $this->assertSame('deleted', $posts[0]->title);
    }

    public function testScopeColumnOccursOnceWhenWhereContainsTableQualifiedColumn(): void
    {
        $sql = Post::find()->where(['is not', 'post.deleted_at', null])->createCommand()->rawSql;

        $occurrences = substr_count(strtolower($sql), 'deleted_at');
        $this->assertSame(1, $occurrences, 'Auto-scope must not be appended when table-qualified column is already in where');
    }

    // -------------------------------------------------------------------------
    // Scope method chaining
    // -------------------------------------------------------------------------

    public function testOnlyDeletedThenWithDeletedReturnsAllRecords(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        // Last call wins — withDeleted() overrides onlyDeleted()
        $posts = Post::find()->onlyDeleted()->withDeleted()->all();

        $this->assertCount(2, $posts);
    }

    public function testWithDeletedThenOnlyDeletedReturnsOnlyDeleted(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'active'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'deleted', 'deleted_at' => 1])->execute();

        // Last call wins — onlyDeleted() overrides withDeleted()
        $posts = Post::find()->withDeleted()->onlyDeleted()->all();

        $this->assertCount(1, $posts);
        $this->assertSame('deleted', $posts[0]->title);
    }

    // -------------------------------------------------------------------------
    // User andOnCondition() + scope methods
    // -------------------------------------------------------------------------

    public function testUserAndOnConditionIsPreservedAfterWithDeleted(): void
    {
        // User adds an extra ON condition and then disables soft-delete scope.
        // Our condition must be removed; the user's condition must survive.
        $query = Post::find()->andOnCondition(['post.title' => 'test'])->withDeleted();

        // $this->on must contain only the user's condition, not the soft-delete scope
        $this->assertSame(['post.title' => 'test'], $query->on);
    }

    public function testUserAndOnConditionIsPreservedAfterOnlyDeleted(): void
    {
        // User adds an extra ON condition, then restricts to only-deleted scope.
        // The user's condition and the deleted condition must both be present.
        $query = Post::find()->andOnCondition(['post.title' => 'test'])->onlyDeleted();

        $this->assertIsArray($query->on);
        // Both conditions must be in on: user's + is-deleted scope
        $flat = json_encode($query->on);
        $this->assertStringContainsString('post.title', $flat);
        $this->assertStringContainsString('deleted_at', $flat);
    }

    // -------------------------------------------------------------------------
    // Scope can be combined with additional where() conditions
    // -------------------------------------------------------------------------

    public function testScopeComposesWithAdditionalWhereConditions(): void
    {
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'keep'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'skip'])->execute();
        \Yii::$app->db->createCommand()->insert('post', ['title' => 'keep', 'deleted_at' => 1])->execute();

        // Should return only the active 'keep' record
        $posts = Post::find()->where(['title' => 'keep'])->all();

        $this->assertCount(1, $posts);
        $this->assertSame('keep', $posts[0]->title);
    }
}