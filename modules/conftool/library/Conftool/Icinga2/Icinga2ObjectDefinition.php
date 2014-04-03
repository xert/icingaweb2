<?php

namespace Icinga\Module\Conftool\Icinga2;

use Icinga\Module\Conftool\Icinga\IcingaObjectDefinition;

class Icinga2ObjectDefinition
{
    protected $properties = array();
    protected $name;
    protected $v1AttributeMap = array();
    protected $v1ArrayProperties = array();
    protected $type;
    protected $assigns = array();
    protected $ignores = array();

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function __set($key, $val)
    {
        $this->properties[$key] = $val;
    }

    public function __get($key)
    {
        return $this->properties[$key];
    }

    protected function assignWhere($assign)
    {
        $this->assigns[] = $assign;
    }

    protected function ignoreWhere($ignore)
    {
        $this->ignores[] = $ignore;
    }

    protected function setAttributesFromIcingaObjectDefinition(IcingaObjectDefinition $object)
    {
        foreach ($object->getAttributes() as $key => $value) {
            $func = 'convert' . ucfirst($key);
            if (method_exists($this, $func)) {
                $this->$func($value);
                continue;
            }
            if (! array_key_exists($key, $this->v1AttributeMap)) {
                throw new Icinga2ConfigMigrationException(
                    sprintf('Cannot convert the "%s" property of given v1 object: ', $key) . print_r($object, 1)
                );
            }
            $value = $this->migrateValue($value, $key);
            $this->{ $this->v1AttributeMap[$key] } = $value;
        }
    }

    protected function migrateValue($value, $key = null)
    {
        if ($key !== null && in_array($key, $this->v1ArrayProperties)) {
            $values = array();
            foreach ($this->splitComma($value) as $value) {
                $values[] = $this->migrateValue($value);
            }
            return $values;
        }
        if (preg_match('/^\d+/', $value)) {
            return $value;
        }
        return $this->migrateLegacyString($value);
    }

    public static function fromIcingaObjectDefinition(IcingaObjectDefinition $object)
    {
        switch ($object->getDefinitionType()) {
            case 'command':
                $new = new Icinga2Command((string) $object);
                $new->setAttributesFromIcingaObjectDefinition($object);
                break;

            case 'hostgroup':
                $new = new Icinga2Hostgroup((string) $object);
                $new->setAttributesFromIcingaObjectDefinition($object);
                break;

            default:
                throw new Icinga2ConfigMigrationException(
                    sprintf(
                        'Cannot convert unknown object "%s" of type "%s"',
                        $object,
                        $object->getDefinitionType()
                    )
                );
        }
        return $new;
    }

    protected function splitComma($string)
    {
        return preg_split('/\s*,\s*/', $string, null, PREG_SPLIT_NO_EMPTY);
    }

    protected function migrateLegacyString($string)
    {
        $string = preg_replace('~\\\~', '\\\\', $string);
        $string = preg_replace('~"~', '\\"', $string);
        return '"' . $string . '"';
    }

    protected function renderArray($array)
    {
        $parts = array();
        foreach ($array as $val) {
            $parts[] = $this->migrateLegacyString($val);
        }
        return '[ ' . implode(', ', $parts) . ' ]';
    }

    protected function arrayToString($array)
    {
        $string = '[ ' . implode(', ', $array) . ' ]';
    }

    protected function getAttributesAsString()
    {
        $str = '';
        foreach ($this->properties as $key => $val) {
            if (is_array($val)) {
                $val = $this->renderArray($val);
            }
            $str .= sprintf("    %s = %s\n", $key, $val);
        }
        return $str;
    }

    protected function getAssignmentsAsString()
    {
        $str = '';
        foreach ($this->assigns as $assign) {
            $str .= sprintf("    assign where %s\n", $assign);
        }
        foreach ($this->ignores as $ignore) {
            $str .= sprintf("    ignore where %s\n", $ignore);
        }
        return $str;
    }

 // inherits "plugin-check-command"

    public function __toString()
    {
        return sprintf(
            "object %s \"%s\" {\n%s%s}\n\n",
            $this->type,
            $this->name,
            $this->getAttributesAsString(),
            $this->getAssignmentsAsString()
        );
    }

    public function dump()
    {
        echo $this->__toString();
    }
}


