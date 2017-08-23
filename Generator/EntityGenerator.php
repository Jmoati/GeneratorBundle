<?php

declare(strict_types=1);

namespace Jmoati\GeneratorBundle\Generator;

use Doctrine\Common\Util\Inflector;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Jmoati\GeneratorBundle\Service\Singularize;
use Jmoati\GeneratorBundle\Service\Uses;
use Symfony\Component\Filesystem\Filesystem;

class EntityGenerator extends AbstractGenerator
{
    /**
     * @var bool
     */
    protected $backupExisting = true;
    /**
     * @var array
     */
    protected $staticReflection = [];
    /**
     * @var int
     */
    protected $numSpaces = 4;
    /**
     * @var string
     */
    protected $spaces = '    ';
    /**
     * @var string
     */
    protected $annotationsPrefix = '';
    /**
     * @var array
     */
    protected $typeAlias = [
        Type::DATETIMETZ => '\DateTime',
        Type::DATETIME => '\DateTime',
        Type::DATE => '\DateTime',
        Type::TIME => '\DateTime',
        Type::OBJECT => '\stdClass',
        Type::BIGINT => 'integer',
        Type::SMALLINT => 'integer',
        Type::TEXT => 'string',
        Type::BLOB => 'string',
        Type::DECIMAL => 'float',
        Type::JSON_ARRAY => 'array',
        Type::SIMPLE_ARRAY => 'array',
    ];

    /**
     * @var Uses
     */
    protected $uses;

    /**
     * @var Singularize
     */
    protected $singularize;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @param Uses        $uses
     * @param Singularize $singularize
     * @param Filesystem  $filesystem
     */
    public function __construct(Uses $uses, Singularize $singularize, Filesystem $filesystem)
    {
        $this->uses = $uses;
        $this->singularize = $singularize;
        $this->filesystem = $filesystem;
    }

    /**
     * @param array  $metadatas
     * @param string $outputDirectory
     */
    public function generate(array $metadatas, $outputDirectory)
    {
        foreach ($metadatas as $metadata) {
            $this->writeEntityClass($metadata, $outputDirectory);
        }
    }

