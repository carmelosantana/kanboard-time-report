<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/**
 * Task 3 — content hashing. Pure, DB-free digests that decide when a cached row
 * summary is stale. Subtask edits do not bump any task timestamp, so the hash must
 * digest subtask content directly.
 */
class ContentHashTest extends Base
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'date_modification' => 1000,
            'description'       => 'Task description',
            'subtasks'          => [
                ['title' => 'A', 'status' => 2, 'time_spent' => 1.5, 'position' => 1],
                ['title' => 'B', 'status' => 0, 'time_spent' => 0.0, 'position' => 2],
            ],
        ], $overrides);
    }

    public function testTaskHashIsStable(): void
    {
        $this->assertSame(
            TimeReportModel::taskContentHash($this->row(), false),
            TimeReportModel::taskContentHash($this->row(), false)
        );
    }

    public function testSubtaskEditFlipsHash(): void
    {
        $base = TimeReportModel::taskContentHash($this->row(), false);

        $titleEdit = $this->row(['subtasks' => [
            ['title' => 'A CHANGED', 'status' => 2, 'time_spent' => 1.5, 'position' => 1],
            ['title' => 'B', 'status' => 0, 'time_spent' => 0.0, 'position' => 2],
        ]]);
        $this->assertNotSame($base, TimeReportModel::taskContentHash($titleEdit, false));

        $statusEdit = $this->row(['subtasks' => [
            ['title' => 'A', 'status' => 0, 'time_spent' => 1.5, 'position' => 1],
            ['title' => 'B', 'status' => 0, 'time_spent' => 0.0, 'position' => 2],
        ]]);
        $this->assertNotSame($base, TimeReportModel::taskContentHash($statusEdit, false));

        $timeEdit = $this->row(['subtasks' => [
            ['title' => 'A', 'status' => 2, 'time_spent' => 2.5, 'position' => 1],
            ['title' => 'B', 'status' => 0, 'time_spent' => 0.0, 'position' => 2],
        ]]);
        $this->assertNotSame($base, TimeReportModel::taskContentHash($timeEdit, false));
    }

    public function testDateModificationFlipsHash(): void
    {
        $base = TimeReportModel::taskContentHash($this->row(), false);
        $this->assertNotSame($base, TimeReportModel::taskContentHash($this->row(['date_modification' => 2000]), false));
    }

    public function testDescriptionEditFlipsHashOnlyWhenOptInOn(): void
    {
        $a = $this->row(['description' => 'first']);
        $b = $this->row(['description' => 'second']);

        // Opt-in OFF: description is not part of the digest.
        $this->assertSame(
            TimeReportModel::taskContentHash($a, false),
            TimeReportModel::taskContentHash($b, false)
        );

        // Opt-in ON: description participates, so an edit invalidates.
        $this->assertNotSame(
            TimeReportModel::taskContentHash($a, true),
            TimeReportModel::taskContentHash($b, true)
        );
    }

    public function testFlippingOptInInvalidatesHash(): void
    {
        // Same content, different opt-in state → different hash, so toggling the
        // admin setting forces regeneration.
        $this->assertNotSame(
            TimeReportModel::taskContentHash($this->row(), false),
            TimeReportModel::taskContentHash($this->row(), true)
        );
    }

    public function testAggregateHashStableAndOrderIndependent(): void
    {
        $a = TimeReportModel::aggregateContentHash([7 => 'hh7', 3 => 'hh3']);
        $b = TimeReportModel::aggregateContentHash([3 => 'hh3', 7 => 'hh7']);
        $this->assertSame($a, $b, 'aggregate hash must not depend on member ordering');
    }

    public function testAggregateHashFlipsOnMemberSetOrContentChange(): void
    {
        $base = TimeReportModel::aggregateContentHash([7 => 'hh7', 3 => 'hh3']);
        // Member added.
        $this->assertNotSame($base, TimeReportModel::aggregateContentHash([7 => 'hh7', 3 => 'hh3', 9 => 'hh9']));
        // Member removed.
        $this->assertNotSame($base, TimeReportModel::aggregateContentHash([7 => 'hh7']));
        // A member's content changed.
        $this->assertNotSame($base, TimeReportModel::aggregateContentHash([7 => 'hh7-v2', 3 => 'hh3']));
    }
}
