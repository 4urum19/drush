<?php

namespace Drush\Attributes;

use Attribute;
use Consolidation\AnnotatedCommand\Parser\CommandInfo;
use Drush\Boot\DrupalBootLevels;
use JetBrains\PhpStorm\ExpectedValues;

#[Attribute(Attribute::TARGET_METHOD)]
class Bootstrap
{
    /**
     * @param $level
     *   The level to bootstrap to.
     * @package $extra
     *   A maximum level when used with MAX.
     */
    public function __construct(
        #[ExpectedValues(valuesFromClass: DrupalBootLevels::class)] public int $level,
        public ?string $extra = null,
    ) {
    }

    public static function handle(\ReflectionAttribute $attribute, CommandInfo $commandInfo)
    {
        $instance = $attribute->newInstance();
        $commandInfo->addAnnotation('bootstrap', $instance->level . ( isset($instance->extra) ? " $instance->extra" : ''));
    }
}