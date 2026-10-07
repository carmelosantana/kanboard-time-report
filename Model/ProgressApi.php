<?php

namespace Kanboard\Plugin\TimeReport\Model;

use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;

/**
 * Permission-checked logic behind the procedures (spec Kanboard #5381). Lives
 * here, not on the procedure classes, because withObject() exposes every
 * method of those objects, private ones included.
 *
 * App-token calls (no user session) see everything, like core. Personal-token
 * calls see only projects the caller is allowed in.
 */
class ProgressApi extends Base
{
    public function taskProgress($taskId)
    {
        $taskId = self::id($taskId);
        $projectId = $taskId ? (int) $this->taskFinderModel->getProjectId($taskId) : 0;
        if ($projectId === 0) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->taskProgress($taskId) ?? false;
    }

    public function projectProgress($projectId)
    {
        $projectId = self::id($projectId);
        if (! $projectId || ! $this->projectModel->exists($projectId)) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->projectProgress($projectId);
    }

    public function milestoneProgress($taskId)
    {
        $taskId = self::id($taskId);
        $projectId = $taskId ? (int) $this->taskFinderModel->getProjectId($taskId) : 0;
        if ($projectId === 0) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->milestoneProgress($taskId) ?? false;
    }

    public function userXp($userId, $projectId = null, $from = null, $to = null)
    {
        $userId = self::id($userId);
        $range = self::range($from, $to);
        if (! $userId || $range === false || ! $this->userModel->exists($userId)) {
            return false;
        }
        $projectId = $projectId === null || $projectId === '' ? null : self::id($projectId);
        if ($projectId === 0 || ($projectId !== null && ! $this->projectModel->exists($projectId))) {
            return false;
        }
        if ($projectId !== null) {
            $this->assertProject($projectId);
        }

        $own = $this->xpByProject($userId, $range, $projectId);
        // An empty map must encode as JSON {} (not []) for typed map decoders.
        $out = ['xp' => array_sum($own)] + self::levelOf(array_sum($own)) + ['by_project' => $own ?: new \stdClass()];

        $xpModel = new XpModel($this->container);
        $agents = array_keys(array_filter($xpModel->agentOwnerMap(), fn (int $owner) => $owner === $userId));
        if ($agents !== []) {
            $members = [];
            foreach ($agents as $agentId) {
                $members[] = ['user_id' => $agentId, 'xp' => array_sum($this->xpByProject($agentId, $range, $projectId))];
            }
            $partyXp = $out['xp'] + array_sum(array_column($members, 'xp'));
            $out['party'] = ['xp' => $partyXp] + self::levelOf($partyXp) + ['members' => $members];
        }

        return $out;
    }

    public function leaderboard($projectId, $from = null, $to = null)
    {
        $projectId = self::id($projectId);
        $range = self::range($from, $to);
        if (! $projectId || $range === false || ! $this->projectModel->exists($projectId)) {
            return false;
        }
        $this->assertProject($projectId);

        $xpModel = new XpModel($this->container);
        $byUser = $xpModel->byUser($projectId, $range[0] ?? null, $range[1] ?? null);
        $owners = $xpModel->agentOwnerMap();

        $rows = [];
        foreach ($byUser as $uid => $xp) {
            $rows[] = ['user_id' => $uid, 'xp' => $xp, 'level' => XpModel::levelFor($xp), 'is_agent' => isset($owners[$uid]), 'owner_id' => $owners[$uid] ?? 0];
        }
        usort($rows, fn (array $a, array $b) => [$b['xp'], $a['user_id']] <=> [$a['xp'], $b['user_id']]);
        return $rows;
    }

    /** @return array<int,int> project_id => xp, restricted to $projectId and to what the caller may see */
    private function xpByProject(int $userId, array $range, ?int $projectId): array
    {
        $byProject = $range === []
            ? (new XpCache($this->container))->lifetime($userId)['by_project']
            : (new XpModel($this->container))->deriveUser($userId, $range[0], $range[1]);

        if ($projectId !== null) {
            return isset($byProject[$projectId]) ? [$projectId => $byProject[$projectId]] : [];
        }
        // Same rule as assertProject(): admins see all; others every project they
        // belong to (directly or via a group), closed ones included.
        if ($this->userSession->isLogged() && ! $this->userSession->isAdmin()) {
            $visible = array_map('intval', $this->projectPermissionModel->getProjectIds($this->userSession->getId()));
            $byProject = array_intersect_key($byProject, array_flip($visible));
        }
        return $byProject;
    }

    private function assertProject(int $projectId): void
    {
        if ($this->userSession->isLogged()
            && ! $this->projectPermissionModel->isUserAllowed($projectId, $this->userSession->getId())) {
            throw new AccessDeniedException('Project Access Denied');
        }
    }

    private static function levelOf(int $xp): array
    {
        return ['level' => XpModel::levelFor($xp), 'next' => XpModel::nextFor($xp)];
    }

    private static function id($value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /** @return array{}|array{0:int,1:int}|false  [] = lifetime; false = invalid, half-open or reversed */
    private static function range($from, $to)
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return [];
        }
        foreach ([$from, $to] as $d) {
            // Round-trip rejects impossible dates such as 2026-02-30.
            $parsed = is_string($d) ? \DateTime::createFromFormat('!Y-m-d', $d) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $d) {
                return false;
            }
        }
        if ($from > $to) {
            return false;
        }
        return [(int) strtotime($from . ' 00:00:00'), (int) strtotime($to . ' 23:59:59')];
    }
}
