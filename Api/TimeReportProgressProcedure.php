<?php

namespace Kanboard\Plugin\TimeReport\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\TimeReport\Model\ProgressApi;

// JSON-RPC: progress reads (spec Kanboard #5381). Prefixed short name because core's ACL
// maps key on short class names. Holds ONLY RPC methods (withObject exposes every method)
// and declares params without type hints (a TypeError is a fatal with no JSON-RPC body).
class TimeReportProgressProcedure extends BaseProcedure
{
    public function getTaskProgress($task_id)
    {
        return (new ProgressApi($this->container))->taskProgress($task_id);
    }

    public function getProjectProgress($project_id)
    {
        return (new ProgressApi($this->container))->projectProgress($project_id);
    }

    public function getMilestoneProgress($task_id)
    {
        return (new ProgressApi($this->container))->milestoneProgress($task_id);
    }
}
