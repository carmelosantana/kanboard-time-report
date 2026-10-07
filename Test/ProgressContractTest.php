<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Plugin;

class ProgressContractTest extends Base
{
    use ProgressFixture;

    private const FIXTURE = __DIR__ . '/fixtures/progress-contract.html';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
        $this->container['xpCache'] = fn ($c) => new XpCache($c);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function tpl(string $name, array $args = []): string
    {
        return $this->container['template']->render($name, $args);
    }

    private function seed(): array
    {
        $alice = $this->user('alice');
        $p = $this->project('Contract');
        $ms = $this->task($p, ['title' => 'Beta', 'date_due' => strtotime('2026-10-20 12:00:00')]);
        $a = $this->task($p, ['owner_id' => $alice]);
        $b = $this->task($p);
        $this->milestone($ms, [$a, $b]);
        $this->subtask($a, $alice, 2);
        $this->close($a);
        return [$alice, $p, $ms];
    }

    public function testTrackMarkup(): void
    {
        [, $p, $ms] = $this->seed();
        $html = $this->tpl('TimeReport:project/track', ['project' => ['id' => $p]]);

        $this->assertStringContainsString('class="tr-track"', $html);
        $this->assertStringContainsString('data-tr-contract="1"', $html);
        $this->assertStringContainsString('data-tr-project="' . $p . '"', $html);
        $this->assertStringContainsString('data-tr-project-pct="33"', $html);
        $this->assertStringContainsString('class="tr-track__milestone"', $html);
        $this->assertStringContainsString('data-tr-milestone="' . $ms . '"', $html);
        $this->assertStringContainsString('data-tr-pct="50"', $html);
        $this->assertStringContainsString('data-tr-due="2026-10-20"', $html);
        $this->assertStringContainsString('data-tr-state="open"', $html);
        $this->assertStringContainsString('Beta', $html);
    }

    public function testTrackIsEmptyForProjectsWithoutTasks(): void
    {
        $this->assertSame('', trim($this->tpl('TimeReport:project/track', ['project' => ['id' => $this->project('Empty')]])));
    }

    public function testLevelBadgeMarkupForCurrentUser(): void
    {
        [$alice] = $this->seed();
        $this->actAs($alice);
        $html = $this->tpl('TimeReport:layout/level');

        $this->assertStringContainsString('class="tr-level"', $html);
        $this->assertStringContainsString('data-tr-user="' . $alice . '"', $html);
        $this->assertStringContainsString('data-tr-xp="35"', $html);
        $this->assertStringContainsString('data-tr-level="1"', $html);
        $this->assertStringContainsString('data-tr-next="65"', $html);
    }

    public function testLevelBadgeOmitsPartyWithoutAgents(): void
    {
        [$alice] = $this->seed();
        $this->actAs($alice);
        $this->assertStringNotContainsString('data-tr-party-xp', $this->tpl('TimeReport:layout/level'));
    }

    public function testLevelBadgeShowsPartyForOwners(): void
    {
        [$alice, $p] = $this->seed();
        $bot = $this->user('alice.claude');
        $this->agentsTable([$bot => $alice]);
        $this->subtask($this->task($p), $bot, 2);
        $this->container['xpCache']->invalidateAll();
        $this->actAs($alice);

        $this->assertStringContainsString('data-tr-party-xp="45"', $this->tpl('TimeReport:layout/level'));
    }

    public function testLevelBadgeIsEmptyWhenLoggedOut(): void
    {
        $this->assertSame('', trim($this->tpl('TimeReport:layout/level')));
    }

    public function testPluginAttachesTrackAndLevelHooks(): void
    {
        (new Plugin($this->container))->initialize();
        $hook = $this->container['hook'];
        $this->assertContains('TimeReport:project/track', array_column($hook->getListeners('template:project:header:after'), 'template'));
        $this->assertContains('TimeReport:layout/level', array_column($hook->getListeners('template:layout:top'), 'template'));
    }

    public function testTrackEscapesMilestoneTitles(): void
    {
        $p = $this->project('Esc');
        $ms = $this->task($p, ['title' => '<b>x</b>"']);
        $this->milestone($ms, [$this->task($p)]);
        $html = $this->tpl('TimeReport:project/track', ['project' => ['id' => $p]]);

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;&quot;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    /** Every class, data attribute and custom property a template emits is documented. */
    public function testContractDocCoversEveryTemplateToken(): void
    {
        $root = dirname(__DIR__);
        $doc = file_get_contents($root . '/docs/progress-contract.md');
        foreach (['board/progress.php', 'project/track.php', 'layout/level.php'] as $f) {
            $src = file_get_contents($root . '/Template/' . $f);
            preg_match_all('/(?<![\w-])(?:data-tr-[a-z-]*[a-z]|tr-[a-z0-9_-]*[a-z0-9]|--tr-[a-z-]*[a-z])/', $src, $m);
            foreach (array_unique($m[0]) as $token) {
                $this->assertStringContainsString($token, $doc, "$f emits $token, which docs/progress-contract.md does not document");
            }
        }
    }

    /**
     * The rendered contract, frozen. Battle Lobby's tests read this file.
     * Regenerate deliberately with TR_UPDATE_FIXTURE=1, and only for ADDITIVE changes.
     */
    public function testRenderedContractMatchesFixture(): void
    {
        [$alice, $p] = $this->seed();
        $this->actAs($alice);
        $card = ['id' => 42, 'project_id' => $p, 'nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4];

        $html = "<!-- TimeReport progress contract v1 (generated by ProgressContractTest) -->\n"
            . "<!-- card -->\n" . trim($this->tpl('TimeReport:board/progress', ['task' => $card])) . "\n"
            . "<!-- track -->\n" . trim($this->tpl('TimeReport:project/track', ['project' => ['id' => $p]])) . "\n"
            . "<!-- level -->\n" . trim($this->tpl('TimeReport:layout/level')) . "\n";
        $html = preg_replace('/data-tr-(project|milestone|user)="\d+"/', 'data-tr-$1="N"', $html);

        if (getenv('TR_UPDATE_FIXTURE') === '1') {
            @mkdir(dirname(self::FIXTURE), 0777, true);
            file_put_contents(self::FIXTURE, $html);
        }
        $this->assertFileExists(self::FIXTURE, 'Run once with TR_UPDATE_FIXTURE=1 to create the fixture.');
        $this->assertSame(file_get_contents(self::FIXTURE), $html);
    }
}
