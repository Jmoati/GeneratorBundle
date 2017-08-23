<?php

declare(strict_types=1);

namespace Jmoati\GeneratorBundle\Service;

use Doctrine\Bundle\DoctrineBundle\Mapping\DisconnectedMetadataFactory;

class Symfony
{
    /**
     * @param Container $symfony
     * @param bool      $namespace
     * @param bool      $shortName
     *
     * @return string[]
     */
    public function getEntities($symfony, $namespace = true, $shortName = true)
    {
        $result = [];
        $namespaces = [];
        $shortNames = [];

        foreach ($symfony->get('kernel')->getBundles() as $bundle) {
            if (false !== mb_strpos($bundle->getPath(), dirname($symfony->get('kernel')->getRootDir()).'/vendor/')) {
                continue;
            }

            try {
                $manager = new DisconnectedMetadataFactory($symfony->get('doctrine'));
                $metadata = $manager->getNamespaceMetadata($bundle->getNamespace());

                if (true === $namespace) {
                    $namespaces[] = str_replace('\\', '/', $bundle->getNamespace());
                }
                if (true === $shortName) {
                    $shortNames[] = $bundle->getName();
                }

                foreach ($metadata->getMetadata() as $entity) {
                    if (true === $namespace) {
                        $namespaces[] = str_replace('\\', '/', $entity->getName());
                    }
                    if (true === $shortName) {
                        $shortNames[] = $bundle->getName().':'.basename(str_replace('\\', '/', $entity->name));
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (true === $shortName) {
            sort($shortNames);
            $result = array_merge($result, $shortNames);
        }

        if (true === $namespace) {
            sort($namespaces);
            $result = array_merge($result, $namespaces);
        }

        return $result;
    }
}
