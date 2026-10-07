<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * Lifetime XP per user per project, plus a per-user marker row (project_id 0)
 * holding the total and the party total. A warm read is one query; a missing
 * marker means "rebuild this user". Invalidation clears the whole table: core
 * subtask events carry no previous assignee, so a targeted rebuild could miss
 * whose XP an assignment change removed. Every row is derivable, so a clear is
 * always safe.
 */
class XpCache extends Base
{
    public const TABLE  = 'timereport_xp_cache';
    public const MARKER = 0;

    /** @return array{total:int, by_project:array<int,int>, party_xp:?int} */
    public function lifetime(int $userId): array
    {
        $rows = $this->db->table(self::TABLE)->eq('user_id', $userId)->findAll();
        $hit = self::fromRows($rows ?: []);
        if ($hit !== null) {
            return $hit;
        }
        return $this->rebuild($userId);
    }

    public function invalidateAll(): void
    {
        $this->db->table(self::TABLE)->remove();
    }

    private function rebuild(int $userId): array
    {
        $xpModel   = new XpModel($this->container);
        $byProject = $xpModel->deriveUser($userId);
        $total     = array_sum($byProject);

        // agentOwnerMap() may hit a missing table; PicoDb rolls back any open
        // transaction on error, so never move it inside one.
        $agents = array_keys(array_filter($xpModel->agentOwnerMap(), fn (int $owner) => $owner === $userId));
        $party  = null;
        if ($agents !== []) {
            $party = $total;
            foreach ($agents as $agentId) {
                $party += array_sum($xpModel->deriveUser($agentId));
            }
        }

        $now = time();
        $this->db->table(self::TABLE)->eq('user_id', $userId)->remove();
        foreach ($byProject as $projectId => $xp) {
            $this->db->table(self::TABLE)->insert(['user_id' => $userId, 'project_id' => $projectId, 'xp' => $xp, 'party_xp' => null, 'computed_at' => $now]);
        }
        $this->db->table(self::TABLE)->insert(['user_id' => $userId, 'project_id' => self::MARKER, 'xp' => $total, 'party_xp' => $party, 'computed_at' => $now]);

        return ['total' => $total, 'by_project' => $byProject, 'party_xp' => $party];
    }

    private static function fromRows(array $rows): ?array
    {
        $marker = null;
        $byProject = [];
        foreach ($rows as $r) {
            if ((int) $r['project_id'] === self::MARKER) {
                $marker = $r;
            } else {
                $byProject[(int) $r['project_id']] = (int) $r['xp'];
            }
        }
        if ($marker === null) {
            return null;
        }
        ksort($byProject);
        return [
            'total'      => (int) $marker['xp'],
            'by_project' => $byProject,
            'party_xp'   => $marker['party_xp'] === null ? null : (int) $marker['party_xp'],
        ];
    }
}
