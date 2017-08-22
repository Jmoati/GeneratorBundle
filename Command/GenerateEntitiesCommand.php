<?php

namespace Jmoati\GeneratorBundle\Command;

use Doctrine\ORM\Tools\EntityRepositoryGenerator;
use Jmoati\GeneratorBundle\Mapping\DisconnectedMetadataFactory;
use Jmoati\GeneratorBundle\Generator\EntityGenerator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateEntitiesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('generate:entities')
            ->addArgument('name', InputArgument::REQUIRED, 'A bundle name, a namespace, or a class name')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'backup existing entities files.');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manager  = new DisconnectedMetadataFactory($this->getContainer()->get('doctrine'));
        $metadata = $this->getMetadata($input, $output, $manager);

        $entity_generator = $this->getContainer()->get(EntityGenerator::class)
            ->setNumSpaces(4)
            ->setAnnotationPrefix('ORM\\')
            ->setSkeletonDirs($this->getSkeletonDirs())
            ->setBackupExisting((bool) $input->getOption('backup'));

        $repository_generator = $generator = new EntityRepositoryGenerator();

        foreach ($metadata->getMetadata() as $m) {
            try {
                $entityMetadata = $manager->getClassMetadata($m->getName());
            } catch (\RuntimeException $e) {
                $entityMetadata = $metadata;
            }

            $output->writeln(sprintf('  > generating <comment>%s</comment>', $m->name));
            $entity_generator->generate(array($m), $entityMetadata->getPath());

            if ($m->customRepositoryClassName && false !== strpos($m->customRepositoryClassName, $metadata->getNamespace())) {
                $repository_generator->writeEntityRepositoryClass($m->customRepositoryClassName, $metadata->getPath());
            }
        }

        return 0;
    }

    /**
     * @return string[]
     */
    protected function getSkeletonDirs()
    {
        $dir = dirname(__DIR__);
        $skeletonDirs = array(
            $dir . '/Skeleton',
        );

        return $skeletonDirs;
    }

    /**
     * @param InputInterface              $input
     * @param OutputInterface             $output
     * @param DisconnectedMetadataFactory $manager
     *
     * @return mixed
     */
    protected function getMetadata(InputInterface $input, OutputInterface $output, DisconnectedMetadataFactory $manager)
    {
        try {
            $bundle = $this->getApplication()->getKernel()->getBundle($input->getArgument('name'));
            $output->writeln(sprintf('Generating entities for bundle "<info>%s</info>"', $bundle->getName()));
            $metadata = $manager->getBundleMetadata($bundle);
        } catch (\InvalidArgumentException $e) {
            $name = strtr($input->getArgument('name'), '/', '\\');

            if (false !== $pos = strpos($name, ':')) {
                $name = $this->getContainer()->get('doctrine')->getAliasNamespace(substr($name, 0, $pos)).'\\'.substr($name, $pos + 1);
            }

            if (class_exists($name)) {
                $output->writeln(sprintf('Generating entity "<info>%s</info>"', $name));
                $metadata = $manager->getClassMetadata($name);
            } else {
                $output->writeln(sprintf('Generating entities for namespace "<info>%s</info>"', $name));
                $metadata = $manager->getNamespaceMetadata($name);
            }
        }

        return $metadata;
    }
}
