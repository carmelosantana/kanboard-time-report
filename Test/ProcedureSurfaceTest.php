<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure;
use Kanboard\Plugin\TimeReport\Api\TimeReportXpProcedure;

/** withObject() exposes EVERY method (private too) and lets core win name clashes. Pin both. */
class ProcedureSurfaceTest extends Base
{
    private const SURFACE = [
        TimeReportProgressProcedure::class => ['getTaskProgress', 'getProjectProgress', 'getMilestoneProgress'],
        TimeReportXpProcedure::class       => ['getUserXp', 'getXpLeaderboard'],
    ];

    public function testProcedureClassesDeclareOnlyTheirRpcMethods(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            $declared = array_map(fn ($m) => $m->getName(), array_filter(
                (new \ReflectionClass($class))->getMethods(),
                fn ($m) => $m->getDeclaringClass()->getName() === $class
            ));
            sort($declared);
            sort($methods);
            $this->assertSame($methods, $declared, $class);
        }
    }

    public function testNoCoreProcedureShadowsOurNames(): void
    {
        $files = glob('app/Api/Procedure/*Procedure.php');
        $this->assertNotEmpty($files, 'run from the Kanboard root');
        foreach ($files as $file) {
            $core = 'Kanboard\\Api\\Procedure\\' . basename($file, '.php');
            foreach (array_merge(...array_values(self::SURFACE)) as $method) {
                $this->assertFalse(method_exists($core, $method), $core . '::' . $method . ' would win over the plugin');
            }
        }
    }

    public function testProcedureParamsCarryNoTypeHints(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            foreach ($methods as $m) {
                foreach ((new \ReflectionMethod($class, $m))->getParameters() as $p) {
                    $this->assertNull($p->getType(), $class . '::' . $m . ' $' . $p->getName());
                }
            }
        }
    }
}
