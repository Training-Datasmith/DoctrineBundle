<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use function array_combine;
use function array_keys;
use function array_map;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Compiler\Service_Locator_Tag_Pass;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Reference;
/** @internal */
final class Service_Repository_Compiler_Pass implements Compiler_Pass_Interface
{
    public const string REPOSITORY_SERVICE_TAG = 'doctrine.repository_service';
    public function process(Container_Builder $container): void
    {
        // when ORM is not enabled
        if (!$container->has_definition('doctrine.orm.container_repository_factory')) {
            return;
        }
        $locator_def = $container->get_definition('doctrine.orm.container_repository_factory');
        $repo_service_ids = array_keys($container->find_tagged_service_ids(self::REPOSITORY_SERVICE_TAG));
        $repo_references = array_map(static fn(string $id): Reference => new Reference($id), $repo_service_ids);
        $ref = Service_Locator_Tag_Pass::register($container, array_combine($repo_service_ids, $repo_references));
        $locator_def->replace_argument(0, $ref);
    }
}