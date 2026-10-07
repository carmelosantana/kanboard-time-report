<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;
use Kanboard\Plugin\TimeReport\Model\XpModel;

/** View-side access to progress data for the contract templates. */
class ProgressHelper extends Base
{
    public const TPB_DISMISS_KEY = 'timereport_tpb_notice_dismissed';

    /** Spec Kanboard #5382: tell admins TaskProgressBar is redundant, until they dismiss it. */
    public function tpbNoticeVisible(): bool
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return false;
        }
        if (! array_key_exists('TaskProgressBar', $this->pluginLoader->getPlugins())) {
            return false;
        }
        return $this->userMetadataModel->get($this->userSession->getId(), self::TPB_DISMISS_KEY, '') !== '1';
    }

    public function card(array $task): array
    {
        return ProgressModel::taskMeters($task);
    }

    /** Raw number for a data-* attribute: 3.40 → "3.4", 4.0 → "4". */
    public function num(float $x): string
    {
        $s = number_format(round($x, 2), 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    public function track(int $projectId): ?array
    {
        $p = (new ProgressModel($this->container))->projectProgress($projectId);
        return $p['total'] > 0 ? $p : null;
    }

    public function level(): ?array
    {
        if (! $this->userSession->isLogged()) {
            return null;
        }
        $userId = (int) $this->userSession->getId();
        $xp = $this->container['xpCache']->lifetime($userId);

        return [
            'user_id'  => $userId,
            'level'    => XpModel::levelFor($xp['total']),
            'xp'       => $xp['total'],
            'next'     => XpModel::nextFor($xp['total']),
            'party_xp' => $xp['party_xp'],
        ];
    }
}
