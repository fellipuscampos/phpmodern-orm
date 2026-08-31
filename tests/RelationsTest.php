<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PDO;
use PhpModern\Orm\Connection;
use PhpModern\Orm\QueryHelper;
use PhpModern\Orm\Relations;
use PhpModern\Orm\Tests\Fixtures\CountingStatement;
use PHPUnit\Framework\TestCase;

final class RelationsTest extends TestCase
{
    private Connection $connection;

    private QueryHelper $queryHelper;

    protected function setUp(): void
    {
        $this->connection = Connection::sqlite(':memory:');
        $pdo = $this->connection->pdo();

        $pdo->exec('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL, title TEXT NOT NULL)');

        $pdo->exec("INSERT INTO authors (id, name) VALUES (1, 'Ada'), (2, 'Alan'), (3, 'Grace')");
        $pdo->exec("INSERT INTO books (author_id, title) VALUES
            (1, 'Notes on the Analytical Engine'),
            (1, 'Sketch of the Analytical Engine'),
            (2, 'Computing Machinery and Intelligence'),
            (3, 'A COBOL Report')");

        $this->queryHelper = new QueryHelper($this->connection);
    }

    public function test_has_many_attaches_the_correct_related_rows_to_each_parent(): void
    {
        $authors = $this->queryHelper->findMany('authors');
        $authors = Relations::hasMany($this->queryHelper, $authors, 'id', 'books', 'author_id', 'books');

        $titlesByName = [];
        foreach ($authors as $author) {
            $titlesByName[$author['name']] = array_column($author['books'], 'title');
        }

        self::assertSame([
            'Notes on the Analytical Engine',
            'Sketch of the Analytical Engine',
        ], $titlesByName['Ada']);
        self::assertSame(['Computing Machinery and Intelligence'], $titlesByName['Alan']);
        self::assertSame(['A COBOL Report'], $titlesByName['Grace']);
    }

    public function test_has_many_gives_an_empty_list_for_a_parent_with_no_related_rows(): void
    {
        $this->connection->pdo()->exec("INSERT INTO authors (id, name) VALUES (4, 'Barbara')");

        $authors = $this->queryHelper->findMany('authors', ['id' => 4]);
        $authors = Relations::hasMany($this->queryHelper, $authors, 'id', 'books', 'author_id', 'books');

        self::assertSame([], $authors[0]['books']);
    }

    public function test_belongs_to_attaches_the_single_related_row(): void
    {
        $books = $this->queryHelper->findMany('books');
        $books = Relations::belongsTo($this->queryHelper, $books, 'author_id', 'authors', 'id', 'author');

        foreach ($books as $book) {
            self::assertNotNull($book['author']);
            self::assertIsString($book['author']['name']);
        }
    }

    public function test_has_many_issues_exactly_one_extra_query_no_matter_how_many_parents(): void
    {
        $this->connection->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);

        $authors = $this->queryHelper->findMany('authors');
        self::assertGreaterThanOrEqual(3, count($authors)); // sanity: more than one parent row

        CountingStatement::$count = 0; // isolate just the eager-load call below

        Relations::hasMany($this->queryHelper, $authors, 'id', 'books', 'author_id', 'books');

        self::assertSame(
            1,
            CountingStatement::$count,
            'hasMany() must issue exactly one query regardless of parent row count',
        );
    }

    public function test_belongs_to_many_attaches_related_rows_through_a_pivot_table(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE book_tag (book_id INTEGER NOT NULL, tag_id INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO tags (id, label) VALUES (1, 'history'), (2, 'ai'), (3, 'math')");
        $insertPivot = $pdo->prepare('INSERT INTO book_tag (book_id, tag_id) VALUES (
            (SELECT id FROM books WHERE title = :title), :tag_id)');
        $insertPivot->execute(['title' => 'Notes on the Analytical Engine', 'tag_id' => 2]);
        $insertPivot->execute(['title' => 'Notes on the Analytical Engine', 'tag_id' => 3]);
        $insertPivot->execute(['title' => 'Computing Machinery and Intelligence', 'tag_id' => 2]);

        $books = $this->queryHelper->findMany('books');
        $books = Relations::belongsToMany(
            $this->queryHelper,
            $books,
            'id',
            'book_tag',
            'book_id',
            'tag_id',
            'tags',
            'id',
            'tags',
        );

        $labelsByTitle = [];
        foreach ($books as $book) {
            $labelsByTitle[$book['title']] = array_column($book['tags'], 'label');
        }

        self::assertSame(['ai', 'math'], $labelsByTitle['Notes on the Analytical Engine']);
        self::assertSame(['ai'], $labelsByTitle['Computing Machinery and Intelligence']);
        self::assertSame([], $labelsByTitle['A COBOL Report']);
    }
}
