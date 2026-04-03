<?php

namespace tuzelko\yii\softdelete\tests;

use PHPUnit\Framework\TestCase;
use tuzelko\yii\softdelete\tests\fixtures\Comment;
use tuzelko\yii\softdelete\tests\fixtures\Post;

/**
 * Tests for soft-delete behaviour when loading relations via with() and joinWith().
 *
 * Scenario: Post hasMany Comment. Both models use SoftDeleteTrait.
 *
 * How the scope is applied:
 *
 *   with()       — executes a separate SELECT for related records.
 *                  That SELECT goes through SoftDeleteActiveQuery::prepare(), so
 *                  the soft-delete scope is applied via WHERE automatically.
 *
 *   joinWith()   — SoftDeleteActiveQuery sets the scope on $this->on in the
 *                  constructor. Yii2 reads $relation->on when building the JOIN
 *                  and places it in the ON clause — before prepare() is called.
 *                  This means the scope ends up in the JOIN's ON clause (not
 *                  WHERE), which preserves correct LEFT JOIN semantics: a post
 *                  with no matching active comments still appears with NULL
 *                  columns rather than disappearing.
 *                  The scope applies to BOTH the main model and the related model.
 *                  Use withDeleted() / onlyDeleted() in a callback to override it.
 */
class SoftDeleteRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->db->createCommand('DELETE FROM comment')->execute();
        \Yii::$app->db->createCommand('DELETE FROM post')->execute();
        \Yii::$app->db->createCommand('DELETE FROM article')->execute();
        \Yii::$app->db->createCommand(
            "DELETE FROM sqlite_sequence WHERE name IN ('post','article','comment')"
        )->execute();
    }

    // =========================================================================
    // with() — eager loading via a separate SELECT
    // =========================================================================

    /**
     * Default scope on the relation hides soft-deleted comments.
     */
    public function testWithDefaultScopeHidesDeletedComments(): void
    {
        $post = $this->createPost('post-1');
        $this->createComment($post->id, 'active comment');
        $this->createComment($post->id, 'deleted comment', deleted: true);

        $loaded = Post::find()->with('comments')->one();

        $this->assertCount(1, $loaded->comments);
        $this->assertSame('active comment', $loaded->comments[0]->body);
    }

    /**
     * withDeleted() on the relation callback includes all comments.
     */
    public function testWithWithDeletedIncludesAllComments(): void
    {
        $post = $this->createPost('post-1');
        $this->createComment($post->id, 'active comment');
        $this->createComment($post->id, 'deleted comment', deleted: true);

        $loaded = Post::find()
            ->with(['comments' => fn($q) => $q->withDeleted()])
            ->one();

        $this->assertCount(2, $loaded->comments);
    }

    /**
     * onlyDeleted() on the relation callback returns only soft-deleted comments.
     */
    public function testWithOnlyDeletedReturnsOnlyDeletedComments(): void
    {
        $post = $this->createPost('post-1');
        $this->createComment($post->id, 'active comment');
        $this->createComment($post->id, 'deleted comment', deleted: true);

        $loaded = Post::find()
            ->with(['comments' => fn($q) => $q->onlyDeleted()])
            ->one();

        $this->assertCount(1, $loaded->comments);
        $this->assertSame('deleted comment', $loaded->comments[0]->body);
    }

    /**
     * A post with no active comments gets an empty array (not null).
     */
    public function testWithReturnsEmptyArrayWhenAllCommentsDeleted(): void
    {
        $post = $this->createPost('post-1');
        $this->createComment($post->id, 'gone', deleted: true);

        $loaded = Post::find()->with('comments')->one();

        $this->assertIsArray($loaded->comments);
        $this->assertCount(0, $loaded->comments);
    }

    /**
     * Default scope is applied per-post when multiple posts are loaded.
     */
    public function testWithScopeAppliedPerPost(): void
    {
        $post1 = $this->createPost('post-1');
        $post2 = $this->createPost('post-2');
        $this->createComment($post1->id, 'a1-active');
        $this->createComment($post1->id, 'a1-deleted', deleted: true);
        $this->createComment($post2->id, 'a2-active');

        $posts = Post::find()->with('comments')->orderBy(['id' => SORT_ASC])->all();

        $this->assertCount(1, $posts[0]->comments, 'post-1 should have 1 active comment');
        $this->assertCount(1, $posts[1]->comments, 'post-2 should have 1 active comment');
    }

    // =========================================================================
    // joinWith() — JOIN-based loading
    // =========================================================================

    /**
     * The main model's (Post) soft-delete scope IS always applied.
     * Deleted posts never appear regardless of join type.
     */
    public function testJoinWithDoesNotExposeDeletedPosts(): void
    {
        $active  = $this->createPost('active');
        $deleted = $this->createPost('deleted');
        $deleted->delete();

        $this->createComment($active->id, 'c1');
        $this->createComment($deleted->id, 'c2');

        $posts = Post::find()->joinWith('comments', false, 'LEFT JOIN')->all();

        $this->assertCount(1, $posts);
        $this->assertSame('active', $posts[0]->title);
    }

    /**
     * The soft-delete scope for both models is present in the SQL.
     * post.deleted_at lands in WHERE (from prepare()); comment.deleted_at lands
     * in the JOIN's ON clause (set eagerly in the constructor via $this->on).
     * Both are table-qualified — no "ambiguous column" errors.
     */
    public function testJoinWithGeneratesQualifiedColumnsForBothModels(): void
    {
        $sql = Post::find()->joinWith('comments')->createCommand()->rawSql;

        $this->assertMatchesRegularExpression('/`post`\s*\.\s*`deleted_at`/i', $sql);
        $this->assertMatchesRegularExpression('/`comment`\s*\.\s*`deleted_at`/i', $sql);
    }

    /**
     * LEFT JOIN (default): all posts appear, including those with no active comments.
     * The soft-delete condition is in the ON clause, so posts with only deleted
     * comments produce a row with NULL comment columns rather than disappearing.
     */
    public function testJoinWithLeftJoinPreservesSemantics(): void
    {
        $post1 = $this->createPost('has-active');
        $post2 = $this->createPost('no-active');
        $this->createComment($post1->id, 'active');
        $this->createComment($post2->id, 'deleted', deleted: true);

        $posts = Post::find()
            ->joinWith('comments', false, 'LEFT JOIN')
            ->orderBy(['post.id' => SORT_ASC])
            ->all();

        $this->assertCount(2, $posts);
    }

    /**
     * INNER JOIN: only posts with at least one active comment are returned.
     */
    public function testJoinWithInnerJoinFiltersPostsWithNoActiveComments(): void
    {
        $post1 = $this->createPost('has-active');
        $post2 = $this->createPost('no-active');
        $this->createComment($post1->id, 'active');
        $this->createComment($post2->id, 'deleted', deleted: true);

        $posts = Post::find()
            ->joinWith('comments', false, 'INNER JOIN')
            ->all();

        $this->assertCount(1, $posts);
        $this->assertSame('has-active', $posts[0]->title);
    }

    /**
     * withDeleted() on the relation callback removes the ON condition,
     * so all comments (active and deleted) are joined.
     */
    public function testJoinWithWithDeletedOnRelationJoinsAllComments(): void
    {
        $post1 = $this->createPost('has-active');
        $post2 = $this->createPost('has-deleted-only');
        $this->createComment($post1->id, 'active');
        $this->createComment($post2->id, 'deleted', deleted: true);

        $posts = Post::find()
            ->joinWith(['comments' => fn($q) => $q->withDeleted()], false, 'INNER JOIN')
            ->all();

        // Both posts have at least one comment (active or deleted) — both appear
        $this->assertCount(2, $posts);
    }

    /**
     * onlyDeleted() on the relation callback replaces the ON condition —
     * only soft-deleted comments are joined.
     */
    public function testJoinWithOnlyDeletedOnRelationJoinsOnlyDeletedComments(): void
    {
        $post1 = $this->createPost('has-active-only');
        $post2 = $this->createPost('has-deleted-only');
        $this->createComment($post1->id, 'active');
        $this->createComment($post2->id, 'deleted', deleted: true);

        $posts = Post::find()
            ->joinWith(['comments' => fn($q) => $q->onlyDeleted()], false, 'INNER JOIN')
            ->all();

        // Only post2 has a deleted comment that satisfies the ON condition
        $this->assertCount(1, $posts);
        $this->assertSame('has-deleted-only', $posts[0]->title);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createPost(string $title): Post
    {
        $post = new Post(['title' => $title]);
        $post->save(false);
        return $post;
    }

    private function createComment(int $postId, string $body, bool $deleted = false): Comment
    {
        $comment = new Comment(['post_id' => $postId, 'body' => $body]);
        $comment->save(false);
        if ($deleted) {
            $comment->delete();
        }
        return $comment;
    }
}