    /**
     * @param ClassMetadataInfo $metadata
     * @param string            $outputDirectory
     *
     * @throws \RuntimeException
     */
    public function writeEntityClass(ClassMetadataInfo $metadata, $outputDirectory)
    {
        if (0 === mb_strpos($metadata->namespace, 'App\\')) {
            $name = mb_substr($metadata->name, 4);
            $path = $outputDirectory.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $name).'.php';
        } else {
            $path = $outputDirectory.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $metadata->name).'.php';
        }

        $this->parseTokensInEntityFile(file_get_contents($path));

        if ($this->backupExisting && file_exists($path)) {
            $backupPath = dirname($path).DIRECTORY_SEPARATOR.basename($path).'~';
            if (!copy($path, $backupPath)) {
                throw new \RuntimeException('Attempt to backup overwritten entity file but copy operation failed.');
            }
        }

        file_put_contents($path, $this->generateUpdatedEntityClass($metadata));
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string $code;
     */
    public function generateUpdatedEntityClass(ClassMetadataInfo $metadata)
    {
        $body = $this->generateEntityBody($metadata);
        $uses = $this->uses->getCurrentUses($metadata->getName());
        $uses = $this->uses->getNewUses($uses, $body);
        $uses_block = $this->uses->generateUsesBlock($uses, $metadata->namespace);

        $code = $this->uses->rewriteHead($metadata->rootEntityName, $uses_block);
        $body = $this->uses->aliasingUse($uses, $body);
        $last = mb_strrpos($code, '}');

        return mb_substr($code, 0, $last).$body.(mb_strlen($body) > 0 ? "\n" : '')."}\n";
    }

    /**
     * @param int $numSpaces
     *
     * @return $this
     */
    public function setNumSpaces($numSpaces)
    {
        $this->spaces = str_repeat(' ', $numSpaces);
        $this->numSpaces = $numSpaces;

        return $this;
    }

    /**
     * @param string $prefix
     *
     * @return $this
     */
    public function setAnnotationPrefix($prefix)
    {
        $this->annotationsPrefix = $prefix;

        return $this;
    }

    /**
     * @param bool $bool
     *
     * @return $this
     */
    public function setBackupExisting($bool)
    {
        $this->backupExisting = $bool;

        return $this;
    }

    /**
     * @param string $src
     */
    protected function parseTokensInEntityFile($src)
    {
        $tokens = token_get_all($src);
        $lastSeenNamespace = '';
        $lastSeenClass = false;
        $inNamespace = false;
        $inClass = false;

        for ($i = 0, $limit = count($tokens); $i < $limit; ++$i) {
            $token = $tokens[$i];
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($inNamespace) {
                if ($token[0] == T_NS_SEPARATOR || $token[0] == T_STRING) {
                    $lastSeenNamespace .= $token[1];
                } elseif (is_string($token) && in_array($token, [';', '{'], true)) {
                    $inNamespace = false;
                }
            }

            if ($inClass) {
                $inClass = false;
                $lastSeenClass = $lastSeenNamespace.($lastSeenNamespace ? '\\' : '').$token[1];
                $this->staticReflection[$lastSeenClass]['properties'] = [];
                $this->staticReflection[$lastSeenClass]['methods'] = [];
            }

            if ($token[0] == T_NAMESPACE) {
                $lastSeenNamespace = '';
                $inNamespace = true;
            } else {
                if ($token[0] == T_CLASS) {
                    $inClass = true;
                } else {
                    if ($token[0] == T_FUNCTION) {
                        if ($tokens[$i + 2][0] == T_STRING) {
                            $this->staticReflection[$lastSeenClass]['methods'][] = $tokens[$i + 2][1];
                        } else {
                            if ($tokens[$i + 2] == '&' && $tokens[$i + 3][0] == T_STRING) {
                                $this->staticReflection[$lastSeenClass]['methods'][] = $tokens[$i + 3][1];
                            }
                        }
                    } else {
                        if (in_array(
                                $token[0],
                                [T_VAR, T_PUBLIC, T_PRIVATE, T_PROTECTED], true
                            ) && $tokens[$i + 2][0] != T_FUNCTION
                        ) {
                            $this->staticReflection[$lastSeenClass]['properties'][] = mb_substr($tokens[$i + 2][1], 1);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityBody(ClassMetadataInfo $metadata)
    {
        $this->generateEntityFieldMappingProperties($metadata);
        $this->generateEntityAssociationMappingProperties($metadata);
        $stubMethods = $this->generateEntityStubMethods($metadata);

        $code = [];
        $code[] = $this->generateEntityConstructor($metadata);

        if ($stubMethods) {
            $code[] = $stubMethods;
        }

        return implode("\n", $code);
    }

    /**
     * @param ClassMetadataInfo $metadata
     */
    protected function generateEntityFieldMappingProperties(ClassMetadataInfo $metadata)
    {
        foreach ($metadata->fieldMappings as $fieldMapping) {
            if (!$this->hasProperty($fieldMapping['fieldName'], $metadata)) {
                unset($metadata->fieldMappings[$fieldMapping['fieldName']]);
            }
        }
    }

    /**
     * @param string            $property
     * @param ClassMetadataInfo $metadata
     *
     * @return bool
     */
    protected function hasProperty($property, ClassMetadataInfo $metadata)
    {
        return isset($this->staticReflection[$metadata->name]) && in_array(
                $property,
                $this->staticReflection[$metadata->name]['properties'], true
            );
    }

    /**
     * @param ClassMetadataInfo $metadata
     */
    protected function generateEntityAssociationMappingProperties(ClassMetadataInfo $metadata)
    {
        foreach ($metadata->associationMappings as $associationMapping) {
            if (!$this->hasProperty($associationMapping['fieldName'], $metadata)) {
                unset($metadata->associationMappings[$associationMapping['fieldName']]);
            }
        }
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityStubMethods(ClassMetadataInfo $metadata)
    {
        $methods = [];

        foreach ($metadata->fieldMappings as $fieldMapping) {
            if (!isset($fieldMapping['id']) || !$fieldMapping['id'] || $metadata->generatorType == ClassMetadataInfo::GENERATOR_TYPE_NONE) {
                $this->generateEntityStubMethod(
                    $methods,
                    $metadata,
                    'set',
                    $fieldMapping['fieldName'],
                    $fieldMapping['type']
                );
            }

            $this->generateEntityStubMethod(
                $methods,
                $metadata,
                'get',
                $fieldMapping['fieldName'],
                $fieldMapping['type']
            );
        }

        foreach ($metadata->associationMappings as $associationMapping) {
            if ($associationMapping['type'] & ClassMetadataInfo::TO_ONE) {
                $nullable = $this->isAssociationIsNullable($associationMapping) ? 'null' : null;

                $this->generateEntityStubMethod(
                    $methods,
                    $metadata,
                    'set',
                    $associationMapping['fieldName'],
                    $associationMapping['targetEntity'],
                    $nullable
                );
                $this->generateEntityStubMethod(
                    $methods,
                    $metadata,
                    'get',
                    $associationMapping['fieldName'],
                    $associationMapping['targetEntity']
                );
            } else {
                if ($associationMapping['type'] & ClassMetadataInfo::TO_MANY) {
                    $this->generateEntityStubMethod(
                        $methods,
                        $metadata,
                        'add',
                        $associationMapping['fieldName'],
                        $associationMapping['targetEntity']
                    );
                    $this->generateEntityStubMethod(
                        $methods,
                        $metadata,
                        'remove',
                        $associationMapping['fieldName'],
                        $associationMapping['targetEntity']
                    );
                    $this->generateEntityStubMethod(
                        $methods,
                        $metadata,
                        'get',
                        $associationMapping['fieldName'],
                        'Doctrine\Common\Collections\ArrayCollection'
                    );
                }
            }
        }

        return implode("\n\n", $methods);
    }

    protected static function methodTypeHint($variableType)
    {
        $hints = [
            'boolean' => 'bool',
            'integer' => 'int',
            'uuid' => 'string',
            'guid' => 'string',
        ];

        return str_replace(array_keys($hints), array_values($hints), $variableType);
    }

    /**
     * @param                   $methods
     * @param ClassMetadataInfo $metadata
     * @param string            $type
     * @param string            $fieldName
     * @param string            $typeHint
     * @param string            $defaultValue
     *
     * @return string
     */
    protected function generateEntityStubMethod(
        &$methods,
        ClassMetadataInfo $metadata,
        $type,
        $fieldName,
        $typeHint = null,
        $defaultValue = null
    ) {
        $methodName = $type.Inflector::classify($fieldName);

        if (in_array($type, ['add', 'remove'], true)) {
            $methodName = $this->singularize->word($methodName);
        }

        if ($this->hasMethod($methodName, $metadata)) {
            return;
        }

        $this->staticReflection[$metadata->name]['methods'][] = $methodName;

        $var = sprintf('%sMethod', $type);

        $types = Type::getTypesMap();
        $variableType = $typeHint ? $this->getType($typeHint).' ' : null;
        $methodTypeHint = self::methodTypeHint($variableType);

        if ($typeHint && !isset($types[$typeHint])) {
            $variableType = '\\'.ltrim($variableType, '\\');
            $methodTypeHint = '\\'.$typeHint.' ';
        }

        $replacements = [
            'methodTypeHint' => $methodTypeHint,
            'variableType' => self::methodTypeHint($variableType),
            'variableName' => Inflector::camelize($fieldName),
            'methodName' => $methodName,
            'fieldName' => $fieldName,
            'variableDefault' => ($defaultValue !== null) ? (' = '.$defaultValue) : '',
            'entity' => $this->getClassName($metadata),
            'update_owning_side' => '',
            'spaces' => '    ',
        ];

        if (in_array($type, ['add', 'remove'], true) && $typeHint) {
            $name = explode('\\', $typeHint);
            $replacements['variableName'] = Inflector::camelize(end($name));
        }

        if ('add' == $type && $typeHint && !$metadata->associationMappings[$fieldName]['isOwningSide']) {
            $func = $metadata->associationMappings[$fieldName]['type'] == ClassMetadataInfo::MANY_TO_MANY ? 'add' : 'set';
            $func .= Inflector::classify($this->getClassName($metadata));

            $replacements['update_owning_side'] = $this->spaces.'$'.$replacements['variableName']."->{$func}(\$this);\n";
        }

        if ('remove' == $type && $typeHint && !$metadata->associationMappings[$fieldName]['isOwningSide']) {
            if ($metadata->associationMappings[$fieldName]['type'] == ClassMetadataInfo::MANY_TO_MANY) {
                $func = 'remove';
                $target = '$this';
            } else {
                $func = 'set';
                $target = 'null';
            }

            $func .= Inflector::classify($this->getClassName($metadata));

            $replacements['update_owning_side'] = $this->spaces.'$'.$replacements['variableName']."->{$func}({$target});\n";
        }

        $method = $this->render("entities/$var.php.twig", $replacements);

        $method = $this->prefixCodeWithSpaces($method);
        $methods[] = $method;

        return $method;
    }

    /**
     * @param string            $method
     * @param ClassMetadataInfo $metadata
     *
     * @return bool
     */
    protected function hasMethod($method, ClassMetadataInfo $metadata)
    {
        $reflection = new \ReflectionClass($metadata->name);

        return $reflection->hasMethod($method);
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected function getType($type)
    {
        return isset($this->typeAlias[$type]) ? $this->typeAlias[$type] : $type;
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function getClassName(ClassMetadataInfo $metadata)
    {
        return ($pos = mb_strrpos($metadata->name, '\\')) ? mb_substr(
            $metadata->name,
            $pos + 1,
            mb_strlen($metadata->name)
        ) : $metadata->name;
    }

    /**
     * @param string $code
     * @param int    $num
     *
     * @return string
     */
    protected function prefixCodeWithSpaces($code, $num = 1)
    {
        $lines = explode("\n", $code);

        foreach ($lines as &$line) {
            $line = str_repeat($this->spaces, $num).$line;
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * @param $associationMapping
     *
     * @return bool
     */
    protected function isAssociationIsNullable($associationMapping)
    {
        if (isset($associationMapping['id']) && $associationMapping['id']) {
            return false;
        }

        if (isset($associationMapping['joinColumns'])) {
            $joinColumns = $associationMapping['joinColumns'];
        } else {
            $joinColumns = [];
        }

        foreach ($joinColumns as $joinColumn) {
            if (isset($joinColumn['nullable']) && !$joinColumn['nullable']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityConstructor(ClassMetadataInfo $metadata)
    {
        if ($this->hasMethod('__construct', $metadata)) {
            return '';
        }

        $collections = [];

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping['type'] & ClassMetadataInfo::TO_MANY) {
                $collections[] = '$this->'.$mapping['fieldName'].' = new \Doctrine\Common\Collections\ArrayCollection();';
            }
        }

        if ($collections) {
            return $this->prefixCodeWithSpaces(
                $this->render(
                    'entities/constructorMethod.php.twig',
                    ['collections' => $collections, 'spaces' => $this->spaces]
                )
            );
        }

        return '';
    }
}
