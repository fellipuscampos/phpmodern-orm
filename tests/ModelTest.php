<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PhpModern\Events\Dispatcher;
use PhpModern\Orm\Comparison;
use PhpModern\Orm\Connection;
use PhpModern\Orm\Events\ModelDeleted;
use PhpModern\Orm\Events\ModelSaved;
use PhpModern\Orm\QueryHelper;
use PhpModern\Orm\Tests\Fixtures\Post;
use PhpModern\Orm\Tests\Fixtures\Widget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL, quantity INTEGER NOT NULL)');
        $connection->pdo()->exec("INSERT INTO widgets (id, name, quantity) VALUES (1, 'gear', 3), (2, 'bolt', 10)");

        Widget::useQueryHelper(new QueryHelper($connection));
    }

    protected function tearDown(): void
    {
        // useDispatcher() is shared static state across every Model
        // subclass — reset it so a dispatcher wired in one test never
        // leaks into the next.
        Widget::useDispatcher(null);
    }

    public function test_find_returns_null_for_a_missing_id(): void
    {
        self::assertNull(Widget::find(999));
    }

    public function test_find_returns_a_hydrated_typed_instance(): void
    {
        $widget = Widget::find(1);

        self::assertInstanceOf(Widget::class, $widget);
        self::assertSame(1, $widget->id);
        self::assertSame('gear', $widget->name);
        self::assertSame(3, $widget->quantity);
    }

    public function test_where_returns_typed_instances_matching_the_condition(): void
    {
        $widgets = Widget::where(['quantity' => Comparison::greaterThan(5)]);

        self::assertCount(1, $widgets);
        self::assertInstanceOf(Widget::class, $widgets[0]);
        self::assertSame('bolt', $widgets[0]->name);
    }

    public function test_all_returns_every_row_ordered(): void
    {
        $widgets = Widget::all('quantity', 'DESC');

        self::assertSame(['bolt', 'gear'], array_map(static fn (Widget $w): string => $w->name, $widgets));
    }

    public function test_save_on_a_new_model_inserts_and_returns_an_instance_with_its_id(): void
    {
        $created = (new Widget(null, 'sprocket', 7))->save();

        self::assertNotNull($created->id);
        self::assertSame('sprocket', $created->name);

        $reloaded = Widget::find($created->id);
        self::assertNotNull($reloaded);
        self::assertSame('sprocket', $reloaded->name);
    }

    public function test_save_on_an_existing_model_updates_it(): void
    {
        $widget = Widget::find(1);
        self::assertNotNull($widget);

        $updated = (new Widget($widget->id, 'gear', 99))->save();

        self::assertSame(1, $updated->id);
        self::assertSame(99, $updated->quantity);
        self::assertSame(99, Widget::find(1)?->quantity);
    }

    public function test_delete_removes_the_row(): void
    {
        $widget = Widget::find(1);
        self::assertNotNull($widget);

        $widget->delete();

        self::assertNull(Widget::find(1));
    }

    public function test_delete_on_a_never_saved_model_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new Widget(null, 'unsaved', 1))->delete();
    }

    public function test_save_with_timestamps_stamps_created_and_updated_at(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, created_at TEXT, updated_at TEXT)',
        );
        Post::useQueryHelper(new QueryHelper($connection));

        $created = (new Post(null, 'Hello'))->save();

        self::assertNotNull($created->createdAt);
        self::assertSame($created->createdAt, $created->updatedAt);
    }

    public function test_save_without_a_dispatcher_configured_does_not_error(): void
    {
        $created = (new Widget(null, 'sprocket', 7))->save();

        self::assertNotNull($created->id);
    }

    public function test_save_on_a_new_model_dispatches_model_saved_with_was_created_true(): void
    {
        $dispatcher = new Dispatcher();
        $events = [];
        $dispatcher->listen(ModelSaved::class, static function (ModelSaved $event) use (&$events): void {
            $events[] = $event;
        });
        Widget::useDispatcher($dispatcher);

        $created = (new Widget(null, 'sprocket', 7))->save();

        self::assertCount(1, $events);
        self::assertSame(Widget::class, $events[0]->model);
        self::assertSame($created->id, $events[0]->id);
        self::assertTrue($events[0]->wasCreated);
    }

    public function test_save_on_an_existing_model_dispatches_model_saved_with_was_created_false(): void
    {
        $dispatcher = new Dispatcher();
        $events = [];
        $dispatcher->listen(ModelSaved::class, static function (ModelSaved $event) use (&$events): void {
            $events[] = $event;
        });
        Widget::useDispatcher($dispatcher);

        (new Widget(1, 'gear', 42))->save();

        self::assertCount(1, $events);
        self::assertFalse($events[0]->wasCreated);
    }

    public function test_delete_dispatches_model_deleted(): void
    {
        $dispatcher = new Dispatcher();
        $events = [];
        $dispatcher->listen(ModelDeleted::class, static function (ModelDeleted $event) use (&$events): void {
            $events[] = $event;
        });
        Widget::useDispatcher($dispatcher);

        $widget = Widget::find(1);
        self::assertNotNull($widget);
        $widget->delete();

        self::assertCount(1, $events);
        self::assertSame(Widget::class, $events[0]->model);
        self::assertSame(1, $events[0]->id);
    }
}
