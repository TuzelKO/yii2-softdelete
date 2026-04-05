#  Yii2 Soft-Delete extension

[![Latest Stable Version](https://poser.pugx.org/tuzelko/yii2-softdelete/v/stable.svg)](https://packagist.org/packages/tuzelko/yii2-softdelete)
[![Total Downloads](https://poser.pugx.org/tuzelko/yii2-softdelete/downloads.svg)](https://packagist.org/packages/tuzelko/yii2-softdelete)
[![License](https://poser.pugx.org/tuzelko/yii2-softdelete/license.svg)](https://packagist.org/packages/tuzelko/yii2-softdelete)

Soft-delete extension for the [Yii2](https://www.yiiframework.com/) framework.

Adds soft-delete (and restore) behaviour to any `ActiveRecord` model via a single PHP trait, with an accompanying query class that automatically hides deleted records from all queries.

## Features

- **Three column strategies** — Unix timestamp (`int`), DB-native datetime, or boolean flag
- **Automatic query scope** — deleted records are invisible by default; opt in with `withDeleted()` / `onlyDeleted()`
- **Instance methods** — `softDelete()`, `hardDelete()`, `restore()`, `isSoftDeleted()`
- **Configurable `delete()`** — routes to `softDelete()` or `hardDelete()` depending on `defaultDeleteMethod()`
- **Bulk methods** — `softDeleteAll()`, `hardDeleteAll()`, `restoreAll()`, `updateAll()` (all scope-aware)
- **Configurable `deleteAll()`** — same routing as `delete()`
- **Events** — `beforeSoftDelete`, `afterSoftDelete`, `beforeRestore`, `afterRestore`
- **Multi-database** — MySQL, PostgreSQL, SQLite, SQL Server, Oracle
- **Zero configuration** — sensible defaults, override only what you need

## Requirements

- PHP >= 8.0
- yiisoft/yii2 ~2.0

## Installation

```bash
composer require tuzelko/yii2-softdelete
```

## Quick start

### 1. Add the column to your migration

```php
// Unix timestamp (default)
$this->addColumn('{{%post}}', 'deleted_at', $this->integer()->null()->defaultValue(null));

// — or — boolean flag
$this->addColumn('{{%article}}', 'is_deleted', $this->boolean()->notNull()->defaultValue(false));
```

### 2. Apply the trait to your model

```php
use tuzelko\yii\softdelete\SoftDeleteTrait;
use yii\db\ActiveRecord;

class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return 'post';
    }
}
```

That's it. `Post::find()` now returns only non-deleted records, and `$post->delete()` soft-deletes instead of hard-deletes.

## Column strategies

Override `softDeleteColumn()` and `softDeleteType()` when the defaults do not fit your schema.

| Constant | Column value when deleted | Column value when restored |
|---|---|---|
| `TYPE_TIMESTAMP_INT` *(default)* | `time()` (Unix timestamp) | `NULL` |
| `TYPE_TIMESTAMP_DB` | `NOW()` / `datetime('now')` / etc. | `NULL` |
| `TYPE_BOOL` | `1` | `0` |

```php
class Article extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string { return 'article'; }

    public static function softDeleteColumn(): string { return 'is_deleted'; }
    public static function softDeleteType(): int      { return self::TYPE_BOOL; }
}
```

## Instance methods

```php
$post = Post::findOne(1);

$post->softDelete();      // soft-delete — sets deleted_at, hides from default scope
$post->isSoftDeleted();   // true

$post->restore();         // clears deleted_at, record becomes visible again
$post->isSoftDeleted();   // false

$post->hardDelete();      // permanent hard-delete (fires standard Yii2 before/afterDelete events)
```

`delete()` is a routing method — by default it calls `softDelete()`. See [Default delete routing](#default-delete-routing) to change this.

> **Deprecated:** `forceDelete()` is a deprecated alias for `hardDelete()`.

## Query scopes

```php
// Default — excludes soft-deleted records (no extra call needed)
Post::find()->all();

// Include soft-deleted records alongside active ones
Post::find()->withDeleted()->all();

// Only soft-deleted records
Post::find()->onlyDeleted()->all();
```

## Bulk operations

```php
// Soft-delete all active records matching the condition
Post::softDeleteAll(['status' => 'spam']);

// Restore all soft-deleted records
Post::restoreAll();

// Restore specific records
Post::restoreAll(['id' => [3, 5, 7]]);

// Permanently delete all soft-deleted records
Post::hardDeleteAll(['is not', 'deleted_at', null]);

// updateAll() also skips soft-deleted records automatically
Post::updateAll(['status' => 'archived'], ['category_id' => 2]);
```

`deleteAll()` is a routing method — by default it calls `softDeleteAll()`. See [Default delete routing](#default-delete-routing) to change this.

> **Deprecated:** `forceDeleteAll()` is a deprecated alias for `hardDeleteAll()`.

> **Auto-scope behaviour:** `softDeleteAll()`, `updateAll()`, and `restoreAll()` automatically add a "not deleted" (or "deleted") condition unless your `$condition` already references the soft-delete column. This prevents double-applying the scope when you target records explicitly.

## Default delete routing

`defaultDeleteMethod()` controls what `delete()` and `deleteAll()` do. Override it in your model to change the behaviour:

| Constant | `delete()` behaviour | `deleteAll()` behaviour |
|---|---|---|
| `DELETE_METHOD_SOFT` *(default)* | calls `softDelete()` | calls `softDeleteAll()` |
| `DELETE_METHOD_HARD` | calls `hardDelete()` | calls `hardDeleteAll()` |
| `DELETE_METHOD_DISABLED` | throws `NotSupportedException` | throws `NotSupportedException` |

```php
class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string { return 'post'; }

    // Always route delete() to hardDelete()
    public static function defaultDeleteMethod(): int
    {
        return self::DELETE_METHOD_HARD;
    }
}
```

```php
class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string { return 'post'; }

    // Disable delete() entirely — callers must choose softDelete() or hardDelete() explicitly
    public static function defaultDeleteMethod(): int
    {
        return self::DELETE_METHOD_DISABLED;
    }
}
```

## Relations

Because `SoftDeleteTrait` overrides `find()` to return a `SoftDeleteActiveQuery`, the soft-delete scope is **automatically applied to every relation** that points to a soft-delete-enabled model — including eager loading via `with()` and join-based loading via `joinWith()`.

### Declaring relations

```php
class User extends ActiveRecord
{
    // hasMany — only active (non-deleted) posts are returned
    public function getPosts(): SoftDeleteActiveQuery
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }

    // To include deleted records in a relation, call withDeleted() on it
    public function getAllPosts(): SoftDeleteActiveQuery
    {
        return $this->hasMany(Post::class, ['user_id' => 'id'])->withDeleted();
    }

    // hasOne — same rules apply
    public function getLatestPost(): SoftDeleteActiveQuery
    {
        return $this->hasOne(Post::class, ['user_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }
}
```

```php
class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string { return 'post'; }

    // Relation to a model that does NOT use soft-delete — works as usual
    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    // Relation to another soft-delete model
    public function getComments(): SoftDeleteActiveQuery
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }
}
```

### Eager loading with `with()`

```php
// Load users and only their active posts (soft-delete scope applied automatically)
$users = User::find()->with('posts')->all();

foreach ($users as $user) {
    foreach ($user->posts as $post) {
        // $post is never soft-deleted
    }
}

// Load users together with ALL their posts (including deleted)
$users = User::find()
    ->with(['posts' => fn($q) => $q->withDeleted()])
    ->all();

// Load users with only their deleted posts
$users = User::find()
    ->with(['posts' => fn($q) => $q->onlyDeleted()])
    ->all();
```

### JOIN-based loading with `joinWith()`

Both models' soft-delete scopes are applied **automatically**. The condition for each model is placed in the JOIN's **ON clause** (not WHERE), which preserves correct LEFT JOIN semantics: a user with no active posts still appears with NULL post columns rather than disappearing.

```php
// LEFT JOIN (default) — all users appear; only active posts are joined
// Generated SQL: ... LEFT JOIN post ON user.id = post.user_id AND post.deleted_at IS NULL
//                    WHERE user.deleted_at IS NULL
$users = User::find()->joinWith('posts')->all();

// INNER JOIN — only users with at least one active post are returned
$users = User::find()->joinWith('posts', false, 'INNER JOIN')->all();
```

Column names are always **table-qualified** (`post.deleted_at`), so joining two tables that both have a soft-delete column never produces an "ambiguous column" SQL error.

The relation callback works the same as with `with()`:

```php
// Include deleted posts in the JOIN
$users = User::find()->joinWith(['posts' => fn($q) => $q->withDeleted()])->all();

// JOIN only with deleted posts (e.g. to find users with pending cleanup)
$users = User::find()
    ->joinWith(['posts' => fn($q) => $q->onlyDeleted()], false, 'INNER JOIN')
    ->all();
```

## Events

All four events receive a `yii\base\ModelEvent`. Setting `$event->isValid = false` in a `before*` handler cancels the operation.

| Constant | When |
|---|---|
| `SoftDeleteTrait::EVENT_BEFORE_SOFT_DELETE` | Before `softDelete()` writes to the DB |
| `SoftDeleteTrait::EVENT_AFTER_SOFT_DELETE` | After `softDelete()` succeeds |
| `SoftDeleteTrait::EVENT_BEFORE_RESTORE` | Before `restore()` writes to the DB |
| `SoftDeleteTrait::EVENT_AFTER_RESTORE` | After `restore()` succeeds |

```php
$post->on(Post::EVENT_BEFORE_SOFT_DELETE, function (\yii\base\ModelEvent $event) {
    if (!Yii::$app->user->can('deletePost')) {
        $event->isValid = false; // cancel the soft-delete
    }
});
```

`hardDelete()` fires the standard Yii2 `ActiveRecord::EVENT_BEFORE_DELETE` / `EVENT_AFTER_DELETE` events.

## Performance

Add an index on the soft-delete column so that the automatic scope does not cause a full-table scan:

```php
// In your migration
$this->createIndex('idx_post_deleted_at', 'post', 'deleted_at');

// Boolean column — a partial index (supported by PostgreSQL and SQLite) is even more efficient
$this->createIndex('idx_article_is_deleted', 'article', 'is_deleted');
```

Without the index every `find()`, `softDeleteAll()`, `updateAll()`, and `restoreAll()` call will scan the whole table once it grows large.

## Cascade soft-deletes

Soft-deleting a parent record does **not** automatically soft-delete its children. If you need cascading behaviour, implement it in the `afterSoftDelete` hook:

```php
class Post extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string { return 'post'; }

    public function afterSoftDelete(): void
    {
        Comment::softDeleteAll(['post_id' => $this->id]);
    }

    public function afterRestore(): void
    {
        Comment::restoreAll(['post_id' => $this->id]);
    }
}
```

## String conditions and auto-scope

`softDeleteAll()`, `updateAll()`, and `restoreAll()` automatically add a soft-delete scope unless your `$condition` already references the soft-delete column. This detection works for **array conditions only**. Plain SQL strings are not inspected.

If you pass a raw string that already targets the soft-delete column, wrap it in an array to prevent the scope from being added twice:

```php
// ✗ scope is added twice — the string is not inspected
Post::softDeleteAll("deleted_at IS NULL AND category_id = 5");

// ✓ wrap in an array so the column is detected
Post::softDeleteAll(['and', ['is', 'deleted_at', null], ['category_id' => 5]]);
```

## Running tests

```bash
make test
```

Tests run inside Docker (PHP 8.3 + SQLite) with no local setup required.

## License

MIT — see [LICENSE](LICENSE).