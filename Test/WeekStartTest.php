<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\ManualTimeLog;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/** Weeks run Sunday to Saturday unless the user says Monday; date fields use one format. */
class WeekStartTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReport', TimeReportHelper::class);
        $this->container['timeReportModel'] = fn ($c) => new TimeReportModel($c);
    }

    private function logIn(int $userId = 1): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
    }

    private function mondayWeeks(int $userId = 1): void
    {
        $this->container['userMetadataModel']->save($userId, [TimeReportModel::WEEK_START_KEY => '1']);
    }

    public function testASundayWeekRunsSundayToSaturday(): void
    {
        $this->assertSame('W2026-11-08', TimeReportModel::weekKey(strtotime('2026-11-08 08:00:00'), 0));
        $this->assertSame('W2026-11-08', TimeReportModel::weekKey(strtotime('2026-11-14 20:00:00'), 0));
        $this->assertSame('W2026-11-15', TimeReportModel::weekKey(strtotime('2026-11-15 00:00:00'), 0));
        $this->assertSame('Nov 08 – Nov 14', TimeReportModel::weekLabel(strtotime('2026-11-12 12:00:00'), 0));
    }

    public function testAMondayWeekLabelRunsMondayToSunday(): void
    {
        $this->assertSame('Nov 09 – Nov 15', TimeReportModel::weekLabel(strtotime('2026-11-15 12:00:00'), 1));
    }

    public function testBucketGroupsWeeksByTheGivenFirstDay(): void
    {
        $c = [
            ['task_id' => 1, 'hours' => 1.0, 'date' => '2026-11-08', 'user_id' => 1], // Sunday
            ['task_id' => 1, 'hours' => 2.0, 'date' => '2026-11-14', 'user_id' => 1], // Saturday
        ];

        $sunday = TimeReportModel::bucket($c, 'week', [], [], 0);
        $this->assertSame(['Nov 08 – Nov 14'], array_column($sunday['breakdown'], 'label'));
        $this->assertSame([3.0], array_column($sunday['breakdown'], 'hours'));

        $monday = TimeReportModel::bucket($c, 'week', [], [], 1);
        $this->assertSame(['Nov 02 – Nov 08', 'Nov 09 – Nov 15'], array_column($monday['breakdown'], 'label'));
    }

    public function testWeekStartDefaultsToSundayAndFollowsTheUsersSetting(): void
    {
        $model = new TimeReportModel($this->container);
        $this->assertSame(0, $model->weekStartFor(1));

        $this->mondayWeeks();
        $this->assertSame(1, $model->weekStartFor(1));

        $this->container['userMetadataModel']->save(1, [TimeReportModel::WEEK_START_KEY => 'nonsense']);
        $this->assertSame(0, $model->weekStartFor(1));
    }

    public function testThePerWeekReportUsesTheRequestersWeek(): void
    {
        $projectId = $this->project('Weeks');
        $subtaskId = $this->subtask($this->task($projectId, ['owner_id' => 1]), 1);
        foreach (['2026-11-08 10:00:00' => 1.0, '2026-11-14 10:00:00' => 2.0] as $when => $hours) {
            $this->container['db']->table(ManualTimeLog::TABLE)->insert(['subtask_id' => $subtaskId, 'user_id' => 1, 'hours' => $hours, 'entered_at' => strtotime($when)]);
        }
        $this->container['db']->table('subtasks')->eq('id', $subtaskId)->update(['time_spent' => 3.0]);
        $model = new TimeReportModel($this->container);

        $report = $model->report($projectId, '2026-11-01', '2026-11-30', 'week', false, 1);
        $this->assertSame(['Nov 08 – Nov 14'], array_column($report['breakdown'], 'label'));
        $this->assertSame(0, $report['week_start']);

        $this->mondayWeeks();
        $report = $model->report($projectId, '2026-11-01', '2026-11-30', 'week', false, 1);
        $this->assertSame(['Nov 02 – Nov 08', 'Nov 09 – Nov 15'], array_column($report['breakdown'], 'label'));
    }

    public function testWeekPresetsRunSundayToSaturdayByDefault(): void
    {
        $presets = (new TimeReportHelper($this->container))->datePresets(strtotime('2026-11-12 15:00:00')); // a Thursday

        $this->assertSame(['2026-11-08', '2026-11-14'], [$presets['this_week']['start'], $presets['this_week']['end']]);
        $this->assertSame(['2026-11-01', '2026-11-07'], [$presets['last_week']['start'], $presets['last_week']['end']]);
    }

    public function testOnASundayThisWeekStartsThatDay(): void
    {
        $presets = (new TimeReportHelper($this->container))->datePresets(strtotime('2026-11-08 09:00:00'));

        $this->assertSame(['2026-11-08', '2026-11-14'], [$presets['this_week']['start'], $presets['this_week']['end']]);
    }

    public function testWeekPresetsFollowAMondaySetting(): void
    {
        $this->logIn();
        $this->mondayWeeks();
        $presets = (new TimeReportHelper($this->container))->datePresets(strtotime('2026-11-12 15:00:00'));

        $this->assertSame(['2026-11-09', '2026-11-15'], [$presets['this_week']['start'], $presets['this_week']['end']]);
        $this->assertSame(['2026-11-02', '2026-11-08'], [$presets['last_week']['start'], $presets['last_week']['end']]);
    }

    public function testUserSettingsOfferTheWeekStartWithTheSavedChoiceSelected(): void
    {
        $html = $this->container['template']->render('TimeReport:user/integrations', ['values' => [TimeReportModel::WEEK_START_KEY => '1']]);

        $this->assertStringContainsString('name="' . TimeReportModel::WEEK_START_KEY . '"', $html);
        $this->assertMatchesRegularExpression('#<option value="1" selected="selected">Monday</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="0">Sunday</option>#', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testUserSettingsDefaultToSunday(): void
    {
        $html = $this->container['template']->render('TimeReport:user/integrations', ['values' => []]);

        $this->assertMatchesRegularExpression('#<option value="0" selected="selected">Sunday</option>#', $html);
    }

    // ── One date format in the fields: the calendar's ────────────────────────

    public function testDateFieldsShowTheSameFormatTheCalendarWrites(): void
    {
        $form = $this->container['template']->render('TimeReport:report/form', [
            'projects' => [5 => 'Acme'], 'ai_enabled' => false, 'profiles' => [], 'values' => ['start_date' => '2026-03-01', 'end_date' => '2026-03-31'],
            'can_report_others' => false, 'send_descriptions' => false,
        ]);

        $this->assertMatchesRegularExpression('#name="start_date"[^>]*value="03/01/2026"#', $form);
        $this->assertMatchesRegularExpression('#name="end_date"[^>]*value="03/31/2026"#', $form);
        $this->assertSame(4, preg_match_all('#data-tr-preset[^>]*data-start="\d\d/\d\d/\d{4}"[^>]*data-end="\d\d/\d\d/\d{4}"#', $form));
    }

    public function testDateFieldsFollowTheConfiguredDateFormat(): void
    {
        $this->container['configModel']->save(['application_date_format' => 'd.m.Y']);
        $this->container['memoryCache']->flush();

        $this->assertSame('31.03.2026', (new TimeReportHelper($this->container))->fieldDate('2026-03-31'));
    }
}
