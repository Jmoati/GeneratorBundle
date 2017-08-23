<?php

declare(strict_types=1);

namespace Jmoati\GeneratorBundle\Generator;

abstract class AbstractGenerator
{
    /**
     * @var array
     */
    protected $skeletonDirs;

    /**
     * @param string[]|string $skeletonDirs
     *
     * @return $this
     */
    public function setSkeletonDirs($skeletonDirs)
    {
        $this->skeletonDirs = is_array($skeletonDirs) ? $skeletonDirs : [$skeletonDirs];

        return $this;
    }

    /**
     * @param string $template
     * @param string $target
     * @param array  $parameters
     *
     * @return int
     */
    protected function renderFile($template, $target, $parameters = [])
    {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        return file_put_contents($target, $this->render($template, $parameters));
    }

    /**
     * @param string $template
     * @param array  $parameters
     *
     * @return string
     */
    protected function render($template, $parameters)
    {
        $twig = new \Twig_Environment(new \Twig_Loader_Filesystem($this->skeletonDirs), [
            'debug' => true,
            'cache' => false,
            'strict_variables' => true,
            'autoescape' => false,
        ]);

        return $twig->render($template, $parameters);
    }
}
