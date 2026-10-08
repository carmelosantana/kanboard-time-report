<?php

namespace Kanboard\Plugin\TimeReport\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\TimeReport\Model\ProgressApi;

// JSON-RPC: XP reads (spec Kanboard #5381). Same rules as TimeReportProgressProcedure.
class TimeReportXpProcedure extends BaseProcedure
{
    public function getUserXp($user_id, $project_id = null, $from = null, $to = null)
    {
        return (new ProgressApi($this->container))->userXp($user_id, $project_id, $from, $to);
    }

    public function getXpLeaderboard($project_id, $from = null, $to = null)
    {
        return (new ProgressApi($this->container))->leaderboard($project_id, $from, $to);
    }
}
