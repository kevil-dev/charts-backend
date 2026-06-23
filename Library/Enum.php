<?php

namespace Library;

abstract class Enum {

    private static $constCacheArray = NULL;

    public static function getConstants() {
        if(self::$constCacheArray == NULL):
            self::$constCacheArray = [];
        endif;
        
        $calledClass = get_called_class();
        if(!array_key_exists($calledClass, self::$constCacheArray)):
            $reflect = new \ReflectionClass($calledClass);
            self::$constCacheArray[$calledClass] = $reflect->getConstants();
        endif;
        
        return self::$constCacheArray[$calledClass];
    }
    
    public static function getConstantName($value) {
        $constants = self::getConstants();
        return array_flip($constants)[$value];
    }

    public static function isValidName($name, $strict = false) {
        $constants = self::getConstants();

        if($strict):
            return array_key_exists($name, $constants);
        endif;

        $keys = array_map('strtolower', array_keys($constants));
        return in_array(strtolower($name), $keys);
    }

    public static function isValidValue($value, $strict = true) {
        $values = array_values(self::getConstants());
        return in_array($value, $values, $strict);
    }
}