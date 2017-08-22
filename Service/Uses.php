<?php

namespace Jmoati\GeneratorBundle\Service;

class Uses
{
    /**
     * @param string $class_name
     *
     * @return array
     */
    public function getCurrentUses($class_name)
    {
        $uses       = array();
        $reflection = new \ReflectionClass($class_name);
        $file       = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
        $head       = implode("\n", array_slice($file, 1, $reflection->getStartLine() - 2));

        if (preg_match_all('/use\ ([^;]*);/', $head, $matches)) {
            foreach ($matches[1] AS $match) {
                $splited = preg_split('/\ AS\ /i', $match, null);

                if (!isset($splited[1])) {
                    $namespace  = explode('\\', $splited[0]);
                    $splited[1] = end($namespace);
                }

                $uses[trim($splited[1])] = trim($splited[0]);
            }
        }

        return $uses;
    }

    /**
     * @param array  $uses
     * @param string $generated_code
     *
     * @return array
     */
    public function getNewUses($uses, $generated_code)
    {
        if (preg_match_all('/\\\\([^\s|^(|^;]*)[\s|(|;]/', $generated_code, $matches)) {
            foreach ($matches[1] AS $match) {
                if (!in_array($match, $uses)) {
                    $namespace = explode('\\', $match);
                    $key       = end($namespace);

                    if (isset($uses[$key])) {
                        $key .= "_" . uniqid();
                    }

                    $uses[$key] = $match;
                }
            }
        }

        return $uses;
    }

    /**
     * @param array  $uses
     * @param string $current_namespace
     *
     * @return string
     */
    public function generateUsesBlock($uses, $current_namespace)
    {
        $uses_block = array();

        foreach ($uses AS $aliase => $use) {

            $class     = explode("\\", $use);
            $namespace = implode("\\", array_slice($class, 0, count($class) - 1));

            if ($namespace == $current_namespace) {
                continue;
            }

            $use = "use $use";

            if (end($class) == $aliase) {
                $use .= ';';
            } else {
                $use .= " as $aliase;";
            }

            $uses_block[] = $use;
        }

        return implode("\n", $uses_block);
    }

    /**
     * @param stdClass $entity
     * @param string   $uses_block
     *
     * @return string
     */
    public static function rewriteHead($entity, $uses_block)
    {
        $reflection = new \ReflectionClass($entity);
        $file       = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
        $code_head  = array_slice($file, 0, $reflection->getStartLine() - 1);

        for ($i = 0, $l = count($code_head); $i < $l; ++$i) {
            if (strstr($code_head[$i], 'use ')) {
                if (isset($uses_block)) {
                    $code_head[$i] = $uses_block;
                    unset($uses_block);
                } else {
                    unset($code_head[$i]);
                }
            }
        }

        return implode(
            "\n",
            array_merge($code_head, array_slice($file, $reflection->getStartLine() - 1, $reflection->getEndLine()))
        );
    }

    /**
     * @param array  $uses
     * @param string $body
     *
     * @return string
     */
    public function aliasingUse($uses, $body)
    {
        $full_class  = array();
        $local_class = array();

        foreach ($uses AS $aliase => $use) {
            $full_class[] = "\\{$use}";
            $full_class[] = "{$use}";

            $local_class[] = $aliase;
            $local_class[] = $aliase;
        }

        return str_replace($full_class, $local_class, $body);
    }

}
