<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;

/** View-side access to progress data for the contract templates. */
class ProgressHelper extends Base
{
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
}
